<?php

return array(

	/*
	| Directory implementation: "native" uses ext-ldap, "fake" an in-memory
	| directory built from fake_users (tests only).
	*/
	'driver' => 'native',

	'host' => getenv('LDAP_HOST') ?: 'ldap',

	'port' => getenv('LDAP_PORT') ?: 389,

	'base_dn' => getenv('LDAP_BASE_DN') ?: 'dc=helpdesk,dc=local',

	// Service account used to search for users.
	'bind_dn' => getenv('LDAP_BIND_DN') ?: 'cn=admin,dc=helpdesk,dc=local',

	'bind_password' => getenv('LDAP_BIND_PASSWORD') ?: 'admin',

	// OpenLDAP: (uid=%s). Active Directory: (sAMAccountName=%s).
	'user_filter' => getenv('LDAP_USER_FILTER') ?: '(uid=%s)',

	'username_attribute' => getenv('LDAP_USERNAME_ATTRIBUTE') ?: 'uid',

	// group:role pairs; the highest mapped role wins, otherwise requester.
	'role_map' => getenv('LDAP_ROLE_MAP') ?: 'helpdesk-admins:admin,helpdesk-agents:agent',

	'fake_users' => array(),

);
