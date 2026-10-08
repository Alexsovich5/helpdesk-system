<?php namespace Helpdesk\Security;

/**
 * Comparison used by the csrf route filter.
 */
class CsrfToken {

	/**
	 * True only when both tokens are non-empty strings with the same bytes.
	 * The comparison takes the same time wherever the strings differ.
	 *
	 * @param  mixed  $sessionToken
	 * @param  mixed  $given
	 * @return bool
	 */
	public static function matches($sessionToken, $given)
	{
		if ( ! is_string($sessionToken) || ! is_string($given)) return false;

		if ($sessionToken === '' || $given === '') return false;

		return hash_equals($sessionToken, $given);
	}

}
