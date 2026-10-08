<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreateTicketCommentsTable extends Migration {

	public function up()
	{
		Schema::create('ticket_comments', function(Blueprint $table)
		{
			$table->increments('id');
			$table->integer('ticket_id')->unsigned();
			$table->integer('user_id')->unsigned();
			$table->text('body');
			$table->boolean('is_internal')->default(false);
			$table->timestamps();

			$table->foreign('ticket_id')->references('id')->on('tickets')->onDelete('cascade');
			$table->foreign('user_id')->references('id')->on('users');
		});
	}

	public function down()
	{
		Schema::drop('ticket_comments');
	}

}
