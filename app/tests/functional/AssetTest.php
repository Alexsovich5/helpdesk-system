<?php

use Carbon\Carbon;

class AssetTest extends TestCase {

	protected $requester;
	protected $agent;
	protected $admin;
	protected $category;

	public function setUp()
	{
		parent::setUp();

		Artisan::call('migrate');

		Carbon::setTestNow(Carbon::create(2014, 7, 1, 9, 0, 0));

		$this->requester = $this->makeUser('carol', 'requester');
		$this->agent = $this->makeUser('bob', 'agent');
		$this->admin = $this->makeUser('alice', 'admin');
		$this->category = Category::create(array('name' => 'Hardware'));
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

	protected function asset($tag, array $attributes = array())
	{
		$asset = new Asset;
		$asset->asset_tag = $tag;
		$asset->name = 'ThinkPad T440';
		$asset->type = 'laptop';
		$asset->status = 'in_use';
		foreach ($attributes as $key => $value) $asset->$key = $value;
		$asset->save();

		return $asset;
	}

	protected function ticket($subject = 'Laptop fan is loud')
	{
		return App::make('Helpdesk\Tickets\TicketService')->create(array(
			'subject'     => $subject,
			'description' => 'Constant noise since Monday.',
			'category_id' => $this->category->id,
		), $this->requester);
	}

	protected function validInput(array $overrides = array())
	{
		return array_merge(array(
			'asset_tag'        => 'LT-0101',
			'name'             => 'ThinkPad T440',
			'type'             => 'laptop',
			'serial'           => 'PC0ABC12',
			'location'         => 'HQ 2nd floor',
			'status'           => 'in_use',
			'assigned_user_id' => (string) $this->requester->id,
		), $overrides);
	}

	public function testAdminCreatesAnAsset()
	{
		$this->be($this->admin);

		$this->assertSame(200, $this->call('GET', '/assets/create')->getStatusCode());

		$response = $this->post('/assets', $this->validInput());

		$asset = Asset::where('asset_tag', 'LT-0101')->first();
		$this->assertNotNull($asset);
		$this->assertSame(URL::to('assets/'.$asset->id), $response->headers->get('Location'));
		$this->assertSame('PC0ABC12', $asset->serial);
		$this->assertEquals($this->requester->id, $asset->assigned_user_id);

		$content = $this->call('GET', '/assets/'.$asset->id)->getContent();
		$this->assertContains('LT-0101', $content);
		$this->assertContains('Carol', $content);
	}

	public function testCreateValidatesTagUniquenessAndStatus()
	{
		$this->asset('LT-0101');
		$this->be($this->admin);

		$response = $this->post('/assets', $this->validInput(array('status' => 'lost', 'name' => '')));

		$this->assertSame(URL::to('assets/create'), $response->headers->get('Location'));
		$this->assertSessionHasErrors(array('asset_tag', 'status', 'name'));
		$this->assertSame(1, Asset::count());
	}

	public function testAdminUpdatesAnAssetAndEmptyOptionalFieldsBecomeNull()
	{
		$asset = $this->asset('LT-0101', array('serial' => 'PC0ABC12', 'assigned_user_id' => $this->requester->id));
		$this->be($this->admin);

		$this->assertSame(200, $this->call('GET', '/assets/'.$asset->id.'/edit')->getStatusCode());

		$response = $this->post('/assets/'.$asset->id, $this->validInput(array(
			'_method' => 'PUT', 'status' => 'repair', 'serial' => '', 'assigned_user_id' => '',
		)));

		$this->assertSame(URL::to('assets/'.$asset->id), $response->headers->get('Location'));
		$asset = Asset::find($asset->id);
		$this->assertSame('repair', $asset->status);
		$this->assertNull($asset->serial);
		$this->assertNull($asset->assigned_user_id);
	}

	public function testAgentCanViewButNotWrite()
	{
		$asset = $this->asset('LT-0101');
		$this->be($this->agent);

		$index = $this->call('GET', '/assets');
		$this->assertSame(200, $index->getStatusCode());
		$this->assertContains('LT-0101', $index->getContent());
		$this->assertSame(200, $this->call('GET', '/assets/'.$asset->id)->getStatusCode());

		$this->assertSame(403, $this->call('GET', '/assets/create')->getStatusCode());
		$this->assertSame(403, $this->post('/assets', $this->validInput(array('asset_tag' => 'LT-0999')))->getStatusCode());
		$this->assertSame(403, $this->call('GET', '/assets/'.$asset->id.'/edit')->getStatusCode());
		$this->assertSame(403, $this->post('/assets/'.$asset->id, $this->validInput(array('_method' => 'PUT', 'name' => 'Changed')))->getStatusCode());
		$this->assertSame(403, $this->post('/assets/'.$asset->id, array('_method' => 'DELETE'))->getStatusCode());

		$this->assertSame(1, Asset::count());
		$this->assertSame('ThinkPad T440', Asset::find($asset->id)->name);
	}

	public function testRequesterCannotOpenTheRegister()
	{
		$asset = $this->asset('LT-0101');
		$this->be($this->requester);

		$this->assertSame(403, $this->call('GET', '/assets')->getStatusCode());
		$this->assertSame(403, $this->call('GET', '/assets/'.$asset->id)->getStatusCode());
	}

	public function testIndexFiltersByTextAndStatus()
	{
		$this->asset('LT-0101', array('name' => 'ThinkPad T440'));
		$this->asset('PR-0007', array('name' => 'HP LaserJet', 'type' => 'printer', 'status' => 'in_stock'));
		$this->be($this->agent);

		$content = $this->call('GET', '/assets', array('q' => 'laserjet'))->getContent();
		$this->assertContains('PR-0007', $content);
		$this->assertNotContains('LT-0101', $content);

		$content = $this->call('GET', '/assets', array('status' => 'in_use'))->getContent();
		$this->assertContains('LT-0101', $content);
		$this->assertNotContains('PR-0007', $content);
	}

	public function testLinkingAnAssetToATicketShowsTheTicketOnTheAssetPage()
	{
		$asset = $this->asset('LT-0101');
		$ticket = $this->ticket();
		$other = $this->ticket('Printer offline');

		$this->be($this->agent);
		$response = $this->post('/tickets/'.$ticket->number.'/asset', array('asset_id' => $asset->id));

		$this->assertSame(URL::to('tickets/'.$ticket->number), $response->headers->get('Location'));
		$this->assertEquals($asset->id, Ticket::find($ticket->id)->asset_id);
		$this->assertSame(1, TicketEvent::where('ticket_id', $ticket->id)->where('type', 'asset_linked')->count());

		$content = $this->call('GET', '/assets/'.$asset->id)->getContent();
		$this->assertContains('href="'.URL::to('tickets/'.$ticket->number).'"', $content);
		$this->assertContains('Laptop fan is loud', $content);
		$this->assertNotContains($other->number, $content);

		$content = $this->call('GET', '/tickets/'.$ticket->number)->getContent();
		$this->assertContains('href="'.URL::to('assets/'.$asset->id).'"', $content);
		$this->assertContains('asset linked', $content);
	}

	public function testRequesterCannotLinkAnAssetAndUnknownAssetsAreRejected()
	{
		$asset = $this->asset('LT-0101');
		$ticket = $this->ticket();

		$this->be($this->requester);
		$this->assertSame(403, $this->post('/tickets/'.$ticket->number.'/asset', array('asset_id' => $asset->id))->getStatusCode());

		$this->be($this->agent);
		$this->post('/tickets/'.$ticket->number.'/asset', array('asset_id' => 9999));
		$this->assertSessionHasErrors('asset_id');

		$this->assertNull(Ticket::find($ticket->id)->asset_id);
	}

	public function testRequesterPicksOneOfTheirOwnAssetsWhenCreatingATicket()
	{
		$mine = $this->asset('LT-0101', array('assigned_user_id' => $this->requester->id));
		$theirs = $this->asset('LT-0102', array('assigned_user_id' => $this->agent->id));

		$this->be($this->requester);

		$form = $this->call('GET', '/tickets/create')->getContent();
		$this->assertContains('LT-0101', $form);
		$this->assertNotContains('LT-0102', $form);

		$this->post('/tickets', array(
			'subject' => 'Not my laptop', 'description' => 'x', 'category_id' => $this->category->id,
			'priority' => 'normal', 'asset_id' => $theirs->id,
		));
		$this->assertSessionHasErrors('asset_id');
		$this->assertSame(0, Ticket::count());

		$this->post('/tickets', array(
			'subject' => 'Fan noise', 'description' => 'x', 'category_id' => $this->category->id,
			'priority' => 'normal', 'asset_id' => $mine->id,
		));
		$ticket = Ticket::where('subject', 'Fan noise')->first();
		$this->assertEquals($mine->id, $ticket->asset_id);

		$this->post('/tickets', array(
			'subject' => 'No device', 'description' => 'x', 'category_id' => $this->category->id,
			'priority' => 'normal', 'asset_id' => '',
		));
		$this->assertNull(Ticket::where('subject', 'No device')->first()->asset_id);
	}

	public function testAgentMayPickAnyAssetWhenCreatingATicket()
	{
		$asset = $this->asset('LT-0102', array('assigned_user_id' => $this->requester->id));

		$this->be($this->agent);
		$this->assertContains('LT-0102', $this->call('GET', '/tickets/create')->getContent());

		$this->post('/tickets', array(
			'subject' => 'Fan noise', 'description' => 'x', 'category_id' => $this->category->id,
			'priority' => 'normal', 'asset_id' => $asset->id,
		));
		$this->assertEquals($asset->id, Ticket::where('subject', 'Fan noise')->first()->asset_id);
	}

	public function testAdminDeletesAnAssetOnlyWhenNoTicketReferencesIt()
	{
		$unused = $this->asset('LT-0101');
		$used = $this->asset('LT-0102');
		$ticket = $this->ticket();
		App::make('Helpdesk\Tickets\TicketService')->linkAsset($ticket, $used, $this->agent);

		$this->be($this->admin);

		$response = $this->post('/assets/'.$unused->id, array('_method' => 'DELETE'));
		$this->assertSame(URL::to('assets'), $response->headers->get('Location'));
		$this->assertNull(Asset::find($unused->id));

		$response = $this->post('/assets/'.$used->id, array('_method' => 'DELETE'));
		$this->assertSame(URL::to('assets/'.$used->id), $response->headers->get('Location'));
		$this->assertSessionHasErrors('asset');
		$this->assertNotNull(Asset::find($used->id));
	}

	public function testAssetFieldsAreEscaped()
	{
		$asset = $this->asset('LT-0101', array('name' => '<script>alert(1)</script>'));
		$this->be($this->agent);

		$content = $this->call('GET', '/assets/'.$asset->id)->getContent();
		$this->assertNotContains('<script>alert(1)</script>', $content);
		$this->assertContains('&lt;script&gt;', $content);
	}

	public function testUnknownAssetIs404()
	{
		$this->be($this->agent);

		$this->assertSame(404, $this->call('GET', '/assets/9999')->getStatusCode());
	}

}
