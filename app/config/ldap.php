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

	// Only groups below this DN count for role mapping (null: ou=groups,<base_dn>).
	// Active Directory example: OU=Helpdesk Groups,DC=corp,DC=example.
	'group_base_dn' => getenv('LDAP_GROUP_BASE_DN') ?: null,

	// Service account used to search for users.
	'bind_dn' => getenv('LDAP_BIND_DN') ?: 'cn=admin,dc=helpdesk,dc=local',

	'bind_password' => getenv('LDAP_BIND_PASSWORD') ?: 'admin',

	// OpenLDAP: (uid=%s). Active Directory: (sAMAccountName=%s).
	'user_filter' => getenv('LDAP_USER_FILTER') ?: '(uid=%s)',

	'username_attribute' => getenv('LDAP_USERNAME_ATTRIBUTE') ?: 'uid',

	// Full group DN => role; the highest mapped role wins, otherwise
	// requester. LDAP_ROLE_MAP overrides it as "dn:role;dn:role", e.g.
	// "CN=Helpdesk Admins,OU=Helpdesk Groups,DC=corp,DC=example:admin".
	'role_map' => getenv('LDAP_ROLE_MAP') ?: array(
		'cn=helpdesk-admins,ou=groups,dc=helpdesk,dc=local' => 'admin',
		'cn=helpdesk-agents,ou=groups,dc=helpdesk,dc=local' => 'agent',
	),

	// Staff notifications go only to directory agents whose role was
	// confirmed (at login or by users:sync-roles) within this many days.
	'role_max_age_days' => (int) (getenv('LDAP_ROLE_MAX_AGE_DAYS') ?: 30),

	'fake_users' => array(),

);
