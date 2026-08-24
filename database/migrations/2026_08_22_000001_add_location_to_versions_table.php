<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A physical copy is in exactly one place, so its location is a nullable
     * FK on `versions` — next to `is_discarded` / `discarded_at`, the other
     * facts that belong to the object rather than the edition. A membership
     * table could never make exclusivity a database fact; this column does.
     *
     * `nullOnDelete` is the backstop only — LocationService refuses to delete
     * a location that still shelves copies unless the caller forces it.
     *
     * `shelf_ordinal` is left-to-right position on the shelf. The backfill
     * carries it over from `list_items.ordinal`; no UI sorts on it yet.
     */
    public function up(): void
    {
        Schema::table('versions', function (Blueprint $table) {
            $table->unsignedBigInteger('location_id')->nullable()->default(null)->after('discarded_at')->index();
            $table->foreign('location_id')->references('location_id')
                ->on('locations')
                ->onDelete('set null');
            $table->unsignedInteger('shelf_ordinal')->nullable()->default(null)->after('location_id');
        });
    }

    public function down(): void
    {
        Schema::table('versions', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropIndex(['location_id']);
            $table->dropColumn(['location_id', 'shelf_ordinal']);
        });
    }
};
