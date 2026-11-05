<?php

/*
 * ManualImportController.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace App\FireflyX\Http;

use App\FireflyX\Import\ManualImportLauncher;
use App\FireflyX\Models\Connection;
use App\FireflyX\Models\ManualImport;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * "Import now" from Pico: the saved connection, imported over a time frame the
 * user picks, followed by the category suggester over the same time frame.
 */
final class ManualImportController extends Controller
{
    public function __construct(private readonly ManualImportLauncher $launcher) {}

    /**
     * POST /api/fireflyx/v1/connections/{name}/imports {date_from, date_to}
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

        $psuIp      = self::cleanIp($request->input('psu.ip_address'));
        $psuAgent   = self::cleanAgent($request->input('psu.user_agent'));

        // Check and create under one lock, or two taps could both pass.
        $run = Cache::lock('fireflyx-manual-import', 10)->block(5, static function () use ($connection, $from, $to, $psuIp, $psuAgent): ?ManualImport {
            if (ManualImport::active()->exists()) {
                return null;
            }

            return $connection->manualImports()->create([
                'date_from'      => $from,
                'date_to'        => $to,
                'status'         => ManualImport::QUEUED,
                'psu_ip_address' => $psuIp,
                'psu_user_agent' => $psuAgent,
            ]);
        });
        if (!$run instanceof ManualImport) {
            return response()->json(['error' => 'Another manual import is still running'], 409);
        }

        try {
            $this->launcher->launch($run);
        } catch (Throwable $e) {
            $run->update(['status' => ManualImport::FAILED, 'error' => 'The import could not be started.', 'finished_at' => Carbon::now()]);
        }

        return response()->json(['data' => $run->fresh()->present()], 202);
    }

    /**
     * A public address only: a private or reserved one (the proxy's own, say)
     * would tell the bank nothing, so it is dropped rather than sent.
     */
    private static function cleanIp(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return false === filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) ? null : $value;
    }

    /**
     * Printable ASCII only (it becomes an HTTP header value) and bounded.
     */
    private static function cleanAgent(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = substr(trim((string) preg_replace('/[^\x20-\x7e]+/', ' ', $value)), 0, 512);

        return '' === $value ? null : $value;
    }

    /**
     * Strictly YYYY-MM-DD:"2026-02-31" must not roll over into March.
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
