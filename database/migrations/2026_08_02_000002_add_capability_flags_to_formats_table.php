<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Formats declare which length fields they carry.
 *
 * Before this, "does this version have an audio runtime" was answered by
 * matching `$format->name` against 'Audiobook' / 'Paper' in the backend and by
 * a hardcoded `format_id === 2` in four frontend files. Both break the moment
 * a format is renamed, re-seeded, or added — the format row now says so
 * itself.
 *
 * Defaults describe a print format (pages, no runtime), which is what every
 * existing row except Audiobook is.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::table('formats', function (Blueprint $table) {
            $table->boolean('expects_page_count')->default(true)->after('slug');
            $table->boolean('expects_audio_runtime')->default(false)->after('expects_page_count');
        });

        // Matched on name because that is the only signal the old code had.
        // Case-insensitive under the default collation, which is deliberate —
        // 'audiobook' and 'Audiobook' were both reachable through POST /formats.
        DB::table('formats')
            ->where('name', 'Audiobook')
            ->update([
                'expects_page_count' => false,
                'expects_audio_runtime' => true,
            ]);

        // An audiobook has no page count. The column being NOT NULL is why
        // `prepareVersions` had to invent one, and why callers sending null
        // for a format that genuinely has no pages hit an integrity-constraint
        // violation instead of a validation error.
        Schema::table('versions', function (Blueprint $table) {
            $table->integer('page_count')->nullable()->change();
        });
    }

    public function down()
    {
        // Existing null page counts would violate the restored NOT NULL, so
        // they go back to the 0 that audiobooks used to be stored with.
        DB::table('versions')->whereNull('page_count')->update(['page_count' => 0]);

        Schema::table('versions', function (Blueprint $table) {
            $table->integer('page_count')->nullable(false)->change();
        });

        Schema::table('formats', function (Blueprint $table) {
            $table->dropColumn(['expects_page_count', 'expects_audio_runtime']);
        });
    }
};
