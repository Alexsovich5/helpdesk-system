<?php

return array(

	'driver' => 'fake',

	'user_filter' => '(uid=%s)',

	'role_map' => array(
		'cn=helpdesk-admins,ou=groups,dc=helpdesk,dc=local' => 'admin',
		'cn=helpdesk-agents,ou=groups,dc=helpdesk,dc=local' => 'agent',
	),

	'fake_users' => array(
		'alice' => array('password' => 'password', 'groups' => array('cn=helpdesk-admins,ou=groups,dc=helpdesk,dc=local'), 'name' => 'Alice Admin', 'email' => 'alice@helpdesk.local'),
		'bob'   => array('password' => 'password', 'groups' => array('cn=helpdesk-agents,ou=groups,dc=helpdesk,dc=local'), 'name' => 'Bob Agent', 'email' => 'bob@helpdesk.local'),
		'carol' => array('password' => 'password', 'groups' => array(), 'name' => 'Carol Requester', 'email' => 'carol@helpdesk.local'),
	),

);
