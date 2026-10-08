<?php

use Carbon\Carbon;
use Helpdesk\Reports\ReportService;

require_once __DIR__.'/../fixtures/ReportFixture.php';

/**
 * The report fixture on MySQL gives the same figures as on sqlite. Fixture
 * rows are dated January 2014 and the summary covers that month only, so
 * nothing outside this test is counted.
 *
 * @group integration
 */
class ReportServiceMysqlTest extends IntegrationTestCase {

	/** @var ReportFixture */
	protected $fixture;

	public function setUp()
	{
		parent::setUp();

		foreach (array('low' => array(480, 4320), 'normal' => array(240, 1440), 'high' => array(60, 480), 'urgent' => array(30, 240)) as $priority => $minutes)
		{
			$policy = SlaPolicy::firstOrNew(array('priority' => $priority));
			if ( ! $policy->exists)
			{
				$policy->response_minutes = $minutes[0];
				$policy->resolution_minutes = $minutes[1];
				$policy->save();
			}
		}

		$this->fixture = (new ReportFixture)->build('report-');

		Carbon::setTestNow(ReportFixture::now());
	}

	public function tearDown()
	{
		Carbon::setTestNow();

		parent::tearDown();
	}

	public function testSummaryMatchesTheSqliteFigures()
	{
		$summary = (new ReportService)->summary(Carbon::create(2014, 1, 1), Carbon::create(2014, 1, 31));

		$this->assertSame(6, $summary['created']);
		$this->assertSame(4, $summary['resolved']);
		$this->assertSame(array('new' => 1, 'open' => 1, 'pending' => 1, 'resolved' => 2, 'closed' => 1), $summary['by_status']);
		$this->assertSame(array('low' => 1, 'normal' => 3, 'high' => 1, 'urgent' => 1), $summary['by_priority']);
		$this->assertSame(array('Hardware' => 2, 'Network' => 1, 'Software' => 3), $summary['by_category']);
		$this->assertSame(array('measured' => 6, 'met' => 4, 'percent' => 66.7), $summary['response']);
		$this->assertSame(array('measured' => 5, 'met' => 2, 'percent' => 40.0), $summary['resolution']);
		$this->assertSame(39.0, $summary['mean_response_minutes']);
		$this->assertSame(660.0, $summary['mean_resolution_minutes']);
		$this->assertSame(array(
			array('name' => 'Bob Agent', 'username' => 'report-bob', 'open' => 1, 'resolved' => 2),
			array('name' => 'Dave Agent', 'username' => 'report-dave', 'open' => 1, 'resolved' => 1),
		), $summary['agents']);
	}

}
