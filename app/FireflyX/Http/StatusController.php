<?php

/*
 * StatusController.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace App\FireflyX\Http;

use App\FireflyX\Models\Connection;
use App\FireflyX\Models\ConnectionEvent;
use App\FireflyX\Models\ImportRun;
use App\FireflyX\Models\ManualImport;
use App\FireflyX\Suggester\SuggesterClient;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * The numbers behind the status page in Pico: how the nightly imports went,
 * where every connection stands, and what the category suggester has been
 * doing. The suggester's part is fetched here so Pico needs no token of its
 * own for it (the importer is the only holder of the suggester's).
 */
final class StatusController extends Controller
{
    private const int DEFAULT_DAYS = 30;
    private const int MAX_DAYS     = 365;
    private const int RECENT       = 10;
    private const int EVENTS       = 30;

    public function __construct(private readonly SuggesterClient $suggester) {}

    /**
     * GET /api/fireflyx/v1/status?days=30
     */
    public function show(Request $request): JsonResponse
    {
        $days = filter_var($request->query('days', self::DEFAULT_DAYS), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => self::MAX_DAYS]]);
        if (false === $days) {
            return response()->json(['error' => sprintf('days must be a number from 1 to %d', self::MAX_DAYS)], 422);
        }
        $since = Carbon::today()->subDays($days - 1);

        return response()->json(['data' => [
            'generated_at' => Carbon::now()->toW3cString(),
            'window_days'  => $days,
            'imports'      => $this->imports($since, $days),
            'manual'       => $this->manual($since),
            'connections'  => $this->connections(),
            'events'       => $this->events($since),
            'suggester'    => $this->suggesterStats($days),
        ]]);
    }

    /**
     * The nightly imports. `days` has an entry for every day of the window,
     * newest first, with ran=false for a day nothing was imported: that gap is
     * the "cron never ran" signal.
     */
    private function imports(Carbon $since, int $days): array
    {
        /** @var Collection<int, ImportRun> $runs */
        $runs     = ImportRun::where('created_at', '>=', $since)->orderByDesc('created_at')->orderByDesc('id')->get();
        $perDay   = $runs->groupBy(static fn (ImportRun $run): string => $run->created_at->format('Y-m-d'));
        $timeline = [];
        for ($i = 0; $i < $days; ++$i) {
            $date       = Carbon::today()->subDays($i)->format('Y-m-d');
            $day        = $perDay->get($date, collect());
            $timeline[] = ['date' => $date, 'ran' => $day->isNotEmpty(), ...self::sum($day), 'connections' => $day->count()];
        }
        $first = ImportRun::oldest('created_at')->first();

        return [
            'tracking_since'  => $first?->created_at->toW3cString(),
            'totals'          => ['runs' => $runs->count(), ...self::sum($runs)],
            'days'            => $timeline,
            'recent_failures' => $runs->where('ok', false)->take(self::RECENT)->map(static fn (ImportRun $run): array => [
                'at'         => $run->created_at->toW3cString(),
                'connection' => $run->connection_name,
                'error'      => $run->first_error,
            ])->values()->all(),
        ];
    }

    /**
     * @param Collection<int, ImportRun> $runs
     *
     * @return array{ok: int, failed: int, created: int, warnings: int, errors: int}
     */
    private static function sum(Collection $runs): array
    {
        return [
            'ok'       => $runs->where('ok', true)->count(),
            'failed'   => $runs->where('ok', false)->count(),
            'created'  => (int) $runs->sum('created'),
            'warnings' => (int) $runs->sum('warnings'),
            'errors'   => (int) $runs->sum('errors'),
        ];
    }

    /**
     * "Import now" runs in the window, by how they ended.
     */
    private function manual(Carbon $since): array
    {
        $runs = ManualImport::with('connection')->where('created_at', '>=', $since)->orderByDesc('id')->get();

        return [
            'total'  => $runs->count(),
            'done'   => $runs->where('status', ManualImport::DONE)->count(),
            'failed' => $runs->filter(static fn (ManualImport $run): bool => ManualImport::FAILED === $run->present()['status'])->count(),
            'recent' => $runs->take(5)->map(static fn (ManualImport $run): array => ['connection' => $run->connection?->name, ...$run->present()])->values()->all(),
        ];
    }

    /**
     * No call to Enable Banking here (unlike GET /connections): this page is
     * opened often, and a bank limits unattended requests per day.
     */
    private function connections(): array
    {
        return Connection::where('lifecycle', '!=', Connection::LIFECYCLE_PENDING)->orderBy('name')->get()->map(static fn (Connection $connection): array => [
            'name'           => $connection->name,
            'bank'           => $connection->bank_name,
            'status'         => $connection->status(),
            'days_left'      => $connection->daysLeft(),
            'last_import_at' => $connection->last_import_at?->toW3cString(),
            'last_import_ok' => $connection->last_import_ok,
            'last_error'     => $connection->last_error,
        ])->all();
    }

    /**
     * What happened to the bank connections in the window: consents started,
     * declined or completed, refreshes that failed, consents that ended. Newest
     * first; `totals` count every event of the window, `recent` only the latest.
     */
    private function events(Carbon $since): array
    {
        $window = ConnectionEvent::where('created_at', '>=', $since);

        return [
            'totals' => [
                ConnectionEvent::ERROR   => (clone $window)->where('level', ConnectionEvent::ERROR)->count(),
                ConnectionEvent::WARNING => (clone $window)->where('level', ConnectionEvent::WARNING)->count(),
            ],
            'recent' => (clone $window)->orderByDesc('id')->limit(self::EVENTS)->get()->map(static fn (ConnectionEvent $event): array => $event->present())->all(),
        ];
    }

    private function suggesterStats(int $days): array
    {
        if (!$this->suggester->configured()) {
            return ['available' => false, 'error' => 'The category suggester is not configured.'];
        }
        try {
            return ['available' => true, ...$this->suggester->stats($days)];
        } catch (RuntimeException $e) {
            return ['available' => false, 'error' => $e->getMessage()];
        }
    }
}
