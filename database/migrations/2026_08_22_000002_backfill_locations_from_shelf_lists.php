<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turn the shelf lists (`O1S5`, `H1S3`, …) into `locations` rows and point
 * every listed version at its shelf.
 *
 * The list name encodes the hierarchy: letter → room, letter+number →
 * bookcase, full code → shelf. Each level is created once and the version's
 * `shelf_ordinal` carries over from `list_items.ordinal`, so physical
 * left-to-right order survives.
 *
 * **The shelf lists are deliberately not dropped.** They stay behind this
 * migration so the old data is recoverable; deleting them is a follow-up
 * once the location UI is trusted. See /feature-plans/locations.md.
 *
 * In the style of `2026_08_09_000000_add_unique_index_to_genres_name`, this
 * refuses and names offenders rather than guessing: a version sitting on two
 * shelf lists has no single place to be, and the operator has to say which.
 * Verified against live data at planning time (465 versions, one shelf each,
 * zero conflicts), so on the real database this is a clean pass — the checks
 * exist for anyone re-running history against a drifted copy.
 */
return new class extends Migration
{
    /** Room letters seen in the live data. Unknown letters get a null name for the operator to fill in. */
    private const ROOM_NAMES = ['O' => 'Office', 'H' => 'Hallway', 'B' => 'Bedroom'];

    private const SHELF_PATTERN = '/^([A-Z])(\d+)S(\d+)$/';

    public function up(): void
    {
        $shelfLists = DB::table('lists')
            ->get(['list_id', 'name'])
            ->filter(fn ($list) => preg_match(self::SHELF_PATTERN, $list->name) === 1)
            ->sortBy('name')
            ->values();

        // A fresh database (tests, new installs) has no shelf lists and
        // nothing to backfill.
        if ($shelfLists->isEmpty()) {
            return;
        }

        $this->refuseVersionsOnMultipleShelves($shelfLists);

        DB::transaction(function () use ($shelfLists) {
            $now = now();
            $roomIds = [];
            $bookcaseIds = [];
            $shelved = 0;

            foreach ($shelfLists as $list) {
                preg_match(self::SHELF_PATTERN, $list->name, $parts);
                [, $letter, $bookcaseNumber, $shelfNumber] = $parts;

                $roomIds[$letter] ??= DB::table('locations')->insertGetId([
                    'parent_id' => null,
                    'code' => $letter,
                    'name' => self::ROOM_NAMES[$letter] ?? null,
                    'kind' => 'room',
                    'slug' => strtolower($letter),
                    'ordinal' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $bookcaseCode = $letter.$bookcaseNumber;
                $bookcaseIds[$bookcaseCode] ??= DB::table('locations')->insertGetId([
                    'parent_id' => $roomIds[$letter],
                    'code' => $bookcaseCode,
                    'name' => null,
                    'kind' => 'bookcase',
                    'slug' => strtolower($bookcaseCode),
                    'ordinal' => (int) $bookcaseNumber,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $shelfId = DB::table('locations')->insertGetId([
                    'parent_id' => $bookcaseIds[$bookcaseCode],
                    'code' => $list->name,
                    'name' => null,
                    'kind' => 'shelf',
                    'slug' => strtolower($list->name),
                    'ordinal' => (int) $shelfNumber,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $items = DB::table('list_items')
                    ->where('list_id', $list->list_id)
                    ->get(['version_id', 'ordinal']);

                foreach ($items as $item) {
                    $shelved += DB::table('versions')
                        ->where('version_id', $item->version_id)
                        ->update([
                            'location_id' => $shelfId,
                            'shelf_ordinal' => $item->ordinal,
                        ]);
                }
            }

            // Every version the shelf lists name must have landed somewhere.
            // A shortfall means a list_item points at a version that no
            // longer exists — refuse rather than commit a partial layout.
            $expected = DB::table('list_items')
                ->whereIn('list_id', $shelfLists->pluck('list_id'))
                ->distinct()
                ->count('version_id');

            if ($shelved !== $expected) {
                throw new RuntimeException(
                    "Backfill shelved {$shelved} versions but the shelf lists name {$expected}. "
                    .'Some list_items point at versions that do not exist; clean them up, then re-run this migration.'
                );
            }
        });
    }

    /**
     * @param  Collection<int, object>  $shelfLists
     */
    private function refuseVersionsOnMultipleShelves($shelfLists): void
    {
        $listNames = $shelfLists->pluck('name', 'list_id');

        $conflicts = DB::table('list_items')
            ->whereIn('list_id', $shelfLists->pluck('list_id'))
            ->get(['version_id', 'list_id'])
            ->groupBy('version_id')
            ->map(fn ($items) => $items->pluck('list_id')->unique())
            ->filter(fn ($listIds) => $listIds->count() > 1);

        if ($conflicts->isEmpty()) {
            return;
        }

        $report = $conflicts
            ->map(fn ($listIds, $versionId) => "  version {$versionId} is on ".$listIds->map(fn ($id) => $listNames[$id])->implode(', '))
            ->implode("\n");

        throw new RuntimeException(
            "Cannot backfill locations: {$conflicts->count()} version(s) sit on more than one shelf list, and a copy has exactly one place.\n\n"
            .$report."\n\n"
            .'Remove each version from all but its real shelf, then re-run this migration.'
        );
    }

    /**
     * The inverse of the backfill: unshelve everything and remove every
     * location, leaves first so the parent FK's `restrict` never trips.
     */
    public function down(): void
    {
        DB::table('versions')->update(['location_id' => null, 'shelf_ordinal' => null]);

        while (DB::table('locations')->count() > 0) {
            $parentIds = DB::table('locations')->whereNotNull('parent_id')->pluck('parent_id')->unique()->all();
            DB::table('locations')->whereNotIn('location_id', $parentIds)->delete();
        }
    }
};
