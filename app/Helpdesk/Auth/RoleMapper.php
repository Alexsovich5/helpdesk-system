<?php namespace Helpdesk\Auth;

/**
 * Maps directory groups, identified by their full distinguished name, to
 * application roles. A user in several mapped groups gets the most
 * privileged role; no mapped group means requester.
 *
 * Group names alone are not enough: a group called "helpdesk-admins" can be
 * created in any OU a user or delegated admin can write to, so only the
 * exact DNs in the map grant a role. DNs are compared case-insensitively
 * with the whitespace around "," "=" and "+" removed.
 */
class RoleMapper {

	/**
	 * Roles in ascending order of privilege.
	 *
	 * @var array
	 */
	protected static $order = array('requester', 'agent', 'admin');

	/**
	 * Normalised group DN => role.
	 *
	 * @var array
	 */
	protected $map = array();

	/**
	 * @param  array  $map  group DN => role
	 */
	public function __construct(array $map = array())
	{
		foreach ($map as $group => $role)
		{
			$group = static::normalise($group);
			$role = strtolower(trim($role));

			if (static::looksLikeDn($group) && in_array($role, static::$order, true))
			{
				$this->map[$group] = $role;
			}
		}
	}

	/**
	 * Parse "dn:role;dn:role" (the LDAP_ROLE_MAP format). The role is the
	 * text after the last colon. Entries that are not a DN, have no colon or
	 * name an unknown role are skipped.
	 *
	 * @param  string  $value
	 * @return static
	 */
	public static function fromString($value)
	{
		$map = array();

		foreach (explode(';', (string) $value) as $pair)
		{
			$colon = strrpos($pair, ':');

			if ($colon === false) continue;

			$map[substr($pair, 0, $colon)] = substr($pair, $colon + 1);
		}

		return new static($map);
	}

	/**
	 * Lower-case a DN and remove the whitespace around its separators.
	 * Escaped characters ("\,") stay part of the value.
	 *
	 * @param  string  $dn
	 * @return string
	 */
	public static function normalise($dn)
	{
		$rdns = array();

		foreach (static::split((string) $dn, ',') as $rdn)
		{
			$values = array();

			foreach (static::split($rdn, '+') as $ava)
			{
				$parts = static::split($ava, '=', 2);
				$values[] = trim($parts[0]).(count($parts) > 1 ? '='.trim($parts[1]) : '');
			}

			$rdns[] = implode('+', $values);
		}

		return strtolower(implode(',', $rdns));
	}

	/**
	 * True when $dn is strictly below $base (whole RDNs compared).
	 *
	 * @param  string  $dn
	 * @param  string  $base
	 * @return bool
	 */
	public static function isWithin($dn, $base)
	{
		$dn = static::normalise($dn);
		$base = static::normalise($base);

		if ($base === '' || strlen($dn) <= strlen($base) + 1) return false;

		$suffix = ','.$base;

		if (substr($dn, -strlen($suffix)) !== $suffix) return false;

		// The comma before the base must not itself be escaped.
		$head = substr($dn, 0, -strlen($suffix));

		return (strlen($head) - strlen(rtrim($head, '\\'))) % 2 === 0;
	}

	/**
	 * @return array
	 */
	public function map()
	{
		return $this->map;
	}

	/**
	 * @param  array  $groups  group DNs
	 * @return string
	 */
	public function roleFor(array $groups)
	{
		$best = 0;

		foreach ($groups as $group)
		{
			$key = static::normalise($group);

			if ( ! isset($this->map[$key])) continue;

			$rank = array_search($this->map[$key], static::$order, true);

			if ($rank > $best) $best = $rank;
		}

		return static::$order[$best];
	}

	protected static function looksLikeDn($value)
	{
		return (bool) preg_match('/^[a-z][a-z0-9-]*=[^,]+(,[a-z][a-z0-9-]*=[^,]*)*$/', preg_replace('/\\\\./', 'x', $value));
	}

	/**
	 * Split on a separator that is not escaped with a backslash; a
	 * backslash escapes the character after it. At most $limit parts.
	 *
	 * @return array
	 */
	protected static function split($value, $separator, $limit = -1)
	{
		$parts = array();
		$current = '';
		$length = strlen($value);

		for ($i = 0; $i < $length; $i++)
		{
			$char = $value[$i];

			if ($char === '\\' && $i + 1 < $length)
			{
				$current .= $char.$value[++$i];
				continue;
			}

			if ($char === $separator && ($limit < 0 || count($parts) < $limit - 1))
			{
				$parts[] = $current;
				$current = '';
				continue;
			}

			$current .= $char;
		}

		$parts[] = $current;

		return $parts;
	}

}
