<?php

namespace Database\Seeders;

use App\Models\Format;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * The canonical formats a fresh database needs.
 *
 * Bulk import looks formats up by name and fails every row when none exist, so
 * a reset is unusable until this has run — see `/feature-plans/reset-database.md`.
 *
 * Insert order used to be load-bearing (the SPA hardcoded `format_id === 2` for
 * Audiobook). It no longer is: the capability flags below are what the forms
 * read. The order still matches the ids in the existing development database,
 * so a fresh one and a reset one agree.
 *
 * The names are the ones actually in use, which is *not* what the code used to
 * assume — `BookController::prepareVersions` branched on a format called
 * 'Paper' that has never existed here.
 */
class FormatSeeder extends Seeder
{
    /**
     * @var array<int, array{name: string, expects_page_count: bool, expects_audio_runtime: bool}>
     */
    private const FORMATS = [
        ['name' => 'Physical', 'expects_page_count' => true, 'expects_audio_runtime' => false],
        ['name' => 'Audiobook', 'expects_page_count' => false, 'expects_audio_runtime' => true],
        ['name' => 'Pirated', 'expects_page_count' => true, 'expects_audio_runtime' => false],
        ['name' => 'Ebook', 'expects_page_count' => true, 'expects_audio_runtime' => false],
        ['name' => 'Graphic Novel', 'expects_page_count' => true, 'expects_audio_runtime' => false],
    ];

    public function run(): void
    {
        foreach (self::FORMATS as $format) {
            // updateOrCreate rather than create: the seeder is also the way to
            // repair a database whose formats predate the capability flags, and
            // re-running it must not duplicate rows or reassign ids.
            Format::updateOrCreate(
                ['name' => $format['name']],
                $format + ['slug' => Str::slug($format['name'])],
            );
        }
    }
}
