<?php

class SlaPolicyTableSeeder extends Seeder {

	/**
	 * Default targets per priority: array(response minutes, resolution minutes).
	 * Safe to run again: a priority that already has a policy is left alone.
	 */
	public static $defaults = array(
		'urgent' => array(30, 240),
		'high'   => array(60, 480),
		'normal' => array(240, 1440),
		'low'    => array(480, 4320),
	);

	public function run()
	{
		foreach (static::$defaults as $priority => $minutes)
		{
			if (SlaPolicy::forPriority($priority)) continue;

			SlaPolicy::create(array(
				'priority'           => $priority,
				'response_minutes'   => $minutes[0],
				'resolution_minutes' => $minutes[1],
			));
		}
	}

}
