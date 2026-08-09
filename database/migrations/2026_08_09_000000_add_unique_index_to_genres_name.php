<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 of the genre work: make `genres.name` structurally unique.
 *
 * Until now `GenreService::findConflict` was the only thing standing between
 * two rows sharing a name, and it is a select-then-insert — every genre write
 * can lose a race with a concurrent request. This index closes that for all
 * four doors at once.
 *
 * Under `utf8mb4_unicode_ci` (see `config/database.php`) the index is
 * case-insensitive, which is the intent: `Fantasy` and `fantasy` are one
 * genre, and the application has always treated them that way. That also means
 * the duplicate check below is case-insensitive for free — `GROUP BY name`
 * compares at the collation.
 *
 * **This migration cannot run against a database that still holds duplicate
 * names.** That is not a style choice; MySQL will refuse to build the index.
 * Rather than let it fail with `Duplicate entry 'x' for key`, which names one
 * row and leaves the operator to find the rest, `up()` counts them first and
 * reports every offender. Clean them with the merge tool at `/admin/genres`,
 * then migrate.
 */
return new class extends Migration
{
    public function up()
    {
        $duplicates = DB::table('genres')
            ->select('name', DB::raw('COUNT(*) as total'))
            ->groupBy('name')
            ->having('total', '>', 1)
            ->pluck('total', 'name');

        if ($duplicates->isNotEmpty()) {
            $report = $duplicates
                ->map(fn ($total, $name) => "  \"{$name}\" ×{$total}")
                ->implode("\n");

            throw new RuntimeException(
                "Cannot add a unique index to `genres.name`: {$duplicates->count()} name(s) are held by more than one row.\n\n"
                .$report."\n\n"
                ."Merge them at /admin/genres (each merge folds the losing rows into one winner and moves their books across), then re-run this migration.\n"
                .'Comparison is case-insensitive, so "Fantasy" and "fantasy" count as the same name here.'
            );
        }

        Schema::table('genres', function (Blueprint $table) {
            $table->unique('name');
        });
    }

    public function down()
    {
        Schema::table('genres', function (Blueprint $table) {
            $table->dropUnique(['name']);
        });
    }
};
