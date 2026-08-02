<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `is_discarded` is the source of truth for the state; `discarded_at` is
     * optional provenance. A lot of the historical library was discarded at
     * some unrecoverable point in the past, so "discarded, date unknown" has
     * to be representable — hence the separate flag rather than treating a
     * non-null timestamp as the state. Mirrors the nullable
     * `read_instances.date_read`.
     */
    public function up(): void
    {
        Schema::table('versions', function (Blueprint $table) {
            $table->boolean('is_discarded')->default(false)->after('nickname');
            $table->date('discarded_at')->nullable()->default(null)->after('is_discarded');

            $table->index('is_discarded');
        });
    }

    public function down(): void
    {
        Schema::table('versions', function (Blueprint $table) {
            $table->dropIndex(['is_discarded']);
            $table->dropColumn(['is_discarded', 'discarded_at']);
        });
    }
};
