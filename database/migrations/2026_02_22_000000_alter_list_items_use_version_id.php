<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('list_items', function (Blueprint $table) {
            $table->dropForeign(['book_id']);
            $table->dropUnique(['list_id', 'book_id']);
            $table->dropUnique(['list_id', 'ordinal']);
            $table->dropColumn('book_id');

            $table->foreignId('version_id')
                ->constrained('versions', 'version_id')
                ->cascadeOnDelete();

            $table->unique(['list_id', 'version_id']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('list_items', function (Blueprint $table) {
            $table->dropForeign(['version_id']);
            $table->dropUnique(['list_id', 'version_id']);
            $table->dropColumn('version_id');

            $table->foreignId('book_id')
                ->constrained('books', 'book_id')
                ->cascadeOnDelete();

            $table->unique(['list_id', 'ordinal']);
            $table->unique(['list_id', 'book_id']);
        });
    }
};
