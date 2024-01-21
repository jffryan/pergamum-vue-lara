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
        Schema::create('backlog_items', function (Blueprint $table) {
            $table->id("backlog_item_id");
            $table->foreignId("book_id")
                ->references("book_id")
                ->on("books")
                ->constrained()
                ->onDelete("cascade");
            $table->integer("backlog_ordinal")->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('backlog_item');
    }
};
