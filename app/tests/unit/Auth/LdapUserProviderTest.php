<?php

use Helpdesk\Auth\FakeLdapGateway;
use Helpdesk\Auth\LdapUserProvider;
use Helpdesk\Auth\NativeLdapGateway;
use Helpdesk\Auth\RoleMapper;

class LdapUserProviderTest extends TestCase {

	const ADMINS = 'cn=helpdesk-admins,ou=groups,dc=helpdesk,dc=local';
	const AGENTS = 'cn=helpdesk-agents,ou=groups,dc=helpdesk,dc=local';

	/** @var FakeLdapGateway */
	protected $directory;

	/** @var LdapUserProvider */
	protected $provider;

	public function setUp()
	{
		parent::setUp();

		Artisan::call('migrate');

		$this->directory = new FakeLdapGateway(array(), '(uid=%s)');
		$this->directory->addUser('alice', 'password', array(static::ADMINS), 'Alice Admin', 'alice@helpdesk.local');
		$this->directory->addUser('bob', 'password', array(static::AGENTS), 'Bob Agent', 'bob@helpdesk.local');
		$this->directory->addUser('carol', 'password', array(), 'Carol Requester', 'carol@helpdesk.local');

		$this->provider = new LdapUserProvider(
			$this->directory,
			RoleMapper::fromString(static::ADMINS.':admin;'.static::AGENTS.':agent'),
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

		$this->directory->setGroups('bob', array(static::ADMINS));
		$this->attempt('bob', 'password');
		$this->assertSame('admin', User::where('username', 'bob')->first()->role);

		$this->directory->setGroups('bob', array());
		$this->attempt('bob', 'password');
		$this->assertSame('requester', User::where('username', 'bob')->first()->role);

		$this->assertSame(1, User::where('username', 'bob')->count());
	}

	public function testSameCommonNameGroupInAnotherOuGrantsNothing()
	{
		$this->directory->setGroups('carol', array(
			'cn=helpdesk-admins,ou=delegated,dc=helpdesk,dc=local',
			'cn=helpdesk-agents,ou=people,dc=helpdesk,dc=local',
		));

		$this->assertInstanceOf('User', $this->attempt('carol', 'password'));
		$this->assertSame('requester', User::where('username', 'carol')->first()->role);
	}

	public function testDirectoryLoginRecordsLoginAndRoleVerificationTimes()
	{
		Carbon\Carbon::setTestNow(Carbon\Carbon::create(2014, 7, 1, 9, 0, 0));

		try
		{
			$this->attempt('bob', 'password');
		}
		finally
		{
			Carbon\Carbon::setTestNow();
		}

		$row = User::where('username', 'bob')->first();
		$this->assertTrue((bool) $row->active);
		$this->assertSame('2014-07-01 09:00:00', (string) $row->last_login_at);
		$this->assertSame('2014-07-01 09:00:00', (string) $row->role_verified_at);
	}

	public function testUserRemovedFromTheDirectoryIsRefusedWithoutBeingChanged()
	{
		$this->attempt('bob', 'password');
		$this->directory->removeUser('bob');

		$this->assertNull($this->attempt('bob', 'wrong'));
		$this->assertNull($this->attempt('bob', 'password'));

		$row = User::where('username', 'bob')->first();
		$this->assertTrue((bool) $row->active);
		$this->assertSame('agent', $row->role);
	}

	public function testDisabledDirectoryAccountIsRefusedWithoutBeingChanged()
	{
		$this->attempt('bob', 'password');
		$this->directory->setDisabled('bob', true);

		$this->assertNotInstanceOf('User', $this->attempt('bob', 'wrong'));
		$this->assertNotInstanceOf('User', $this->attempt('bob', 'password'));

		$row = User::where('username', 'bob')->first();
		$this->assertTrue((bool) $row->active);
		$this->assertSame('agent', $row->role);
	}

	public function testAmbiguousDirectoryEntryIsRefusedWithoutBeingChanged()
	{
		$this->attempt('bob', 'password');
		$this->directory->makeAmbiguous('bob');

		$this->assertNull($this->attempt('bob', 'wrong'));
		$this->assertNull($this->attempt('bob', 'password'));

		$row = User::where('username', 'bob')->first();
		$this->assertTrue((bool) $row->active);
		$this->assertSame('agent', $row->role);
	}

	public function testDirectoryOutageRefusesTheLoginButKeepsTheAccount()
	{
		$this->attempt('bob', 'password');
		$this->directory->failWith(new Helpdesk\Auth\LdapUnavailableException('cannot connect to ldap'));

		$this->assertNull($this->attempt('bob', 'password'));

		$row = User::where('username', 'bob')->first();
		$this->assertTrue((bool) $row->active);
		$this->assertSame('agent', $row->role);
	}

	public function testDeactivatedDirectoryUserIsReactivatedByAValidLogin()
	{
		$this->attempt('bob', 'password');
		User::where('username', 'bob')->update(array('active' => false));

		$this->assertInstanceOf('User', $this->attempt('bob', 'password'));
		$this->assertTrue((bool) User::where('username', 'bob')->first()->active);
	}

	public function testDeactivatedLocalUserCannotLogIn()
	{
		$local = $this->makeLocalUser('admin', 'break-glass');
		$local->active = false;
		$local->save();

		$this->assertNotInstanceOf('User', $this->attempt('admin', 'break-glass'));
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
