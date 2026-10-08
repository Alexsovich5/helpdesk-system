<?php

/**
 * Base class for tests that run against the compose services (MySQL and,
 * in later suites, the directory and mail simulators).
 *
 * The application boots in the "integration" environment, so the configs in
 * app/config/integration/* apply and the database is helpdesk_test. Migrations
 * run once per test class and every test runs inside a transaction that is
 * rolled back afterwards. Tests that run DDL themselves set
 * $useTransaction = false.
 */
abstract class IntegrationTestCase extends TestCase {

	/**
	 * Wrap each test in a transaction that is rolled back in tearDown().
	 *
	 * @var bool
	 */
	protected $useTransaction = true;

	/**
	 * Test classes that have already run the migrations.
	 *
	 * @var array
	 */
	protected static $migrated = array();

	public function createApplication()
	{
		$unitTesting = true;

		$testEnvironment = 'integration';

		return require __DIR__.'/../../bootstrap/start.php';
	}

	public function setUp()
	{
		parent::setUp();

		$class = get_class($this);

		if ( ! isset(static::$migrated[$class]))
		{
			Artisan::call('migrate');

			static::$migrated[$class] = true;
		}

		if ($this->useTransaction)
		{
			DB::beginTransaction();
		}
	}

	public function tearDown()
	{
		if ($this->useTransaction)
		{
			DB::rollBack();
		}

		parent::tearDown();
	}

}
