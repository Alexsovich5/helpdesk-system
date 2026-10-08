<?php

class UserTest extends TestCase {

	protected function userWithRole($role)
	{
		$user = new User;
		$user->role = $role;

		return $user;
	}

	public function testRequesterHasNoStaffRoles()
	{
		$user = $this->userWithRole('requester');

		$this->assertFalse($user->isAgent());
		$this->assertFalse($user->isAdmin());
	}

	public function testAgentIsAgentButNotAdmin()
	{
		$user = $this->userWithRole('agent');

		$this->assertTrue($user->isAgent());
		$this->assertFalse($user->isAdmin());
	}

	public function testAdminCountsAsAgent()
	{
		$user = $this->userWithRole('admin');

		$this->assertTrue($user->isAgent());
		$this->assertTrue($user->isAdmin());
	}

	public function testHasRoleRanksRoles()
	{
		$this->assertTrue($this->userWithRole('admin')->hasRole('requester'));
		$this->assertTrue($this->userWithRole('agent')->hasRole('agent'));
		$this->assertFalse($this->userWithRole('requester')->hasRole('agent'));
		$this->assertFalse($this->userWithRole('admin')->hasRole('superuser'));
	}

	public function testUnknownRoleGetsNothing()
	{
		$user = $this->userWithRole(null);

		$this->assertFalse($user->isAgent());
		$this->assertFalse($user->isAdmin());
		$this->assertFalse($user->hasRole('requester'));
	}

	public function testPasswordAndRememberTokenAreHidden()
	{
		$user = $this->userWithRole('agent');
		$user->password = 'hash';
		$user->remember_token = 'token';

		$this->assertArrayNotHasKey('password', $user->toArray());
		$this->assertArrayNotHasKey('remember_token', $user->toArray());
	}

}
