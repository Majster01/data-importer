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
 * GET /api/fireflyx/v1/errors?from=2026-10-10&to=2026-11-09&connection=nlb&source=nightly&q=iban&page=1&limit=50
 *
 * The errors of the nightly and manual imports that started in the days from
 * from..to (the app's own days, as in the status timeline), one entry per error
 * message, for Pico's errors page. The last 30 days unless asked otherwise.
 */
final class ErrorsController extends Controller
{
    private const int DEFAULT_DAYS = 30;
    private const int MAX_DAYS     = 366;
    private const int MAX_LIMIT    = 100;

    public function show(Request $request): JsonResponse
    {
        $to   = self::day($request->query('to', Carbon::today()->format('Y-m-d')));
        $from = self::day($request->query('from', false === $to ? '' : $to->copy()->subDays(self::DEFAULT_DAYS - 1)->format('Y-m-d')));
        if (false === $to || false === $from) {
            return response()->json(['error' => 'from and to must be days, as 2026-11-09'], 422);
        }
        // By calendar day: a difference in hours is off by one across a change of daylight saving time
        if ($from->greaterThan($to) || $to->greaterThan($from->copy()->addDays(self::MAX_DAYS - 1))) {
            return response()->json(['error' => sprintf('from must not be after to, and at most %d days before it', self::MAX_DAYS - 1)], 422);
        }
        $connection = $request->query('connection');
        $source     = $request->query('source');
        $search     = $request->query('q');
        if ((null !== $connection && (!is_string($connection) || 1 !== preg_match('/^[a-z0-9-]+$/', $connection)))
            || (null !== $source && !in_array($source, ErrorFeed::SOURCES, true))
            || (null !== $search && (!is_string($search) || mb_strlen($search) > 100))) {
            return response()->json(['error' => 'connection must be a connection name, source nightly or manual, q at most 100 characters'], 422);
        }
        $page  = filter_var($request->query('page', 1), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $limit = filter_var($request->query('limit', 50), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => self::MAX_LIMIT]]);
        if (false === $page || false === $limit) {
            return response()->json(['error' => sprintf('page must be a number from 1, limit from 1 to %d', self::MAX_LIMIT)], 422);
        }

        $window  = [$from, $to->copy()->endOfDay()];
        $runs    = ImportRun::whereBetween('created_at', $window)->get();
        $manuals = ManualImport::with(['connection' => static fn ($query) => $query->withTrashed()])->whereBetween('created_at', $window)->get();
        $banks   = Connection::withTrashed()->pluck('bank_name', 'name')->all();

        return response()->json(['data' => [
            'from' => $from->format('Y-m-d'),
            'to'   => $to->format('Y-m-d'),
            ...ErrorFeed::build($runs, $manuals, $banks, ['connection' => $connection, 'source' => $source, 'q' => $search], $page, $limit),
        ]]);
    }

    private static function day(mixed $value): Carbon|false
    {
        if (!is_string($value) || 1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }
        $day = Carbon::createFromFormat('!Y-m-d', $value);

        return false !== $day && $day->format('Y-m-d') === $value ? $day : false;
    }
}
