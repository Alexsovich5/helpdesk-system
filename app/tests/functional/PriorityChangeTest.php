<?php

use Carbon\Carbon;

class PriorityChangeTest extends TestCase {

	protected $requester;
	protected $agent;
	protected $ticket;

	public function setUp()
	{
		parent::setUp();

		Artisan::call('migrate');
		(new SlaPolicyTableSeeder)->run();

		Carbon::setTestNow(Carbon::create(2014, 7, 1, 9, 0, 0));

		$this->requester = $this->makeUser('carol', 'requester');
		$this->agent = $this->makeUser('bob', 'agent');
		$category = Category::create(array('name' => 'Hardware'));

		$this->ticket = App::make('Helpdesk\Tickets\TicketService')->create(array(
			'subject'     => 'Laptop will not boot',
			'description' => 'Black screen after the logo.',
			'category_id' => $category->id,
			'priority'    => 'normal',
		), $this->requester);
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

	public function testAgentChangingToUrgentRecomputesDueDates()
	{
		$fresh = Ticket::find($this->ticket->id);
		$this->assertEquals('2014-07-01 13:00:00', $fresh->response_due_at->toDateTimeString());
		$this->assertEquals('2014-07-02 09:00:00', $fresh->resolution_due_at->toDateTimeString());

		$this->be($this->agent);
		Carbon::setTestNow(Carbon::create(2014, 7, 1, 9, 10, 0));

		$response = $this->post('/tickets/HD-000001/priority', array('priority' => 'urgent'));

		$this->assertTrue($response->isRedirect());
		$this->assertSame(URL::to('tickets/HD-000001'), $response->headers->get('Location'));

		$fresh = Ticket::find($this->ticket->id);
		$this->assertSame('urgent', $fresh->priority);
		$this->assertEquals('2014-07-01 09:30:00', $fresh->response_due_at->toDateTimeString());
		$this->assertEquals('2014-07-01 13:00:00', $fresh->resolution_due_at->toDateTimeString());
	}

	public function testUnknownPriorityIsRejectedWithAnError()
	{
		$this->be($this->agent);

		$response = $this->post('/tickets/HD-000001/priority', array('priority' => 'critical'));

		$this->assertTrue($response->isRedirect());
		$this->assertTrue(Session::get('errors')->has('priority'));
		$this->assertSame('normal', Ticket::find($this->ticket->id)->priority);
	}

	public function testRequesterCannotChangePriority()
	{
		$this->be($this->requester);

		$response = $this->post('/tickets/HD-000001/priority', array('priority' => 'urgent'));

		$this->assertSame(403, $response->getStatusCode());
		$this->assertSame('normal', Ticket::find($this->ticket->id)->priority);
	}

	public function testShowPageListsDueTimesAndSlaBadge()
	{
		$this->be($this->agent);

		$content = $this->call('GET', '/tickets/HD-000001')->getContent();

		$this->assertContains('Response due', $content);
		$this->assertContains('2014-07-01 13:00', $content);
		$this->assertContains('2014-07-02 09:00', $content);
		$this->assertContains('sla-ok', $content);
		$this->assertContains('name="priority"', $content);
	}

	public function testIndexShowsSlaBadgeAndResolutionDue()
	{
		Ticket::where('id', $this->ticket->id)->update(array('sla_state' => 'breached'));
		$this->be($this->agent);

		$content = $this->call('GET', '/tickets')->getContent();

		$this->assertContains('sla-breached', $content);
		$this->assertContains('2014-07-02 09:00', $content);
	}

}
