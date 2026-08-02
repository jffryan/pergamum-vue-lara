<?php

namespace App\Services\BulkImport;

use App\Models\BookList;
use App\Models\ListItem;
use App\Models\Version;

/**
 * Files every version a successful row resolved into one brand-new list.
 *
 * The list is created lazily, inside the first successful row's transaction, so an
 * import where every row fails leaves nothing behind and the name stays free for a
 * retry. Bookkeeping is snapshotted per row so a rolled-back row claims nothing.
 */
class ListCollector
{
    private ?BookList $list = null;

    /** @var array<int, string> identity keys already filed, in insertion order */
    private array $seen = [];

    private int $ordinal = 0;

    /** @var array{0: array<int, string>, 1: int, 2: ?BookList} */
    private array $snapshot = [[], 0, null];

    public function __construct(
        private readonly string $name,
        private readonly string $slug,
        private readonly int $userId,
        private readonly bool $dryRun,
    ) {}

    public function beginRow(): void
    {
        $this->snapshot = [$this->seen, $this->ordinal, $this->list];
    }

    /**
     * Undo what this row claimed. `list` is snapshotted too: restoring it drops a list
     * created *inside* the rolled-back row (so the next successful row creates it
     * again) while keeping one committed by an earlier row.
     */
    public function rollBackRow(): void
    {
        [$this->seen, $this->ordinal, $this->list] = $this->snapshot;
    }

    /**
     * Append a version to the list. Called inside the row's transaction.
     *
     * The seen-set is mandatory, not an optimization: several re-read rows for one
     * paperback resolve to the same version and `(list_id, version_id)` is uniquely
     * indexed, so without it the second row would throw and be reported as an
     * `internal_error`.
     *
     * Dedupe is on $key — the version's identity tuple — rather than on `version_id`,
     * because under dry-run each row's transaction rolls back and the *same* version is
     * re-created with a fresh id on every row. In a real run the two are equivalent
     * (one tuple resolves to exactly one version), so this costs nothing and makes the
     * dry-run item count exact.
     */
    public function add(Version $version, string $key): void
    {
        if (in_array($key, $this->seen, true)) {
            return;
        }

        // A dry run writes nothing at all. The bookkeeping below still advances, so the
        // reported item count is exact rather than an artifact of per-row rollback.
        if (! $this->dryRun) {
            $this->list ??= BookList::create([
                'name' => $this->name,
                'slug' => $this->slug,
                'user_id' => $this->userId,
            ]);

            ListItem::create([
                'list_id' => $this->list->list_id,
                'version_id' => $version->version_id,
                'ordinal' => $this->ordinal,
            ]);
        }

        $this->seen[] = $key;
        $this->ordinal++;
    }

    /**
     * The response's `list` block. `list_id` is null when the list was requested but
     * never created — a dry run, or an import with no successful rows.
     */
    public function toArray(): array
    {
        return [
            'list_id' => $this->list?->list_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'items_added' => count($this->seen),
        ];
    }
}
