<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Stylemix\Listing\Facades\Entities;

class CreateBooksTable extends Migration
{

	/**
	 * Run the migrations.
	 *
	 * @return  void
	 */
	public function up()
	{
		Schema::create('books', function (Blueprint $table) {
			$table->increments('id');
			$table->string('title');
			$table->timestamps();
			$table->entityColumns();
		});

		Entities::createDataTable('books');
	}

	/**
	 * Reverse the migrations.
	 *
	 * @return  void
	 */
	public function down()
	{
		Entities::dropDataTable('books');
		Schema::dropIfExists('books');
	}
}
