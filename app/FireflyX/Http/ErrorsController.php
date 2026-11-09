<?php

/*
 * ErrorsController.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace App\FireflyX\Http;

use App\FireflyX\Models\Connection;
use App\FireflyX\Models\ImportRun;
use App\FireflyX\Models\ManualImport;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * GET /api/fireflyx/v1/errors?date=2026-11-09&connection=nlb: the errors of
 * the nightly and manual imports that started on one day, for Pico's errors
 * page. The day is the app's own, as in the status timeline.
 */
final class ErrorsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $date = $request->query('date', Carbon::today()->format('Y-m-d'));
        $day  = is_string($date) && 1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? Carbon::createFromFormat('!Y-m-d', $date) : false;
        if (false === $day || $day->format('Y-m-d') !== $date) {
            return response()->json(['error' => 'date must be a day, as 2026-11-09'], 422);
        }
        $only = $request->query('connection');
        if (null !== $only && (!is_string($only) || 1 !== preg_match('/^[a-z0-9-]+$/', $only))) {
            return response()->json(['error' => 'connection must be a connection name'], 422);
        }

        $window  = [$day, $day->copy()->endOfDay()];
        $runs    = ImportRun::whereBetween('created_at', $window)->get();
        $manuals = ManualImport::with(['connection' => static fn ($query) => $query->withTrashed()])->whereBetween('created_at', $window)->get();
        $banks   = Connection::withTrashed()->pluck('bank_name', 'name')->all();

        return response()->json(['data' => ErrorDay::build($day, $runs, $manuals, $banks, $only)]);
    }
}
