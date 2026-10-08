<?php namespace Helpdesk\Auth;

use Psr\Log\LoggerInterface;

/**
 * LDAP gateway on top of ext-ldap. Searches with a service account, then
 * checks passwords with a simple bind as the user's DN.
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
	 * @param  array  $config  host, port, base_dn, bind_dn, bind_password,
	 *                         user_filter, username_attribute
	 */
	public function __construct(array $config, LoggerInterface $log = null)
	{
		$this->config = array_merge(array(
			'host'               => 'localhost',
			'port'               => 389,
			'base_dn'            => '',
			'bind_dn'            => null,
			'bind_password'      => null,
			'user_filter'        => '(uid=%s)',
			'username_attribute' => 'uid',
		), $config);

		$this->log = $log;
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

		if ( ! $link) return null;

		try
		{
			if ( ! @ldap_bind($link, $this->config['bind_dn'], $this->config['bind_password']))
			{
				$this->warn('service bind failed: '.ldap_error($link));

				return null;
			}

			$attribute = strtolower($this->config['username_attribute']);
			$filter = static::userFilter($this->config['user_filter'], $username);
			$fields = array($attribute, 'cn', 'displayname', 'mail', 'memberof');

			$search = @ldap_search($link, $this->config['base_dn'], $filter, $fields);

			if ( ! $search) return null;

			$entries = ldap_get_entries($link, $search);

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

		$link = $this->connect();

		if ( ! $link) return false;

		$ok = @ldap_bind($link, $dn, $password);

		ldap_unbind($link);

		return (bool) $ok;
	}

	/**
	 * @return resource|false
	 */
	protected function connect()
	{
		$link = @ldap_connect($this->config['host'], (int) $this->config['port']);

		if ( ! $link)
		{
			$this->warn('cannot connect to '.$this->config['host']);

			return false;
		}

		ldap_set_option($link, LDAP_OPT_PROTOCOL_VERSION, 3);
		ldap_set_option($link, LDAP_OPT_REFERRALS, 0);
		ldap_set_option($link, LDAP_OPT_NETWORK_TIMEOUT, 5);

		return $link;
	}

	/**
	 * Group common names from memberOf (Active Directory), or from a search
	 * for groupOfNames entries listing the DN as a member (OpenLDAP).
	 *
	 * @return array
	 */
	protected function groups($link, array $entry, $dn)
	{
		$dns = array();

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
			$search = @ldap_search($link, $this->config['base_dn'], $filter, array('cn'));

			if ($search)
			{
				$groups = ldap_get_entries($link, $search);

				for ($i = 0; $i < $groups['count']; $i++)
				{
					$dns[] = $groups[$i]['dn'];
				}
			}
		}

		$names = array();

		foreach ($dns as $groupDn)
		{
			if (preg_match('/^cn=([^,]+)/i', $groupDn, $m)) $names[] = $m[1];
		}

		return $names;
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
