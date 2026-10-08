<?php

use Carbon\Carbon;

class TicketAssetLinkTest extends TestCase {

	protected $service;
	protected $requester;
	protected $agent;
	protected $ticket;

	/** @var array names of the ticket.* events fired */
	protected $fired = array();

	public function setUp()
	{
		parent::setUp();

		Artisan::call('migrate');

		Carbon::setTestNow(Carbon::create(2014, 7, 1, 9, 0, 0));

		$this->requester = $this->makeUser('carol', 'requester');
		$this->agent = $this->makeUser('bob', 'agent');
		$category = Category::create(array('name' => 'Hardware'));

		$this->service = App::make('Helpdesk\Tickets\TicketService');
		$this->ticket = $this->service->create(array(
			'subject' => 'Laptop fan is loud',
			'description' => 'Constant noise since Monday.',
			'category_id' => $category->id,
		), $this->requester);

		$fired =& $this->fired;
		Event::listen('ticket.*', function() use (&$fired)
		{
			$fired[] = Event::firing();
		});
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

	protected function asset($tag)
	{
		$asset = new Asset;
		$asset->asset_tag = $tag;
		$asset->name = 'ThinkPad T440';
		$asset->type = 'laptop';
		$asset->status = 'in_use';
		$asset->save();

		return $asset;
	}

	public function testLinkingSetsTheAssetAndRecordsAnEvent()
	{
		$asset = $this->asset('LT-0101');

		$this->service->linkAsset($this->ticket, $asset, $this->agent);

		$ticket = Ticket::find($this->ticket->id);
		$this->assertEquals($asset->id, $ticket->asset_id);
		$this->assertSame('LT-0101', $ticket->asset->asset_tag);

		$event = TicketEvent::where('ticket_id', $ticket->id)->where('type', 'asset_linked')->first();
		$this->assertNotNull($event);
		$this->assertNull($event->from_value);
		$this->assertSame('LT-0101', $event->to_value);
		$this->assertEquals($this->agent->id, $event->user_id);
		$this->assertSame(array('ticket.asset_linked'), $this->fired);
	}

	public function testRelinkingRecordsThePreviousTag()
	{
		$first = $this->asset('LT-0101');
		$second = $this->asset('LT-0102');

		$this->service->linkAsset($this->ticket, $first, $this->agent);
		$this->service->linkAsset($this->ticket, $second, $this->agent);

		$event = TicketEvent::where('ticket_id', $this->ticket->id)->where('type', 'asset_linked')->orderBy('id', 'desc')->first();
		$this->assertSame('LT-0101', $event->from_value);
		$this->assertSame('LT-0102', $event->to_value);
		$this->assertEquals($second->id, Ticket::find($this->ticket->id)->asset_id);
	}

	public function testLinkingTheSameAssetAgainChangesNothing()
	{
		$asset = $this->asset('LT-0101');

		$this->service->linkAsset($this->ticket, $asset, $this->agent);
		$this->service->linkAsset($this->ticket, $asset, $this->agent);

		$this->assertSame(1, TicketEvent::where('ticket_id', $this->ticket->id)->where('type', 'asset_linked')->count());
		$this->assertSame(array('ticket.asset_linked'), $this->fired);
	}

	public function testRequesterCannotLinkAnAsset()
	{
		$asset = $this->asset('LT-0101');

		try
		{
			$this->service->linkAsset($this->ticket, $asset, $this->requester);
			$this->fail('A requester linked an asset.');
		}
		catch (Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException $e)
		{
			$this->assertNull(Ticket::find($this->ticket->id)->asset_id);
		}
	}

	public function testCreateStoresTheChosenAsset()
	{
		$asset = $this->asset('LT-0101');

		$ticket = $this->service->create(array(
			'subject' => 'Battery does not charge',
			'description' => 'Stays at 0%.',
			'category_id' => $this->ticket->category_id,
			'asset_id' => $asset->id,
		), $this->requester);

		$this->assertEquals($asset->id, Ticket::find($ticket->id)->asset_id);
		$this->assertEquals(array($ticket->id), $asset->tickets()->lists('id'));
	}

}
