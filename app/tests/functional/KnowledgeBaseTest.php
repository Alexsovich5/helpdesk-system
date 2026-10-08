<?php

use Carbon\Carbon;

class KnowledgeBaseTest extends TestCase {

	protected $requester;
	protected $agent;
	protected $kbCategory;

	public function setUp()
	{
		parent::setUp();

		Artisan::call('migrate');

		Carbon::setTestNow(Carbon::create(2014, 7, 1, 9, 0, 0));

		$this->requester = $this->makeUser('carol', 'requester');
		$this->agent = $this->makeUser('bob', 'agent');
		$this->kbCategory = KbCategory::create(array('name' => 'Remote access'));
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

	protected function article($title, $body, $published = true)
	{
		$article = new KbArticle(array('title' => $title, 'body' => $body));
		$article->kb_category_id = $this->kbCategory->id;
		$article->author_id = $this->agent->id;
		$article->is_published = $published;
		$article->save();

		return $article;
	}

	public function testAgentCreatesADraftAndThenPublishesIt()
	{
		$this->be($this->agent);

		$this->assertSame(200, $this->call('GET', '/kb/create')->getStatusCode());

		$response = $this->post('/kb', array(
			'title'          => 'Reset your VPN password',
			'body'           => 'Open the self-service portal and choose "Forgot password".',
			'kb_category_id' => $this->kbCategory->id,
		));

		$article = KbArticle::where('title', 'Reset your VPN password')->first();
		$this->assertNotNull($article);
		$this->assertFalse((bool) $article->is_published);
		$this->assertEquals($this->agent->id, $article->author_id);
		$this->assertTrue($response->isRedirect());
		$this->assertSame(URL::to('kb/'.$article->id), $response->headers->get('Location'));

		$this->assertSame(200, $this->call('GET', '/kb/'.$article->id.'/edit')->getStatusCode());

		$response = $this->post('/kb/'.$article->id, array(
			'_method'        => 'PUT',
			'title'          => 'Reset your VPN password',
			'body'           => 'Open the self-service portal.',
			'kb_category_id' => $this->kbCategory->id,
			'is_published'   => '1',
		));

		$this->assertSame(URL::to('kb/'.$article->id), $response->headers->get('Location'));
		$article = KbArticle::find($article->id);
		$this->assertTrue((bool) $article->is_published);
		$this->assertSame('Open the self-service portal.', $article->body);
	}

	public function testCreateRequiresTitleBodyAndCategory()
	{
		$this->be($this->agent);

		$response = $this->post('/kb', array('title' => '', 'body' => '', 'kb_category_id' => ''));

		$this->assertSame(URL::to('kb/create'), $response->headers->get('Location'));
		$this->assertSessionHasErrors(array('title', 'body', 'kb_category_id'));
		$this->assertSame(0, KbArticle::count());
	}

	public function testRequesterCannotAuthorArticles()
	{
		$this->be($this->requester);
		$article = $this->article('Printer jams', 'Open tray two.');

		$this->assertSame(403, $this->call('GET', '/kb/create')->getStatusCode());
		$this->assertSame(403, $this->post('/kb', array('title' => 'x', 'body' => 'y', 'kb_category_id' => $this->kbCategory->id))->getStatusCode());
		$this->assertSame(403, $this->call('GET', '/kb/'.$article->id.'/edit')->getStatusCode());
		$this->assertSame(403, $this->post('/kb/'.$article->id, array('_method' => 'PUT', 'title' => 'Changed', 'body' => 'y', 'kb_category_id' => $this->kbCategory->id))->getStatusCode());
		$this->assertSame('Printer jams', KbArticle::find($article->id)->title);
	}

	public function testGuestsAreSentToLogin()
	{
		$response = $this->call('GET', '/kb');

		$this->assertSame(URL::to('login'), $response->headers->get('Location'));
	}

	public function testRequesterReadsPublishedArticleButNotADraft()
	{
		$published = $this->article('Reset your VPN password', 'Use the self-service portal.');
		$draft = $this->article('VPN outage workaround', 'Still being written.', false);

		$this->be($this->requester);

		$response = $this->call('GET', '/kb/'.$published->id);
		$this->assertSame(200, $response->getStatusCode());
		$this->assertContains('Reset your VPN password', $response->getContent());
		$this->assertContains('Use the self-service portal.', $response->getContent());

		$this->assertSame(404, $this->call('GET', '/kb/'.$draft->id)->getStatusCode());
		$this->assertSame(404, $this->call('GET', '/kb/9999')->getStatusCode());

		$this->be($this->agent);
		$response = $this->call('GET', '/kb/'.$draft->id);
		$this->assertSame(200, $response->getStatusCode());
		$this->assertContains('Draft', $response->getContent());
	}

	public function testIndexSearchesAndHidesDraftsFromRequesters()
	{
		$this->article('Reset your VPN password', 'Use the self-service portal.');
		$this->article('Printer jams', 'Open tray two.');
		$this->article('VPN outage workaround', 'Still being written.', false);

		$this->be($this->requester);

		$content = $this->call('GET', '/kb', array('q' => 'vpn'))->getContent();
		$this->assertContains('Reset your VPN password', $content);
		$this->assertNotContains('Printer jams', $content);
		$this->assertNotContains('VPN outage workaround', $content);
		$this->assertContains('table-responsive', $content);
		$this->assertContains('href="'.URL::to('kb').'"', $content);

		$content = $this->call('GET', '/kb')->getContent();
		$this->assertContains('Printer jams', $content);

		$this->be($this->agent);
		$content = $this->call('GET', '/kb', array('q' => 'vpn'))->getContent();
		$this->assertContains('VPN outage workaround', $content);
	}

	public function testArticleBodyIsEscaped()
	{
		$article = $this->article('Script test', "<script>alert('kb')</script>\nSecond line");

		$this->be($this->requester);
		$content = $this->call('GET', '/kb/'.$article->id)->getContent();

		$this->assertNotContains("<script>alert('kb')</script>", $content);
		$this->assertContains('&lt;script&gt;', $content);
		$this->assertContains('<br />', $content);
	}

	public function testAgentLinksArticleToTicketAndRequesterSeesIt()
	{
		$category = Category::create(array('name' => 'Network'));
		$ticket = App::make('Helpdesk\Tickets\TicketService')->create(array(
			'subject'     => 'VPN keeps asking for my password',
			'description' => 'Since this morning.',
			'category_id' => $category->id,
		), $this->requester);
		$article = $this->article('Reset your VPN password', 'Use the self-service portal.');

		$this->be($this->agent);
		$response = $this->post('/tickets/'.$ticket->number.'/articles', array('kb_article_id' => $article->id));

		$this->assertSame(URL::to('tickets/'.$ticket->number), $response->headers->get('Location'));
		$this->assertSame(1, TicketEvent::where('ticket_id', $ticket->id)->where('type', 'article_linked')->count());

		$this->be($this->requester);
		$content = $this->call('GET', '/tickets/'.$ticket->number)->getContent();
		$this->assertContains('href="'.URL::to('kb/'.$article->id).'"', $content);
		$this->assertContains('article linked', $content);
	}

	public function testRequesterCannotLinkArticles()
	{
		$category = Category::create(array('name' => 'Network'));
		$ticket = App::make('Helpdesk\Tickets\TicketService')->create(array(
			'subject'     => 'VPN keeps asking for my password',
			'description' => 'Since this morning.',
			'category_id' => $category->id,
		), $this->requester);
		$article = $this->article('Reset your VPN password', 'Use the self-service portal.');

		$this->be($this->requester);
		$response = $this->post('/tickets/'.$ticket->number.'/articles', array('kb_article_id' => $article->id));

		$this->assertSame(403, $response->getStatusCode());
		$this->assertSame(0, DB::table('ticket_kb_article')->count());
	}

	public function testLinkingADraftOrUnknownArticleIsRejected()
	{
		$category = Category::create(array('name' => 'Network'));
		$ticket = App::make('Helpdesk\Tickets\TicketService')->create(array(
			'subject'     => 'VPN keeps asking for my password',
			'description' => 'Since this morning.',
			'category_id' => $category->id,
		), $this->requester);
		$draft = $this->article('VPN outage workaround', 'Still being written.', false);

		$this->be($this->agent);
		$this->post('/tickets/'.$ticket->number.'/articles', array('kb_article_id' => $draft->id));
		$this->assertSessionHasErrors('kb_article_id');

		$this->post('/tickets/'.$ticket->number.'/articles', array('kb_article_id' => 9999));
		$this->assertSessionHasErrors('kb_article_id');

		$this->assertSame(0, DB::table('ticket_kb_article')->count());
	}

	public function testAgentManagesKbCategories()
	{
		$this->be($this->agent);

		$this->assertSame(200, $this->call('GET', '/kb/categories')->getStatusCode());

		$response = $this->post('/kb/categories', array('name' => 'Printing'));
		$this->assertSame(URL::to('kb/categories'), $response->headers->get('Location'));
		$this->assertSame(1, KbCategory::where('name', 'Printing')->count());

		$this->post('/kb/categories', array('name' => 'Printing'));
		$this->assertSessionHasErrors('name');
		$this->assertSame(1, KbCategory::where('name', 'Printing')->count());

		$this->be($this->requester);
		$this->assertSame(403, $this->call('GET', '/kb/categories')->getStatusCode());
	}

	public function testPostWithoutTokenIsRejected()
	{
		$this->be($this->agent);

		$this->setExpectedException('Illuminate\Session\TokenMismatchException');

		$this->call('POST', '/kb', array('title' => 'x', 'body' => 'y', 'kb_category_id' => $this->kbCategory->id));
	}

}
