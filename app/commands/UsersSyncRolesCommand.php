<?php

use Helpdesk\Auth\LdapUnavailableException;
use Helpdesk\Auth\RoleSyncAbortedException;
use Illuminate\Console\Command;

class UsersSyncRolesCommand extends Command {

	protected $name = 'users:sync-roles';

	protected $description = 'Re-check directory agents and admins against LDAP; demote or deactivate the ones it no longer backs';

	public function fire()
	{
		try
		{
			$counts = $this->laravel->make('Helpdesk\Auth\RoleSync')->run();
		}
		catch (RoleSyncAbortedException $e)
		{
			$this->error($e->getMessage().' (no user was changed)');

			return 1;
		}
		catch (LdapUnavailableException $e)
		{
			$this->error($e->getMessage().' (no user was changed)');

			return 1;
		}

		$this->line(sprintf('checked %d, verified %d, demoted %d, deactivated %d',
			$counts['checked'], $counts['verified'], $counts['demoted'], $counts['deactivated']));

		return 0;
	}

}
