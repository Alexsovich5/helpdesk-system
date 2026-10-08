<?php

class AuthTest extends TestCase {

	public function setUp()
	{
		parent::setUp();

		Artisan::call('migrate');
	}

	protected function makeUser($username, $role, $password = 'secret')
	{
		$user = new User;
		$user->username = $username;
		$user->name = ucfirst($username);
		$user->email = $username.'@helpdesk.local';
		$user->password = Hash::make($password);
		$user->role = $role;
		$user->source = 'local';
		$user->save();

		return $user;
	}

	public function testGuestIsRedirectedFromHomeToLogin()
	{
		$response = $this->call('GET', '/');

		$this->assertTrue($response->isRedirect());
		$this->assertSame(URL::to('login'), $response->headers->get('Location'));
	}

	public function testLoginPageRendersForm()
	{
		$response = $this->call('GET', '/login');

		$this->assertSame(200, $response->getStatusCode());
		$this->assertContains('name="username"', $response->getContent());
		$this->assertContains('name="password"', $response->getContent());
		$this->assertContains('name="_token"', $response->getContent());
	}

	public function testValidLocalLoginRedirectsHomeAndAuthenticates()
	{
		$user = $this->makeUser('dana', 'requester');

		$response = $this->post('/login', array('username' => 'dana', 'password' => 'secret'));

		$this->assertTrue($response->isRedirect());
		$this->assertSame(URL::to('/'), $response->headers->get('Location'));
		$this->assertTrue(Auth::check());
		$this->assertEquals($user->id, Auth::user()->id);
	}

	public function testBadPasswordReturnsToLoginWithErrors()
	{
		$this->makeUser('dana', 'requester');

		$response = $this->post('/login', array('username' => 'dana', 'password' => 'wrong'));

		$this->assertTrue($response->isRedirect());
		$this->assertSame(URL::to('login'), $response->headers->get('Location'));
		$this->assertFalse(Auth::check());
		$this->assertTrue(Session::has('errors'));
		$this->assertSame('dana', Session::getOldInput('username'));
	}

	public function testUnknownUserIsRejected()
	{
		$response = $this->post('/login', array('username' => 'nobody', 'password' => 'secret'));

		$this->assertSame(URL::to('login'), $response->headers->get('Location'));
		$this->assertFalse(Auth::check());
	}

	public function testLoggedInUserSeesHomeWithLayout()
	{
		$this->be($this->makeUser('dana', 'requester'));

		$response = $this->call('GET', '/');

		$this->assertSame(200, $response->getStatusCode());
		$content = $response->getContent();
		$this->assertContains('<meta name="viewport" content="width=device-width, initial-scale=1">', $content);
		$this->assertContains('navbar-toggle', $content);
		$this->assertContains('jquery-1.11.1.min.js', $content);
		$this->assertContains('vendor/bootstrap/css/bootstrap.min.css', $content);
		$this->assertContains('Dana', $content);
	}

	public function testLoggedInUserIsRedirectedAwayFromLogin()
	{
		$this->be($this->makeUser('dana', 'requester'));

		$response = $this->call('GET', '/login');

		$this->assertSame(URL::to('/'), $response->headers->get('Location'));
	}

	public function testLogoutEndsTheSession()
	{
		$this->be($this->makeUser('dana', 'requester'));

		$response = $this->call('GET', '/logout');

		$this->assertSame(URL::to('login'), $response->headers->get('Location'));
		$this->assertFalse(Auth::check());
	}

	public function testRoleAdminFilterForbidsRequester()
	{
		$this->defineRoleRoutes();
		$this->be($this->makeUser('dana', 'requester'));

		$this->assertSame(403, $this->call('GET', '/auth-test-admin')->getStatusCode());
		$this->assertSame(403, $this->call('GET', '/auth-test-agent')->getStatusCode());
	}

	public function testRoleFiltersLetAgentsAndAdminsThrough()
	{
		$this->defineRoleRoutes();

		$this->be($this->makeUser('erin', 'agent'));
		$this->assertSame(200, $this->call('GET', '/auth-test-agent')->getStatusCode());
		$this->assertSame(403, $this->call('GET', '/auth-test-admin')->getStatusCode());

		$this->be($this->makeUser('frank', 'admin'));
		$this->assertSame(200, $this->call('GET', '/auth-test-agent')->getStatusCode());
		$this->assertSame(200, $this->call('GET', '/auth-test-admin')->getStatusCode());
	}

	public function testRoleFilterRedirectsGuestsToLogin()
	{
		$this->defineRoleRoutes();

		$response = $this->call('GET', '/auth-test-admin');

		$this->assertSame(URL::to('login'), $response->headers->get('Location'));
	}

	public function testLoginPostWithoutTokenIsRejected()
	{
		$this->makeUser('dana', 'requester');

		$this->setExpectedException('Illuminate\Session\TokenMismatchException');

		$this->call('POST', '/login', array('username' => 'dana', 'password' => 'secret'));
	}

	protected function defineRoleRoutes()
	{
		Route::get('auth-test-agent', array('before' => 'role:agent', function()
		{
			return 'agent area';
		}));

		Route::get('auth-test-admin', array('before' => 'role:admin', function()
		{
			return 'admin area';
		}));
	}

}
