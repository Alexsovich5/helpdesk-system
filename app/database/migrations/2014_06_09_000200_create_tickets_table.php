<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreateTicketsTable extends Migration {

	public function up()
	{
		Schema::create('tickets', function(Blueprint $table)
		{
			$table->increments('id');
			// Derived from the id (HD-000123), so it is filled in right after the insert.
			$table->string('number', 20)->nullable()->unique();
			$table->string('subject');
			$table->text('description');
			$table->enum('status', array('new', 'open', 'pending', 'resolved', 'closed'))->default('new');
			$table->enum('priority', array('low', 'normal', 'high', 'urgent'))->default('normal');
			$table->integer('category_id')->unsigned();
			$table->integer('requester_id')->unsigned();
			$table->integer('assignee_id')->unsigned()->nullable();
			$table->timestamp('response_due_at')->nullable();
			$table->timestamp('resolution_due_at')->nullable();
			$table->timestamp('first_responded_at')->nullable();
			$table->timestamp('resolved_at')->nullable();
			$table->timestamp('closed_at')->nullable();
			$table->enum('sla_state', array('ok', 'warning', 'breached'))->default('ok');
			$table->timestamps();

			$table->index('status');
			$table->index('assignee_id');
			$table->index('sla_state');

			$table->foreign('category_id')->references('id')->on('categories');
			$table->foreign('requester_id')->references('id')->on('users');
			$table->foreign('assignee_id')->references('id')->on('users');
		});
	}

	public function down()
	{
		Schema::drop('tickets');
	}

}
