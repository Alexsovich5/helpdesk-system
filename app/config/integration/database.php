<?php

return array(

	'default' => 'mysql',

	'connections' => array(

		'mysql' => array(
			'driver'    => 'mysql',
			'host'      => getenv('DB_HOST') ?: 'db',
			'database'  => getenv('DB_TEST_DATABASE') ?: 'helpdesk_test',
			'username'  => getenv('DB_USERNAME') ?: 'helpdesk',
			'password'  => getenv('DB_PASSWORD') ?: 'helpdesk',
			'charset'   => 'utf8',
			'collation' => 'utf8_unicode_ci',
			'prefix'    => '',
		),

	),

);
