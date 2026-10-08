<?php

class TestCase extends Illuminate\Foundation\Testing\TestCase {

	/**
	 * Creates the application.
	 *
	 * @return \Symfony\Component\HttpKernel\HttpKernelInterface
	 */
	public function createApplication()
	{
		$unitTesting = true;

		$testEnvironment = 'testing';

		return require __DIR__.'/../../bootstrap/start.php';
	}

	/**
	 * Laravel disables route filters in the "testing" environment; turn them
	 * back on so auth, role and csrf filters run in tests, and start the
	 * session so a CSRF token exists.
	 */
	public function setUp()
	{
		parent::setUp();

		Route::enableFilters();

		Session::start();
	}

	/**
	 * POST to a URI with the session's CSRF token added to the data.
	 *
	 * @param  string  $uri
	 * @param  array   $data
	 * @return \Illuminate\Http\Response
	 */
	protected function post($uri, array $data = array())
	{
		return $this->call('POST', $uri, array_merge(array('_token' => Session::token()), $data));
	}

}
