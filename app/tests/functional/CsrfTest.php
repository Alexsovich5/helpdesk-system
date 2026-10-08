<?php

/**
 * Every POST, PUT, PATCH and DELETE route is covered by the csrf filter,
 * whatever filters the route itself lists, and the filter accepts only the
 * session's token given as a string.
 */
class CsrfTest extends TestCase {

	public function setUp()
	{
		parent::setUp();

		Artisan::call('migrate');
	}

	protected function admin()
	{
		$user = new User;
		$user->username = 'csrf-admin';
		$user->name = 'Csrf Admin';
		$user->email = 'csrf-admin@helpdesk.local';
		$user->role = 'admin';
		$user->source = 'local';
		$user->save();

		return $user;
	}

	/**
	 * @return array  of array(method, uri with parameters filled in)
	 */
	protected function stateChangingRoutes()
	{
		$routes = array();

		foreach (Route::getRoutes() as $route)
		{
			$uri = strtr($route->getPath(), array('{number}' => 'HD-00001', '{id}' => '1'));

			foreach (array_intersect($route->getMethods(), array('POST', 'PUT', 'PATCH', 'DELETE')) as $method)
			{
				$routes[] = array($method, '/'.ltrim($uri, '/'));
			}
		}

		return $routes;
	}

	/**
	 * @return string|null  the exception class thrown, or null when the request went through
	 */
	protected function attempt($method, $uri, array $data)
	{
		try
		{
			$this->call($method, $uri, $data);
		}
		catch (Exception $e)
		{
			return get_class($e);
		}

		return null;
	}

	public function testTheRouteListIncludesTheKnownWriteRoutes()
	{
		$routes = $this->stateChangingRoutes();

		$this->assertContains(array('POST', '/login'), $routes);
		$this->assertContains(array('POST', '/logout'), $routes);
		$this->assertContains(array('POST', '/tickets'), $routes);
		$this->assertContains(array('PUT', '/kb/1'), $routes);
		$this->assertContains(array('DELETE', '/assets/1'), $routes);
		$this->assertGreaterThanOrEqual(15, count($routes));
	}

	public function testEveryWriteRouteRejectsAMissingWrongOrNonStringToken()
	{
		$this->be($this->admin());
		$mismatch = 'Illuminate\Session\TokenMismatchException';
		$failures = array();

		foreach ($this->stateChangingRoutes() as $route)
		{
			list($method, $uri) = $route;

			foreach (array(
				'missing'   => array(),
				'wrong'     => array('_token' => str_repeat('x', 40)),
				'empty'     => array('_token' => ''),
				'array'     => array('_token' => array(Session::token())),
			) as $case => $data)
			{
				$thrown = $this->attempt($method, $uri, $data);

				if ($thrown !== $mismatch) $failures[] = "$method $uri ($case token): ".($thrown ?: 'accepted');
			}
		}

		$this->assertSame(array(), $failures, implode("\n", $failures));
	}

	public function testGuestsGetTheSameCheck()
	{
		$this->assertSame('Illuminate\Session\TokenMismatchException',
			$this->attempt('POST', '/tickets', array('subject' => 'x')));
	}

	public function testTheRightTokenStillWorks()
	{
		$this->be($this->admin());

		$response = $this->post('/kb/categories', array('name' => 'Printers'));

		$this->assertTrue($response->isRedirect());
		$this->assertSame(1, KbCategory::where('name', 'Printers')->count());
	}

	public function testHeaderTokenIsNotAcceptedInPlaceOfTheFormField()
	{
		$this->be($this->admin());

		$thrown = null;

		try
		{
			$this->call('POST', '/kb/categories', array('name' => 'Printers'), array(),
				array('HTTP_X_CSRF_TOKEN' => Session::token()));
		}
		catch (Exception $e)
		{
			$thrown = get_class($e);
		}

		$this->assertSame('Illuminate\Session\TokenMismatchException', $thrown);
		$this->assertSame(0, KbCategory::where('name', 'Printers')->count());
	}

	public function testLogoutIsAPostWithToken()
	{
		$this->be($this->admin());

		$this->assertSame(405, $this->statusOf('GET', '/logout'));
		$this->assertTrue(Auth::check());

		$response = $this->post('/logout');

		$this->assertSame(URL::to('login'), $response->headers->get('Location'));
		$this->assertFalse(Auth::check());
	}

	protected function statusOf($method, $uri)
	{
		try
		{
			return $this->call($method, $uri)->getStatusCode();
		}
		catch (Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e)
		{
			return $e->getStatusCode();
		}
	}

}
