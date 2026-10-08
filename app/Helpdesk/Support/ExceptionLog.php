<?php namespace Helpdesk\Support;

use Exception;
use Illuminate\Config\Repository;

/**
 * Text for logging an exception without leaking credentials.
 *
 * PHP 5.6 records call arguments in exception traces, so the default
 * (string) $exception of a failed new PDO($dsn, $user, $password) or
 * ldap_bind($link, $dn, $password) contains the password. This formatter
 * writes class, message, file and line of the exception and each previous
 * one, and a trace of function names only, and masks every configured
 * secret that still appears in a message.
 */
class ExceptionLog {

	const MASK = '<redacted>';

	/**
	 * Configured secret values: the app key, database, directory and mail
	 * passwords. Values shorter than four characters are left out, since
	 * masking them would garble ordinary text.
	 *
	 * @return array
	 */
	public static function secretsFrom(Repository $config)
	{
		$secrets = array($config->get('app.key'), $config->get('ldap.bind_password'), $config->get('mail.password'));

		foreach ((array) $config->get('database.connections') as $connection)
		{
			if (isset($connection['password'])) $secrets[] = $connection['password'];
		}

		return array_values(array_unique(array_filter($secrets, function($value)
		{
			return is_string($value) && strlen($value) >= 4;
		})));
	}

	/**
	 * @param  Exception  $exception
	 * @param  array      $secrets  values to mask
	 * @return string
	 */
	public static function format(Exception $exception, array $secrets)
	{
		$parts = array();

		for ($e = $exception; $e; $e = $e->getPrevious())
		{
			$parts[] = sprintf("%s%s: %s in %s:%d\nStack trace:\n%s",
				$e === $exception ? '' : 'Previous: ',
				get_class($e),
				$e->getMessage(),
				$e->getFile(),
				$e->getLine(),
				static::trace($e));
		}

		return static::mask(implode("\n", $parts), $secrets);
	}

	/**
	 * @param  string  $text
	 * @param  array   $secrets
	 * @return string
	 */
	public static function mask($text, array $secrets)
	{
		// Longest first, so a secret that contains another is masked whole.
		usort($secrets, function($a, $b) { return strlen($b) - strlen($a); });

		foreach ($secrets as $secret)
		{
			$text = str_replace($secret, static::MASK, $text);
		}

		return $text;
	}

	protected static function trace(Exception $e)
	{
		$lines = array();

		foreach ($e->getTrace() as $i => $frame)
		{
			$call = (isset($frame['class']) ? $frame['class'].$frame['type'] : '').$frame['function'].'()';
			$where = isset($frame['file']) ? $frame['file'].'('.$frame['line'].'): ' : '[internal function]: ';

			$lines[] = '#'.$i.' '.$where.$call;
		}

		$lines[] = '#'.count($lines).' {main}';

		return implode("\n", $lines);
	}

}
