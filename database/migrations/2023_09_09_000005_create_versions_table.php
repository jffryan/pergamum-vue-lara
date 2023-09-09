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
        Schema::create('versions', function (Blueprint $table) {
            $table->id("version_id");
            $table->integer("page_count");
            $table->unsignedBigInteger("format_id")->index();
            $table->foreign("format_id")->references("format_id")
                ->on("formats")
                ->onDelete("cascade");
            $table->unsignedBigInteger("book_id")->index();
            $table->foreign("book_id")->references("book_id")
                ->on("books")
                ->onDelete("cascade");
            $table->timestamps();
        });


        DB::table("versions")->insert(
            [
                [
                    "page_count" => 267,
                    "format_id" => 1,
                    "book_id" => 1,
                    "created_at" => Carbon::now()->format('Y-m-d H:i:s'),
                    "updated_at" => Carbon::now()->format('Y-m-d H:i:s'),
                ],
                [
                    "page_count" => 304,
                    "format_id" => 1,
                    "book_id" => 2,
                    "created_at" => Carbon::now()->format('Y-m-d H:i:s'),
                    "updated_at" => Carbon::now()->format('Y-m-d H:i:s'),
                ],
                [
                    "page_count" => 180,
                    "format_id" => 1,
                    "book_id" => 3,
                    "created_at" => Carbon::now()->format('Y-m-d H:i:s'),
                    "updated_at" => Carbon::now()->format('Y-m-d H:i:s'),
                ],
                [
                    "page_count" => 326,
                    "format_id" => 1,
                    "book_id" => 4,
                    "created_at" => Carbon::now()->format('Y-m-d H:i:s'),
                    "updated_at" => Carbon::now()->format('Y-m-d H:i:s'),
                ],
                [
                    "page_count" => 208,
                    "format_id" => 2,
                    "book_id" => 5,
                    "created_at" => Carbon::now()->format('Y-m-d H:i:s'),
                    "updated_at" => Carbon::now()->format('Y-m-d H:i:s'),
                ],
                [
                    "page_count" => 397,
                    "format_id" => 1,
                    "book_id" => 6,
                    "created_at" => Carbon::now()->format('Y-m-d H:i:s'),
                    "updated_at" => Carbon::now()->format('Y-m-d H:i:s'),
                ],
                [
                    "page_count" => 376,
                    "format_id" => 1,
                    "book_id" => 7,
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
        Schema::dropIfExists('versions');
    }
};
