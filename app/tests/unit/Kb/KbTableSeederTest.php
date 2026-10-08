<?php

class KbTableSeederTest extends TestCase {

	public function setUp()
	{
		parent::setUp();

		Artisan::call('migrate');
	}

	public function testSeedsCategoriesAndPublishedArticlesOnceWhenRunTwice()
	{
		$agent = new User;
		$agent->username = 'bob';
		$agent->name = 'Bob';
		$agent->email = 'bob@helpdesk.local';
		$agent->role = 'agent';
		$agent->source = 'local';
		$agent->save();

		$seeder = new KbTableSeeder;
		$seeder->run();
		$seeder->run();

		$this->assertEquals(array_keys(KbTableSeeder::$articles), KbCategory::orderBy('id')->lists('name'));

		$expected = 0;
		foreach (KbTableSeeder::$articles as $articles) $expected += count($articles);

		$this->assertSame($expected, KbArticle::count());
		$this->assertSame($expected, KbArticle::published()->count());
		$this->assertSame(array($agent->id), array_map('intval', array_unique(KbArticle::lists('author_id'))));
	}

	public function testSkipsArticlesWhenThereIsNoAgentToAuthorThem()
	{
		$seeder = new KbTableSeeder;
		$seeder->run();

		$this->assertSame(count(KbTableSeeder::$articles), KbCategory::count());
		$this->assertSame(0, KbArticle::count());
	}

}
