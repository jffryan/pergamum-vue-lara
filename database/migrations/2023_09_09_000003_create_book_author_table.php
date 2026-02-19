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
        Schema::create('book_author', function (Blueprint $table) {
            $table->id("book_author_id");

            $table->unsignedBigInteger("book_id")->index();
            $table->foreign("book_id")->references("book_id")
                ->on("books")
                ->onDelete("cascade");

            $table->unsignedBigInteger("author_id")->index();
            $table->foreign("author_id")->references("author_id")
                ->on("authors")
                ->onDelete("cascade");

            $table->integer("author_ordinal")->default(1);
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
        Schema::dropIfExists('book_author');
    }
};
