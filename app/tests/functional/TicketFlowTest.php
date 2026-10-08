<?php

use Carbon\Carbon;

class TicketFlowTest extends TestCase {

	protected $requester;
	protected $otherRequester;
	protected $agent;
	protected $category;

	public function setUp()
	{
		parent::setUp();

		Artisan::call('migrate');

		Carbon::setTestNow(Carbon::create(2014, 7, 1, 9, 0, 0));

		$this->requester = $this->makeUser('carol', 'requester');
		$this->otherRequester = $this->makeUser('dave', 'requester');
		$this->agent = $this->makeUser('bob', 'agent');
		$this->category = Category::create(array('name' => 'Hardware'));
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

	protected function ticketFor(User $requester, $subject, $priority = 'normal')
	{
		return App::make('Helpdesk\Tickets\TicketService')->create(array(
			'subject'     => $subject,
			'description' => 'Details for '.$subject,
			'category_id' => $this->category->id,
			'priority'    => $priority,
		), $requester);
	}

	protected function assertRedirectsTo($uri, $response)
	{
		$this->assertTrue($response->isRedirect(), 'Expected a redirect, got '.$response->getStatusCode());
		$this->assertSame(URL::to($uri), $response->headers->get('Location'));
	}

	public function testCreateFormRenders()
	{
		$this->be($this->requester);

		$response = $this->call('GET', '/tickets/create');

		$this->assertSame(200, $response->getStatusCode());
		$this->assertContains('name="subject"', $response->getContent());
		$this->assertContains('name="category_id"', $response->getContent());
		$this->assertContains('Hardware', $response->getContent());
	}

	public function testRequesterCreatesTicketAndIsRedirectedToIt()
	{
		$this->be($this->requester);

		$response = $this->post('/tickets', array(
			'subject'     => 'VPN drops every hour',
			'description' => 'The client disconnects at the top of each hour.',
			'category_id' => $this->category->id,
			'priority'    => 'high',
		));

		$this->assertRedirectsTo('tickets/HD-000001', $response);

		$ticket = Ticket::findByNumber('HD-000001');
		$this->assertNotNull($ticket);
		$this->assertSame('VPN drops every hour', $ticket->subject);
		$this->assertSame('high', $ticket->priority);
		$this->assertSame('new', $ticket->status);
		$this->assertEquals($this->requester->id, $ticket->requester_id);

		$show = $this->call('GET', '/tickets/HD-000001');
		$this->assertSame(200, $show->getStatusCode());
		$this->assertContains('VPN drops every hour', $show->getContent());
		$this->assertContains('The client disconnects at the top of each hour.', $show->getContent());
	}

	public function testEmptySubjectFailsValidation()
	{
		$this->be($this->requester);

		$response = $this->post('/tickets', array(
			'subject'     => '',
			'description' => 'Something is broken.',
			'category_id' => $this->category->id,
			'priority'    => 'normal',
		));

		$this->assertRedirectsTo('tickets/create', $response);
		$this->assertTrue(Session::has('errors'));
		$this->assertTrue(Session::get('errors')->has('subject'));
		$this->assertSame(0, Ticket::count());
	}

	public function testUnknownCategoryAndPriorityFailValidation()
	{
		$this->be($this->requester);

		$this->post('/tickets', array(
			'subject'     => 'Mouse',
			'description' => 'Broken.',
			'category_id' => 999,
			'priority'    => 'whenever',
		));

		$errors = Session::get('errors');
		$this->assertTrue($errors->has('category_id'));
		$this->assertTrue($errors->has('priority'));
		$this->assertSame(0, Ticket::count());
	}

	public function testRequesterGetsForbiddenOnSomeoneElsesTicket()
	{
		$ticket = $this->ticketFor($this->otherRequester, 'Dave laptop fan');
		$this->be($this->requester);

		$this->assertSame(403, $this->call('GET', '/tickets/'.$ticket->number)->getStatusCode());
		$this->assertSame(403, $this->post('/tickets/'.$ticket->number.'/comments', array('body' => 'hi'))->getStatusCode());
		$this->assertSame(0, TicketComment::count());
	}

	public function testUnknownTicketNumberIsNotFound()
	{
		$this->be($this->agent);

		$this->assertSame(404, $this->call('GET', '/tickets/HD-999999')->getStatusCode());
	}

	public function testIndexShowsOnlyOwnTicketsToRequester()
	{
		$this->ticketFor($this->requester, 'Carol monitor flickers');
		$this->ticketFor($this->otherRequester, 'Dave laptop fan');

		$this->be($this->requester);
		$content = $this->call('GET', '/tickets')->getContent();

		$this->assertContains('Carol monitor flickers', $content);
		$this->assertNotContains('Dave laptop fan', $content);
		$this->assertContains('table-responsive', $content);
	}

	public function testIndexShowsAllTicketsToAgent()
	{
		$this->ticketFor($this->requester, 'Carol monitor flickers');
		$this->ticketFor($this->otherRequester, 'Dave laptop fan');

		$this->be($this->agent);
		$content = $this->call('GET', '/tickets')->getContent();

		$this->assertContains('Carol monitor flickers', $content);
		$this->assertContains('Dave laptop fan', $content);
	}

	public function testStatusAndPriorityFiltersNarrowTheList()
	{
		$urgent = $this->ticketFor($this->requester, 'Server room too hot', 'urgent');
		$this->ticketFor($this->requester, 'Need a new keyboard', 'low');
		$openUrgent = $this->ticketFor($this->otherRequester, 'Mail server down', 'urgent');
		App::make('Helpdesk\Tickets\TicketService')->assign($openUrgent, $this->agent, $this->agent);

		$this->be($this->agent);

		$content = $this->call('GET', '/tickets', array('priority' => 'urgent'))->getContent();
		$this->assertContains('Server room too hot', $content);
		$this->assertContains('Mail server down', $content);
		$this->assertNotContains('Need a new keyboard', $content);

		$content = $this->call('GET', '/tickets', array('priority' => 'urgent', 'status' => 'new'))->getContent();
		$this->assertContains('Server room too hot', $content);
		$this->assertNotContains('Mail server down', $content);
		$this->assertNotContains('Need a new keyboard', $content);
	}

	public function testSearchMatchesSubjectAndNumber()
	{
		$first = $this->ticketFor($this->requester, 'Server room too hot');
		$this->ticketFor($this->requester, 'Need a new keyboard');

		$this->be($this->agent);

		$content = $this->call('GET', '/tickets', array('q' => 'keyboard'))->getContent();
		$this->assertContains('Need a new keyboard', $content);
		$this->assertNotContains('Server room too hot', $content);

		$content = $this->call('GET', '/tickets', array('q' => $first->number))->getContent();
		$this->assertContains('Server room too hot', $content);
		$this->assertNotContains('Need a new keyboard', $content);
	}

	public function testIndexPaginatesTwentyPerPage()
	{
		for ($i = 1; $i <= 21; $i++)
		{
			$this->ticketFor($this->requester, sprintf('Paged ticket %02d', $i));
		}

		$this->be($this->agent);

		$page1 = $this->call('GET', '/tickets')->getContent();
		$this->assertSame(20, substr_count($page1, 'Paged ticket '));
		$this->assertContains('class="pagination"', $page1);

		$page2 = $this->call('GET', '/tickets', array('page' => 2))->getContent();
		$this->assertSame(1, substr_count($page2, 'Paged ticket '));
	}

	public function testAgentAssignsAndResolvesThenRequesterReopens()
	{
		$ticket = $this->ticketFor($this->requester, 'Printer jams');
		$number = $ticket->number;

		$this->be($this->agent);
		$response = $this->post("/tickets/$number/assign", array('assignee_id' => $this->agent->id));
		$this->assertRedirectsTo("tickets/$number", $response);
		$ticket = Ticket::find($ticket->id);
		$this->assertSame('open', $ticket->status);
		$this->assertEquals($this->agent->id, $ticket->assignee_id);

		$response = $this->post("/tickets/$number/status", array('status' => 'resolved'));
		$this->assertRedirectsTo("tickets/$number", $response);
		$this->assertSame('resolved', Ticket::find($ticket->id)->status);

		$this->be($this->requester);
		$response = $this->post("/tickets/$number/status", array('status' => 'open'));
		$this->assertSame(302, $response->getStatusCode());
		$this->assertRedirectsTo("tickets/$number", $response);
		$this->assertSame('open', Ticket::find($ticket->id)->status);

		$response = $this->post("/tickets/$number/status", array('status' => 'resolved'));
		$this->assertSame(403, $response->getStatusCode());
		$this->assertSame('open', Ticket::find($ticket->id)->status);
	}

	public function testRequesterCannotUseAssign()
	{
		$ticket = $this->ticketFor($this->requester, 'Printer jams');
		$this->be($this->requester);

		$response = $this->post('/tickets/'.$ticket->number.'/assign', array('assignee_id' => $this->agent->id));

		$this->assertSame(403, $response->getStatusCode());
		$this->assertNull(Ticket::find($ticket->id)->assignee_id);
	}

	public function testInvalidTransitionRedirectsBackWithError()
	{
		$ticket = $this->ticketFor($this->requester, 'Printer jams');
		$this->be($this->agent);

		$response = $this->post('/tickets/'.$ticket->number.'/status', array('status' => 'pending'));

		$this->assertRedirectsTo('tickets/'.$ticket->number, $response);
		$this->assertTrue(Session::get('errors')->has('status'));
		$this->assertSame('new', Ticket::find($ticket->id)->status);
	}

	public function testRequesterInternalFlagIsIgnored()
	{
		$ticket = $this->ticketFor($this->requester, 'Printer jams');
		$this->be($this->requester);

		$response = $this->post('/tickets/'.$ticket->number.'/comments', array('body' => 'Still jamming.', 'is_internal' => '1'));

		$this->assertRedirectsTo('tickets/'.$ticket->number, $response);
		$comment = TicketComment::where('ticket_id', $ticket->id)->first();
		$this->assertSame('Still jamming.', $comment->body);
		$this->assertFalse((bool) $comment->is_internal);
	}

	public function testEmptyCommentFailsValidation()
	{
		$ticket = $this->ticketFor($this->requester, 'Printer jams');
		$this->be($this->requester);

		$this->post('/tickets/'.$ticket->number.'/comments', array('body' => ''));

		$this->assertTrue(Session::get('errors')->has('body'));
		$this->assertSame(0, TicketComment::count());
	}

	public function testInternalCommentsAreHiddenFromRequester()
	{
		$ticket = $this->ticketFor($this->requester, 'Printer jams');

		$this->be($this->agent);
		$this->post('/tickets/'.$ticket->number.'/comments', array('body' => 'Toner vendor is slow, chase on Friday.', 'is_internal' => '1'));
		$this->post('/tickets/'.$ticket->number.'/comments', array('body' => 'We have ordered a new tray.'));

		$this->assertTrue((bool) TicketComment::where('body', 'like', 'Toner vendor%')->first()->is_internal);

		$agentView = $this->call('GET', '/tickets/'.$ticket->number)->getContent();
		$this->assertContains('Toner vendor is slow', $agentView);
		$this->assertContains('We have ordered a new tray.', $agentView);

		$this->be($this->requester);
		$requesterView = $this->call('GET', '/tickets/'.$ticket->number)->getContent();
		$this->assertNotContains('Toner vendor is slow', $requesterView);
		$this->assertContains('We have ordered a new tray.', $requesterView);
	}

	public function testAgentSidebarOnlyShownToAgents()
	{
		$ticket = $this->ticketFor($this->requester, 'Printer jams');

		$this->be($this->agent);
		$this->assertContains('name="assignee_id"', $this->call('GET', '/tickets/'.$ticket->number)->getContent());

		$this->be($this->requester);
		$this->assertNotContains('name="assignee_id"', $this->call('GET', '/tickets/'.$ticket->number)->getContent());
	}

	public function testDashboardListsMyOpenTicketsAndUnassignedQueue()
	{
		$this->ticketFor($this->requester, 'Carol monitor flickers');
		$this->ticketFor($this->otherRequester, 'Dave laptop fan');

		$this->be($this->requester);
		$content = $this->call('GET', '/')->getContent();
		$this->assertContains('Carol monitor flickers', $content);
		$this->assertNotContains('Dave laptop fan', $content);

		$this->be($this->agent);
		$content = $this->call('GET', '/')->getContent();
		$this->assertContains('Unassigned', $content);
		$this->assertContains('Carol monitor flickers', $content);
		$this->assertContains('Dave laptop fan', $content);
	}

	public function testTicketPostWithoutTokenIsRejected()
	{
		$this->be($this->requester);

		$this->setExpectedException('Illuminate\Session\TokenMismatchException');

		$this->call('POST', '/tickets', array(
			'subject'     => 'No token',
			'description' => 'x',
			'category_id' => $this->category->id,
			'priority'    => 'low',
		));
	}

}
