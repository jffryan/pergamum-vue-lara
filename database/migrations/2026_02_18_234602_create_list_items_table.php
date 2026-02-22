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
        Schema::create('list_items', function (Blueprint $table) {
            $table->id('list_item_id');
            $table->foreignId('list_id')
                ->constrained('lists', 'list_id')
                ->cascadeOnDelete();
            $table->foreignId('book_id')
                ->constrained('books', 'book_id')
                ->cascadeOnDelete();
            $table->unsignedInteger('ordinal');
            $table->timestamps();

            $table->unique(['list_id', 'ordinal']);
            $table->unique(['list_id', 'book_id']);

            $table->index(['list_id', 'ordinal']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('list_items');
    }
};
