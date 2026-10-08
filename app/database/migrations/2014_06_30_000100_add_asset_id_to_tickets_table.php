<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class AddAssetIdToTicketsTable extends Migration {

	public function up()
	{
		Schema::table('tickets', function(Blueprint $table)
		{
			$table->integer('asset_id')->unsigned()->nullable()->after('assignee_id');

			$table->foreign('asset_id')->references('id')->on('assets');
		});
	}

	public function down()
	{
		Schema::table('tickets', function(Blueprint $table)
		{
			$table->dropForeign('tickets_asset_id_foreign');
			$table->dropColumn('asset_id');
		});
	}

}
