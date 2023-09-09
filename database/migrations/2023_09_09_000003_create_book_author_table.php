<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

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

        DB::table("book_author")->insert(
            [
                [
                    "book_id" => 1,
                    "author_id" => 1,
                    "author_ordinal" => 1,
                    "created_at" => Carbon::now()->format('Y-m-d H:i:s'),
                    "updated_at" => Carbon::now()->format('Y-m-d H:i:s'),
                ],
                [
                    "book_id" => 3,
                    "author_id" => 2,
                    "author_ordinal" => 1,
                    "created_at" => Carbon::now()->format('Y-m-d H:i:s'),
                    "updated_at" => Carbon::now()->format('Y-m-d H:i:s'),
                ],
                [
                    "book_id" => 2,
                    "author_id" => 3,
                    "author_ordinal" => 1,
                    "created_at" => Carbon::now()->format('Y-m-d H:i:s'),
                    "updated_at" => Carbon::now()->format('Y-m-d H:i:s'),
                ],
                [
                    "book_id" => 4,
                    "author_id" => 1,
                    "author_ordinal" => 1,
                    "created_at" => Carbon::now()->format('Y-m-d H:i:s'),
                    "updated_at" => Carbon::now()->format('Y-m-d H:i:s'),
                ],
                [
                    "book_id" => 5,
                    "author_id" => 4,
                    "author_ordinal" => 1,
                    "created_at" => Carbon::now()->format('Y-m-d H:i:s'),
                    "updated_at" => Carbon::now()->format('Y-m-d H:i:s'),
                ],
                [
                    "book_id" => 6,
                    "author_id" => 5,
                    "author_ordinal" => 1,
                    "created_at" => Carbon::now()->format('Y-m-d H:i:s'),
                    "updated_at" => Carbon::now()->format('Y-m-d H:i:s'),
                ],
                [
                    "book_id" => 7,
                    "author_id" => 6,
                    "author_ordinal" => 1,
                    "created_at" => Carbon::now()->format('Y-m-d H:i:s'),
                    "updated_at" => Carbon::now()->format('Y-m-d H:i:s'),
                ],
            ]);
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
