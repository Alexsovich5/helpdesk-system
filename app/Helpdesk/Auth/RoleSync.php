<?php namespace Helpdesk\Auth;

use Carbon\Carbon;
use User;

/**
 * Re-checks every active directory agent and admin against the directory.
 *
 *   entry found, still in a mapped group  role set to the mapped role, role_verified_at = now
 *   entry found, no mapped group           demoted to requester, role_verified_at = now
 *   no entry, or disabled                  deactivated and demoted to requester
 *   several entries match (ambiguous)      left unchanged, a warning is logged
 *
 * Every lookup happens before anything is written, so a directory outage
 * (LdapUnavailableException) aborts the run with no change at all. So does a
 * run in which the users to deactivate exceed maxRemovalShare of the users
 * checked (RoleSyncAbortedException), which protects against a wrong base DN
 * or an emptied directory. Local accounts and requesters are left alone.
 */
class RoleSync {

	protected $gateway;

	protected $roles;

	protected $log;

	protected $maxRemovalShare;

	/**
	 * @param  LdapGateway  $gateway
	 * @param  RoleMapper   $roles
	 * @param  object|null  $log              needs a warning($message) method
	 * @param  float        $maxRemovalShare  0 to 1; the run aborts when more than this share of checked users would be deactivated
	 */
	public function __construct(LdapGateway $gateway, RoleMapper $roles, $log = null, $maxRemovalShare = 0.2)
	{
		$this->gateway = $gateway;
		$this->roles = $roles;
		$this->log = $log;
		$this->maxRemovalShare = (float) $maxRemovalShare;
	}

	/**
	 * @return array  checked, verified, demoted, deactivated
	 * @throws LdapUnavailableException
	 * @throws RoleSyncAbortedException
	 */
	public function run()
	{
		$users = User::where('source', 'ldap')
			->where('active', true)
			->whereIn('role', array('agent', 'admin'))
			->orderBy('id')
			->get();

		$entries = array();
		$ambiguous = array();
		$removals = 0;
		foreach ($users as $user)
		{
			try
			{
				$entries[$user->id] = $this->gateway->findUser($user->username);
			}
			catch (LdapAmbiguousEntryException $e)
			{
				$ambiguous[$user->id] = true;

				continue;
			}

			if (is_null($entries[$user->id]) || ! empty($entries[$user->id]['disabled'])) $removals++;
		}

		if (count($users) > 0 and $removals / count($users) > $this->maxRemovalShare)
		{
			throw new RoleSyncAbortedException(sprintf(
				'%d of %d users would be deactivated, more than the allowed %s%%',
				$removals, count($users), round($this->maxRemovalShare * 100, 2)
			));
		}

		$counts = array('checked' => count($users), 'verified' => 0, 'demoted' => 0, 'deactivated' => 0);
		$now = Carbon::now();

		foreach ($users as $user)
		{
			if (isset($ambiguous[$user->id]))
			{
				if ($this->log) $this->log->warning('Role sync skipped "'.$user->username.'": several directory entries match');

				continue;
			}

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
