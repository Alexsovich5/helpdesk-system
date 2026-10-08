<?php

use Helpdesk\Kb\ArticleSearch;

class ArticleSearchTest extends TestCase {

	/** @var ArticleSearch */
	protected $search;

	protected $agent;
	protected $requester;
	protected $category;

	public function setUp()
	{
		parent::setUp();

		Artisan::call('migrate');

		$this->agent = $this->makeUser('bob', 'agent');
		$this->requester = $this->makeUser('carol', 'requester');
		$this->category = KbCategory::create(array('name' => 'Remote access'));

		$this->search = new ArticleSearch;
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

	protected function article($title, $body, $published = true)
	{
		$article = new KbArticle(array('title' => $title, 'body' => $body));
		$article->kb_category_id = $this->category->id;
		$article->author_id = $this->agent->id;
		$article->is_published = $published;
		$article->save();

		return $article;
	}

	protected function titles($query, User $user)
	{
		$titles = $this->search->search($query, $user)->orderBy('title')->lists('title');
		sort($titles);

		return $titles;
	}

	public function testEveryTermMustMatchTitleOrBody()
	{
		$this->article('Reset your VPN password', 'Use the self-service portal.');
		$this->article('Install the VPN client', 'Download the installer from the intranet.');
		$this->article('Password policy', 'Passwords expire every 90 days.');
		$this->article('Mapped drives over VPN', 'Reconnect after changing your password.');

		$this->assertEquals(
			array('Mapped drives over VPN', 'Reset your VPN password'),
			$this->titles('vpn password', $this->requester)
		);
		$this->assertEquals(array('Install the VPN client'), $this->titles('vpn   installer', $this->requester));
		$this->assertEquals(array(), $this->titles('vpn printer', $this->requester));
	}

	public function testMatchingIgnoresCase()
	{
		$this->article('Reset your VPN password', 'Use the self-service PORTAL.');
		$this->article('Printer jams', 'Open tray two.');

		$this->assertEquals(array('Reset your VPN password'), $this->titles('vpn', $this->requester));
		$this->assertEquals(array('Reset your VPN password'), $this->titles('RESET Portal', $this->requester));
	}

	public function testWildcardCharactersInTermsMatchLiterally()
	{
		$this->article('Disk at 100% full', 'Clear the temp folder.');
		$this->article('Disk at 1000 MB', 'Quota report.');
		$this->article('Mail_box size', 'Archive old mail.');
		$this->article('Mailbox rules', 'Use the web client.');

		$this->assertEquals(array('Disk at 100% full'), $this->titles('100%', $this->requester));
		$this->assertEquals(array('Mail_box size'), $this->titles('mail_box', $this->requester));
	}

	public function testBlankQueryListsEveryVisibleArticle()
	{
		$this->article('Printer jams', 'Open tray two.');
		$this->article('Laptop docking', 'Press the eject key first.');

		$this->assertEquals(array('Laptop docking', 'Printer jams'), $this->titles('   ', $this->requester));
	}

	public function testDraftsAreHiddenFromRequestersAndVisibleToAgents()
	{
		$this->article('Reset your VPN password', 'Published fix.');
		$this->article('VPN outage workaround', 'Still being written.', false);

		$this->assertEquals(array('Reset your VPN password'), $this->titles('vpn', $this->requester));
		$this->assertEquals(
			array('Reset your VPN password', 'VPN outage workaround'),
			$this->titles('vpn', $this->agent)
		);
	}

}
