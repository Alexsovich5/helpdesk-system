<?php

use Carbon\Carbon;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Every GET page carries the markup Bootstrap needs to work at phone width:
 * viewport meta, a collapsing navbar, tables wrapped for horizontal scroll,
 * and the jQuery/Bootstrap assets.
 */
class ResponsiveMarkupTest extends TestCase {

	protected $users = array();
	protected $ticket;
	protected $article;
	protected $asset;

	public function setUp()
	{
		parent::setUp();

		Artisan::call('migrate');

		Carbon::setTestNow(Carbon::create(2014, 7, 1, 9, 0, 0));

		$this->users['requester'] = $this->makeUser('carol', 'requester');
		$this->users['agent'] = $this->makeUser('bob', 'agent');
		$this->users['admin'] = $this->makeUser('alice', 'admin');

		$category = Category::create(array('name' => 'Hardware'));
		$kbCategory = KbCategory::create(array('name' => 'Remote access'));

		$this->asset = new Asset;
		$this->asset->asset_tag = 'LT-0101';
		$this->asset->name = 'ThinkPad T440';
		$this->asset->type = 'laptop';
		$this->asset->status = 'in_use';
		$this->asset->location = 'HQ 2nd floor';
		$this->asset->save();

		$this->article = new KbArticle(array('title' => 'Reset your VPN password', 'body' => 'Use the self-service portal.'));
		$this->article->kb_category_id = $kbCategory->id;
		$this->article->author_id = $this->users['agent']->id;
		$this->article->is_published = true;
		$this->article->save();

		$tickets = App::make('Helpdesk\Tickets\TicketService');

		// One ticket assigned to the agent and linked to the asset, one left
		// in the queue, so the dashboard, list and asset history all render tables.
		$this->ticket = $tickets->create(array(
			'subject'     => 'Laptop fan is loud',
			'description' => 'Constant noise since Monday.',
			'category_id' => $category->id,
		), $this->users['requester']);
		$tickets->assign($this->ticket, $this->users['agent'], $this->users['agent']);
		$this->ticket->asset_id = $this->asset->id;
		$this->ticket->save();

		$tickets->create(array(
			'subject'     => 'Printer offline',
			'description' => 'Second floor printer shows offline.',
			'category_id' => $category->id,
		), $this->users['requester']);
	}

	public function tearDown()
	{
		Carbon::setTestNow();

		parent::tearDown();
	}

	protected function makeUser($username, $role)
	{
		$user = new User;
		$user->username = $username;
		$user->name = ucfirst($username);
		$user->email = $username.'@helpdesk.local';
		$user->role = $role;
		$user->source = 'local';
		$user->save();

		return $user;
	}

	/**
	 * Every GET page, with the role that may see it (null = signed out).
	 * {ticket}, {article} and {asset} are replaced with fixture keys.
	 */
	public function pages()
	{
		return array(
			'login'            => array('/login', null),
			'dashboard'        => array('/', 'agent'),
			'ticket list'      => array('/tickets', 'agent'),
			'new ticket'       => array('/tickets/create', 'agent'),
			'ticket detail'    => array('/tickets/{ticket}', 'agent'),
			'kb list'          => array('/kb', 'agent'),
			'kb new'           => array('/kb/create', 'agent'),
			'kb categories'    => array('/kb/categories', 'agent'),
			'kb article'       => array('/kb/{article}', 'agent'),
			'kb edit'          => array('/kb/{article}/edit', 'agent'),
			'asset list'       => array('/assets', 'agent'),
			'asset detail'     => array('/assets/{asset}', 'agent'),
			'asset new'        => array('/assets/create', 'admin'),
			'asset edit'       => array('/assets/{asset}/edit', 'admin'),
			'reports'          => array('/reports', 'agent'),
			'requester ticket' => array('/tickets/{ticket}', 'requester'),
		);
	}

	protected function fetch($uri, $role)
	{
		$uri = str_replace(
			array('{ticket}', '{article}', '{asset}'),
			array($this->ticket->number, $this->article->id, $this->asset->id),
			$uri
		);

		if ($role) $this->be($this->users[$role]);

		$response = $this->call('GET', $uri, $role === 'agent' && $uri === '/reports'
			? array('from' => '2014-07-01', 'to' => '2014-07-31')
			: array());

		$this->assertSame(200, $response->getStatusCode(), "GET $uri as ".($role ?: 'guest'));

		return $response->getContent();
	}

