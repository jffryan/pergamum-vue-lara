<?php

namespace App\Services\BulkImport;

use App\Models\Format;
use Carbon\Carbon;

/**
 * One CSV row that has passed every validation gate, ready to persist. Produced by
 * BulkImportService::validateRow and consumed by its persist step — the only reason
 * this type exists is to carry those values across that boundary with names and
 * types instead of a wide associative array.
 */
readonly class ImportRow
{
    /**
     * @param  array<int, array{first: string, last: string, slug: string}>  $authors
     * @param  array<int, string>  $genres
     * @param  array<int, array{name: string, ordinal: ?int}>  $lists
     */
    public function __construct(
        public string $title,
        public array $authors,
        public Format $format,
        public ?int $pageCount,
        public ?int $audioRuntime,
        public ?string $nickname,
        public array $genres,
        public ?Carbon $dateRead,
        public ?float $rating,
        public bool $isDiscarded = false,
        public ?Carbon $discardedAt = null,
        public array $lists = [],
        // The shelf `code` ('O1S5') and optional left-to-right position — a
        // copy has exactly one place, so unlike `lists` this is one value.
        public ?string $location = null,
        public ?int $shelfOrdinal = null,
    ) {}
}
