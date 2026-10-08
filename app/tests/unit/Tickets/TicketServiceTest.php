<?php

use Carbon\Carbon;
use Helpdesk\Tickets\TicketService;

class TicketServiceTest extends TestCase {

	/** @var TicketService */
	protected $service;

	protected $requester;
	protected $otherRequester;
	protected $agent;
	protected $category;

	/** @var array list of array(event name, payload) */
	protected $fired = array();

	public function setUp()
	{
		parent::setUp();

		Artisan::call('migrate');

		Carbon::setTestNow(Carbon::create(2014, 7, 1, 9, 0, 0));

		$this->requester = $this->makeUser('carol', 'requester');
		$this->otherRequester = $this->makeUser('dave', 'requester');
		$this->agent = $this->makeUser('bob', 'agent');
		$this->category = Category::create(array('name' => 'Hardware'));

		$fired =& $this->fired;
		Event::listen('ticket.*', function() use (&$fired)
		{
			$fired[] = array(Event::firing(), func_get_args());
		});

		$this->service = App::make('Helpdesk\Tickets\TicketService');
	}

	public function tearDown()
	{
		Carbon::setTestNow();

		parent::tearDown();
	}

	protected function makeUser($username, $role)
	{
		$user = new User;
		$user->username = $username;
		$user->name = ucfirst($username);
		$user->email = $username.'@helpdesk.local';
		$user->role = $role;
		$user->source = 'local';
		$user->save();

		return $user;
	}

	protected function newTicket($requester = null)
	{
		return $this->service->create(array(
			'subject'     => 'Printer on floor 2 jams',
			'description' => 'Every second page jams in tray 1.',
			'category_id' => $this->category->id,
			'priority'    => 'high',
		), $requester ?: $this->requester);
	}

	protected function eventTypes($ticket)
	{
		return TicketEvent::where('ticket_id', $ticket->id)->orderBy('id')->lists('type');
	}

	protected function firedNames()
	{
		return array_map(function($f) { return $f[0]; }, $this->fired);
	}

	protected function resolvedTicket()
	{
		$ticket = $this->newTicket();
		$this->service->assign($ticket, $this->agent, $this->agent);
		$this->service->transition($ticket, 'resolved', $this->agent);

		return $ticket;
	}

	public function testCreateSetsNewStatusNumberAndCreatedEvent()
	{
		$ticket = $this->newTicket();

		$this->assertTrue($ticket->exists);
		$this->assertSame('new', $ticket->status);
		$this->assertSame('HD-000001', $ticket->number);
		$this->assertSame('high', $ticket->priority);
		$this->assertSame('ok', $ticket->sla_state);
		$this->assertEquals($this->requester->id, $ticket->requester_id);
		$this->assertNull($ticket->assignee_id);

		$fresh = Ticket::find($ticket->id);
		$this->assertSame('HD-000001', $fresh->number);

		$events = TicketEvent::where('ticket_id', $ticket->id)->get();
		$this->assertCount(1, $events);
		$this->assertSame('created', $events[0]->type);
		$this->assertSame('new', $events[0]->to_value);
		$this->assertEquals($this->requester->id, $events[0]->user_id);
		$this->assertEquals('2014-07-01 09:00:00', $events[0]->created_at->toDateTimeString());
	}

	public function testNumbersFollowIds()
	{
		$first = $this->newTicket();
		$second = $this->newTicket($this->otherRequester);

		$this->assertSame('HD-000002', $second->number);
		$this->assertEquals($second->id, Ticket::findByNumber('HD-000002')->id);
		$this->assertNull(Ticket::findByNumber('HD-000099'));
		$this->assertNotEquals($first->number, $second->number);
	}

	public function testPriorityDefaultsToNormal()
	{
		$ticket = $this->service->create(array(
			'subject' => 'VPN drops', 'description' => 'Hourly.', 'category_id' => $this->category->id,
		), $this->requester);

		$this->assertSame('normal', $ticket->priority);
	}

