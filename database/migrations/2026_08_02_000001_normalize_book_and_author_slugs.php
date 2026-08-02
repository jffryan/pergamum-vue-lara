<?php

use App\Support\Slugger;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        $this->normalize(
            'books',
            'book_id',
            fn ($row) => Slugger::for($row->title),
            'book',
        );

        $this->normalize(
            'authors',
            'author_id',
            fn ($row) => Slugger::for(trim(($row->first_name ?? '').' '.$row->last_name)),
            'author',
        );
    }

    /**
     * Intentionally empty. Truncation is lossy — the pre-normalization slug cannot be
     * recovered from the stored one, so there is nothing to reverse.
     */
    public function down()
    {
        //
    }

    /**
     * Recompute every row's slug through the shared helper and write only where it
     * differs. Recomputing all rows (rather than only over-long ones) makes this a
     * definition of correctness rather than a patch for one symptom, and makes a
     * second run a no-op.
     */
    private function normalize(string $table, string $pk, Closure $derive, string $fallbackPrefix): void
    {
        $taken = [];
        $updates = [];

        // Every row walks the $taken map even when its slug is unchanged: truncation can
        // make two distinct long titles collide, and a truncated candidate can collide
        // with an untouched short-slug row. Walking all of them is what makes the -2/-3
        // suffix assignment deterministic.
        DB::table($table)->orderBy($pk)->each(function ($row) use (&$taken, &$updates, $derive, $pk, $fallbackPrefix) {
            $base = $derive($row);

            if ($base === '') {
                $base = $fallbackPrefix.'-'.$row->{$pk};
            }

            $candidate = $base;
            $suffix = 2;
            while (isset($taken[$candidate])) {
                $candidate = $base.'-'.$suffix++;
            }
            $taken[$candidate] = true;

            if ($row->slug !== $candidate) {
                $updates[] = ['pk' => $row->{$pk}, 'slug' => $candidate];
            }
        });

        // Both slug columns are uniquely indexed, so writing finals in place can collide
        // with a row that has not been rewritten yet (a truncated candidate landing on a
        // short slug still held by a later row). Park every changing row on a temporary
        // slug first; underscores never survive Str::slug, so these cannot collide with
        // a real slug.
        foreach ($updates as $update) {
            DB::table($table)
                ->where($pk, $update['pk'])
                ->update(['slug' => '__migrating_'.$update['pk']]);
        }

        foreach ($updates as $update) {
            DB::table($table)
                ->where($pk, $update['pk'])
                ->update(['slug' => $update['slug']]);
        }
    }
};
