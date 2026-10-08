<?php

return array(

	'driver' => 'fake',

	'user_filter' => '(uid=%s)',

	'role_map' => 'helpdesk-admins:admin,helpdesk-agents:agent',

	'fake_users' => array(
		'alice' => array('password' => 'password', 'groups' => array('helpdesk-admins'), 'name' => 'Alice Admin', 'email' => 'alice@helpdesk.local'),
		'bob'   => array('password' => 'password', 'groups' => array('helpdesk-agents'), 'name' => 'Bob Agent', 'email' => 'bob@helpdesk.local'),
		'carol' => array('password' => 'password', 'groups' => array(), 'name' => 'Carol Requester', 'email' => 'carol@helpdesk.local'),
	),

);
