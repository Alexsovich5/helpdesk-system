<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreateUsersTable extends Migration {

	public function up()
	{
		Schema::create('users', function(Blueprint $table)
		{
			$table->increments('id');
			$table->string('username', 64)->unique();
			$table->string('name');
			$table->string('email')->nullable();
			// Null for accounts that only authenticate against the directory.
			$table->string('password', 60)->nullable();
			$table->enum('role', array('requester', 'agent', 'admin'))->default('requester');
			$table->enum('source', array('local', 'ldap'))->default('local');
			$table->rememberToken();
			$table->timestamps();
		});
	}

	public function down()
	{
		Schema::drop('users');
	}

}
