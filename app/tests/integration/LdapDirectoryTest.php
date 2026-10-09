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
		$this->assertSame(array('cn=helpdesk-agents,ou=groups,dc=helpdesk,dc=local'), array_map('strtolower', $user['groups']));
	}

	public function testUserWithoutGroupsHasAnEmptyGroupList()
	{
		$user = $this->gateway()->findUser('carol');

		$this->assertNotNull($user);
		$this->assertSame(array(), $user['groups']);
	}

	public function testSameCommonNameGroupOutsideTheGroupBaseIsIgnored()
	{
		// seed.ldif puts carol into cn=helpdesk-admins,ou=delegated.
		$user = $this->gateway()->findUser('carol');

		$this->assertNotNull($user);
		$this->assertSame(array(), $user['groups']);

		$this->assertTrue(Auth::attempt(array('username' => 'carol', 'password' => 'password')));
		$this->assertSame('requester', Auth::user()->role);
	}

	public function testUsernameMatchingTwoEntriesIsRefused()
	{
		// seed.ldif has uid=dana in both ou=people and ou=delegated.
		try
		{
			$this->gateway()->findUser('dana');
			$this->fail('expected an ambiguous-entry exception');
		}
		catch (Helpdesk\Auth\LdapAmbiguousEntryException $e)
		{
			$this->assertContains('dana', $e->getMessage());
		}

		$this->assertFalse(Auth::attempt(array('username' => 'dana', 'password' => 'password')));
		$this->assertNull(User::where('username', 'dana')->first());
	}

	public function testAgentRoleComesFromTheConfiguredGroupDn()
	{
		$this->assertTrue(Auth::attempt(array('username' => 'bob', 'password' => 'password')));
		$this->assertSame('agent', Auth::user()->role);
	}

	public function testUnreachableDirectoryIsReportedAsAnOutage()
	{
		$gateway = new NativeLdapGateway(array_merge(Config::get('ldap'), array('host' => 'ldap', 'port' => 1)));

		$this->setExpectedException('Helpdesk\Auth\LdapUnavailableException');

		$gateway->findUser('bob');
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