	public function testAssigningANewTicketOpensItWithTwoEvents()
	{
		$ticket = $this->newTicket();

		$this->service->assign($ticket, $this->agent, $this->agent);

		$fresh = Ticket::find($ticket->id);
		$this->assertEquals($this->agent->id, $fresh->assignee_id);
		$this->assertSame('open', $fresh->status);
		$this->assertSame(array('created', 'assigned', 'status'), $this->eventTypes($ticket));

		$status = TicketEvent::where('ticket_id', $ticket->id)->where('type', 'status')->first();
		$this->assertSame('new', $status->from_value);
		$this->assertSame('open', $status->to_value);

		$assigned = TicketEvent::where('ticket_id', $ticket->id)->where('type', 'assigned')->first();
		$this->assertNull($assigned->from_value);
		$this->assertSame('bob', $assigned->to_value);
	}

	public function testReassigningAnOpenTicketDoesNotChangeStatus()
	{
		$ticket = $this->newTicket();
		$this->service->assign($ticket, $this->agent, $this->agent);
		$other = $this->makeUser('erin', 'admin');

		$this->service->assign($ticket, $other, $this->agent);

		$this->assertSame('open', $ticket->status);
		$this->assertEquals($other->id, Ticket::find($ticket->id)->assignee_id);
		$this->assertSame(array('created', 'assigned', 'status', 'assigned'), $this->eventTypes($ticket));
	}

	public function testOnlyAgentsCanBeAssigned()
	{
		$ticket = $this->newTicket();

		$this->setExpectedException('InvalidArgumentException');

		$this->service->assign($ticket, $this->otherRequester, $this->agent);
	}

	public function testFirstPublicAgentCommentSetsFirstRespondedAt()
	{
		$ticket = $this->newTicket();

		Carbon::setTestNow(Carbon::create(2014, 7, 1, 9, 20, 0));
		$comment = $this->service->comment($ticket, $this->agent, 'Can you send the model number?');

		$this->assertFalse((bool) $comment->is_internal);
		$this->assertEquals('2014-07-01 09:20:00', Ticket::find($ticket->id)->first_responded_at->toDateTimeString());

		Carbon::setTestNow(Carbon::create(2014, 7, 1, 10, 0, 0));
		$this->service->comment($ticket, $this->agent, 'Any update?');

		$this->assertEquals('2014-07-01 09:20:00', Ticket::find($ticket->id)->first_responded_at->toDateTimeString());
		$this->assertSame(array('created', 'comment', 'comment'), $this->eventTypes($ticket));
	}

	public function testInternalAgentCommentDoesNotSetFirstRespondedAt()
	{
		$ticket = $this->newTicket();

		$comment = $this->service->comment($ticket, $this->agent, 'Probably the fuser.', true);

		$this->assertTrue((bool) $comment->is_internal);
		$this->assertNull(Ticket::find($ticket->id)->first_responded_at);
	}

	public function testRequesterCommentDoesNotSetFirstRespondedAtAndCannotBeInternal()
	{
		$ticket = $this->newTicket();

		$comment = $this->service->comment($ticket, $this->requester, 'Still jamming.', true);

		$this->assertFalse((bool) TicketComment::find($comment->id)->is_internal);
		$this->assertNull(Ticket::find($ticket->id)->first_responded_at);
	}

	public function testResolvingSetsResolvedAtAndReopenClearsIt()
	{
		$ticket = $this->newTicket();
		$this->service->assign($ticket, $this->agent, $this->agent);

		Carbon::setTestNow(Carbon::create(2014, 7, 1, 11, 0, 0));
		$this->service->transition($ticket, 'resolved', $this->agent);

		$fresh = Ticket::find($ticket->id);
		$this->assertSame('resolved', $fresh->status);
		$this->assertEquals('2014-07-01 11:00:00', $fresh->resolved_at->toDateTimeString());

		$this->service->transition($ticket, 'open', $this->agent);

		$fresh = Ticket::find($ticket->id);
		$this->assertSame('open', $fresh->status);
		$this->assertNull($fresh->resolved_at);

		$last = TicketEvent::where('ticket_id', $ticket->id)->orderBy('id', 'desc')->first();
		$this->assertSame('status', $last->type);
		$this->assertSame('resolved', $last->from_value);
		$this->assertSame('open', $last->to_value);
	}

	public function testClosingSetsClosedAt()
	{
		$ticket = $this->resolvedTicket();

		$this->service->transition($ticket, 'closed', $this->agent);

		$this->assertSame('closed', $ticket->status);
		$this->assertNotNull(Ticket::find($ticket->id)->closed_at);
	}

