<?php

namespace App\Http\Controllers;

use App\Http\Requests\StatisticsRequest;
use App\Statistics\MetricRegistry;

class StatisticsController extends Controller
{
    public function __construct(private MetricRegistry $registry) {}

    /**
     * One endpoint for every statistics surface: resolve the scope, validate
     * the requested metrics against it, compute, and describe the result.
     */
    public function show(StatisticsRequest $request)
    {
        $scope = $request->scope();
        $payload = $this->registry->compute($scope, $request->metricKeys());

        return response()->json([
            'scope' => $scope->toArray(),
            'metrics' => (object) $payload['metrics'],
            'meta' => $payload['meta'],
        ]);
    }
}
