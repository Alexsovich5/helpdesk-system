<?php

use Carbon\Carbon;
use Symfony\Component\Console\Output\BufferedOutput;

class SlaCheckCommandTest extends TestCase {

	protected $tickets = array();

	public function setUp()
	{
		parent::setUp();

		Artisan::call('migrate');
		(new SlaPolicyTableSeeder)->run();

		$requester = new User;
		$requester->username = 'carol';
		$requester->name = 'Carol';
		$requester->email = 'carol@helpdesk.local';
		$requester->role = 'requester';
		$requester->source = 'local';
		$requester->save();

		$category = Category::create(array('name' => 'Software'));
		$service = App::make('Helpdesk\Tickets\TicketService');

		// At 09:25: the 08:00 urgent ticket is past its 08:30 response due time,
		// the 09:00 urgent one has used 25 of 30 minutes, the normal one is fine.
		$created = array(
			'breached' => array(Carbon::create(2014, 7, 1, 8, 0, 0), 'urgent'),
			'warning'  => array(Carbon::create(2014, 7, 1, 9, 0, 0), 'urgent'),
			'ok'       => array(Carbon::create(2014, 7, 1, 9, 0, 0), 'normal'),
		);

		foreach ($created as $expected => $spec)
		{
			Carbon::setTestNow($spec[0]);
			$this->tickets[$expected] = $service->create(array(
				'subject'     => 'Outlook keeps asking for the password',
				'description' => 'Prompt returns after every restart.',
				'category_id' => $category->id,
				'priority'    => $spec[1],
			), $requester);
		}

		Carbon::setTestNow(Carbon::create(2014, 7, 1, 9, 25, 0));
	}

	/**
	 * Runs sla:check and returns array(exit status, trimmed output).
	 */
	protected function slaCheck(array $options = array())
	{
		$output = new BufferedOutput;
		$status = Artisan::call('sla:check', $options, $output);

		return array($status, trim($output->fetch()));
	}

	public function tearDown()
	{
		Carbon::setTestNow();

		parent::tearDown();
	}

	public function testPrintsCountsAndUpdatesStates()
	{
		list($status, $output) = $this->slaCheck();

		$this->assertSame(0, $status);
		$this->assertSame('checked 3, warning 1, breached 1', $output);

		foreach ($this->tickets as $expected => $ticket)
		{
			$this->assertSame($expected, Ticket::find($ticket->id)->sla_state);
		}
	}

	public function testDryRunPrintsCountsWithoutChangingTickets()
	{
		list(, $output) = $this->slaCheck(array('--dry-run' => true));

		$this->assertSame('checked 3, warning 1, breached 1', $output);

		foreach ($this->tickets as $ticket)
		{
			$this->assertSame('ok', Ticket::find($ticket->id)->sla_state);
		}
		$this->assertSame(0, TicketEvent::whereIn('type', array('sla_warning', 'sla_breached'))->count());
	}

}
