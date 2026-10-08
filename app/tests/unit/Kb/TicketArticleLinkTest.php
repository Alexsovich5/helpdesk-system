<?php

use Carbon\Carbon;

class TicketArticleLinkTest extends TestCase {

	protected $service;
	protected $agent;
	protected $ticket;
	protected $kbCategory;

	/** @var array names of the ticket.* events fired */
	protected $fired = array();

	public function setUp()
	{
		parent::setUp();

		Artisan::call('migrate');

		Carbon::setTestNow(Carbon::create(2014, 7, 1, 9, 0, 0));

		$requester = $this->makeUser('carol', 'requester');
		$this->agent = $this->makeUser('bob', 'agent');
		$category = Category::create(array('name' => 'Network'));
		$this->kbCategory = KbCategory::create(array('name' => 'Remote access'));

		$this->service = App::make('Helpdesk\Tickets\TicketService');
		$this->ticket = $this->service->create(array(
			'subject' => 'VPN keeps asking for my password',
			'description' => 'Since this morning.',
			'category_id' => $category->id,
		), $requester);

		$fired =& $this->fired;
		Event::listen('ticket.*', function() use (&$fired)
		{
			$fired[] = Event::firing();
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

	protected function article($title, $published = true)
	{
		$article = new KbArticle(array('title' => $title, 'body' => 'Steps for '.$title));
		$article->kb_category_id = $this->kbCategory->id;
		$article->author_id = $this->agent->id;
		$article->is_published = $published;
		$article->save();

		return $article;
	}

	public function testLinkingAttachesTheArticleAndRecordsAnEvent()
	{
		$article = $this->article('Reset your VPN password');

		$this->service->linkArticle($this->ticket, $article, $this->agent);

		$this->assertEquals(array($article->id), Ticket::find($this->ticket->id)->articles->modelKeys());

		$event = TicketEvent::where('ticket_id', $this->ticket->id)->where('type', 'article_linked')->first();
		$this->assertNotNull($event);
		$this->assertEquals($this->agent->id, $event->user_id);
		$this->assertSame('Reset your VPN password', $event->to_value);
		$this->assertEquals(array('ticket.article_linked'), $this->fired);
	}

	public function testLinkingTheSameArticleTwiceChangesNothing()
	{
		$article = $this->article('Reset your VPN password');

		$this->service->linkArticle($this->ticket, $article, $this->agent);
		$this->service->linkArticle($this->ticket, $article, $this->agent);

		$this->assertSame(1, DB::table('ticket_kb_article')->where('ticket_id', $this->ticket->id)->count());
		$this->assertSame(1, TicketEvent::where('ticket_id', $this->ticket->id)->where('type', 'article_linked')->count());
		$this->assertEquals(array('ticket.article_linked'), $this->fired);
	}

	public function testDraftArticlesCannotBeLinked()
	{
		$draft = $this->article('Half-written fix', false);

		try
		{
			$this->service->linkArticle($this->ticket, $draft, $this->agent);
			$this->fail('Linking a draft should throw.');
		}
		catch (InvalidArgumentException $e)
		{
			$this->assertSame(0, DB::table('ticket_kb_article')->count());
		}
	}

	public function testOnlyAgentsCanLinkArticles()
	{
		$article = $this->article('Reset your VPN password');

		$this->setExpectedException('Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException');

		$this->service->linkArticle($this->ticket, $article, $this->ticket->requester);
	}

}
