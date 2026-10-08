<?php

class UserTableSeeder extends Seeder {

	/**
	 * Creates the local "admin" account (password "admin") that still works
	 * when the directory is unreachable. Only runs in the local environment.
	 */
	public function run()
	{
		if ( ! App::environment('local')) return;

		$admin = User::firstOrNew(array('username' => 'admin'));
		$admin->name = 'Local Administrator';
		$admin->email = 'admin@helpdesk.local';
		$admin->password = Hash::make('admin');
		$admin->role = 'admin';
		$admin->source = 'local';
		$admin->save();
	}

}
