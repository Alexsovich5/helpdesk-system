<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreateSlaPoliciesTable extends Migration {

	public function up()
	{
		Schema::create('sla_policies', function(Blueprint $table)
		{
			$table->increments('id');
			$table->enum('priority', array('low', 'normal', 'high', 'urgent'))->unique();
			$table->integer('response_minutes')->unsigned();
			$table->integer('resolution_minutes')->unsigned();
			$table->timestamps();
		});
	}

	public function down()
	{
		Schema::drop('sla_policies');
	}

}
