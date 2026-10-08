<?php

class EnvironmentTest extends TestCase {

	public function testRunsInTheTestingEnvironment()
	{
		$this->assertSame('testing', App::environment());
	}

	public function testFrameworkIsLaravel42()
	{
		$this->assertStringStartsWith('4.2.', Illuminate\Foundation\Application::VERSION);
	}

	public function testRuntimeIsPhp56()
	{
		$this->assertStringStartsWith('5.6.', PHP_VERSION);
	}

	public function testRequiredExtensionsAreLoaded()
	{
		foreach (array('mcrypt', 'ldap', 'pdo_mysql') as $extension)
		{
			$this->assertTrue(extension_loaded($extension), "extension $extension is not loaded");
		}
	}

	public function testRouteFiltersAreEnabled()
	{
		Route::filter('environment-test-block', function()
		{
			return Response::make('blocked by filter', 418);
		});

		Route::get('environment-test-filtered', array('before' => 'environment-test-block', function()
		{
			return 'reached the route';
		}));

		$response = $this->call('GET', '/environment-test-filtered');

		$this->assertSame(418, $response->getStatusCode());
		$this->assertSame('blocked by filter', $response->getContent());
	}

	public function testHomePageResponds()
	{
		$response = $this->call('GET', '/');

		$this->assertSame(200, $response->getStatusCode());
	}

}
