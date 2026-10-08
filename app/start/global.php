<?php

/*
|--------------------------------------------------------------------------
| Register The Laravel Class Loader
|--------------------------------------------------------------------------
|
| In addition to using Composer, you may use the Laravel class loader to
| load your controllers and models. This is useful for keeping all of
| your classes in the "global" namespace without Composer updating.
|
*/

ClassLoader::addDirectories(array(

	app_path().'/commands',
	app_path().'/controllers',
	app_path().'/models',
	app_path().'/database/seeds',

));

/*
|--------------------------------------------------------------------------
| Application Error Logger
|--------------------------------------------------------------------------
|
| Here we will configure the error logger setup for the application which
| is built on top of the wonderful Monolog library. By default we will
| build a basic log file setup which creates a single file for logs.
|
*/

Log::useFiles(storage_path().'/logs/laravel.log');

/*
|--------------------------------------------------------------------------
| Application Error Handler
|--------------------------------------------------------------------------
|
| Here you may handle any errors that occur in your application, including
| logging them or displaying custom views for specific errors. You may
| even register several error handlers to handle different types of
| exceptions. If nothing is returned, the default error view is
| shown, which includes a detailed stack trace during debug.
|
*/

App::error(function(Exception $exception, $code)
{
	// Class, message, file, line and a trace without call arguments, with
	// configured passwords and the app key masked (PHP 5.6 traces include
	// arguments, such as the password given to new PDO()).
	// A failure to log must not stop the error page: Laravel would turn an
	// exception thrown here into a 200 response.
	try
	{
		Log::error(Helpdesk\Support\ExceptionLog::format($exception,
			Helpdesk\Support\ExceptionLog::secretsFrom(App::make('config'))));
	}
	catch (Exception $e)
	{
		error_log('helpdesk: could not write the application log: '.get_class($e));
	}
});

App::error(function(Illuminate\Session\TokenMismatchException $exception)
{
	return Response::make('The form had expired or did not come from this site. Go back, reload the page and try again.', 403);
});

App::error(function(UnexpectedValueException $exception)
{
	if (preg_match('/^(Untrusted|Invalid) Host/', $exception->getMessage())) return Response::make('Bad Request', 400);
});

/*
|--------------------------------------------------------------------------
| Encryption Key and Application URL
|--------------------------------------------------------------------------
|
| Refuse to run without a usable APP_KEY (the app container generates one
| on first start, see docker/app/entrypoint.sh). Every generated URL, in
| web requests and console commands alike, is rooted at app.url, which
| must come from APP_URL in production.
|
*/

Helpdesk\Security\AppKey::assertUsable(Config::get('app.key'));

Config::set('app.url', Helpdesk\Http\HostGuard::rootUrl(App::environment(),
	App::environment('production') ? getenv('APP_URL') : Config::get('app.url')));

Helpdesk\Http\HostGuard::apply(App::make('url'), Config::get('app.url'));

/*
|--------------------------------------------------------------------------
| Maintenance Mode Handler
|--------------------------------------------------------------------------
|
| The "down" Artisan command gives you the ability to put an application
| into maintenance mode. Here, you will define what is displayed back
| to the user if maintenance mode is in effect for the application.
|
*/

App::down(function()
{
	return Response::make("Be right back!", 503);
});

/*
|--------------------------------------------------------------------------
| Require The Filters File
|--------------------------------------------------------------------------
|
| Next we will load the filters file for the application. This gives us
| a nice separate location to store our route and application filter
| definitions instead of putting them all in the main routes file.
|
*/

require app_path().'/filters.php';

/*
|--------------------------------------------------------------------------
| Ticket Notifications
|--------------------------------------------------------------------------
|
| E-mail the people involved when a ticket is created, assigned, commented
| on, changes status or moves into SLA warning or breach.
|
*/

Event::subscribe('Helpdesk\Notifications\TicketNotifier');
