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
        Schema::create('read_instances', function (Blueprint $table) {
            $table->id("read_instance_id");
            $table->foreignId('user_id')
                ->constrained('users', 'user_id')
                ->cascadeOnDelete();
            $table->foreignId("book_id")
                ->constrained("books", "book_id")
                ->cascadeOnDelete();
            $table->foreignId('version_id')
                ->nullable()
                ->constrained('versions', 'version_id')
                ->nullOnDelete();
            $table->date("date_read");
            $table->unsignedTinyInteger('rating')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'date_read']);
            $table->index(['book_id', 'date_read']);
            $table->index(['user_id', 'book_id']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('read_instances');
    }
};
