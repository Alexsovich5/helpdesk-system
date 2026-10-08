<?php namespace Helpdesk\Auth;

use Psr\Log\LoggerInterface;

/**
 * LDAP gateway on top of ext-ldap. Searches with a service account, then
 * checks passwords with a simple bind as the user's DN.
 *
 * Group memberships are returned as full DNs and only for groups below
 * group_base_dn (default ou=groups,<base_dn>): memberOf values outside it
 * are dropped, and the groupOfNames search (OpenLDAP) is run there only.
 * Connection, service-bind and search failures throw
 * LdapUnavailableException; their messages never contain a password.
 */
class NativeLdapGateway implements LdapGateway {

	/**
	 * @var array
	 */
	protected $config;

	/**
	 * @var \Psr\Log\LoggerInterface|null
	 */
	protected $log;

	/**
	 * @param  array  $config  host, port, base_dn, group_base_dn, bind_dn,
	 *                         bind_password, user_filter, username_attribute
	 */
	public function __construct(array $config, LoggerInterface $log = null)
	{
		$this->config = array_merge(array(
			'host'               => 'localhost',
			'port'               => 389,
			'base_dn'            => '',
			'group_base_dn'      => null,
			'bind_dn'            => null,
			'bind_password'      => null,
			'user_filter'        => '(uid=%s)',
			'username_attribute' => 'uid',
		), $config);

		$this->log = $log;
	}

	/**
	 * @return string
	 */
	public function groupBaseDn()
	{
		if ( ! empty($this->config['group_base_dn'])) return $this->config['group_base_dn'];

		return 'ou=groups,'.$this->config['base_dn'];
	}

	/**
	 * The DNs from $dns that lie below $base, in their original spelling.
	 *
	 * @param  array   $dns
	 * @param  string  $base
	 * @return array
	 */
	public static function groupsWithin(array $dns, $base)
	{
		return array_values(array_filter($dns, function($dn) use ($base)
		{
			return RoleMapper::isWithin($dn, $base);
		}));
	}

	/**
	 * Active Directory accounts with ACCOUNTDISABLE (0x2) set in
	 * userAccountControl.
	 *
	 * @param  array  $entry  as returned by ldap_get_entries()
	 * @return bool
	 */
	public static function isDisabled(array $entry)
	{
		if ( ! isset($entry['useraccountcontrol'][0])) return false;

		return ((int) $entry['useraccountcontrol'][0] & 2) === 2;
	}

	/**
	 * Escape a value for use inside a search filter (RFC 4515).
	 *
	 * @param  string  $value
	 * @return string
	 */
	public static function escape($value)
	{
		return strtr((string) $value, array(
			'\\' => '\5c',
			'*'  => '\2a',
			'('  => '\28',
			')'  => '\29',
			"\0" => '\00',
		));
	}

	/**
	 * Build the user search filter, e.g. "(uid=%s)" or "(sAMAccountName=%s)".
	 *
	 * @param  string  $template
	 * @param  string  $username
	 * @return string
	 */
	public static function userFilter($template, $username)
	{
		return str_replace('%s', static::escape($username), $template);
	}

	public function findUser($username)
	{
		$link = $this->connect();

		try
		{
			$this->serviceBind($link);

			$attribute = strtolower($this->config['username_attribute']);
			$filter = static::userFilter($this->config['user_filter'], $username);
			$fields = array($attribute, 'cn', 'displayname', 'mail', 'memberof', 'useraccountcontrol');

			$search = @ldap_search($link, $this->config['base_dn'], $filter, $fields);

			if ( ! $search) $this->unavailable('user search failed: '.ldap_error($link));

			$entries = ldap_get_entries($link, $search);

			// No entry, or an ambiguous name that matches several.
			if ($entries['count'] !== 1) return null;

			$entry = $entries[0];
			$dn = $entry['dn'];

			$name = $this->first($entry, 'displayname') ?: $this->first($entry, 'cn');

			return array(
				'dn'       => $dn,
				'username' => $this->first($entry, $attribute) ?: $username,
				'name'     => $name ?: $username,
				'email'    => $this->first($entry, 'mail'),
				'groups'   => $this->groups($link, $entry, $dn),
				'disabled' => static::isDisabled($entry),
			);
		}
		finally
		{
			ldap_unbind($link);
		}
	}

	public function bind($dn, $password)
	{
		// An empty password makes an unauthenticated bind, which many servers accept.
		if ((string) $password === '' || (string) $dn === '') return false;

		try
		{
			$link = $this->connect();
		}
		catch (LdapUnavailableException $e)
		{
			return false;
		}

		$ok = @ldap_bind($link, $dn, $password);

		ldap_unbind($link);

		return (bool) $ok;
	}

	/**
	 * @return resource
	 * @throws LdapUnavailableException
	 */
	protected function connect()
	{
		$link = @ldap_connect($this->config['host'], (int) $this->config['port']);

		if ( ! $link) $this->unavailable('cannot connect to '.$this->config['host']);

		ldap_set_option($link, LDAP_OPT_PROTOCOL_VERSION, 3);
		ldap_set_option($link, LDAP_OPT_REFERRALS, 0);
		ldap_set_option($link, LDAP_OPT_NETWORK_TIMEOUT, 5);

		return $link;
	}

	/**
	 * Bind as the service account. The password is read from the config
	 * here, so it never appears in an argument list of the trace.
	 *
	 * @throws LdapUnavailableException
	 */
	protected function serviceBind($link)
	{
		if (@ldap_bind($link, $this->config['bind_dn'], $this->config['bind_password'])) return;

		$this->unavailable('service bind as '.$this->config['bind_dn'].' failed: '.ldap_error($link));
	}

	/**
	 * @throws LdapUnavailableException
	 */
	protected function unavailable($message)
	{
		$this->warn($message);

		throw new LdapUnavailableException('LDAP: '.$message);
	}

	/**
	 * DNs of the user's groups below groupBaseDn(): from memberOf (Active
	 * Directory), or from a search there for groupOfNames entries listing
	 * the user's DN as a member (OpenLDAP).
	 *
	 * @return array
	 */
	protected function groups($link, array $entry, $dn)
	{
		$dns = array();
		$base = $this->groupBaseDn();

		if (isset($entry['memberof']))
		{
			for ($i = 0; $i < $entry['memberof']['count']; $i++)
			{
				$dns[] = $entry['memberof'][$i];
			}
		}
		else
		{
			$filter = '(&(objectClass=groupOfNames)(member='.static::escape($dn).'))';
			$search = @ldap_search($link, $base, $filter, array('cn'));

			// A missing group base yields "No such object"; anything else is an outage.
			if ( ! $search && ldap_errno($link) !== 32) $this->unavailable('group search failed: '.ldap_error($link));

			if ($search)
			{
				$groups = ldap_get_entries($link, $search);

				for ($i = 0; $i < $groups['count']; $i++)
				{
					$dns[] = $groups[$i]['dn'];
				}
			}
		}

		return static::groupsWithin($dns, $base);
	}

	protected function first(array $entry, $attribute)
	{
		return isset($entry[$attribute][0]) ? $entry[$attribute][0] : null;
	}

	protected function warn($message)
	{
		if ($this->log) $this->log->warning('LDAP: '.$message);
	}

}