	/**
	 * @dataProvider pages
	 */
	public function testPageCarriesResponsiveMarkup($uri, $role)
	{
		$html = $this->fetch($uri, $role);
		$crawler = new Crawler($html);

		$this->assertContains('<meta name="viewport" content="width=device-width, initial-scale=1">', $html);

		$toggle = $crawler->filter('button.navbar-toggle');
		$this->assertCount(1, $toggle);
		$target = $toggle->attr('data-target');
		$this->assertNotEmpty($target);
		$this->assertCount(1, $crawler->filter('.navbar-collapse'.$target));

		foreach ($crawler->filter('table') as $index => $table)
		{
			$parent = $table->parentNode;
			$classes = $parent instanceof DOMElement ? preg_split('/\s+/', $parent->getAttribute('class')) : array();
			$this->assertContains('table-responsive', $classes, "table #$index on $uri is not inside a table-responsive div");
			$this->assertSame('div', $parent->nodeName);
		}

		$this->assertRegExp('#<link rel="stylesheet" href="[^"]*/vendor/bootstrap/css/bootstrap\.min\.css">#', $html);
		$this->assertRegExp('#<script src="[^"]*/js/jquery-1\.11\.1\.min\.js"></script>#', $html);
		$this->assertRegExp('#<script src="[^"]*/vendor/bootstrap/js/bootstrap\.min\.js"></script>#', $html);
		$this->assertLessThan(
			strpos($html, 'bootstrap/js/bootstrap.min.js'),
			strpos($html, 'jquery-1.11.1.min.js'),
			'jQuery loads before the Bootstrap plugins'
		);
	}

	/**
	 * Pages whose lists render at least one table, so the loop above is not vacuous.
	 */
	public function testListPagesRenderTables()
	{
		foreach (array('/', '/tickets', '/kb', '/kb/categories', '/assets', '/assets/{asset}', '/reports') as $uri)
		{
			$crawler = new Crawler($this->fetch($uri, 'agent'));
			$this->assertGreaterThan(0, $crawler->filter('.table-responsive > table')->count(), "no table on $uri");
		}
	}

	/**
	 * Secondary columns drop out on phones so the key columns fit without scrolling.
	 *
	 * @return array  uri => header labels hidden below 768px
	 */
	public function secondaryColumns()
	{
		return array(
			'ticket list' => array('/tickets', array('Requester', 'Resolution due', 'Created')),
			'dashboard'   => array('/', array('Requester', 'Resolution due', 'Created')),
			'kb list'     => array('/kb', array('Category', 'Updated')),
			'asset list'  => array('/assets', array('Type', 'Assigned to', 'Location')),
		);
	}

	/**
	 * @dataProvider secondaryColumns
	 */
	public function testSecondaryColumnsAreHiddenOnPhones($uri, array $hidden)
	{
		$crawler = new Crawler($this->fetch($uri, 'agent'));
		$table = $crawler->filter('.table-responsive > table')->first();

		$headers = array();
		$table->filter('thead th')->each(function($th) use (&$headers)
		{
			$headers[trim($th->text())] = preg_split('/\s+/', (string) $th->attr('class'));
		});

		foreach ($headers as $label => $classes)
		{
			$this->assertSame(in_array($label, $hidden), in_array('hidden-xs', $classes), "$uri column \"$label\"");
		}

		// Body cells follow their header so the columns stay aligned.
		$columns = array_values(array_map(function($classes) { return in_array('hidden-xs', $classes); }, $headers));
		$row = $table->filter('tbody tr')->first();
		$row->filter('td')->each(function($td, $i) use ($columns, $uri)
		{
			$this->assertSame($columns[$i], in_array('hidden-xs', preg_split('/\s+/', (string) $td->attr('class'))), "$uri cell $i");
		});
	}

	/**
	 * Main form submit buttons stretch to full width below 768px (.btn-block-xs in app.css).
	 */
	public function testPhoneFormsUseFullWidthButtons()
	{
		$checks = array(
			array('/tickets/create', 'agent', 'Create ticket'),
			array('/tickets/{ticket}', 'agent', 'Add comment'),
			array('/kb/create', 'agent', 'Save'),
			array('/kb/{article}/edit', 'agent', 'Save'),
			array('/assets/create', 'admin', 'Save'),
			array('/assets/{asset}/edit', 'admin', 'Save'),
		);

		foreach ($checks as $check)
		{
			list($uri, $role, $label) = $check;
			$crawler = new Crawler($this->fetch($uri, $role));

			$buttons = $crawler->filter('form button[type=submit]')->reduce(function($button) use ($label)
			{
				return trim($button->text()) === $label;
			});

			$this->assertCount(1, $buttons, "\"$label\" button on $uri");
			$classes = preg_split('/\s+/', (string) $buttons->attr('class'));
			$this->assertContains('btn-block-xs', $classes, "\"$label\" on $uri is not full width on phones");
		}
	}

}
