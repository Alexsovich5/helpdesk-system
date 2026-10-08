<?php

use Carbon\Carbon;
use Helpdesk\Reports\ReportService;

require_once __DIR__.'/../../fixtures/ReportFixture.php';

class ReportServiceTest extends TestCase {

	/** @var ReportService */
	protected $reports;

	/** @var ReportFixture */
	protected $fixture;

	public function setUp()
	{
		parent::setUp();

		Artisan::call('migrate');

		$this->fixture = (new ReportFixture)->build();
		$this->reports = new ReportService;

		Carbon::setTestNow(ReportFixture::now());
	}

	public function tearDown()
	{
		Carbon::setTestNow();

		parent::tearDown();
	}

	protected function january()
	{
		return $this->reports->summary(Carbon::create(2014, 1, 1, 0, 0, 0), Carbon::create(2014, 1, 31, 0, 0, 0));
	}

	public function testCountsByStatusPriorityAndCategory()
	{
		$summary = $this->january();

		$this->assertSame(array('new' => 1, 'open' => 1, 'pending' => 1, 'resolved' => 2, 'closed' => 1), $summary['by_status']);
		$this->assertSame(array('low' => 1, 'normal' => 3, 'high' => 1, 'urgent' => 1), $summary['by_priority']);
		$this->assertSame(array('Hardware' => 2, 'Network' => 1, 'Software' => 3), $summary['by_category']);
	}

	public function testCreatedVersusResolvedInTheRange()
	{
		$summary = $this->january();

		$this->assertSame(6, $summary['created']);
		// t1, t2, t4 and the December ticket resolved on 2 January.
		$this->assertSame(4, $summary['resolved']);
	}

	public function testSlaCompliance()
	{
		$summary = $this->january();

		$this->assertSame(array('measured' => 6, 'met' => 4, 'percent' => 66.7), $summary['response']);
		$this->assertSame(array('measured' => 5, 'met' => 2, 'percent' => 40.0), $summary['resolution']);
	}

	public function testMeanTimes()
	{
		$summary = $this->january();

		$this->assertSame(39.0, $summary['mean_response_minutes']);
		$this->assertSame(660.0, $summary['mean_resolution_minutes']);
	}

	public function testPerAgentOpenAndResolved()
	{
		$summary = $this->january();

		$this->assertSame(array(
			array('name' => 'Bob Agent', 'username' => 'bob', 'open' => 1, 'resolved' => 2),
			array('name' => 'Dave Agent', 'username' => 'dave', 'open' => 1, 'resolved' => 1),
		), $summary['agents']);
	}

	public function testRangeIsWholeDaysAndExcludesOutsideTickets()
	{
		$summary = $this->reports->summary(Carbon::create(2014, 1, 31, 15, 0, 0), Carbon::create(2014, 2, 1, 0, 0, 0));

		$this->assertSame('2014-01-31 00:00:00', $summary['from']->toDateTimeString());
		$this->assertSame('2014-02-01 23:59:59', $summary['to']->toDateTimeString());
		// t5 (31 Jan 10:00) and the 1 Feb ticket; nothing from earlier in January.
		$this->assertSame(2, $summary['created']);
		$this->assertSame(array('low' => 1, 'normal' => 0, 'high' => 0, 'urgent' => 1), $summary['by_priority']);
	}

	public function testTicketsReturnsTheRangeInCreationOrder()
	{
		$tickets = $this->reports->tickets(Carbon::create(2014, 1, 1), Carbon::create(2014, 1, 31));

		$this->assertSame(
			array('t1', 't2', 't3', 't4', 't6', 't5'),
			array_map(function($t) { return substr($t->subject, strlen('Report fixture ')); }, $tickets->all())
		);
	}

	public function testEmptyRangeGivesZerosAndNullCompliance()
	{
		$summary = $this->reports->summary(Carbon::create(2013, 6, 1), Carbon::create(2013, 6, 30));

		$this->assertSame(0, $summary['created']);
		$this->assertSame(0, $summary['resolved']);
		$this->assertSame(array('new' => 0, 'open' => 0, 'pending' => 0, 'resolved' => 0, 'closed' => 0), $summary['by_status']);
		$this->assertSame(array(), $summary['by_category']);
		$this->assertSame(array('measured' => 0, 'met' => 0, 'percent' => null), $summary['response']);
		$this->assertSame(array('measured' => 0, 'met' => 0, 'percent' => null), $summary['resolution']);
		$this->assertNull($summary['mean_response_minutes']);
		$this->assertNull($summary['mean_resolution_minutes']);
		$this->assertSame(array(), $summary['agents']);
	}

}
