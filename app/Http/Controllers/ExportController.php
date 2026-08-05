<?php

namespace App\Http\Controllers;

use App\Services\CatalogExportService;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    public function __construct(private readonly CatalogExportService $service) {}

    /**
     * The catalog plus this user's reading state, as a bulk-importable CSV.
     *
     * Streamed rather than assembled: the file this produces is the one you
     * reach for when the database is already in trouble, so it should not need
     * the whole catalog resident to succeed.
     */
    public function download(): StreamedResponse
    {
        $userId = (int) auth()->id();
        $filename = 'pergamum-export-'.Carbon::now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($userId) {
            $handle = fopen('php://output', 'w');

            foreach ($this->service->rows($userId) as $row) {
                fputcsv($handle, $row);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }
}