	public function testClosedTicketCannotMoveAnywhere()
	{
		$ticket = $this->resolvedTicket();
		$this->service->transition($ticket, 'closed', $this->agent);

		foreach (array('new', 'open', 'pending', 'resolved') as $to)
		{
			try
			{
				$this->service->transition($ticket, $to, $this->agent);
				$this->fail("closed -> $to was allowed");
			}
			catch (Helpdesk\Tickets\InvalidTransitionException $e)
			{
				$this->assertSame('closed', Ticket::find($ticket->id)->status);
			}
		}
	}

	public function testInvalidTransitionWritesNoEvent()
	{
		$ticket = $this->newTicket();

		try
		{
			$this->service->transition($ticket, 'pending', $this->agent);
			$this->fail('new -> pending was allowed');
		}
		catch (Helpdesk\Tickets\InvalidTransitionException $e) {}

		$this->assertSame(array('created'), $this->eventTypes($ticket));
		$this->assertSame('new', Ticket::find($ticket->id)->status);
	}

	public function testRequesterCanReopenOwnResolvedTicket()
	{
		$ticket = $this->resolvedTicket();

		$this->service->transition($ticket, 'open', $this->requester);

		$this->assertSame('open', Ticket::find($ticket->id)->status);
	}

	public function testRequesterCanCloseOwnResolvedTicket()
	{
		$ticket = $this->resolvedTicket();

		$this->service->transition($ticket, 'closed', $this->requester);

		$this->assertSame('closed', Ticket::find($ticket->id)->status);
	}

	public function testRequesterCannotResolveOwnOpenTicket()
	{
		$ticket = $this->newTicket();
		$this->service->assign($ticket, $this->agent, $this->agent);

		$this->setExpectedException('Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException');

		$this->service->transition($ticket, 'resolved', $this->requester);
	}

	public function testRequesterCannotTouchAnotherRequestersTicket()
	{
		$ticket = $this->resolvedTicket();

		try
		{
			$this->service->transition($ticket, 'open', $this->otherRequester);
			$this->fail('another requester reopened the ticket');
		}
		catch (Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException $e) {}

		$this->assertSame('resolved', Ticket::find($ticket->id)->status);
	}

	public function testDomainEventsAreFired()
	{
		$ticket = $this->newTicket();
		$this->assertSame(array('ticket.created'), $this->firedNames());
		$this->assertSame($ticket, $this->fired[0][1][0]);
		$this->assertSame($this->requester, $this->fired[0][1][1]);

		$this->fired = array();
		$this->service->assign($ticket, $this->agent, $this->agent);
		$this->assertSame(array('ticket.assigned', 'ticket.status'), $this->firedNames());
		$this->assertSame($this->agent, $this->fired[0][1][1]);
		$this->assertSame(array('new', 'open'), array_slice($this->fired[1][1], 1, 2));

		$this->fired = array();
		$comment = $this->service->comment($ticket, $this->agent, 'Looking now.');
		$this->assertSame(array('ticket.commented'), $this->firedNames());
		$this->assertSame($comment, $this->fired[0][1][1]);

		$this->fired = array();
		$this->service->transition($ticket, 'pending', $this->agent);
		$this->assertSame(array('ticket.status'), $this->firedNames());
		$this->assertSame(array($ticket, 'open', 'pending', $this->agent), $this->fired[0][1]);
	}

	public function testEventRowsAreNeverUpdated()
	{
		$ticket = $this->newTicket();
		$event = TicketEvent::where('ticket_id', $ticket->id)->first();

		$event->to_value = 'closed';
		$this->assertFalse($event->save());

		$this->assertSame('new', TicketEvent::find($event->id)->to_value);
	}

	public function testVisibleToScopeLimitsRequestersToOwnTickets()
	{
		$own = $this->newTicket();
		$other = $this->newTicket($this->otherRequester);

		$this->assertEquals(array($own->id), Ticket::visibleTo($this->requester)->lists('id'));
		$this->assertEquals(array($own->id, $other->id), Ticket::visibleTo($this->agent)->orderBy('id')->lists('id'));
	}

	public function testOpenScopeExcludesResolvedAndClosed()
	{
		$pending = $this->newTicket();
		$resolved = $this->resolvedTicket();
		$this->service->assign($pending, $this->agent, $this->agent);
		$this->service->transition($pending, 'pending', $this->agent);
		$fresh = $this->newTicket();

		$this->assertEquals(array($pending->id, $fresh->id), Ticket::open()->orderBy('id')->lists('id'));
	}

}
