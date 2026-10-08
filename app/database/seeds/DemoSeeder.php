<?php

use Carbon\Carbon;
use Helpdesk\Sla\SlaCalculator;
use Helpdesk\Sla\SlaMonitor;
use Helpdesk\Tickets\TicketService;
use Illuminate\Events\Dispatcher;

/**
 * Demo dataset: the three directory users, ten assets and 25 tickets worked
 * through the normal ticket workflow, plus the default knowledge-base
 * articles. Faker runs with a fixed seed, so every run produces the same
 * subjects, assets and workflow; only the timestamps move, because tickets
 * are dated relative to the time the seeder runs.
 *
 * Tickets are written through TicketService (so each one has its
 * ticket_events history) with an empty event dispatcher, so seeding sends no
 * e-mail. Meant for a freshly migrated database (make demo runs
 * migrate:refresh --seed).
 */
class DemoSeeder extends Seeder {

	const SEED = 4242;

	const ASSETS = 10;

	const TICKETS = 25;

	/**
	 * Categories the demo tickets are filed under.
	 *
	 * @var array
	 */
	public static $categories = array('Hardware', 'Software', 'Network');

	/**
	 * How many demo tickets end up in each status.
	 *
	 * @var array
	 */
	public static $statusCounts = array('new' => 5, 'open' => 6, 'pending' => 4, 'resolved' => 5, 'closed' => 5);

	/**
	 * Users that exist in the OpenLDAP simulator (docker/ldap/seed.ldif),
	 * with the role their directory groups map to. They sign in with their
	 * directory password; the rows are refreshed on every login.
	 *
	 * @var array
	 */
	public static $users = array(
		'alice' => array('Alice Admin', 'admin'),
		'bob'   => array('Bob Agent', 'agent'),
		'carol' => array('Carol Requester', 'requester'),
	);

	/**
	 * Ticket subjects with their category and description.
	 *
	 * @var array
	 */
	public static $subjects = array(
		array('Hardware', 'Laptop does not wake from sleep', 'The lid is opened but the screen stays black until the power button is held down.'),
		array('Hardware', 'Second monitor flickers', 'The external monitor on the docking station flickers every few seconds.'),
		array('Hardware', 'Printer on 2F jams on every job', 'Printer 2F-201 jams on the first page of every print job.'),
		array('Hardware', 'Keyboard missing keys', 'The E and R keys came off the laptop keyboard.'),
		array('Hardware', 'Docking station not charging laptop', 'The laptop battery drains while it sits in the dock.'),
		array('Hardware', 'Desk phone has no dial tone', 'The handset is silent; the display shows "No service".'),
		array('Hardware', 'Toner low on the 3F printer', 'Printer 3F-310 reports low toner and prints faded pages.'),
		array('Hardware', 'New starter needs a laptop', 'A new team member starts on Monday and needs a laptop and docking station.'),
		array('Software', 'Spreadsheet app crashes on large files', 'Opening the monthly report workbook closes the application without an error.'),
		array('Software', 'PDF reader asks for an update every day', 'The PDF reader shows an update prompt every morning even after updating.'),
		array('Software', 'Cannot install the project planning tool', 'The installer stops with "administrator rights required".'),
		array('Software', 'E-mail client stuck on "Updating inbox"', 'New messages arrive on the phone but not in the desktop client.'),
		array('Software', 'Browser opens the wrong home page', 'The browser opens a search page instead of the intranet.'),
		array('Software', 'Calendar invites arrive an hour late', 'Meeting times show one hour later than the organiser set.'),
		array('Software', 'Antivirus scan slows the PC every morning', 'The PC is unusable for 20 minutes after logon while the scan runs.'),
		array('Software', 'Licence expired for the diagram editor', 'The diagram editor opens in read-only mode with a licence warning.'),
		array('Software', 'Shared printer missing from print dialog', 'The 2F printer no longer appears in the list of printers.'),
		array('Network', 'Wi-Fi drops in meeting room 4', 'Laptops lose the wireless connection every few minutes in meeting room 4.'),
		array('Network', 'VPN disconnects after ten minutes', 'The VPN connection drops after about ten minutes when working from home.'),
		array('Network', 'Shared drive H: not mapped at logon', 'Drive H: is missing after signing in; mapping it by hand works.'),
		array('Network', 'Intranet very slow from the 3rd floor', 'Intranet pages take more than 30 seconds to load on the 3rd floor.'),
		array('Network', 'Network port at desk 2F-14 is dead', 'No link light on the wall port at desk 2F-14.'),
		array('Network', 'Guest Wi-Fi password needed for visitors', 'Visitors arrive on Thursday and need guest wireless access.'),
		array('Network', 'Cannot reach the file server by name', 'The file server answers by IP address but not by its name.'),
		array('Network', 'Video calls drop at the branch office', 'Video calls from the branch office freeze and disconnect after a few minutes.'),
	);

	/**
	 * Asset types with the tag prefix and model names to pick from.
	 *
	 * @var array
	 */
	protected static $assetTypes = array(
		'laptop'  => array('LT', array('ThinkPad T440', 'Latitude E7440', 'EliteBook 840')),
		'desktop' => array('DT', array('OptiPlex 9020', 'ThinkCentre M93p')),
		'printer' => array('PR', array('LaserJet M401dn', 'Color LaserJet M451')),
		'phone'   => array('PH', array('IP Phone 7942', 'IP Phone 7962')),
	);

