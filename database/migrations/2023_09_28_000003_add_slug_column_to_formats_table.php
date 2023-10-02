<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class AddSlugColumnToFormatsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('formats', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('name'); // Assuming the column after which slug should appear is 'name'
        });

        $slugs = [
            'paper',
            'audio',
            'ebook',
            'pirated',
            'borrowed'
        ];

        $formats = DB::table('formats')->select('format_id')->orderBy('format_id')->get();

        foreach ($formats as $index => $format) {
            if (isset($slugs[$index])) {
                DB::table('formats')
                  ->where('format_id', $format->format_id)
                  ->update(['slug' => $slugs[$index]]);
            }
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('formats', function (Blueprint $table) {
            $table->dropColumn('slug');
        });
    }
}
