<?php

use App\Statistics\Metrics\AudioRuntimeByYear;
use App\Statistics\Metrics\AverageRating;
use App\Statistics\Metrics\CompletedCount;
use App\Statistics\Metrics\CompletedPercent;
use App\Statistics\Metrics\EstimatedTotalPagesByYear;
use App\Statistics\Metrics\GenreBreakdown;
use App\Statistics\Metrics\NewestBooks;
use App\Statistics\Metrics\PagesReadByYear;
use App\Statistics\Metrics\PercentageOfBooksRead;
use App\Statistics\Metrics\RatingDistribution;
use App\Statistics\Metrics\ReadsByYear;
use App\Statistics\Metrics\TotalBooks;
use App\Statistics\Metrics\TotalBooksRead;
use App\Statistics\Metrics\TotalItems;
use App\Statistics\Metrics\TotalPages;
use App\Statistics\Metrics\TotalReads;
use App\Statistics\Metrics\UniqueBooksReadByYear;

return [

    /*
    |--------------------------------------------------------------------------
    | Metric registry
    |--------------------------------------------------------------------------
    |
    | Every metric the statistics endpoint can serve. Adding one is a class in
    | App\Statistics\Metrics plus a line here; the metric declares which scopes
    | it supports and which caveats apply to it, and the registry does the rest.
    |
    */

    'metrics' => [
        TotalBooks::class,
        TotalBooksRead::class,
        PercentageOfBooksRead::class,
        TotalReads::class,
        ReadsByYear::class,
        UniqueBooksReadByYear::class,
        PagesReadByYear::class,
        AudioRuntimeByYear::class,
        EstimatedTotalPagesByYear::class,
        AverageRating::class,
        RatingDistribution::class,
        NewestBooks::class,

        // List scope
        TotalItems::class,
        CompletedCount::class,
        CompletedPercent::class,
        TotalPages::class,
        GenreBreakdown::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Estimates
    |--------------------------------------------------------------------------
    |
    | Assumptions, not facts — which is why they live here and are echoed back
    | to the client under `meta.estimated` rather than being buried in a query.
    |
    | `pagesPerAudioMinute` converts listening time into pages so audio and
    | print can share one number. The default (~33 pages an hour) follows from
    | a typical narration pace against a ~275-word page.
    |
    */

    'estimates' => [
        'pagesPerAudioMinute' => (float) env('STATISTICS_PAGES_PER_AUDIO_MINUTE', 0.55),
    ],

];
