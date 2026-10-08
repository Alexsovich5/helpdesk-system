<?php

use Carbon\Carbon;
use Helpdesk\Auth\FakeLdapGateway;
use Helpdesk\Auth\LdapUnavailableException;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * users:sync-roles re-checks every directory agent and admin against the
 * directory and demotes or deactivates the ones it no longer backs.
 */
class UsersSyncRolesCommandTest extends TestCase {

	const ADMINS = 'cn=helpdesk-admins,ou=groups,dc=helpdesk,dc=local';
	const AGENTS = 'cn=helpdesk-agents,ou=groups,dc=helpdesk,dc=local';

	/** @var FakeLdapGateway */
	protected $directory;

	public function setUp()
	{
		parent::setUp();

		Artisan::call('migrate');
		Carbon::setTestNow(Carbon::create(2014, 7, 1, 9, 0, 0));

		$this->directory = new FakeLdapGateway();
		$this->directory->addUser('alice', 'password', array(static::ADMINS), 'Alice Admin', 'alice@helpdesk.local');
		$this->directory->addUser('bob', 'password', array(static::AGENTS), 'Bob Agent', 'bob@helpdesk.local');
		App::instance('Helpdesk\Auth\LdapGateway', $this->directory);
	}

	public function tearDown()
	{
		Carbon::setTestNow();

		parent::tearDown();
	}

	protected function makeUser($username, $role, $source = 'ldap')
	{
		$user = new User;
		$user->username = $username;
		$user->name = ucfirst($username);
		$user->email = $username.'@helpdesk.local';
		$user->role = $role;
		$user->source = $source;
		$user->role_verified_at = Carbon::create(2014, 5, 1, 0, 0, 0);
		$user->save();

		return $user;
	}

	protected function sync()
	{
		$output = new BufferedOutput;
		$status = Artisan::call('users:sync-roles', array(), $output);

		return array($status, $output->fetch());
	}

	public function testStillMappedUserKeepsTheRoleAndIsReverified()
	{
		$this->makeUser('bob', 'agent');

		list($status) = $this->sync();

		$bob = User::where('username', 'bob')->first();
		$this->assertSame(0, $status);
		$this->assertSame('agent', $bob->role);
		$this->assertTrue((bool) $bob->active);
		$this->assertSame('2014-07-01 09:00:00', (string) $bob->role_verified_at);
	}

	public function testUserRemovedFromTheAgentGroupIsDemoted()
	{
		$this->makeUser('bob', 'agent');
		$this->directory->setGroups('bob', array());

		list($status, $text) = $this->sync();

		$bob = User::where('username', 'bob')->first();
		$this->assertSame(0, $status);
		$this->assertSame('requester', $bob->role);
		$this->assertTrue((bool) $bob->active);
		$this->assertContains('demoted 1', $text);
	}

	public function testAdminMovedToTheAgentGroupBecomesAgent()
	{
		$this->makeUser('alice', 'admin');
		$this->directory->setGroups('alice', array(static::AGENTS));

		$this->sync();

		$this->assertSame('agent', User::where('username', 'alice')->first()->role);
	}

	public function testUserMissingFromTheDirectoryIsDeactivated()
	{
		$this->makeUser('gone', 'agent');

		list($status, $text) = $this->sync();

		$gone = User::where('username', 'gone')->first();
		$this->assertSame(0, $status);
		$this->assertFalse((bool) $gone->active);
		$this->assertSame('requester', $gone->role);
		$this->assertContains('deactivated 1', $text);
	}

	public function testDisabledDirectoryAccountIsDeactivated()
	{
		$this->makeUser('bob', 'agent');
		$this->directory->setDisabled('bob', true);

		$this->sync();

		$this->assertFalse((bool) User::where('username', 'bob')->first()->active);
	}

	public function testLocalAccountsAndRequestersAreLeftAlone()
	{
		$local = $this->makeUser('admin', 'admin', 'local');
		$requester = $this->makeUser('carol', 'requester');

		$this->sync();

		$this->assertSame('admin', User::find($local->id)->role);
		$this->assertTrue((bool) User::find($local->id)->active);
		$this->assertTrue((bool) User::find($requester->id)->active);
	}

	public function testDirectoryOutageChangesNothingAndFails()
	{
		$this->makeUser('bob', 'agent');
		$this->makeUser('gone', 'agent');
		$this->directory->failWith(new LdapUnavailableException('cannot connect to the directory'));

		list($status, $text) = $this->sync();

		$this->assertNotSame(0, $status);
		$this->assertContains('cannot connect to the directory', $text);
		foreach (array('bob', 'gone') as $username)
		{
			$user = User::where('username', $username)->first();
			$this->assertSame('agent', $user->role);
			$this->assertTrue((bool) $user->active);
			$this->assertSame('2014-05-01 00:00:00', (string) $user->role_verified_at);
		}
	}

	public function testDeactivatedUserIsSignedOutOnTheNextRequest()
	{
		$bob = $this->makeUser('bob', 'agent');
		$this->be($bob);

		$bob->active = false;
		$bob->save();

		$response = $this->call('GET', '/tickets');

		$this->assertSame(URL::to('login'), $response->headers->get('Location'));
		$this->assertFalse(Auth::check());
	}

	public function testInactiveUsersAreNotOfferedAsAssignees()
	{
		$admin = $this->makeUser('alice', 'admin');
		$admin->role_verified_at = Carbon::now();
		$admin->save();
		$this->makeUser('bob', 'agent');
		User::where('username', 'bob')->update(array('active' => false));

		$category = Category::create(array('name' => 'Network'));
		$ticket = App::make('Helpdesk\Tickets\TicketService')->create(array(
			'subject' => 'x', 'description' => 'y', 'category_id' => $category->id,
		), $admin);
		$this->be($admin);

		$page = $this->call('GET', '/tickets/'.$ticket->number)->getContent();
		$this->assertNotContains('>Bob</option>', $page);

		$this->post('/tickets/'.$ticket->number.'/assign', array('assignee_id' => User::where('username', 'bob')->first()->id));
		$this->assertNull(Ticket::find($ticket->id)->assignee_id);
	}

	public function testSchedulerRunsTheSync()
	{
		$compose = file_get_contents(base_path().'/docker-compose.yml');

		$this->assertContains('php artisan users:sync-roles', $compose);
	}

}
