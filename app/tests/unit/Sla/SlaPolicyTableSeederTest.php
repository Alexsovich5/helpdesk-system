<?php

class SlaPolicyTableSeederTest extends TestCase {

	public function testSeedsDefaultTargetsOncePerPriority()
	{
		Artisan::call('migrate');

		$seeder = new SlaPolicyTableSeeder;
		$seeder->run();
		$seeder->run();

		$rows = array();
		foreach (SlaPolicy::orderBy('id')->get() as $policy)
		{
			$rows[$policy->priority] = array((int) $policy->response_minutes, (int) $policy->resolution_minutes);
		}

		$this->assertSame(array(
			'urgent' => array(30, 240),
			'high'   => array(60, 480),
			'normal' => array(240, 1440),
			'low'    => array(480, 4320),
		), $rows);
	}

	public function testForPriorityFindsThePolicy()
	{
		Artisan::call('migrate');
		(new SlaPolicyTableSeeder)->run();

		$this->assertSame(60, (int) SlaPolicy::forPriority('high')->response_minutes);
		$this->assertNull(SlaPolicy::forPriority('unknown'));
	}

}
