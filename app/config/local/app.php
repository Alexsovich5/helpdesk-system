<?php

return array(

	/*
	|--------------------------------------------------------------------------
	| Application Debug Mode
	|--------------------------------------------------------------------------
	|
	| Detailed error pages show stack traces and environment variables, so
	| they are off unless APP_DEBUG=true is set for a development session.
	|
	*/

	'debug' => filter_var(getenv('APP_DEBUG'), FILTER_VALIDATE_BOOLEAN),

);
