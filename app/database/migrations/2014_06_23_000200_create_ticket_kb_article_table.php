<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreateTicketKbArticleTable extends Migration {

	public function up()
	{
		Schema::create('ticket_kb_article', function(Blueprint $table)
		{
			$table->integer('ticket_id')->unsigned();
			$table->integer('kb_article_id')->unsigned();

			$table->primary(array('ticket_id', 'kb_article_id'));
			$table->foreign('ticket_id')->references('id')->on('tickets')->onDelete('cascade');
			$table->foreign('kb_article_id')->references('id')->on('kb_articles')->onDelete('cascade');
		});
	}

	public function down()
	{
		Schema::drop('ticket_kb_article');
	}

}
