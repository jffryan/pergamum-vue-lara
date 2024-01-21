<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\BacklogItem;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('backlog_items', function (Blueprint $table) {
            $table->integer('backlog_ordinal')->nullable();
        });

        // Update existing rows with an ascending ordinal
        BacklogItem::orderBy('created_at') // or any other column you prefer
            ->get()
            ->each(function (BacklogItem $item, $index) {
                $item->backlog_ordinal = $index + 1; // +1 to start counting from 1
                $item->save();
            });
    }

    public function down()
    {
        Schema::table('backlog_items', function (Blueprint $table) {
            $table->dropColumn('backlog_ordinal');
        });
    }
};
