<?php

use Helpdesk\Auth\NativeLdapGateway;

/**
 * The parts of NativeLdapGateway that need no directory server. The search
 * and bind paths run against OpenLDAP in LdapDirectoryTest.
 */
class NativeLdapGatewayTest extends PHPUnit_Framework_TestCase {

	public function testOnlyGroupsBelowTheGroupBaseAreKept()
	{
		$groups = NativeLdapGateway::groupsWithin(array(
			'cn=helpdesk-agents,ou=groups,dc=helpdesk,dc=local',
			'cn=helpdesk-admins,ou=delegated,dc=helpdesk,dc=local',
			'CN=Helpdesk-Admins,OU=Groups,DC=helpdesk,DC=local',
			'cn=helpdesk-admins,ou=groups,dc=other,dc=local',
		), 'ou=groups,dc=helpdesk,dc=local');

		$this->assertSame(array(
			'cn=helpdesk-agents,ou=groups,dc=helpdesk,dc=local',
			'CN=Helpdesk-Admins,OU=Groups,DC=helpdesk,DC=local',
		), $groups);
	}

	public function testGroupBaseDefaultsToOuGroupsUnderTheBaseDn()
	{
		$gateway = new NativeLdapGateway(array('base_dn' => 'dc=corp,dc=example'));

		$this->assertSame('ou=groups,dc=corp,dc=example', $gateway->groupBaseDn());

		$gateway = new NativeLdapGateway(array('base_dn' => 'dc=corp,dc=example', 'group_base_dn' => 'ou=Security Groups,dc=corp,dc=example'));

		$this->assertSame('ou=Security Groups,dc=corp,dc=example', $gateway->groupBaseDn());
	}

	public function testActiveDirectoryDisabledFlagIsRead()
	{
		$this->assertTrue(NativeLdapGateway::isDisabled(array('useraccountcontrol' => array('count' => 1, 0 => '514'))));
		$this->assertFalse(NativeLdapGateway::isDisabled(array('useraccountcontrol' => array('count' => 1, 0 => '512'))));
		$this->assertFalse(NativeLdapGateway::isDisabled(array()));
	}

	public function testUnreachableServerThrowsWithoutTheBindPassword()
	{
		$gateway = new NativeLdapGateway(array(
			'host'          => '127.0.0.1',
			'port'          => 1,
			'base_dn'       => 'dc=helpdesk,dc=local',
			'bind_dn'       => 'cn=admin,dc=helpdesk,dc=local',
			'bind_password' => 'S3NTINEL-pw',
		));

		try
		{
			$gateway->findUser('bob');
			$this->fail('findUser() returned instead of reporting the outage');
		}
		catch (Helpdesk\Auth\LdapUnavailableException $e)
		{
			$this->assertNotContains('S3NTINEL-pw', $e->getMessage());
			$this->assertNotContains('S3NTINEL-pw', $e->getTraceAsString());
		}
	}

}
