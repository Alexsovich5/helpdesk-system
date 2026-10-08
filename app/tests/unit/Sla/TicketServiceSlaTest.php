<?php

use Carbon\Carbon;
use Helpdesk\Tickets\TicketService;

class TicketServiceSlaTest extends TestCase {

	/** @var TicketService */
	protected $service;

	protected $requester;
	protected $agent;
	protected $category;

	public function setUp()
	{
		parent::setUp();

		Artisan::call('migrate');
		(new SlaPolicyTableSeeder)->run();

		Carbon::setTestNow(Carbon::create(2014, 7, 1, 9, 0, 0));

		$this->requester = $this->makeUser('carol', 'requester');
		$this->agent = $this->makeUser('bob', 'agent');
		$this->category = Category::create(array('name' => 'Hardware'));

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

	protected function newTicket($priority)
	{
		return $this->service->create(array(
			'subject'     => 'Printer on floor 2 jams',
			'description' => 'Every second page jams in tray 1.',
			'category_id' => $this->category->id,
			'priority'    => $priority,
		), $this->requester);
	}

	protected function lastEvent(Ticket $ticket)
	{
		return TicketEvent::where('ticket_id', $ticket->id)->orderBy('id', 'desc')->first();
	}

	public function testCreateSetsDueDatesFromThePriorityPolicy()
	{
		$ticket = Ticket::find($this->newTicket('high')->id);

		$this->assertEquals('2014-07-01 10:00:00', $ticket->response_due_at->toDateTimeString());
		$this->assertEquals('2014-07-01 17:00:00', $ticket->resolution_due_at->toDateTimeString());
	}

	public function testResolvingInTimeStoresOk()
	{
		$ticket = $this->newTicket('urgent');
		$this->service->assign($ticket, $this->agent, $this->agent);

		Carbon::setTestNow(Carbon::create(2014, 7, 1, 9, 10, 0));
		$this->service->comment($ticket, $this->agent, 'On my way.');

		Carbon::setTestNow(Carbon::create(2014, 7, 1, 12, 0, 0));
		$this->service->transition($ticket, 'resolved', $this->agent);

		$this->assertSame('ok', Ticket::find($ticket->id)->sla_state);
	}

	public function testResolvingAfterTheResolutionDueTimeStoresBreached()
	{
		$ticket = $this->newTicket('urgent');
		$this->service->assign($ticket, $this->agent, $this->agent);

		Carbon::setTestNow(Carbon::create(2014, 7, 1, 9, 10, 0));
		$this->service->comment($ticket, $this->agent, 'On my way.');

		Carbon::setTestNow(Carbon::create(2014, 7, 1, 13, 5, 0));
		$this->service->transition($ticket, 'resolved', $this->agent);

		$this->assertSame('breached', Ticket::find($ticket->id)->sla_state);
	}

	public function testChangePriorityRecomputesDueDatesFromCreationTime()
	{
		$ticket = $this->newTicket('low');

		Carbon::setTestNow(Carbon::create(2014, 7, 1, 9, 20, 0));
		$this->service->changePriority($ticket, 'urgent', $this->agent);

		$fresh = Ticket::find($ticket->id);
		$this->assertSame('urgent', $fresh->priority);
		$this->assertEquals('2014-07-01 09:30:00', $fresh->response_due_at->toDateTimeString());
		$this->assertEquals('2014-07-01 13:00:00', $fresh->resolution_due_at->toDateTimeString());

		$event = $this->lastEvent($ticket);
		$this->assertSame('priority', $event->type);
		$this->assertSame('low', $event->from_value);
		$this->assertSame('urgent', $event->to_value);
		$this->assertEquals($this->agent->id, $event->user_id);
	}

	public function testChangingToTheSamePriorityWritesNoEvent()
	{
		$ticket = $this->newTicket('high');

		$this->service->changePriority($ticket, 'high', $this->agent);

		$this->assertSame('created', $this->lastEvent($ticket)->type);
	}

	public function testUnknownPriorityIsRejected()
	{
		$ticket = $this->newTicket('high');

		$this->setExpectedException('InvalidArgumentException');

		$this->service->changePriority($ticket, 'critical', $this->agent);
	}

}
