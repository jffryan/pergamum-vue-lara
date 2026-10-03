<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A lent copy is still owned — unlike a discarded one — so the loan sits
     * beside `location_id` rather than replacing it: the shelf is the copy's
     * home and stays set while it is out. `is_on_loan` is the state, the
     * `discarded_at` rule again: `loaned_to` and `loaned_at` are both
     * optional ("lent to someone, some time"), so neither can stand in for
     * the flag.
     */
    public function up(): void
    {
        Schema::table('versions', function (Blueprint $table) {
            $table->boolean('is_on_loan')->default(false)->after('discarded_at');
            $table->string('loaned_to')->nullable()->default(null)->after('is_on_loan');
            $table->date('loaned_at')->nullable()->default(null)->after('loaned_to');

            $table->index('is_on_loan');
        });
    }

    public function down(): void
    {
        Schema::table('versions', function (Blueprint $table) {
            $table->dropIndex(['is_on_loan']);
            $table->dropColumn(['is_on_loan', 'loaned_to', 'loaned_at']);
        });
    }
};
