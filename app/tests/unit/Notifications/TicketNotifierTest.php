<?php

use Carbon\Carbon;
use Helpdesk\Tickets\TicketService;
use Illuminate\Mail\Message;

/**
 * Drives TicketService and SlaMonitor so the real ticket.* events reach the
 * subscriber, with a Mockery mailer bound in the container that records
 * every message as array(view, recipient address, subject).
 */
class TicketNotifierTest extends TestCase {

	/** @var TicketService */
	protected $service;

	protected $requester;
	protected $agent;
	protected $otherAgent;
	protected $admin;
	protected $category;

	protected $sent = array();

	public function setUp()
	{
		parent::setUp();

		Artisan::call('migrate');
		(new SlaPolicyTableSeeder)->run();

		Carbon::setTestNow(Carbon::create(2014, 7, 1, 9, 0, 0));

		$this->requester = $this->makeUser('carol', 'requester');
		$this->agent = $this->makeUser('bob', 'agent');
		$this->otherAgent = $this->makeUser('dave', 'agent');
		$this->admin = $this->makeUser('alice', 'admin');
		$this->category = Category::create(array('name' => 'Network'));

		$sent =& $this->sent;
		$mailer = Mockery::mock('Illuminate\Mail\Mailer');
		$mailer->shouldReceive('send')->andReturnUsing(function($view, $data, $callback) use (&$sent)
		{
			$message = new Message(new Swift_Message);
			$callback($message);

			foreach (array_keys($message->getTo()) as $address)
			{
				$sent[] = array($view, $address, $message->getSubject());
			}
		});
		App::instance('mailer', $mailer);

		$this->service = App::make('Helpdesk\Tickets\TicketService');
	}

	public function tearDown()
	{
		Carbon::setTestNow();
		Mockery::close();

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

	protected function newTicket($priority = 'normal')
	{
		return $this->service->create(array(
			'subject'     => 'Printer on floor 2 jams',
			'description' => 'Every second page jams in tray 1.',
			'category_id' => $this->category->id,
			'priority'    => $priority,
		), $this->requester);
	}

	/**
	 * Recipient addresses of the messages sent with the given view, sorted.
	 */
	protected function recipients($view)
	{
		$to = array();
		foreach ($this->sent as $mail)
		{
			if ($mail[0] === $view) $to[] = $mail[1];
		}
		sort($to);

		return $to;
	}

	public function testCreatedMailsRequesterAndEveryAgent()
	{
		$this->newTicket();

		$this->assertEquals(array(
			'alice@helpdesk.local',
			'bob@helpdesk.local',
			'carol@helpdesk.local',
			'dave@helpdesk.local',
		), $this->recipients('emails.ticket_created'));
		$this->assertCount(4, $this->sent);
	}

	public function testAssignedMailsTheAssigneeOnly()
	{
		$ticket = $this->newTicket();
		$this->sent = array();

		$this->service->assign($ticket, $this->agent, $this->admin);

		$this->assertEquals(array('bob@helpdesk.local'), $this->recipients('emails.ticket_assigned'));
		$this->assertEquals(array(), array_diff(array_map(function($m) { return $m[0]; }, $this->sent),
			array('emails.ticket_assigned', 'emails.ticket_status')));
	}

	public function testSelfAssignmentSendsNoAssignmentMail()
	{
		$ticket = $this->newTicket();
		$this->sent = array();

		$this->service->assign($ticket, $this->agent, $this->agent);

		$this->assertEquals(array(), $this->recipients('emails.ticket_assigned'));
	}

	public function testPublicAgentCommentMailsTheRequester()
	{
		$ticket = $this->newTicket();
		$this->service->assign($ticket, $this->agent, $this->agent);
		$this->sent = array();

		$this->service->comment($ticket, $this->agent, 'Replaced the pickup roller.');

		$this->assertEquals(array('carol@helpdesk.local'), $this->recipients('emails.ticket_commented'));
		$this->assertCount(1, $this->sent);
	}

	public function testRequesterCommentMailsTheAssignee()
	{
		$ticket = $this->newTicket();
		$this->service->assign($ticket, $this->agent, $this->admin);
		$this->sent = array();

		$this->service->comment($ticket, $this->requester, 'It jammed again this morning.');

		$this->assertEquals(array('bob@helpdesk.local'), $this->recipients('emails.ticket_commented'));
		$this->assertCount(1, $this->sent);
	}

	public function testInternalCommentIsNotMailedToTheRequester()
	{
		$ticket = $this->newTicket();
		$this->service->assign($ticket, $this->agent, $this->admin);
		$this->sent = array();

		$this->service->comment($ticket, $this->otherAgent, 'Vendor says the fuser is on back order.', true);

		$to = $this->recipients('emails.ticket_commented');
		$this->assertNotContains('carol@helpdesk.local', $to);
		$this->assertEquals(array('bob@helpdesk.local'), $to);
	}

	public function testStatusChangeMailsTheRequester()
	{
		$ticket = $this->newTicket();
		$this->service->assign($ticket, $this->agent, $this->agent);
		$this->sent = array();

		$this->service->transition($ticket, 'resolved', $this->agent);

		$this->assertEquals(array('carol@helpdesk.local'), $this->recipients('emails.ticket_status'));
		$this->assertCount(1, $this->sent);
	}

	public function testRequesterIsNotMailedAboutTheirOwnStatusChange()
	{
		$ticket = $this->newTicket();
		$this->service->assign($ticket, $this->agent, $this->agent);
		$this->service->transition($ticket, 'resolved', $this->agent);
		$this->sent = array();

		$this->service->transition($ticket, 'closed', $this->requester);

		$this->assertEquals(array(), $this->recipients('emails.ticket_status'));
	}

	public function testSlaBreachOnUnassignedTicketMailsEveryAgent()
	{
		$ticket = $this->newTicket('urgent');
		$this->sent = array();

		App::make('Helpdesk\Sla\SlaMonitor')->run(Carbon::create(2014, 7, 1, 9, 31, 0));

		$this->assertEquals(array(
			'alice@helpdesk.local',
			'bob@helpdesk.local',
			'dave@helpdesk.local',
		), $this->recipients('emails.ticket_sla'));
		$this->assertCount(3, $this->sent);
	}

	public function testSlaWarningOnAssignedTicketMailsTheAssignee()
	{
		$ticket = $this->newTicket('urgent');
		$this->service->assign($ticket, $this->agent, $this->admin);
		$this->sent = array();

		App::make('Helpdesk\Sla\SlaMonitor')->run(Carbon::create(2014, 7, 1, 9, 25, 0));

		$this->assertEquals(array('bob@helpdesk.local'), $this->recipients('emails.ticket_sla'));
		$this->assertCount(1, $this->sent);
	}

	public function testSubjectContainsTheTicketNumber()
	{
		$ticket = $this->newTicket();

		$this->assertNotEmpty($this->sent);
		foreach ($this->sent as $mail)
		{
			$this->assertContains($ticket->number, $mail[2]);
		}
	}

	public function testMailBodyLinksToTheTicket()
	{
		$ticket = $this->newTicket();

		$html = View::make('emails.ticket_created', array('ticket' => $ticket, 'recipient' => $this->requester))->render();

		$this->assertContains(URL::to('tickets/'.$ticket->number), $html);
		$this->assertContains(e($ticket->subject), $html);
	}

}
