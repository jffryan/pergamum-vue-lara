<?php

namespace App\Services\BulkImport;

use App\Models\Format;
use Carbon\Carbon;

/**
 * One CSV row that has passed every validation gate, ready to persist. Produced by
 * BulkImportService::validateRow and consumed by its persist step — the only reason
 * this type exists is to carry those nine values across that boundary with names and
 * types instead of a nine-key associative array.
 */
readonly class ImportRow
{
    /**
     * @param  array<int, array{first: string, last: string, slug: string}>  $authors
     * @param  array<int, string>  $genres
     */
    public function __construct(
        public string $title,
        public array $authors,
        public Format $format,
        public int $pageCount,
        public ?int $audioRuntime,
        public ?string $nickname,
        public array $genres,
        public ?Carbon $dateRead,
        public ?float $rating,
    ) {}
}
