<?php namespace Helpdesk\Auth;

use Illuminate\Auth\UserInterface;
use Illuminate\Auth\UserProviderInterface;
use Illuminate\Hashing\HasherInterface;
use User;

/**
 * Auth provider that checks local accounts (source=local) against their
 * bcrypt hash and everyone else against the directory. A successful directory
 * login creates or refreshes the user row with source=ldap and the mapped role.
 */
class LdapUserProvider implements UserProviderInterface {

	/**
	 * @var LdapGateway
	 */
	protected $gateway;

	/**
	 * @var RoleMapper
	 */
	protected $roles;

	/**
	 * @var \Illuminate\Hashing\HasherInterface
	 */
	protected $hasher;

	/**
	 * Directory entries found by retrieveByCredentials(), keyed by lower-cased username.
	 *
	 * @var array
	 */
	protected $entries = array();

	public function __construct(LdapGateway $gateway, RoleMapper $roles, HasherInterface $hasher)
	{
		$this->gateway = $gateway;
		$this->roles = $roles;
		$this->hasher = $hasher;
	}

	public function retrieveById($identifier)
	{
		return User::find($identifier);
	}

	public function retrieveByToken($identifier, $token)
	{
		return User::where('id', $identifier)->where('remember_token', $token)->first();
	}

	public function updateRememberToken(UserInterface $user, $token)
	{
		$user->setRememberToken($token);

		$user->save();
	}

	public function retrieveByCredentials(array $credentials)
	{
		$username = isset($credentials['username']) ? trim($credentials['username']) : '';

		if ($username === '') return null;

		$user = User::where('username', $username)->first();

		if ($user && $user->source === 'local') return $user;

		$entry = $this->gateway->findUser($username);

		if (is_null($entry)) return $user;

		$this->entries[strtolower($username)] = $entry;

		if ($user) return $user;

		// The directory may spell the name differently from what was typed.
		$user = User::where('username', $entry['username'])->first();

		if ($user) return $user->source === 'local' ? null : $user;

		$user = new User;
		$user->username = $entry['username'];
		$user->source = 'ldap';

		return $user;
	}

	public function validateCredentials(UserInterface $user, array $credentials)
	{
		$password = isset($credentials['password']) ? (string) $credentials['password'] : '';

		if ($password === '') return false;

		if ($user->source === 'local')
		{
			$hash = $user->getAuthPassword();

			return ! empty($hash) && $this->hasher->check($password, $hash);
		}

		$key = strtolower(isset($credentials['username']) ? trim($credentials['username']) : $user->username);

		if ( ! isset($this->entries[$key])) return false;

		$entry = $this->entries[$key];

		if ( ! $this->gateway->bind($entry['dn'], $password)) return false;

		$this->provision($user, $entry);

		return true;
	}

	/**
	 * Copy the directory entry onto the user and save it.
	 */
	protected function provision(User $user, array $entry)
	{
		$user->username = $entry['username'];
		$user->name = $entry['name'];
		$user->email = $entry['email'];
		$user->role = $this->roles->roleFor($entry['groups']);
		$user->source = 'ldap';
		$user->password = null;

		$user->save();
	}

}
