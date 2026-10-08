<?php

return array(

	// Integration tests talk to the OpenLDAP simulator (compose service "ldap").
	'driver' => 'native',

	'host' => getenv('LDAP_HOST') ?: 'ldap',

);
