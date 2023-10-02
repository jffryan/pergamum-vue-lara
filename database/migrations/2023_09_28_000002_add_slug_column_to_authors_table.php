<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class AddSlugColumnToAuthorsTable extends Migration
{
  /**
   * Run the migrations.
   *
   * @return void
   */
  public function up()
  {
    Schema::table('authors', function (Blueprint $table) {
      $table->string('slug')->nullable()->after('last_name');
    });

    $slugs = [
      'william-faulkner',
      'f-scott-fitzgerald',
      'jennette-mccurdy',
      'stephen-pressfield',
      'karen-russell',
      'sarah-broom',
      'qa-test',
      'merlin-coverley',
      'neil-postman',
      'steve-powers',
      'philip-pullman',
      'jared-diamond',
      'dale-carnegie',
      'vladimir-nabokov',
      'joe-navarro',
      'garth-nix',
      'arthur-rimbaud',
      'peter-dale-scott',
      'anselm',
      'brian-allison',
      'dathan-auerbach',
      'don-delillo',
      'isabel-allende'
    ];

    $authors = DB::table('authors')->select('author_id')->orderBy('author_id')->get();

    foreach ($authors as $index => $author) {
      DB::table('authors')
        ->where('author_id', $author->author_id)
        ->update(['slug' => $slugs[$index]]);
    }
  }

  /**
   * Reverse the migrations.
   *
   * @return void
   */
  public function down()
  {
    Schema::table('authors', function (Blueprint $table) {
      $table->dropColumn('slug');
    });
  }
}
