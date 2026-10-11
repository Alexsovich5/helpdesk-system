<?php

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputArgument;

class UsersActivateCommand extends Command {

	protected $name = 'users:activate';

	protected $description = 'Activate a deactivated user (an administrator action); the role stays requester until the next directory check';

	public function fire()
	{
		$user = User::where('username', $this->argument('username'))->first();

		if ( ! $user)
		{
			$this->error('No user named "'.$this->argument('username').'"');

			return 1;
		}

		$user->active = true;
		$user->save();

		$this->line('activated '.$user->username);

		return 0;
	}

	protected function getArguments()
	{
		return array(array('username', InputArgument::REQUIRED, 'Username to activate'));
	}

}
