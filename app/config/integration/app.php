<?php

return array(

	// Each test run uses its own random key unless APP_KEY is set.
	'key' => getenv('APP_KEY') ?: str_random(32),

);
