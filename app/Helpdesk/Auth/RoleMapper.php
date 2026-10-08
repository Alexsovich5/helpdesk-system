<?php namespace Helpdesk\Auth;

/**
 * Maps directory group names (cn) to application roles. A user in several
 * mapped groups gets the most privileged role; no mapped group means requester.
 */
class RoleMapper {

	/**
	 * Roles in ascending order of privilege.
	 *
	 * @var array
	 */
	protected static $order = array('requester', 'agent', 'admin');

	/**
	 * Lower-cased group name => role.
	 *
	 * @var array
	 */
	protected $map = array();

	public function __construct(array $map = array())
	{
		foreach ($map as $group => $role)
		{
			$group = strtolower(trim($group));
			$role = strtolower(trim($role));

			if ($group !== '' && in_array($role, static::$order, true))
			{
				$this->map[$group] = $role;
			}
		}
	}

	/**
	 * Parse "group:role,group:role" (the LDAP_ROLE_MAP format). Entries
	 * without a colon or with an unknown role are skipped.
	 *
	 * @param  string  $value
	 * @return static
	 */
	public static function fromString($value)
	{
		$map = array();

		foreach (explode(',', (string) $value) as $pair)
		{
			if (strpos($pair, ':') === false) continue;

			list($group, $role) = explode(':', $pair, 2);

			$map[$group] = $role;
		}

		return new static($map);
	}

	/**
	 * @return array
	 */
	public function map()
	{
		return $this->map;
	}

	/**
	 * @param  array  $groups  group common names
	 * @return string
	 */
	public function roleFor(array $groups)
	{
		$best = 0;

		foreach ($groups as $group)
		{
			$key = strtolower(trim($group));

			if ( ! isset($this->map[$key])) continue;

			$rank = array_search($this->map[$key], static::$order, true);

			if ($rank > $best) $best = $rank;
		}

		return static::$order[$best];
	}

}
