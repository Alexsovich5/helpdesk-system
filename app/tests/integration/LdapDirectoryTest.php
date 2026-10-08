<?php

use Helpdesk\Auth\NativeLdapGateway;

/**
 * NativeLdapGateway and the ldap auth driver against the OpenLDAP
 * directory simulator (compose service "ldap", seeded from
 * docker/ldap/seed.ldif).
 *
 * @group integration
 */
class LdapDirectoryTest extends IntegrationTestCase {

	/**
	 * @return \Helpdesk\Auth\LdapGateway
	 */
	protected function gateway()
	{
		return App::make('Helpdesk\Auth\LdapGateway');
	}

	public function testIntegrationEnvironmentUsesTheNativeGateway()
	{
		$this->assertInstanceOf('Helpdesk\Auth\NativeLdapGateway', $this->gateway());
	}

	public function testFindUserReturnsDnEmailAndGroups()
	{
		$user = $this->gateway()->findUser('bob');

		$this->assertNotNull($user);
		$this->assertSame('uid=bob,ou=people,dc=helpdesk,dc=local', strtolower($user['dn']));
		$this->assertSame('bob', $user['username']);
		$this->assertSame('bob@helpdesk.local', $user['email']);
		$this->assertSame(array('helpdesk-agents'), $user['groups']);
	}

	public function testUserWithoutGroupsHasAnEmptyGroupList()
	{
		$user = $this->gateway()->findUser('carol');

		$this->assertNotNull($user);
		$this->assertSame(array(), $user['groups']);
	}

	public function testBindSucceedsWithTheRightPasswordOnly()
	{
		$user = $this->gateway()->findUser('bob');

		$this->assertTrue($this->gateway()->bind($user['dn'], 'password'));
		$this->assertFalse($this->gateway()->bind($user['dn'], 'wrong'));
	}

	public function testUnknownUserIsNull()
	{
		$this->assertNull($this->gateway()->findUser('nobody'));
	}

	public function testFilterInjectionDoesNotMatchEveryone()
	{
		$this->assertNull($this->gateway()->findUser('*)(uid=*'));
		$this->assertNull($this->gateway()->findUser('*'));
	}

	public function testAuthAttemptProvisionsAnAdminFromTheDirectory()
	{
		$this->assertTrue(Auth::attempt(array('username' => 'alice', 'password' => 'password')));

		$user = Auth::user();

		$this->assertSame('alice', $user->username);
		$this->assertSame('admin', $user->role);
		$this->assertSame('ldap', $user->source);
		$this->assertNotNull(User::where('username', 'alice')->first());
	}

	public function testAuthAttemptRejectsAWrongDirectoryPassword()
	{
		$this->assertFalse(Auth::attempt(array('username' => 'bob', 'password' => 'wrong')));
	}

}
