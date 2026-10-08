<?php namespace Helpdesk\Auth;

use Carbon\Carbon;
use Illuminate\Auth\UserInterface;
use Illuminate\Auth\UserProviderInterface;
use Illuminate\Hashing\HasherInterface;
use User;

/**
 * Auth provider that checks local accounts (source=local) against their
 * bcrypt hash and everyone else against the directory. A successful directory
 * login creates or refreshes the user row with source=ldap, the mapped role,
 * active=1 and the login and role-verification times.
 *
 * A directory user whose entry is gone or disabled is deactivated and
 * demoted when they next try to sign in. A directory outage only refuses the
 * login; it changes nothing. Deactivated local accounts cannot sign in.
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

		if ($user && $user->source === 'local') return $user->active ? $user : null;

		try
		{
			$entry = $this->gateway->findUser($username);
		}
		catch (LdapUnavailableException $e)
		{
			return null;
		}

		if (is_null($entry) || ! empty($entry['disabled']))
		{
			if ($user) static::deactivate($user);

			return null;
		}

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

			if ( ! $user->active || empty($hash) || ! $this->hasher->check($password, $hash)) return false;

			$user->last_login_at = Carbon::now();
			$user->save();

			return true;
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
		$user->active = true;
		$user->last_login_at = Carbon::now();
		$user->role_verified_at = Carbon::now();

		$user->save();
	}

	/**
	 * Mark a directory user inactive and take away any staff role.
	 */
	public static function deactivate(User $user)
	{
		if ( ! $user->exists || $user->source !== 'ldap') return;

		$user->active = false;
		$user->role = 'requester';
		$user->save();
	}

}