	public function run()
	{
		$faker = Faker\Factory::create();
		$faker->seed(static::SEED);

		// Every random choice is made up front, before any database work, so
		// nothing else that draws from mt_rand can change the sequence.
		$assetPlan = $this->planAssets($faker);
		$ticketPlan = $this->planTickets($faker);

		$users = $this->users();
		$this->call('KbTableSeeder');

		$categories = array();
		foreach (static::$categories as $name)
		{
			$categories[$name] = Category::firstOrCreate(array('name' => $name));
		}

		$assets = $this->assets($assetPlan, $users);

		$sla = new SlaCalculator;
		$service = new TicketService(App::make('db'), new Dispatcher, $sla);
		$articles = KbArticle::published()->orderBy('id')->get()->all();
		$realNow = Carbon::now();

		try
		{
			foreach ($ticketPlan as $i => $plan)
			{
				$this->ticket($service, $plan, $realNow, $users, $categories, $assets, $articles, $i);
			}
		}
		catch (Exception $e)
		{
			Carbon::setTestNow();

			throw $e;
		}

		Carbon::setTestNow();

		// Bring sla_state up to date for the tickets that are still open.
		$monitor = new SlaMonitor(App::make('db'), new Dispatcher, $sla);
		$monitor->run(Carbon::now());
	}

	/**
	 * @return array  username => User
	 */
	protected function users()
	{
		$users = array();

		foreach (static::$users as $username => $info)
		{
			$user = User::firstOrNew(array('username' => $username));
			$user->name = $info[0];
			$user->email = $username.'@helpdesk.local';
			$user->role = $info[1];
			$user->source = 'ldap';
			$user->password = null;
			$user->save();

			$users[$username] = $user;
		}

		return $users;
	}

	protected function planAssets($faker)
	{
		$plan = array();
		$types = array_keys(static::$assetTypes);

		for ($n = 1; $n <= static::ASSETS; $n++)
		{
			$type = $types[($n - 1) % count($types)];
			list($prefix, $models) = static::$assetTypes[$type];

			$plan[] = array(
				'asset_tag' => sprintf('%s-%04d', $prefix, 100 + $n),
				'name'      => $faker->randomElement($models),
				'type'      => $type,
				'serial'    => strtoupper($faker->bothify('??#####??#')),
				'location'  => $faker->randomElement(array('2F-201', '2F-214', '3F-310', '3F-322', 'Store room')),
				'status'    => $faker->randomElement(array('in_use', 'in_use', 'in_use', 'in_stock', 'repair')),
			);
		}

		return $plan;
	}

	/**
	 * @return array one entry per ticket, in creation order
	 */
	protected function planTickets($faker)
	{
		$statuses = array();
		foreach (static::$statusCounts as $status => $count)
		{
			$statuses = array_merge($statuses, array_fill(0, $count, $status));
		}

		$statuses = $faker->randomElements($statuses, count($statuses));
		$subjects = $faker->randomElements(static::$subjects, static::TICKETS);

		$plan = array();
		foreach ($subjects as $i => $subject)
		{
			$plan[] = array(
				'category'    => $subject[0],
				'subject'     => $subject[1],
				'description' => $subject[2],
				'status'      => $statuses[$i],
				'priority'    => $faker->randomElement(array('low', 'normal', 'normal', 'normal', 'high', 'urgent')),
				'requester'   => $faker->randomElement(array('carol', 'carol', 'carol', 'alice')),
				'assignee'    => $faker->randomElement(array('bob', 'bob', 'alice')),
				'asset'       => $faker->numberBetween(0, static::ASSETS + 4),
				// From about twelve days ago up to five hours ago, closer
				// together towards now; at least five hours so the whole
				// workflow below fits before now.
				'age_minutes' => 300 + pow(static::TICKETS - 1 - $i, 2) * 30 + $faker->numberBetween(0, 120),
			);
		}

		return $plan;
	}

	/**
	 * @return array  Asset models in creation order
	 */
	protected function assets(array $plan, array $users)
	{
		$assets = array();

		foreach ($plan as $row)
		{
			$asset = new Asset($row);
			if ($row['status'] === 'in_use') $asset->assigned_user_id = $users['carol']->id;
			$asset->save();

			$assets[] = $asset;
		}

		return $assets;
	}

	protected function ticket(TicketService $service, array $plan, Carbon $realNow, array $users, array $categories, array $assets, array $articles, $index)
	{
		$created = $realNow->copy()->subMinutes($plan['age_minutes']);
		$at = function($minutes) use ($created)
		{
			Carbon::setTestNow($created->copy()->addMinutes($minutes));
		};

		$requester = $users[$plan['requester']];
		$agent = $users[$plan['assignee']];

		$data = array(
			'subject'     => $plan['subject'],
			'description' => $plan['description'],
			'category_id' => $categories[$plan['category']]->id,
			'priority'    => $plan['priority'],
		);
		if ($plan['asset'] < count($assets)) $data['asset_id'] = $assets[$plan['asset']]->id;

		$at(0);
		$ticket = $service->create($data, $requester);

		$status = $plan['status'];
		if ($status === 'new') return;

		$at(20);
		$service->assign($ticket, $agent, $agent);

		$at(45);
		$service->comment($ticket, $agent, 'Thanks, I am looking into this now.');

		if ($status === 'open') return;

		if ($status === 'pending')
		{
			$at(60);
			$service->transition($ticket, 'pending', $agent);
			$service->comment($ticket, $agent, 'Could you let me know when you are at your desk so we can test together?');
			return;
		}

		$at(90);
		$service->comment($ticket, $agent, 'Internal: fixed after a restart and a settings reset.', true);

		$at(120);
		if ($index % 2 === 0 && count($articles))
		{
			$service->linkArticle($ticket, $articles[$index % count($articles)], $agent);
		}
		$service->transition($ticket, 'resolved', $agent);

		if ($status === 'closed')
		{
			$at(240);
			$service->transition($ticket, 'closed', $requester);
		}
	}

}
