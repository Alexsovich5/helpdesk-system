<?php

use Helpdesk\Http\HostGuard;
use Illuminate\Mail\Message;

/**
 * Links in pages and e-mails are built from app.url, never from the Host
 * header of the request, and requests for a host that is not configured
 * are refused.
 */
class HostHeaderTest extends TestCase {

	protected $bodies = array();

	public function setUp()
	{
		parent::setUp();

		Artisan::call('migrate');
		(new SlaPolicyTableSeeder)->run();

		Config::set('app.url', 'https://helpdesk.example.org');
		Config::set('app.trusted_hosts', array('localhost', 'helpdesk-alias.test'));

		$bodies =& $this->bodies;
		$mailer = Mockery::mock('Illuminate\Mail\Mailer');
		$mailer->shouldReceive('send')->andReturnUsing(function($view, $data, $callback) use (&$bodies)
		{
			$message = new Message(new Swift_Message);
			$callback($message);

			$bodies[] = View::make($view, $data)->render();
		});
		App::instance('mailer', $mailer);
	}

	public function tearDown()
	{
		Mockery::close();
		HostGuard::reset(App::make('url'));

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

	protected function get($uri, $host)
	{
		return $this->call('GET', $uri, array(), array(), array('HTTP_HOST' => $host));
	}

	public function testUntrustedHostIsRejected()
	{
		$response = $this->get('/login', 'evil.example');

		$this->assertSame(400, $response->getStatusCode());
		$this->assertNotContains('evil.example', $response->getContent());
	}

	public function testHostThatOnlyContainsATrustedNameIsRejected()
	{
		$this->assertSame(400, $this->get('/login', 'localhost.evil.example')->getStatusCode());
		$this->assertSame(400, $this->get('/login', 'evil-localhost')->getStatusCode());
	}

	public function testTrustedHostsAreServed()
	{
		$this->assertSame(200, $this->get('/login', 'localhost')->getStatusCode());
		$this->assertSame(200, $this->get('/login', 'HELPDESK-ALIAS.TEST')->getStatusCode());
	}

	public function testPageLinksUseAppUrlWhateverTheHostHeader()
	{
		$this->be($this->makeUser('carol', 'requester'));

		$response = $this->get('/', 'helpdesk-alias.test');

		$this->assertSame(200, $response->getStatusCode());
		$this->assertContains('href="https://helpdesk.example.org/tickets"', $response->getContent());
		$this->assertNotContains('helpdesk-alias.test', $response->getContent());
	}

	public function testTicketMailsLinkToAppUrlWhenCreatedThroughAnotherHost()
	{
		$this->makeUser('bob', 'agent');
		$this->be($this->makeUser('carol', 'requester'));
		$category = Category::create(array('name' => 'Network'));

		$response = $this->call('POST', '/tickets', array(
			'_token'      => Session::token(),
			'subject'     => 'VPN drops',
			'description' => 'Every ten minutes.',
			'category_id' => $category->id,
			'priority'    => 'normal',
		), array(), array('HTTP_HOST' => 'helpdesk-alias.test'));

		$this->assertTrue($response->isRedirect());
		$this->assertCount(2, $this->bodies);

		foreach ($this->bodies as $body)
		{
			$this->assertContains('href="https://helpdesk.example.org/tickets/HD-', $body);
			$this->assertNotContains('helpdesk-alias.test', $body);
		}
	}

	public function testTrustedHostsDefaultToTheAppUrlHost()
	{
		$this->assertSame(array('helpdesk.example.org'), HostGuard::trustedHosts('https://helpdesk.example.org:8443/', ''));
		$this->assertSame(array('helpdesk.example.org', 'app', '127.0.0.1'), HostGuard::trustedHosts('https://helpdesk.example.org', ' app, 127.0.0.1 ,'));
	}

	public function testHostPatternsAreAnchoredAndLiteral()
	{
		$patterns = HostGuard::patterns(array('helpdesk.example.org'));

		$this->assertSame(array('^helpdesk\.example\.org$'), $patterns);
	}

	public function testAppUrlIsRequiredInProduction()
	{
		$this->assertSame('https://helpdesk.example.org', HostGuard::rootUrl('production', 'https://helpdesk.example.org/'));
		$this->assertSame('http://localhost', HostGuard::rootUrl('local', false));

		$this->setExpectedException('RuntimeException', 'APP_URL');
		HostGuard::rootUrl('production', false);
	}

	public function testConsoleLinksUseAppUrl()
	{
		HostGuard::apply(App::make('url'), 'https://helpdesk.example.org');

		$this->assertSame('https://helpdesk.example.org/tickets/HD-000001', URL::to('tickets/HD-000001'));
	}

}
