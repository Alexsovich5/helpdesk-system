<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * active: deactivated users cannot sign in and get no staff e-mail.
 * role_verified_at: when the directory last confirmed the user's role.
 * last_login_at: the last successful sign-in.
 */
class AddAccountStateToUsersTable extends Migration {

	public function up()
	{
		Schema::table('users', function(Blueprint $table)
		{
			$table->boolean('active')->default(true);
			$table->timestamp('role_verified_at')->nullable();
			$table->timestamp('last_login_at')->nullable();
		});
	}

	public function down()
	{
		Schema::table('users', function(Blueprint $table)
		{
			$table->dropColumn('active', 'role_verified_at', 'last_login_at');
		});
	}

}
