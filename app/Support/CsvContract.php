<?php

namespace App\Support;

/**
 * The bulk CSV's column vocabulary, shared by the reader and the writer.
 *
 * `BulkImportService` validates a header against {@see COLUMNS} and
 * `CatalogExportService` emits exactly that list in exactly that order. Held in
 * one place because the two only roundtrip while they agree: a column added to
 * the importer and forgotten by the exporter is a field that silently stops
 * surviving a reset, which is the failure this whole surface exists to prevent.
 *
 * Only {@see REQUIRED_COLUMNS} must be present in an uploaded header. Every
 * other column is optional on the way in — a file of nothing but paperbacks
 * needn't carry an `audio_runtime` column — and always present on the way out.
 */
class CsvContract
{
    /**
     * Column presence only. Whether a *value* is required is a per-row question
     * the row's format answers.
     */
    public const REQUIRED_COLUMNS = ['title', 'authors', 'format'];

    public const COLUMNS = [
        'title',
        'authors',
        'format',
        'page_count',
        'audio_runtime',
        'version_nickname',
        'genres',
        'date_read',
        'rating',
        'is_discarded',
        'discarded_at',
        'lists',
    ];

    /** Date formats accepted on the way in. The first is what the export writes. */
    public const DATE_FORMATS = ['Y-m-d', 'n/j/Y', 'm/d/Y'];
}
