<?php

/*
 * ManualImportController.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace App\FireflyX\Http;

use App\FireflyX\Import\ManualImportLauncher;
use App\FireflyX\Import\PsuDetails;
use App\FireflyX\Models\Connection;
use App\FireflyX\Models\ManualImport;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * "Import now" from Pico: the saved connection, imported over a time frame the
 * user picks, followed by the category suggester over the same time frame.
 */
final class ManualImportController extends Controller
{
    public function __construct(private readonly ManualImportLauncher $launcher) {}

    /**
     * POST /api/fireflyx/v1/connections/{name}/imports {date_from, date_to, psu_ip?, psu_user_agent?}
     *
     * The optional psu_* fields are the tapping user's address and User-Agent,
     * which mark the run as user-initiated at the bank. Unusable ones are
     * ignored: tagging is never a reason to refuse the import.
     */
    public function store(Request $request, string $name): JsonResponse
    {
        $connection = Connection::with('accounts')->where('name', $name)->firstOrFail();
        $from       = self::parseDay($request->input('date_from'));
        $to         = self::parseDay($request->input('date_to'));
        if (!$from instanceof Carbon || !$to instanceof Carbon) {
            return response()->json(['error' => 'date_from and date_to must be dates (YYYY-MM-DD)'], 422);
        }
        if ($from->greaterThan($to) || $to->greaterThan(Carbon::today())) {
            return response()->json(['error' => 'The time frame must end today at the latest and not start after it ends'], 422);
        }
        if (!$connection->isImportable()) {
            return response()->json(['error' => sprintf('This connection cannot be imported (%s)', $connection->status())], 422);
        }

        // Check and create under one lock, or two taps could both pass.
        $psu = ['psu_ip' => PsuDetails::ip($request->input('psu_ip')), 'psu_user_agent' => PsuDetails::userAgent($request->input('psu_user_agent'))];
        foreach ($psu as $field => $value) {
            if (null === $value && null !== $request->input($field)) {
                // Never the value itself.
                Log::info(sprintf('FireflyX: manual import of "%s": %s was not usable and is left out.', $name, $field));
            }
        }
        $run = Cache::lock('fireflyx-manual-import', 10)->block(5, static function () use ($connection, $from, $to, $psu): ?ManualImport {
            if (ManualImport::active()->exists()) {
                return null;
            }
            // Nothing is running, so any run still holding a user's details died before it could wipe them.
            ManualImport::query()->where(static fn ($q) => $q->whereNotNull('psu_ip')->orWhereNotNull('psu_user_agent'))
                ->update(['psu_ip' => null, 'psu_user_agent' => null]);

            return $connection->manualImports()->create(['date_from' => $from, 'date_to' => $to, 'status' => ManualImport::QUEUED, ...$psu]);
        });
        if (!$run instanceof ManualImport) {
            return response()->json(['error' => 'Another manual import is still running'], 409);
        }

        try {
            $this->launcher->launch($run);
        } catch (Throwable $e) {
            $run->update(['status' => ManualImport::FAILED, 'error' => 'The import could not be started.', 'finished_at' => Carbon::now(), 'psu_ip' => null, 'psu_user_agent' => null]);
        }

        return response()->json(['data' => $run->fresh()->present()], 202);
    }

    /**
     * Strictly YYYY-MM-DD: "2026-02-31" must not roll over into March.
     */
    private static function parseDay(mixed $value): ?Carbon
    {
        if (!is_string($value) || 1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $day = Carbon::createFromFormat('!Y-m-d', $value);

        return $day instanceof Carbon && $day->format('Y-m-d') === $value ? $day : null;
    }
}
