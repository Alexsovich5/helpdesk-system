<?php

use Helpdesk\Auth\RoleMapper;

class RoleMapperTest extends PHPUnit_Framework_TestCase {

	const ADMINS = 'cn=helpdesk-admins,ou=groups,dc=helpdesk,dc=local';
	const AGENTS = 'cn=helpdesk-agents,ou=groups,dc=helpdesk,dc=local';

	protected function mapper()
	{
		return RoleMapper::fromString(static::ADMINS.':admin;'.static::AGENTS.':agent');
	}

	public function testNoGroupsGivesRequester()
	{
		$this->assertSame('requester', $this->mapper()->roleFor(array()));
	}

	public function testUnmappedGroupsGiveRequester()
	{
		$this->assertSame('requester', $this->mapper()->roleFor(array(
			'cn=staff,ou=groups,dc=helpdesk,dc=local',
			'cn=printers,ou=groups,dc=helpdesk,dc=local',
		)));
	}

	public function testMappedGroupGivesItsRole()
	{
		$this->assertSame('agent', $this->mapper()->roleFor(array('cn=staff,ou=groups,dc=helpdesk,dc=local', static::AGENTS)));
		$this->assertSame('admin', $this->mapper()->roleFor(array(static::ADMINS)));
	}

	public function testHighestRoleWinsWhateverTheGroupOrder()
	{
		$this->assertSame('admin', $this->mapper()->roleFor(array(static::AGENTS, static::ADMINS)));
		$this->assertSame('admin', $this->mapper()->roleFor(array(static::ADMINS, static::AGENTS)));
	}

	public function testSameCommonNameInAnotherOuGrantsNothing()
	{
		$this->assertSame('requester', $this->mapper()->roleFor(array(
			'cn=helpdesk-admins,ou=delegated,dc=helpdesk,dc=local',
			'cn=helpdesk-agents,ou=people,dc=helpdesk,dc=local',
			'cn=helpdesk-admins,ou=groups,dc=other,dc=local',
		)));
	}

	public function testBareCommonNamesGrantNothing()
	{
		$this->assertSame('requester', $this->mapper()->roleFor(array('helpdesk-admins', 'helpdesk-agents')));
	}

	public function testDnCaseAndSpacingVariantsStillMatch()
	{
		$this->assertSame('admin', $this->mapper()->roleFor(array('CN=Helpdesk-Admins,OU=Groups,DC=helpdesk,DC=local')));
		$this->assertSame('admin', $this->mapper()->roleFor(array('cn = helpdesk-admins , ou=groups,  dc=helpdesk ,dc = local')));
		$this->assertSame('agent', $this->mapper()->roleFor(array(' cn=helpdesk-agents,ou=groups,dc=helpdesk,dc=local ')));
	}

	public function testNormaliseKeepsEscapedSeparatorsInsideValues()
	{
		$this->assertSame('cn=smith\, john,ou=people,dc=helpdesk,dc=local',
			RoleMapper::normalise('CN=Smith\, John , OU=People,DC=helpdesk,DC=local'));
		$this->assertNotSame(RoleMapper::normalise('cn=a\,ou=b,dc=x'), RoleMapper::normalise('cn=a,ou=b,dc=x'));
	}

	public function testParsesEnvStringWithSpacesAndSkipsBadEntries()
	{
		$mapper = RoleMapper::fromString(' CN=IT-Staff, OU=Groups,DC=corp,DC=example : agent ; ; broken; cn=ops,dc=corp:superuser ;cn=domain admins,cn=users,dc=corp,dc=example:admin ');

		$this->assertSame(array(
			'cn=it-staff,ou=groups,dc=corp,dc=example' => 'agent',
			'cn=domain admins,cn=users,dc=corp,dc=example' => 'admin',
		), $mapper->map());
		$this->assertSame('agent', $mapper->roleFor(array('cn=it-staff,ou=groups,dc=corp,dc=example')));
		$this->assertSame('requester', $mapper->roleFor(array('cn=ops,dc=corp')));
	}

	public function testEntriesThatAreNotDistinguishedNamesAreSkipped()
	{
		$mapper = RoleMapper::fromString('helpdesk-admins:admin;'.static::AGENTS.':agent');

		$this->assertSame(array(static::AGENTS => 'agent'), $mapper->map());
	}

	public function testArrayMapIsAccepted()
	{
		$mapper = new RoleMapper(array(static::ADMINS => 'admin'));

		$this->assertSame('admin', $mapper->roleFor(array(strtoupper(static::ADMINS))));
	}

	public function testEmptyStringMapsEverythingToRequester()
	{
		$mapper = RoleMapper::fromString('');

		$this->assertSame(array(), $mapper->map());
		$this->assertSame('requester', $mapper->roleFor(array(static::ADMINS)));
	}

	public function testIsWithinComparesWholeRdnsUnderTheBase()
	{
		$base = 'ou=groups,dc=helpdesk,dc=local';

		$this->assertTrue(RoleMapper::isWithin(static::ADMINS, $base));
		$this->assertTrue(RoleMapper::isWithin('CN=x, OU=Groups,DC=helpdesk,DC=local', $base));
		$this->assertFalse(RoleMapper::isWithin('cn=helpdesk-admins,ou=delegated,dc=helpdesk,dc=local', $base));
		$this->assertFalse(RoleMapper::isWithin('cn=x,ou=evilgroups,dc=helpdesk,dc=local', $base));
		$this->assertFalse(RoleMapper::isWithin($base, $base));
		$this->assertFalse(RoleMapper::isWithin('cn=x,ou=groups,dc=helpdesk,dc=local', ''));
	}

}
