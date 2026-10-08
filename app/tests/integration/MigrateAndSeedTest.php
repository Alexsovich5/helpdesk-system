<?php

/**
 * Runs the migrations and seeders against MySQL (helpdesk_test) and checks
 * the demo dataset. Migrations commit implicitly on MySQL, so this class runs
 * without the per-test transaction and leaves an empty migrated schema
 * behind for the classes that run after it.
 *
 * @group integration
 */
class MigrateAndSeedTest extends IntegrationTestCase {

	protected $useTransaction = false;

	public function setUp()
	{
		parent::setUp();

		$this->assertSame(0, Artisan::call('migrate:refresh', array('--seed' => true)));
		$this->assertSame(0, Artisan::call('db:seed', array('--class' => 'DemoSeeder')));
	}

	public function tearDown()
	{
		Artisan::call('migrate:refresh');

		parent::tearDown();
	}

	public function testRunsAgainstTheTestDatabaseOnMysql()
	{
		$this->assertSame('mysql', DB::connection()->getDriverName());
		$this->assertSame('helpdesk_test', DB::connection()->getDatabaseName());
	}

	public function testDirectoryUsersArePreProvisionedWithoutPasswords()
	{
		$expected = array('alice' => 'admin', 'bob' => 'agent', 'carol' => 'requester');

		foreach ($expected as $username => $role)
		{
			$user = User::where('username', $username)->first();

			$this->assertNotNull($user, "$username is missing");
			$this->assertSame($role, $user->role);
			$this->assertSame('ldap', $user->source);
			$this->assertNull($user->password);
		}

		$this->assertSame(count($expected), User::count());
	}

	public function testRowCountsMatchTheSeeder()
	{
		$this->assertSame(DemoSeeder::TICKETS, array_sum(DemoSeeder::$statusCounts));

		$this->assertSame(DemoSeeder::ASSETS, Asset::count());
		$this->assertSame(DemoSeeder::TICKETS, Ticket::count());

		$categories = array_unique(array_merge(CategoryTableSeeder::$names, DemoSeeder::$categories));
		$this->assertSame(count($categories), Category::count());
		$this->assertSame(count(SlaPolicyTableSeeder::$defaults), SlaPolicy::count());

		$used = Ticket::distinct()->lists('category_id');
		$this->assertCount(count(DemoSeeder::$categories), $used);
		$names = DemoSeeder::$categories;
		sort($names);
		$this->assertSame($names, Category::whereIn('id', $used)->orderBy('name')->lists('name'));

		$articles = 0;
		foreach (KbTableSeeder::$articles as $titles) $articles += count($titles);
		$this->assertSame($articles, KbArticle::published()->count());
	}

	public function testTicketsAreSpreadAcrossStatusesAsSeeded()
	{
		$actual = array();
		foreach (Ticket::select('status', DB::raw('COUNT(*) AS n'))->groupBy('status')->get() as $row)
		{
			$actual[$row->status] = (int) $row->n;
		}

		ksort($actual);
		$expected = DemoSeeder::$statusCounts;
		ksort($expected);

		$this->assertSame($expected, $actual);
	}

	public function testEveryTicketHasANumberAndHistory()
	{
		foreach (Ticket::orderBy('id')->get() as $ticket)
		{
			$this->assertSame(sprintf('HD-%06d', $ticket->id), $ticket->number);
			$this->assertSame('created', $ticket->events()->first()->type, "{$ticket->number} has no created event");
		}

		$withoutEvents = DB::table('tickets')
			->whereNotExists(function($q)
			{
				$q->select(DB::raw(1))->from('ticket_events')->whereRaw('ticket_events.ticket_id = tickets.id');
			})
			->count();

		$this->assertSame(0, (int) $withoutEvents);
	}

	public function testWorkedTicketsCarryTheirWorkflowHistory()
	{
		foreach (Ticket::whereIn('status', array('resolved', 'closed'))->get() as $ticket)
		{
			$this->assertNotNull($ticket->assignee_id, $ticket->number);
			$this->assertNotNull($ticket->resolved_at, $ticket->number);
			$this->assertTrue($ticket->events()->where('type', 'status')->where('to_value', 'resolved')->exists(), $ticket->number);
		}

		foreach (Ticket::where('status', 'new')->get() as $ticket)
		{
			$this->assertNull($ticket->assignee_id, $ticket->number);
		}

		$this->assertGreaterThan(0, Ticket::whereNotNull('asset_id')->count());
		$this->assertGreaterThan(0, DB::table('ticket_kb_article')->count());
	}

	public function testSeedingIsDeterministic()
	{
		$first = Ticket::orderBy('id')->lists('subject', 'number');
		$tags = Asset::orderBy('id')->lists('serial', 'asset_tag');

		Artisan::call('migrate:refresh', array('--seed' => true));
		Artisan::call('db:seed', array('--class' => 'DemoSeeder'));

		$this->assertSame($first, Ticket::orderBy('id')->lists('subject', 'number'));
		$this->assertSame($tags, Asset::orderBy('id')->lists('serial', 'asset_tag'));
	}

	public function testLikeSearchIgnoresCaseUnderTheMysqlCollation()
	{
		$carol = User::where('username', 'carol')->first();
		$search = new Helpdesk\Kb\ArticleSearch;

		$titles = $search->search('VPN cErTiFiCaTe', $carol)->lists('title');
		$this->assertSame(array('Connect to the VPN'), $titles);

		$this->assertSame(0, $search->search('no-such-term-anywhere', $carol)->count());

		$tickets = Ticket::where('subject', 'LIKE', '%PRINTER%')->count();
		$this->assertSame(Ticket::whereRaw('LOWER(subject) LIKE ?', array('%printer%'))->count(), $tickets);
		$this->assertGreaterThan(0, $tickets);
	}

}
