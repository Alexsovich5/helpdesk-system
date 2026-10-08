<?php

use Helpdesk\Support\ExceptionLog;

/**
 * A sentinel password configured for the database, the directory and the
 * mailer must never reach the log file, the error page or the logged
 * exception text when the connection that uses it fails.
 *
 * PHP 5.6 writes call arguments into exception traces, so a failed
 * new PDO($dsn, $user, $password) carries the password in its trace.
 */
class SecretRedactionTest extends TestCase {

	const SENTINEL = 'S3NTINEL-pw';

	protected $log;

	public function setUp()
	{
		parent::setUp();

		$this->log = tempnam(sys_get_temp_dir(), 'redaction');
		$monolog = Log::getMonolog();
		while (count($monolog->getHandlers())) $monolog->popHandler();
		Log::useFiles($this->log);

		Config::set('database.connections.sentinel', array(
			'driver'    => 'mysql',
			'host'      => '127.0.0.1',
			'port'      => 1,
			'database'  => 'helpdesk',
			'username'  => 'helpdesk',
			'password'  => static::SENTINEL,
			'charset'   => 'utf8',
			'collation' => 'utf8_unicode_ci',
			'prefix'    => '',
		));
		Config::set('ldap.bind_password', static::SENTINEL.'-ldap');
		Config::set('mail.password', static::SENTINEL.'-mail');
	}

	public function tearDown()
	{
		@unlink($this->log);

		parent::tearDown();
	}

	/**
	 * @return \Exception  the PDOException from a refused connection
	 */
	protected function failedConnection()
	{
		try
		{
			DB::connection('sentinel')->getPdo();
		}
		catch (Exception $e)
		{
			return $e;
		}

		$this->fail('the sentinel connection unexpectedly worked');
	}

	public function testThePlainTraceWouldLeakTheSentinel()
	{
		$this->assertContains(static::SENTINEL, $this->failedConnection()->getTraceAsString());
	}

	public function testFormattedExceptionHasNoSecretsButKeepsTheFacts()
	{
		$e = $this->failedConnection();

		$text = ExceptionLog::format($e, ExceptionLog::secretsFrom(App::make('config')));

		$this->assertNotContains(static::SENTINEL, $text);
		$this->assertContains('PDOException', $text);
		$this->assertContains($e->getMessage(), $text);
		$this->assertContains('PDO->__construct()', $text);
	}

	public function testConfiguredSecretsInTheMessageAreMasked()
	{
		$e = new RuntimeException('bind as cn=admin with '.static::SENTINEL.'-ldap failed');

		$text = ExceptionLog::format($e, ExceptionLog::secretsFrom(App::make('config')));

		$this->assertNotContains(static::SENTINEL, $text);
		$this->assertContains('<redacted>', $text);
	}

	public function testPreviousExceptionsAreFormattedTheSameWay()
	{
		$e = new RuntimeException('query failed', 0, $this->failedConnection());

		$text = ExceptionLog::format($e, array());

		$this->assertContains('PDOException', $text);
		$this->assertNotContains(static::SENTINEL, $text);
	}

	public function testSecretsAreCollectedFromConfig()
	{
		$secrets = ExceptionLog::secretsFrom(App::make('config'));

		$this->assertContains(static::SENTINEL, $secrets);
		$this->assertContains(static::SENTINEL.'-ldap', $secrets);
		$this->assertContains(static::SENTINEL.'-mail', $secrets);
		$this->assertContains(Config::get('app.key'), $secrets);
	}

	public function testErrorHandlerLogsAndShowsNoSecret()
	{
		$handler = App::make('exception');
		$handler->setDebug(false);

		$response = $handler->handleException($this->failedConnection());
		$logged = file_get_contents($this->log);

		$this->assertSame(500, $response->getStatusCode());
		$this->assertNotContains(static::SENTINEL, $response->getContent());
		$this->assertContains('PDOException', $logged);
		$this->assertNotContains(static::SENTINEL, $logged);
		$this->assertNotContains(Config::get('app.key'), $logged);
	}

	public function testAFailingLogStillGivesA500Page()
	{
		// Laravel turns an exception thrown inside an error handler into a
		// 200 response reading "Error in exception handler".
		Log::shouldReceive('error')->andThrow(new RuntimeException('log file is not writable'));

		$handler = App::make('exception');
		$handler->setDebug(false);

		$response = $handler->handleException(new RuntimeException('boom'));

		$this->assertSame(500, $response->getStatusCode());
		$this->assertNotContains('Error in exception handler', $response->getContent());
	}

	public function testConsoleErrorsAreLoggedWithoutSecrets()
	{
		App::make('exception')->handleConsole($this->failedConnection());

		$logged = file_get_contents($this->log);

		$this->assertContains('PDOException', $logged);
		$this->assertNotContains(static::SENTINEL, $logged);
	}

}
