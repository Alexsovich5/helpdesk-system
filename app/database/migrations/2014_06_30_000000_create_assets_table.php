<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreateAssetsTable extends Migration {

	public function up()
	{
		Schema::create('assets', function(Blueprint $table)
		{
			$table->increments('id');
			$table->string('asset_tag', 50)->unique();
			$table->string('name');
			$table->string('type', 50);
			$table->string('serial', 100)->nullable();
			$table->string('location')->nullable();
			$table->enum('status', array('in_use', 'in_stock', 'repair', 'retired'))->default('in_stock');
			$table->integer('assigned_user_id')->unsigned()->nullable();
			$table->timestamps();

			$table->index('status');
			$table->foreign('assigned_user_id')->references('id')->on('users');
		});
	}

	public function down()
	{
		Schema::drop('assets');
	}

}
