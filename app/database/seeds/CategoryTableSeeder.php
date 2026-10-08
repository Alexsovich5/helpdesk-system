<?php

class CategoryTableSeeder extends Seeder {

	/**
	 * Ticket categories every installation starts with. Safe to run again:
	 * existing names are left alone.
	 */
	public static $names = array(
		'Hardware',
		'Software',
		'Network',
		'Accounts & Access',
		'E-mail',
		'Other',
	);

	public function run()
	{
		foreach (static::$names as $name)
		{
			Category::firstOrCreate(array('name' => $name));
		}
	}

}
