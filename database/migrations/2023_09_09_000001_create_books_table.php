<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
        Schema::create('books', function (Blueprint $table) {
            $table->id("book_id");
            $table->string("title");
            $table->boolean("is_completed")->default(0);
            $table->integer("rating")->nullable();
            $table->date("date_completed")->nullable();
            $table->timestamps();
        });

        // Insert some dummy data
        DB::table("books")->insert(
            [
                [
                    "title" => "As I Lay Dying",
                    "created_at" => Carbon::now()->format('Y-m-d H:i:s'),
                    "updated_at" => Carbon::now()->format('Y-m-d H:i:s'),
                    "is_completed" => true,
                    "rating" => 5,
                    "date_completed" => Carbon::createFromFormat("Y-m-d", "2023-05-24"),
                ],
                [
                    "title" => "I'm Glad My Mom Died",
                    "created_at" => Carbon::now()->format('Y-m-d H:i:s'),
                    "updated_at" => Carbon::now()->format('Y-m-d H:i:s'),
                    "is_completed" => true,
                    "rating" => 3.5,
                    "date_completed" => Carbon::createFromFormat("Y-m-d", "2023-04-17"),
                ],
                [
                    "title" => "The Great Gatsby",
                    "created_at" => Carbon::now()->format('Y-m-d H:i:s'),
                    "updated_at" => Carbon::now()->format('Y-m-d H:i:s'),
                    "is_completed" => true,
                    "rating" => 5,
                    "date_completed" => Carbon::createFromFormat("Y-m-d", "2016-04-03"),
                ],
                [
                    "title" => "The Sound and the Fury",
                    "created_at" => Carbon::now()->format('Y-m-d H:i:s'),
                    "updated_at" => Carbon::now()->format('Y-m-d H:i:s'),
                    "is_completed" => true,
                    "rating" => 5,
                    "date_completed" => Carbon::createFromFormat("Y-m-d", "2016-05-15"),
                ],
                [
                    "title" => "Nobody Wants to Read Your Shit",
                    "created_at" => Carbon::now()->format('Y-m-d H:i:s'),
                    "updated_at" => Carbon::now()->format('Y-m-d H:i:s'),
                    "is_completed" => true,
                    "rating" => 5,
                    "date_completed" => Carbon::createFromFormat("Y-m-d", "2016-05-27"),
                ],
                [
                    "title" => "Swamplandia!",
                    "created_at" => Carbon::now()->format('Y-m-d H:i:s'),
                    "updated_at" => Carbon::now()->format('Y-m-d H:i:s'),
                    "is_completed" => true,
                    "rating" => 5,
                    "date_completed" => Carbon::createFromFormat("Y-m-d", "2017-06-17"),
                ],
                [
                    "title" => "The Yellow House",
                    "created_at" => Carbon::now()->format('Y-m-d H:i:s'),
                    "updated_at" => Carbon::now()->format('Y-m-d H:i:s'),
                    "is_completed" => false,
                    "rating" => null,
                    "date_completed" => null,
                ]
            ]
        );
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('books');
    }
};
