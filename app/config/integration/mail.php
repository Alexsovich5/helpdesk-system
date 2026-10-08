<?php

/*
| Messages are sent over SMTP to the smtp-sink compose service, which
| writes each one to the shared mail-sink volume.
*/

return array(

	'pretend' => false,

	'host' => getenv('MAIL_HOST') ?: 'smtp-sink',

	'port' => getenv('MAIL_PORT') ?: 1025,

);
