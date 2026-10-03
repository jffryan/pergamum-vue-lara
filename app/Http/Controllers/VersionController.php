<?php

namespace App\Http\Controllers;

use App\Http\Requests\MoveVersionRequest;
use App\Http\Requests\StoreVersionRequest;
use App\Models\Version;
use App\Services\Exceptions\CopyDiscardedException;
use App\Services\LocationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VersionController extends Controller
{
    public function __construct(protected LocationService $locationService) {}

    /**
     * Add a copy to an existing book, optionally shelving it as it's made.
     *
     * Shelving goes through `LocationService::shelveVersion` — the picker's
     * call, and the new-book door's — inside the same transaction as the
     * insert, so a copy is never left half-made on a bad shelf. A fresh copy
     * is never discarded, so the service's discarded-copy refusal can't fire.
     */
    public function addNewVersion(StoreVersionRequest $request)
    {
        $version = DB::transaction(function () use ($request) {
            $version = Version::create($request->versionAttributes());

            if ($request->locationId() !== null) {
                $this->locationService->shelveVersion($version, $request->locationId());
            }

            return $version;
        });

        // refresh() so DB-side defaults (is_discarded) are in the payload,
        // and `format` / `location` because the book page's copy row renders
        // both — the SPA appends this response to the book it holds.
        return response()->json($version->refresh()->load('format', 'location'), 201);
    }

    /**
     * Mark a copy as discarded — we used to own it, we no longer do.
     *
     * `discarded_at` is optional: much of the historical library was got rid
     * of at an unrecoverable point in the past, so omitting the key (or
     * sending null) records "discarded, date unknown". Omitting the key
     * entirely on a re-discard preserves whatever date is already there.
     */
    public function discard(Request $request, $version_id)
    {
        $validated = $request->validate([
            'discarded_at' => 'nullable|date',
        ]);

        $version = Version::findOrFail($version_id);

        $version->is_discarded = true;

        if ($request->has('discarded_at')) {
            $version->discarded_at = $validated['discarded_at'] ?? null;
        }

        // A copy you no longer own is not on a shelf: it moves to the
        // virtual "Discarded" location, which is derived from `is_discarded`
        // (see `VirtualLocations`), and `shelveVersion` refuses to put it
        // back on one while it stays discarded. Restoring does not reshelve
        // — the copy comes back "unshelved" and gets placed by hand.
        $version->location_id = null;
        $version->shelf_ordinal = null;

        // Discarding also ends a loan — the usual way a lent copy gets here
        // is by never coming back. A copy is in the pile or out on loan,
        // never both, and `lend` refuses the other direction.
        $version->is_on_loan = false;
        $version->loaned_to = null;
        $version->loaned_at = null;

        $version->save();

        // `location` and not just `format`: the SPA merges this response over
        // the copy it already holds, so a response that omits the relation
        // leaves the shelf it was just taken off of sitting in the payload.
        return response()->json($version->load('format', 'location'));
    }

    /**
     * Undo a discard — we have the copy again. Clears the date along with the
     * flag so a later re-discard doesn't inherit a stale one.
     */
    public function restore($version_id)
    {
        $version = Version::findOrFail($version_id);

        $version->fill([
            'is_discarded' => false,
            'discarded_at' => null,
        ])->save();

        return response()->json($version->load('format', 'location'));
    }

    /**
     * Mark a copy as lent out — still ours, currently someone else's.
     *
     * Unlike discard this leaves the location alone: the shelf is where the
     * copy goes back to, so a lent copy is listed on its shelf (flagged) and
     * in the virtual "On loan" location, and returning it is just clearing
     * the loan. `loaned_to` (free text — whoever has it) and `loaned_at` are
     * both optional and follow discard's key rule: omitting a key on a
     * re-lend keeps what is there, sending null clears it.
     */
    public function lend(Request $request, $version_id)
    {
        $validated = $request->validate([
            'loaned_to' => 'nullable|string|max:255',
            'loaned_at' => 'nullable|date',
        ]);

        $version = Version::findOrFail($version_id);

        // Same reason code the shelve path uses: a discarded copy is in the
        // pile, not anywhere a copy you own can be.
        if ($version->is_discarded) {
            return response()->json([
                'reason_code' => 'copy_discarded',
                'reason' => 'A discarded copy cannot be lent. Restore it first.',
            ], 422);
        }

        $version->is_on_loan = true;

        foreach (['loaned_to', 'loaned_at'] as $key) {
            if ($request->has($key)) {
                $version->{$key} = $validated[$key] ?? null;
            }
        }

        $version->save();

        return response()->json($version->load('format', 'location'));
    }

    /**
     * The copy is back. Clears the details with the flag, so the next loan
     * doesn't inherit the last borrower. The location was never touched, so
     * the copy is on its shelf again with nothing else to do.
     */
    public function returnFromLoan($version_id)
    {
        $version = Version::findOrFail($version_id);

        $version->fill([
            'is_on_loan' => false,
            'loaned_to' => null,
            'loaned_at' => null,
        ])->save();

        return response()->json($version->load('format', 'location'));
    }

    /**
     * Shelve, reshelve, or unshelve one copy — `location_id: null` unshelves.
     * The single-FK update is the whole point of locations over lists. A
     * discarded copy is a 422: it is in the discarded pile, not on a shelf.
     */
    public function setLocation(MoveVersionRequest $request, $version_id)
    {
        $version = Version::findOrFail($version_id);

        try {
            $this->locationService->shelveVersion($version, $request->locationId(), $request->shelfOrdinal());
        } catch (CopyDiscardedException $e) {
            return response()->json([
                'reason_code' => $e->reasonCode,
                'reason' => $e->getMessage(),
            ], 422);
        }

        return response()->json($version->load('format', 'location'));
    }
}
