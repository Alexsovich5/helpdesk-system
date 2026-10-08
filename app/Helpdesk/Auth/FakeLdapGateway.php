<?php namespace Helpdesk\Auth;

/**
 * In-memory directory for tests. Like a real server that permits
 * unauthenticated binds, bind() succeeds for a known DN with an empty
 * password, so callers must guard against empty passwords themselves.
 */
class FakeLdapGateway implements LdapGateway {

	/**
	 * Lower-cased username => entry including the password.
	 *
	 * @var array
	 */
	protected $users = array();

	/**
	 * @var string
	 */
	protected $userFilter;

	/**
	 * Filters findUser() would have sent to a server, in order.
	 *
	 * @var array
	 */
	protected $filters = array();

	/**
	 * @param  array   $users  username => ['password'=>..,'groups'=>[..],'name'=>..,'email'=>..]
	 * @param  string  $userFilter
	 */
	public function __construct(array $users = array(), $userFilter = '(uid=%s)')
	{
		$this->userFilter = $userFilter;

		foreach ($users as $username => $user)
		{
			$this->addUser(
				$username,
				isset($user['password']) ? $user['password'] : '',
				isset($user['groups']) ? $user['groups'] : array(),
				isset($user['name']) ? $user['name'] : null,
				isset($user['email']) ? $user['email'] : null
			);
		}
	}

	public function addUser($username, $password, array $groups = array(), $name = null, $email = null)
	{
		$this->users[strtolower($username)] = array(
			'dn'       => 'uid='.$username.',ou=people,dc=helpdesk,dc=local',
			'username' => $username,
			'name'     => $name ?: $username,
			'email'    => $email,
			'groups'   => array_values($groups),
			'password' => (string) $password,
		);
	}

	public function setGroups($username, array $groups)
	{
		$this->users[strtolower($username)]['groups'] = array_values($groups);
	}

	/**
	 * @return array
	 */
	public function searchedFilters()
	{
		return $this->filters;
	}

	public function findUser($username)
	{
		$this->filters[] = NativeLdapGateway::userFilter($this->userFilter, $username);

		$key = strtolower($username);

		if ( ! isset($this->users[$key])) return null;

		$entry = $this->users[$key];
		unset($entry['password']);

		return $entry;
	}

	public function bind($dn, $password)
	{
		foreach ($this->users as $user)
		{
			if (strcasecmp($user['dn'], $dn) !== 0) continue;

			return (string) $password === '' || $user['password'] === (string) $password;
		}

		return false;
	}

}
