<?php

use Carbon\Carbon;
use Helpdesk\Sla\SlaMonitor;
use Helpdesk\Tickets\TicketService;

class SlaMonitorTest extends TestCase {

	/** @var SlaMonitor */
	protected $monitor;

	/** @var TicketService */
	protected $service;

	protected $requester;
	protected $agent;
	protected $category;

	/**
	 * ticket.sla payloads seen by the listener: array(ticket id, from, to).
	 *
	 * @var array
	 */
	protected $fired = array();

	public function setUp()
	{
		parent::setUp();

		Artisan::call('migrate');
		(new SlaPolicyTableSeeder)->run();

		Carbon::setTestNow(Carbon::create(2014, 7, 1, 9, 0, 0));

		$this->requester = $this->makeUser('carol', 'requester');
		$this->agent = $this->makeUser('bob', 'agent');
		$this->category = Category::create(array('name' => 'Network'));

		$this->service = App::make('Helpdesk\Tickets\TicketService');
		$this->monitor = App::make('Helpdesk\Sla\SlaMonitor');

		$fired =& $this->fired;
		Event::listen('ticket.sla', function($ticket, $from, $to) use (&$fired)
		{
			$fired[] = array((int) $ticket->id, $from, $to);
		});
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
			'subject'     => 'VPN drops every few minutes',
			'description' => 'Connection resets while on the office wifi.',
			'category_id' => $this->category->id,
			'priority'    => $priority,
		), $this->requester);
	}

	protected function slaEvents(Ticket $ticket)
	{
		return TicketEvent::where('ticket_id', $ticket->id)
			->whereIn('type', array('sla_warning', 'sla_breached'))
			->orderBy('id')
			->get();
	}

	protected function clockAt($hour, $minute)
	{
		return Carbon::create(2014, 7, 1, $hour, $minute, 0);
	}

	public function testOkToWarningWritesAnEventAndFiresOnce()
	{
		$ticket = $this->newTicket('urgent');

		$counts = $this->monitor->run($this->clockAt(9, 25));

		$this->assertSame(array('checked' => 1, 'warning' => 1, 'breached' => 0), $counts);
		$this->assertSame('warning', Ticket::find($ticket->id)->sla_state);

		$events = $this->slaEvents($ticket);
		$this->assertCount(1, $events);
		$this->assertSame('sla_warning', $events[0]->type);
		$this->assertSame('ok', $events[0]->from_value);
		$this->assertSame('warning', $events[0]->to_value);
		$this->assertNull($events[0]->user_id);

		$this->assertSame(array(array((int) $ticket->id, 'ok', 'warning')), $this->fired);
	}

	public function testUnchangedTicketIsNotRecorded()
	{
		$ticket = $this->newTicket('urgent');

		$this->monitor->run($this->clockAt(9, 10));

		$this->assertSame('ok', Ticket::find($ticket->id)->sla_state);
		$this->assertCount(0, $this->slaEvents($ticket));
		$this->assertSame(array(), $this->fired);
	}

	public function testRunningTwiceAtTheSameTimeFiresNothingTheSecondTime()
	{
		$ticket = $this->newTicket('urgent');

		$this->monitor->run($this->clockAt(9, 25));
		$this->fired = array();

		$counts = $this->monitor->run($this->clockAt(9, 25));

		$this->assertSame(array('checked' => 1, 'warning' => 1, 'breached' => 0), $counts);
		$this->assertSame(array(), $this->fired);
		$this->assertCount(1, $this->slaEvents($ticket));
	}

	public function testWarningToBreachedWritesSlaBreached()
	{
		$ticket = $this->newTicket('urgent');

		$this->monitor->run($this->clockAt(9, 25));
		$counts = $this->monitor->run($this->clockAt(9, 31));

		$this->assertSame(array('checked' => 1, 'warning' => 0, 'breached' => 1), $counts);
		$this->assertSame('breached', Ticket::find($ticket->id)->sla_state);

		$events = $this->slaEvents($ticket);
		$this->assertCount(2, $events);
		$this->assertSame('sla_breached', $events[1]->type);
		$this->assertSame('warning', $events[1]->from_value);
		$this->assertSame('breached', $events[1]->to_value);

		$this->assertSame(array(
			array((int) $ticket->id, 'ok', 'warning'),
			array((int) $ticket->id, 'warning', 'breached'),
		), $this->fired);
	}

	public function testResolvedAndClosedTicketsAreSkipped()
	{
		$resolved = $this->newTicket('urgent');
		$closed = $this->newTicket('urgent');
		Ticket::where('id', $resolved->id)->update(array('status' => 'resolved', 'resolved_at' => $this->clockAt(15, 0)));
		Ticket::where('id', $closed->id)->update(array('status' => 'closed', 'resolved_at' => $this->clockAt(15, 0), 'closed_at' => $this->clockAt(16, 0)));

		$counts = $this->monitor->run($this->clockAt(17, 0));

		$this->assertSame(array('checked' => 0, 'warning' => 0, 'breached' => 0), $counts);
		$this->assertSame('ok', Ticket::find($resolved->id)->sla_state);
		$this->assertSame('ok', Ticket::find($closed->id)->sla_state);
		$this->assertCount(0, $this->slaEvents($resolved));
		$this->assertCount(0, $this->slaEvents($closed));
		$this->assertSame(array(), $this->fired);
	}

	public function testDryRunCountsButWritesNothing()
	{
		$ticket = $this->newTicket('urgent');

		$counts = $this->monitor->run($this->clockAt(9, 31), true);

		$this->assertSame(array('checked' => 1, 'warning' => 0, 'breached' => 1), $counts);
		$this->assertSame('ok', Ticket::find($ticket->id)->sla_state);
		$this->assertCount(0, $this->slaEvents($ticket));
		$this->assertSame(array(), $this->fired);
	}

}
