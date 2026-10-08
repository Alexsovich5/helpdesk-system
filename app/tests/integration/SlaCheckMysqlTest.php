<?php

use Carbon\Carbon;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * sla:check against MySQL. Fixtures are created inside the per-test
 * transaction and every assertion is limited to the tickets created here.
 *
 * @group integration
 */
class SlaCheckMysqlTest extends IntegrationTestCase {

	protected $tickets = array();

	public function setUp()
	{
		parent::setUp();

		foreach (array('urgent' => array(30, 240), 'normal' => array(240, 1440)) as $priority => $minutes)
		{
			$policy = SlaPolicy::firstOrNew(array('priority' => $priority));
			$policy->response_minutes = $minutes[0];
			$policy->resolution_minutes = $minutes[1];
			$policy->save();
		}

		$requester = User::firstOrCreate(array(
			'username' => 'sla-check-requester',
			'name'     => 'SLA Check Requester',
			'email'    => 'sla-check-requester@helpdesk.local',
			'role'     => 'requester',
			'source'   => 'local',
		));

		$category = Category::firstOrCreate(array('name' => 'Software'));
		$service = App::make('Helpdesk\Tickets\TicketService');

		$created = array(
			'breached' => array(Carbon::create(2014, 7, 1, 8, 0, 0), 'urgent'),
			'warning'  => array(Carbon::create(2014, 7, 1, 9, 0, 0), 'urgent'),
			'ok'       => array(Carbon::create(2014, 7, 1, 9, 0, 0), 'normal'),
		);

		foreach ($created as $expected => $spec)
		{
			Carbon::setTestNow($spec[0]);
			$this->tickets[$expected] = $service->create(array(
				'subject'     => 'Shared drive mapping fails at logon',
				'description' => 'Drive H: is missing after signing in.',
				'category_id' => $category->id,
				'priority'    => $spec[1],
			), $requester);
		}

		Carbon::setTestNow(Carbon::create(2014, 7, 1, 9, 25, 0));
	}

	/**
	 * Runs sla:check and returns array(exit status, trimmed output).
	 */
	protected function slaCheck(array $options = array())
	{
		$output = new BufferedOutput;
		$status = Artisan::call('sla:check', $options, $output);

		return array($status, trim($output->fetch()));
	}

	public function tearDown()
	{
		Carbon::setTestNow();

		parent::tearDown();
	}

	protected function ids()
	{
		return array_map(function($ticket) { return $ticket->id; }, array_values($this->tickets));
	}

	public function testCheckUpdatesStatesAndRecordsEventsForChangedTickets()
	{
		list($status, $output) = $this->slaCheck();

		$this->assertSame(0, $status);
		$this->assertRegExp('/^checked \d+, warning \d+, breached \d+$/', $output);

		foreach ($this->tickets as $expected => $ticket)
		{
			$this->assertSame($expected, Ticket::find($ticket->id)->sla_state);
		}

		$events = TicketEvent::whereIn('ticket_id', $this->ids())
			->whereIn('type', array('sla_warning', 'sla_breached'))
			->orderBy('id')
			->get();

		$this->assertCount(2, $events);
		$this->assertEquals($this->tickets['breached']->id, $events[0]->ticket_id);
		$this->assertSame('sla_breached', $events[0]->type);
		$this->assertSame('ok', $events[0]->from_value);
		$this->assertSame('breached', $events[0]->to_value);
		$this->assertEquals($this->tickets['warning']->id, $events[1]->ticket_id);
		$this->assertSame('sla_warning', $events[1]->type);
	}

	public function testSecondRunAtTheSameTimeRecordsNothingNew()
	{
		$this->slaCheck();
		$this->slaCheck();

		$count = TicketEvent::whereIn('ticket_id', $this->ids())
			->whereIn('type', array('sla_warning', 'sla_breached'))
			->count();

		$this->assertEquals(2, $count);
	}

}
