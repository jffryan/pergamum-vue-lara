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
        Schema::table("versions", function (Blueprint $table) {
            $table->integer("audio_runtime")->nullable()->after("page_count")->default(null);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table("versions", function (Blueprint $table) {
            $table->dropColumn("audio_runtime");
        });
    }
};
