<?php

namespace App\Http\Controllers;

use App\Services\BulkImportService;
use App\Services\Exceptions\BulkImportHeaderException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BulkUploadController extends Controller
{
    public function __construct(private readonly BulkImportService $service) {}

    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'csv_file' => 'required|file|mimes:csv,txt',
            'dry_run' => 'sometimes|boolean',
        ]);

        $dryRun = $request->boolean('dry_run');

        try {
            $payload = $this->service->importCsv(
                $request->file('csv_file'),
                (int) auth()->id(),
                $dryRun,
            );
        } catch (BulkImportHeaderException $e) {
            return response()->json([
                'reason_code' => $e->reasonCode,
                'reason' => $e->getMessage(),
            ], 422);
        }

        return response()->json($payload + ['dry_run' => $dryRun]);
    }
}
