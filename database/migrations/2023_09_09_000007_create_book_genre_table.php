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
        Schema::create('book_genre', function (Blueprint $table) {
            $table->id("book_genre_id");

            $table->unsignedBigInteger("book_id")->index();
            $table->foreign("book_id")->references("book_id")
                ->on("books")
                ->onDelete("cascade");

            $table->unsignedBigInteger("genre_id")->index();
            $table->foreign("genre_id")->references("genre_id")
                ->on("genres")
                ->onDelete("cascade");
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
        Schema::dropIfExists('book_genre');
    }
};
