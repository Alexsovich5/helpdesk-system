<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreateKbArticlesTable extends Migration {

	public function up()
	{
		Schema::create('kb_articles', function(Blueprint $table)
		{
			$table->increments('id');
			$table->integer('kb_category_id')->unsigned();
			$table->integer('author_id')->unsigned();
			$table->string('title');
			$table->text('body');
			$table->boolean('is_published')->default(false);
			$table->timestamps();

			$table->index('is_published');
			$table->foreign('kb_category_id')->references('id')->on('kb_categories');
			$table->foreign('author_id')->references('id')->on('users');
		});
	}

	public function down()
	{
		Schema::drop('kb_articles');
	}

}
