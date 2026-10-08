<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreateTicketEventsTable extends Migration {

	/**
	 * Append-only history, so there is no updated_at column.
	 */
	public function up()
	{
		Schema::create('ticket_events', function(Blueprint $table)
		{
			$table->increments('id');
			$table->integer('ticket_id')->unsigned();
			$table->integer('user_id')->unsigned()->nullable();
			$table->string('type', 32);
			$table->string('from_value')->nullable();
			$table->string('to_value')->nullable();
			$table->timestamp('created_at');

			$table->index(array('ticket_id', 'id'));
			$table->foreign('ticket_id')->references('id')->on('tickets')->onDelete('cascade');
			$table->foreign('user_id')->references('id')->on('users');
		});
	}

	public function down()
	{
		Schema::drop('ticket_events');
	}

}
