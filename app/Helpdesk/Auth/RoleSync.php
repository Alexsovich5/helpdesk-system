<?php namespace Helpdesk\Auth;

use Carbon\Carbon;
use User;

/**
 * Re-checks every active directory agent and admin against the directory.
 *
 *   entry found, still in a mapped group  role set to the mapped role, role_verified_at = now
 *   entry found, no mapped group           demoted to requester, role_verified_at = now
 *   no entry, ambiguous or disabled        deactivated and demoted to requester
 *
 * Every lookup happens before anything is written, so a directory outage
 * (LdapUnavailableException) aborts the run with no change at all. Local
 * accounts and requesters are left alone.
 */
class RoleSync {

	protected $gateway;

	protected $roles;

	public function __construct(LdapGateway $gateway, RoleMapper $roles)
	{
		$this->gateway = $gateway;
		$this->roles = $roles;
	}

	/**
	 * @return array  checked, verified, demoted, deactivated
	 * @throws LdapUnavailableException
	 */
	public function run()
	{
		$users = User::where('source', 'ldap')
			->where('active', true)
			->whereIn('role', array('agent', 'admin'))
			->orderBy('id')
			->get();

		$entries = array();
		foreach ($users as $user)
		{
			$entries[$user->id] = $this->gateway->findUser($user->username);
		}

		$counts = array('checked' => count($users), 'verified' => 0, 'demoted' => 0, 'deactivated' => 0);
		$now = Carbon::now();

		foreach ($users as $user)
		{
			$entry = $entries[$user->id];

			if (is_null($entry) || ! empty($entry['disabled']))
			{
				LdapUserProvider::deactivate($user);
				$counts['deactivated']++;

				continue;
			}

			$role = $this->roles->roleFor($entry['groups']);
			$counts[$role === 'requester' ? 'demoted' : 'verified']++;

			$user->role = $role;
			$user->role_verified_at = $now;
			$user->save();
		}

		return $counts;
	}

}
