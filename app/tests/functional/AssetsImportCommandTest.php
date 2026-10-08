<?php

use Symfony\Component\Console\Output\BufferedOutput;

class AssetsImportCommandTest extends TestCase {

	public function setUp()
	{
		parent::setUp();

		Artisan::call('migrate');

		$carol = new User;
		$carol->username = 'carol';
		$carol->name = 'Carol';
		$carol->email = 'carol@helpdesk.local';
		$carol->role = 'requester';
		$carol->source = 'local';
		$carol->save();

		$existing = new Asset;
		$existing->asset_tag = 'LT-0042';
		$existing->name = 'Latitude';
		$existing->type = 'laptop';
		$existing->status = 'in_use';
		$existing->save();
	}

	/**
	 * Runs assets:import and returns array(exit status, trimmed output).
	 */
	protected function import($file)
	{
		$output = new BufferedOutput;
		$status = Artisan::call('assets:import', array('file' => $file), $output);

		return array($status, trim($output->fetch()));
	}

	public function testImportsTheFixtureAndPrintsCounts()
	{
		list($status, $output) = $this->import(app_path().'/tests/fixtures/assets.csv');

		$this->assertSame(0, $status);
		$lines = explode("\n", $output);
		$this->assertSame('created 2, updated 1, skipped 1', $lines[0]);
		$this->assertContains('line 5', $output);
		$this->assertContains('lost', $output);

		$this->assertSame(3, Asset::count());
		$this->assertSame('repair', Asset::where('asset_tag', 'LT-0042')->first()->status);
		$this->assertSame('carol', Asset::where('asset_tag', 'LT-0101')->first()->assignedUser->username);
	}

	public function testRunningTheSameFileTwiceUpdatesInsteadOfDuplicating()
	{
		$this->import(app_path().'/tests/fixtures/assets.csv');
		list(, $output) = $this->import(app_path().'/tests/fixtures/assets.csv');

		$this->assertStringStartsWith('created 0, updated 3, skipped 1', $output);
		$this->assertSame(3, Asset::count());
	}

	public function testAnUnreadableFileFailsWithANonZeroStatus()
	{
		list($status, $output) = $this->import('/nonexistent/assets.csv');

		$this->assertSame(1, $status);
		$this->assertContains('not readable', $output);
		$this->assertSame(1, Asset::count());
	}

}
