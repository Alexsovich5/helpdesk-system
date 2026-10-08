<?php

/**
 * @group integration
 */
class MysqlConnectionTest extends IntegrationTestCase {

	public function testRunsInTheIntegrationEnvironment()
	{
		$this->assertSame('integration', App::environment());
	}

	public function testUsesTheMysqlDriver()
	{
		$this->assertSame('mysql', DB::connection()->getDriverName());
	}

	public function testServerIsMysql56()
	{
		$rows = DB::select('SELECT VERSION() AS v');

		$this->assertStringStartsWith('5.6', $rows[0]->v);
	}

	public function testUsesTheTestDatabaseNotTheDemoDatabase()
	{
		$this->assertSame('helpdesk_test', DB::connection()->getDatabaseName());
	}

}
