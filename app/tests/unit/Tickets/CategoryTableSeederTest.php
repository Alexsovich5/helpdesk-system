<?php

class CategoryTableSeederTest extends TestCase {

	public function testSeedsDefaultCategoriesOnceWhenRunTwice()
	{
		Artisan::call('migrate');

		$seeder = new CategoryTableSeeder;
		$seeder->run();
		$seeder->run();

		$this->assertEquals(CategoryTableSeeder::$names, Category::orderBy('id')->lists('name'));
	}

}
