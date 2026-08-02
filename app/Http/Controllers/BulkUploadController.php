<?php

namespace App\Http\Controllers;

use App\Services\BulkImportService;
use App\Services\Exceptions\BulkImportFileException;
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
            // `required` rather than `nullable` so a blank-but-present name is a
            // standard {message, errors} 422 instead of an empty-slug list.
            'list_name' => 'sometimes|required|string|max:255',
        ]);

        $dryRun = $request->boolean('dry_run');

        try {
            $payload = $this->service->importCsv(
                $request->file('csv_file'),
                (int) auth()->id(),
                $dryRun,
                $request->input('list_name'),
            );
        } catch (BulkImportFileException $e) {
            return response()->json([
                'reason_code' => $e->reasonCode,
                'reason' => $e->getMessage(),
            ], 422);
        }

        return response()->json($payload + ['dry_run' => $dryRun]);
    }
}
