<?php

/*
|--------------------------------------------------------------------------
| Application & Route Filters
|--------------------------------------------------------------------------
|
| Below you will find the "before" and "after" events for the application
| which may be used to do any work before or after a request into your
| application. Here you may also register your custom route filters.
|
*/

App::before(function($request)
{
	// Links are rooted at app.url; a Host header that is not app.url's host
	// or listed in app.trusted_hosts is refused.
	$trusted = Helpdesk\Http\HostGuard::check($request, App::make('url'),
		Config::get('app.url'), Config::get('app.trusted_hosts'));

	if ( ! $trusted) return Response::make('Bad Request', 400);

	// A user deactivated while signed in is signed out on their next request.
	if (Auth::check() && ! Auth::user()->active) Auth::logout();
});


App::after(function($request, $response)
{
	//
});

/*
|--------------------------------------------------------------------------
| Authentication Filters
|--------------------------------------------------------------------------
|
| The following filters are used to verify that the user of the current
| session is logged into this application. The "basic" filter easily
| integrates HTTP Basic authentication for quick, simple checking.
|
*/

Route::filter('auth', function()
{
	if (Auth::guest())
	{
		if (Request::ajax())
		{
			return Response::make('Unauthorized', 401);
		}
		else
		{
			return Redirect::guest('login');
		}
	}
});


Route::filter('auth.basic', function()
{
	return Auth::basic();
});

/*
|--------------------------------------------------------------------------
| Role Filter
|--------------------------------------------------------------------------
|
| "role:agent" lets agents and admins through, "role:admin" only admins.
| Guests are sent to the login page; signed-in users without the role get
| a 403.
|
*/

Route::filter('role', function($route, $request, $role)
{
	if (Auth::guest())
	{
		return Redirect::guest('login');
	}

	if ( ! Auth::user()->hasRole($role))
	{
		return Response::make('Forbidden', 403);
	}
});

/*
|--------------------------------------------------------------------------
| Ticket Access Filter
|--------------------------------------------------------------------------
|
| For routes with a {number} parameter: agents may open any ticket, other
| users only the tickets they raised. Unknown numbers get a 404.
|
*/

Route::filter('ticket.access', function($route)
{
	if (Auth::guest())
	{
		return Redirect::guest('login');
	}

	$ticket = Ticket::findByNumber($route->getParameter('number'));

	if (is_null($ticket))
	{
		return Response::make('Not Found', 404);
	}

	if ( ! Auth::user()->isAgent() && ! $ticket->isOwnedBy(Auth::user()))
	{
		return Response::make('Forbidden', 403);
	}
});

/*
|--------------------------------------------------------------------------
| Guest Filter
|--------------------------------------------------------------------------
|
| The "guest" filter is the counterpart of the authentication filters as
| it simply checks that the current user is not logged in. A redirect
| response will be issued if they are, which you may freely change.
|
*/

Route::filter('guest', function()
{
	if (Auth::check()) return Redirect::to('/');
});

/*
|--------------------------------------------------------------------------
| CSRF Protection Filter
|--------------------------------------------------------------------------
|
| Applied to every POST, PUT, PATCH and DELETE request (app/routes.php).
| The form field _token must be a string equal to the session's token.
|
*/

Route::filter('csrf', function()
{
	if ( ! Helpdesk\Security\CsrfToken::matches(Session::token(), Input::get('_token')))
	{
		throw new Illuminate\Session\TokenMismatchException;
	}
});
