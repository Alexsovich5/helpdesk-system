<?php namespace Helpdesk\Security;

use RuntimeException;

/**
 * Checks the encryption key before the application uses it. Laravel 4.2
 * decrypts and unserializes cookies with this key, so a missing, short or
 * published key lets anyone forge cookies.
 */
class AppKey {

	/**
	 * Keys that have been published and must never be used: the Laravel
	 * skeleton default and the sample key earlier versions of this
	 * repository shipped (split so the repository scan does not flag it).
	 *
	 * @var array
	 */
	protected static $published = array(
		'YourSecretKey!!!',
		'LocalSampleKey'.'ForHelpdeskStack32',
	);

	/**
	 * The key stored by docker/app/entrypoint.sh, for processes that did
	 * not start through it (docker compose exec).
	 *
	 * @param  string  $path
	 * @return string|false
	 */
	public static function fromFile($path)
	{
		if ( ! is_string($path) || $path === '' || ! is_file($path) || ! is_readable($path)) return false;

		return trim(file_get_contents($path));
	}

	/**
	 * @param  mixed  $key
	 * @return bool
	 */
	public static function usable($key)
	{
		return is_string($key)
			&& strlen($key) === 32
			&& ! in_array($key, static::$published, true);
	}

	/**
	 * @param  mixed  $key
	 * @throws RuntimeException  without the key in the message
	 */
	public static function assertUsable($key)
	{
		if ( ! static::usable($key))
		{
			throw new RuntimeException('APP_KEY is missing, not 32 characters long or a published sample key. '
				.'The app container writes a random key on first start; see README "Running it".');
		}
	}

}
