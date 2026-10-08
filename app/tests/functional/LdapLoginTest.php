<?php

class LdapLoginTest extends TestCase {

	public function setUp()
	{
		parent::setUp();

		Artisan::call('migrate');
	}

	public function testAuthUsesTheLdapDriverWithTheFakeDirectory()
	{
		$this->assertSame('ldap', Config::get('auth.driver'));
		$this->assertSame('fake', Config::get('ldap.driver'));
		$this->assertInstanceOf('Helpdesk\Auth\LdapUserProvider', Auth::driver()->getProvider());
		$this->assertInstanceOf('Helpdesk\Auth\FakeLdapGateway', App::make('Helpdesk\Auth\LdapGateway'));
	}

	public function testDirectoryUserLogsInAndIsProvisionedAsAdmin()
	{
		$response = $this->post('/login', array('username' => 'alice', 'password' => 'password'));

		$this->assertSame(URL::to('/'), $response->headers->get('Location'));
		$this->assertTrue(Auth::check());
		$this->assertSame('alice', Auth::user()->username);
		$this->assertSame('admin', Auth::user()->role);
		$this->assertSame('ldap', Auth::user()->source);

		$home = $this->call('GET', '/');
		$this->assertSame(200, $home->getStatusCode());
		$this->assertContains(Auth::user()->name, $home->getContent());
	}

	public function testAgentGroupMemberGetsAgentRole()
	{
		$this->post('/login', array('username' => 'bob', 'password' => 'password'));

		$this->assertTrue(Auth::check());
		$this->assertSame('agent', Auth::user()->role);
	}

	public function testDirectoryUserWithWrongPasswordIsSentBackToLogin()
	{
		$response = $this->post('/login', array('username' => 'carol', 'password' => 'nope'));

		$this->assertSame(URL::to('login'), $response->headers->get('Location'));
		$this->assertFalse(Auth::check());
		$this->assertTrue(Session::has('errors'));
		$this->assertSame(0, User::where('username', 'carol')->count());
	}

	public function testSecondLoginReusesTheProvisionedRow()
	{
		$this->post('/login', array('username' => 'carol', 'password' => 'password'));
		$first = Auth::user()->id;
		$this->post('/logout');
		$this->assertFalse(Auth::check());

		$this->post('/login', array('username' => 'carol', 'password' => 'password'));

		$this->assertEquals($first, Auth::user()->id);
		$this->assertSame(1, User::where('username', 'carol')->count());
	}

	public function testLocalBreakGlassAccountStillLogsIn()
	{
		$user = new User;
		$user->username = 'admin';
		$user->name = 'Local Admin';
		$user->email = 'admin@helpdesk.local';
		$user->password = Hash::make('break-glass');
		$user->role = 'admin';
		$user->source = 'local';
		$user->save();

		$this->post('/login', array('username' => 'admin', 'password' => 'break-glass'));

		$this->assertTrue(Auth::check());
		$this->assertSame('local', Auth::user()->source);
	}

}
