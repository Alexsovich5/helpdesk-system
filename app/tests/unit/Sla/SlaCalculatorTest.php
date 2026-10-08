<?php

use Carbon\Carbon;
use Helpdesk\Sla\SlaCalculator;

class SlaCalculatorTest extends TestCase {

	/** @var SlaCalculator */
	protected $calculator;

	public function setUp()
	{
		parent::setUp();

		Artisan::call('migrate');
		(new SlaPolicyTableSeeder)->run();

		$this->calculator = new SlaCalculator;
	}

	public function tearDown()
	{
		Carbon::setTestNow();

		parent::tearDown();
	}

	protected function time($hour, $minute, $second = 0)
	{
		return Carbon::create(2014, 7, 1, $hour, $minute, $second);
	}

	/**
	 * An urgent ticket created at 10:00 with due dates already computed.
	 */
	protected function urgentTicket()
	{
		Carbon::setTestNow($this->time(10, 0));

		$ticket = new Ticket(array('priority' => 'urgent'));
		$ticket->created_at = $this->time(10, 0);

		foreach ($this->calculator->dueDates($ticket) as $column => $due)
		{
			$ticket->$column = $due;
		}

		return $ticket;
	}

	public function testUrgentTicketGetsThirtyMinuteResponseAndFourHourResolution()
	{
		$dates = $this->calculator->dueDates($this->urgentTicket());

		$this->assertEquals($this->time(10, 30), $dates['response_due_at']);
		$this->assertEquals($this->time(14, 0), $dates['resolution_due_at']);
	}

	public function testDueDatesFollowEachPriorityPolicy()
	{
		$expected = array(
			'high'   => array($this->time(11, 0), $this->time(18, 0)),
			'normal' => array($this->time(14, 0), Carbon::create(2014, 7, 2, 10, 0, 0)),
			'low'    => array($this->time(18, 0), Carbon::create(2014, 7, 4, 10, 0, 0)),
		);

		foreach ($expected as $priority => $due)
		{
			$ticket = new Ticket(array('priority' => $priority));
			$ticket->created_at = $this->time(10, 0);

			$dates = $this->calculator->dueDates($ticket);

			$this->assertEquals($due[0], $dates['response_due_at'], "$priority response");
			$this->assertEquals($due[1], $dates['resolution_due_at'], "$priority resolution");
		}
	}

	public function testUnsavedTicketIsTimedFromNow()
	{
		Carbon::setTestNow($this->time(9, 15));

		$dates = $this->calculator->dueDates(new Ticket(array('priority' => 'urgent')));

		$this->assertEquals($this->time(9, 45), $dates['response_due_at']);
		$this->assertEquals($this->time(13, 15), $dates['resolution_due_at']);
	}

	public function testPriorityWithoutPolicyHasNoDueDates()
	{
		SlaPolicy::where('priority', 'low')->delete();

		$ticket = new Ticket(array('priority' => 'low'));
		$ticket->created_at = $this->time(10, 0);

		$this->assertSame(array('response_due_at' => null, 'resolution_due_at' => null), $this->calculator->dueDates($ticket));
	}

	public function testRunningResponseClockIsOkEarlyOn()
	{
		$this->assertSame('ok', $this->calculator->state($this->urgentTicket(), $this->time(10, 10)));
	}

	public function testRunningResponseClockWarnsAtEightyPercent()
	{
		$ticket = $this->urgentTicket();

		$this->assertSame('ok', $this->calculator->state($ticket, $this->time(10, 23, 59)));
		$this->assertSame('warning', $this->calculator->state($ticket, $this->time(10, 24)));
	}

	public function testRunningResponseClockBreachesAfterDue()
	{
		$ticket = $this->urgentTicket();

		$this->assertSame('warning', $this->calculator->state($ticket, $this->time(10, 30)));
		$this->assertSame('breached', $this->calculator->state($ticket, $this->time(10, 31)));
	}

	public function testResponseClockStopsOnceFirstResponseIsGiven()
	{
		$ticket = $this->urgentTicket();
		$ticket->first_responded_at = $this->time(10, 20);

		// Response clock stopped in time; resolution clock at 31 of 240 minutes.
		$this->assertSame('ok', $this->calculator->state($ticket, $this->time(10, 31)));
	}

	public function testStateIsWorstOfBothClocks()
	{
		$ticket = $this->urgentTicket();
		$ticket->first_responded_at = $this->time(10, 20);

		// Response ok, resolution at 200 of 240 minutes (83 %).
		$this->assertSame('warning', $this->calculator->state($ticket, $this->time(13, 20)));

		// Response breached, resolution ok.
		$late = $this->urgentTicket();
		$this->assertSame('breached', $this->calculator->state($late, $this->time(10, 45)));
	}

	public function testLateResponseKeepsResponseClockBreached()
	{
		$ticket = $this->urgentTicket();
		$ticket->first_responded_at = $this->time(10, 40);

		$this->assertSame('breached', $this->calculator->state($ticket, $this->time(10, 41)));
		$this->assertSame('breached', $this->calculator->state($ticket, $this->time(11, 0)));
	}

	public function testTicketResolvedInTimeIsOkAtAnyLaterTime()
	{
		$ticket = $this->urgentTicket();
		$ticket->first_responded_at = $this->time(10, 5);
		$ticket->resolved_at = $this->time(13, 50);

		$this->assertSame('ok', $this->calculator->state($ticket, $this->time(13, 50)));
		$this->assertSame('ok', $this->calculator->state($ticket, Carbon::create(2014, 7, 9, 12, 0, 0)));
	}

	public function testTicketResolvedLateIsBreachedAndNeverWarning()
	{
		$ticket = $this->urgentTicket();
		$ticket->first_responded_at = $this->time(10, 5);
		$ticket->resolved_at = $this->time(14, 10);

		$this->assertSame('breached', $this->calculator->state($ticket, $this->time(14, 10)));
		$this->assertSame('breached', $this->calculator->state($ticket, Carbon::create(2014, 7, 9, 12, 0, 0)));
	}

	public function testStoppedClockInsideWarningWindowReportsOk()
	{
		$ticket = $this->urgentTicket();
		$ticket->first_responded_at = $this->time(10, 28);

		// Response stopped at 93 % of its window; resolution barely started.
		$this->assertSame('ok', $this->calculator->state($ticket, $this->time(10, 29)));
	}

	public function testNullDueDatesCountAsOk()
	{
		$ticket = new Ticket(array('priority' => 'urgent'));
		$ticket->created_at = $this->time(10, 0);

		$this->assertSame('ok', $this->calculator->state($ticket, Carbon::create(2014, 8, 1, 0, 0, 0)));
	}

}
