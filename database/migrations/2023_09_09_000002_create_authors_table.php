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
        Schema::create('authors', function (Blueprint $table) {
            $table->id("author_id");
            $table->string("first_name");
            $table->string("last_name");
            $table->timestamps();
        });

        // Insert some dummy data
        DB::table("authors")->insert(
            [
                [
                    "first_name" => "William",
                    "last_name" => "Faulkner",
                    "created_at" => Carbon::now()->format('Y-m-d H:i:s'),
                    "updated_at" => Carbon::now()->format('Y-m-d H:i:s'),
                ],

                [
                    "first_name" => "F. Scott",
                    "last_name" => "Fitzgerald",
                    "created_at" => Carbon::now()->format('Y-m-d H:i:s'),
                    "updated_at" => Carbon::now()->format('Y-m-d H:i:s'),
                ],
                [
                    "first_name" => "Jennette",
                    "last_name" => "McCurdy",
                    "created_at" => Carbon::now()->format('Y-m-d H:i:s'),
                    "updated_at" => Carbon::now()->format('Y-m-d H:i:s'),
                ],
                [
                    "first_name" => "Stephen",
                    "last_name" => "Pressfield",
                    "created_at" => Carbon::now()->format('Y-m-d H:i:s'),
                    "updated_at" => Carbon::now()->format('Y-m-d H:i:s'),
                ],
                [
                    "first_name" => "Karen",
                    "last_name" => "Russell",
                    "created_at" => Carbon::now()->format('Y-m-d H:i:s'),
                    "updated_at" => Carbon::now()->format('Y-m-d H:i:s'),
                ],
                [
                    "first_name" => "Sarah",
                    "last_name" => "Broom",
                    "created_at" => Carbon::now()->format('Y-m-d H:i:s'),
                    "updated_at" => Carbon::now()->format('Y-m-d H:i:s'),
                ],
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
        Schema::dropIfExists('authors');
    }
};
