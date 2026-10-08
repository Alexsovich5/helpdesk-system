<?php

use Carbon\Carbon;

require_once __DIR__.'/../fixtures/ReportFixture.php';

class ReportTest extends TestCase {

	/** @var ReportFixture */
	protected $fixture;

	public function setUp()
	{
		parent::setUp();

		Artisan::call('migrate');

		$this->fixture = (new ReportFixture)->build();

		Carbon::setTestNow(ReportFixture::now());
	}

	public function tearDown()
	{
		Carbon::setTestNow();

		parent::tearDown();
	}

	public function testRequesterIsForbidden()
	{
		$this->be($this->fixture->requester);

		$this->assertSame(403, $this->call('GET', '/reports')->getStatusCode());
		$this->assertSame(403, $this->call('GET', '/reports/export.csv')->getStatusCode());
	}

	public function testGuestIsSentToLogin()
	{
		$response = $this->call('GET', '/reports');

		$this->assertSame(URL::to('login'), $response->headers->get('Location'));
	}

	public function testAgentSeesTheDashboardForTheRange()
	{
		$this->be($this->fixture->agentA);

		$response = $this->call('GET', '/reports', array('from' => '2014-01-01', 'to' => '2014-01-31'));

		$this->assertSame(200, $response->getStatusCode());
		$content = $response->getContent();
		$this->assertContains('value="2014-01-01"', $content);
		$this->assertContains('value="2014-01-31"', $content);
		$this->assertContains('66.7%', $content);
		$this->assertContains('40.0%', $content);
		$this->assertContains('39.0', $content);
		$this->assertContains('660.0', $content);
		$this->assertContains('Dave Agent', $content);
		$this->assertContains('progress-bar', $content);
		$this->assertContains('href="'.URL::to('reports/export.csv').'?from=2014-01-01&amp;to=2014-01-31"', $content);
	}

	public function testEmptyRangeShowsNoComplianceFigure()
	{
		$this->be($this->fixture->agentA);

		$content = $this->call('GET', '/reports', array('from' => '2013-06-01', 'to' => '2013-06-30'))->getContent();

		$this->assertContains('No tickets were created in this range.', $content);
		$this->assertNotContains('%</', $content);
	}

	public function testDefaultRangeIsTheCurrentMonthToDate()
	{
		$this->be($this->fixture->agentA);

		$content = $this->call('GET', '/reports')->getContent();

		$this->assertContains('value="2014-02-01"', $content);
		$this->assertContains('value="2014-02-03"', $content);
	}

	public function testInvalidDatesFallBackToTheDefaultRange()
	{
		$this->be($this->fixture->agentA);

		$response = $this->call('GET', '/reports', array('from' => 'yesterday', 'to' => '2014-13-45'));

		$this->assertSame(200, $response->getStatusCode());
		$this->assertContains('value="2014-02-01"', $response->getContent());
		$this->assertContains('value="2014-02-03"', $response->getContent());
	}

	public function testExportReturnsTheTicketListAsCsv()
	{
		$this->be($this->fixture->agentB);

		$response = $this->call('GET', '/reports/export.csv', array('from' => '2014-01-01', 'to' => '2014-01-31'));

		$this->assertSame(200, $response->getStatusCode());
		$this->assertSame('text/csv; charset=utf-8', $response->headers->get('Content-Type'));
		$this->assertSame(
			'attachment; filename="tickets-2014-01-01-to-2014-01-31.csv"',
			$response->headers->get('Content-Disposition')
		);

		$lines = explode("\n", trim($response->getContent()));
		$this->assertCount(7, $lines);
		$this->assertStringStartsWith('number,subject,', $lines[0]);
		$this->assertContains('Report fixture t1', $lines[1]);
		$this->assertNotContains('Report fixture before', $response->getContent());
		$this->assertNotContains('Report fixture after', $response->getContent());
	}

	public function testNavShowsReportsToAgentsOnly()
	{
		$this->be($this->fixture->agentA);
		$this->assertContains('href="'.URL::to('reports').'"', $this->call('GET', '/tickets')->getContent());

		$this->be($this->fixture->requester);
		$this->assertNotContains('href="'.URL::to('reports').'"', $this->call('GET', '/tickets')->getContent());
	}

}
