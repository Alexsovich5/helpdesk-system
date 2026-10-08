<?php

use Helpdesk\Auth\FakeLdapGateway;
use Helpdesk\Auth\LdapUserProvider;
use Helpdesk\Auth\NativeLdapGateway;
use Helpdesk\Auth\RoleMapper;

class LdapUserProviderTest extends TestCase {

	/** @var FakeLdapGateway */
	protected $directory;

	/** @var LdapUserProvider */
	protected $provider;

	public function setUp()
	{
		parent::setUp();

		Artisan::call('migrate');

		$this->directory = new FakeLdapGateway(array(), '(uid=%s)');
		$this->directory->addUser('alice', 'password', array('helpdesk-admins'), 'Alice Admin', 'alice@helpdesk.local');
		$this->directory->addUser('bob', 'password', array('helpdesk-agents'), 'Bob Agent', 'bob@helpdesk.local');
		$this->directory->addUser('carol', 'password', array(), 'Carol Requester', 'carol@helpdesk.local');

		$this->provider = new LdapUserProvider(
			$this->directory,
			RoleMapper::fromString('helpdesk-admins:admin,helpdesk-agents:agent'),
			$this->app['hash']
		);
	}

	protected function attempt($username, $password)
	{
		$credentials = array('username' => $username, 'password' => $password);

		$user = $this->provider->retrieveByCredentials($credentials);

		if (is_null($user)) return null;

		return $this->provider->validateCredentials($user, $credentials) ? $user : false;
	}

	protected function makeLocalUser($username, $password, $role = 'admin')
	{
		$user = new User;
		$user->username = $username;
		$user->name = ucfirst($username);
		$user->email = $username.'@example.test';
		$user->password = Hash::make($password);
		$user->role = $role;
		$user->source = 'local';
		$user->save();

		return $user;
	}

	public function testUnknownUserReturnsNull()
	{
		$this->assertNull($this->provider->retrieveByCredentials(array('username' => 'nobody', 'password' => 'password')));
	}

	public function testWrongPasswordIsRejectedAndNothingIsProvisioned()
	{
		$this->assertFalse($this->attempt('bob', 'wrong'));
		$this->assertSame(0, User::where('username', 'bob')->count());
	}

	public function testCorrectPasswordProvisionsLdapUserWithMappedRole()
	{
		$user = $this->attempt('bob', 'password');

		$this->assertInstanceOf('User', $user);
		$this->assertTrue($user->exists);

		$row = User::where('username', 'bob')->first();
		$this->assertNotNull($row);
		$this->assertSame('ldap', $row->source);
		$this->assertSame('agent', $row->role);
		$this->assertSame('Bob Agent', $row->name);
		$this->assertSame('bob@helpdesk.local', $row->email);
		$this->assertNull($row->password);
		$this->assertEquals($row->id, $user->getAuthIdentifier());
	}

	public function testUserWithoutMappedGroupBecomesRequester()
	{
		$this->attempt('carol', 'password');

		$this->assertSame('requester', User::where('username', 'carol')->first()->role);
	}

	public function testChangedGroupUpdatesRoleOnNextLogin()
	{
		$this->attempt('bob', 'password');
		$this->assertSame('agent', User::where('username', 'bob')->first()->role);

		$this->directory->setGroups('bob', array('helpdesk-admins'));
		$this->attempt('bob', 'password');
		$this->assertSame('admin', User::where('username', 'bob')->first()->role);

		$this->directory->setGroups('bob', array());
		$this->attempt('bob', 'password');
		$this->assertSame('requester', User::where('username', 'bob')->first()->role);

		$this->assertSame(1, User::where('username', 'bob')->count());
	}

	public function testUsernameTypedInOtherCaseReusesTheDirectorySpelling()
	{
		$this->attempt('bob', 'password');
		$user = $this->attempt('BOB', 'password');

		$this->assertInstanceOf('User', $user);
		$this->assertSame('bob', $user->username);
		$this->assertSame(1, User::whereRaw('lower(username) = ?', array('bob'))->count());
	}

	public function testLocalUserAuthenticatesWhenDirectoryHasNoEntry()
	{
		$local = $this->makeLocalUser('admin', 'break-glass');

		$user = $this->attempt('admin', 'break-glass');

		$this->assertInstanceOf('User', $user);
		$this->assertEquals($local->id, $user->id);
		$this->assertSame('local', User::find($local->id)->source);
		$this->assertFalse($this->attempt('admin', 'wrong'));
	}

	public function testLocalAccountIsNotTakenOverByDirectoryEntryOfSameName()
	{
		$local = $this->makeLocalUser('alice', 'local-secret', 'requester');

		$this->assertFalse($this->attempt('alice', 'password'));
		$this->assertInstanceOf('User', $this->attempt('alice', 'local-secret'));

		$row = User::find($local->id);
		$this->assertSame('local', $row->source);
		$this->assertSame('requester', $row->role);
	}

	public function testEmptyPasswordIsRejectedEvenThoughTheDirectoryAcceptsAnUnauthenticatedBind()
	{
		$entry = $this->directory->findUser('alice');
		$this->assertTrue($this->directory->bind($entry['dn'], ''), 'the fake mirrors a server that allows unauthenticated binds');

		$this->assertFalse($this->attempt('alice', ''));
		$this->assertSame(0, User::where('username', 'alice')->count());
	}

	public function testEmptyUsernameReturnsNull()
	{
		$this->assertNull($this->provider->retrieveByCredentials(array('username' => '', 'password' => 'password')));
		$this->assertNull($this->provider->retrieveByCredentials(array('password' => 'password')));
	}

	public function testFilterInputIsEscaped()
	{
		$this->assertNull($this->attempt('*)(uid=*', 'password'));

		$this->assertSame(array('(uid=\2a\29\28uid=\2a)'), $this->directory->searchedFilters());
	}

	public function testUserFilterEscapesEveryFilterMetacharacter()
	{
		$this->assertSame('(uid=bob)', NativeLdapGateway::userFilter('(uid=%s)', 'bob'));
		$this->assertSame('(sAMAccountName=\5c\2a\28\29\00x)', NativeLdapGateway::userFilter('(sAMAccountName=%s)', "\\*()\0x"));
		$this->assertSame('(uid=\2a\29\28uid=\2a)', NativeLdapGateway::userFilter('(uid=%s)', '*)(uid=*'));
	}

	public function testRetrieveByIdReturnsTheStoredUser()
	{
		$local = $this->makeLocalUser('admin', 'break-glass');

		$this->assertEquals($local->id, $this->provider->retrieveById($local->id)->id);
		$this->assertNull($this->provider->retrieveById(999));
	}

	public function testRememberTokenRoundTrip()
	{
		$local = $this->makeLocalUser('admin', 'break-glass');

		$this->provider->updateRememberToken($local, 'tok123');

		$this->assertEquals($local->id, $this->provider->retrieveByToken($local->id, 'tok123')->id);
		$this->assertNull($this->provider->retrieveByToken($local->id, 'other'));
	}

}
