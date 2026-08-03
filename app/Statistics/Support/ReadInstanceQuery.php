<?php

namespace App\Statistics\Support;

use App\Models\ReadInstance;
use App\Statistics\Scope;
use Illuminate\Database\Eloquent\Builder;

/**
 * The shared read-history base query.
 *
 * Every read-derived metric starts here, so scope narrowing and the version
 * join are written once. Reads are always the *requesting user's* reads,
 * whatever the scope is about.
 *
 * Discarded copies are deliberately not filtered out: reads aggregate over
 * `read_instances`, and getting rid of a book later doesn't undo having read
 * it. Shelf state belongs to metrics that describe the shelf.
 */
class ReadInstanceQuery
{
    private function __construct(private Builder $query) {}

    public static function forScope(Scope $scope): self
    {
        $query = ReadInstance::query()->where('read_instances.user_id', $scope->userId);

        // Narrowing by scope happens here rather than in each metric: a list's
        // average rating is the user's rating of *any* copy of a listed book,
        // not only of the copy that happens to be on the list.
        if ($scope->is(Scope::LIST)) {
            $query->whereIn('read_instances.book_id', ListQuery::bookIds($scope));
        }

        return new self($query);
    }

    /**
     * Join the version the read was recorded against.
     *
     * The `book_id` predicate rides along with the `version_id` one: a read
     * instance whose version belongs to a different book would otherwise
     * contribute that book's page count. `ReadInstance::booted()` blocks new
     * mismatches, but historical rows predate it.
     */
    public function joinVersions(): self
    {
        $this->query->join('versions', function ($join) {
            $join->on('read_instances.version_id', '=', 'versions.version_id')
                ->on('read_instances.book_id', '=', 'versions.book_id');
        });

        return $this;
    }

    public function query(): Builder
    {
        return $this->query;
    }

    /**
     * Aggregate the current query per calendar year of `date_read`.
     *
     * Undated reads are excluded — they have no year to sit in. Metrics that
     * shouldn't lose them (`totalReads`) don't go through here.
     *
     * @param  string  $select  SQL aggregate, e.g. `COUNT(*)`
     * @return array<int, array{year: int, total: int}> newest year first
     */
    public function groupedByYear(string $select): array
    {
        return $this->query
            ->selectRaw("YEAR(read_instances.date_read) as year, {$select} as total")
            ->whereNotNull('read_instances.date_read')
            ->groupBy('year')
            ->orderBy('year', 'desc')
            ->get()
            ->map(fn ($row) => ['year' => (int) $row->year, 'total' => (int) $row->total])
            ->all();
    }
}
