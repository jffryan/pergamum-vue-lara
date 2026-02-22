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
        Schema::create('versions', function (Blueprint $table) {
            $table->id("version_id");
            $table->integer("page_count");
            $table->integer("audio_runtime")->nullable()->default(null);
            $table->unsignedBigInteger("format_id")->index();
            $table->foreign("format_id")->references("format_id")
                ->on("formats")
                ->onDelete("cascade");
            $table->unsignedBigInteger("book_id")->index();
            $table->foreign("book_id")->references("book_id")
                ->on("books")
                ->onDelete("cascade");
            $table->string("nickname")->nullable();
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
        Schema::dropIfExists('versions');
    }
};
