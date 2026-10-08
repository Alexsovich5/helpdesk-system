<?php

use Helpdesk\Auth\RoleMapper;

class RoleMapperTest extends PHPUnit_Framework_TestCase {

	protected function mapper()
	{
		return RoleMapper::fromString('helpdesk-admins:admin,helpdesk-agents:agent');
	}

	public function testNoGroupsGivesRequester()
	{
		$this->assertSame('requester', $this->mapper()->roleFor(array()));
	}

	public function testUnmappedGroupsGiveRequester()
	{
		$this->assertSame('requester', $this->mapper()->roleFor(array('staff', 'printers')));
	}

	public function testMappedGroupGivesItsRole()
	{
		$this->assertSame('agent', $this->mapper()->roleFor(array('staff', 'helpdesk-agents')));
		$this->assertSame('admin', $this->mapper()->roleFor(array('helpdesk-admins')));
	}

	public function testHighestRoleWinsWhateverTheGroupOrder()
	{
		$this->assertSame('admin', $this->mapper()->roleFor(array('helpdesk-agents', 'helpdesk-admins')));
		$this->assertSame('admin', $this->mapper()->roleFor(array('helpdesk-admins', 'helpdesk-agents')));
	}

	public function testGroupNamesMatchCaseInsensitively()
	{
		$this->assertSame('admin', $this->mapper()->roleFor(array('Helpdesk-Admins')));
	}

	public function testParsesEnvStringWithSpacesAndSkipsBadEntries()
	{
		$mapper = RoleMapper::fromString(' it-staff : agent , , broken, ops:superuser ,domain-admins:admin ');

		$this->assertSame(array('it-staff' => 'agent', 'domain-admins' => 'admin'), $mapper->map());
		$this->assertSame('agent', $mapper->roleFor(array('IT-Staff')));
		$this->assertSame('requester', $mapper->roleFor(array('ops')));
	}

	public function testEmptyStringMapsEverythingToRequester()
	{
		$mapper = RoleMapper::fromString('');

		$this->assertSame(array(), $mapper->map());
		$this->assertSame('requester', $mapper->roleFor(array('helpdesk-admins')));
	}

}
