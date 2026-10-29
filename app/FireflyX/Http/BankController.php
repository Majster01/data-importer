<?php

/*
 * BankController.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace App\FireflyX\Http;

use App\FireflyX\EnableBanking\EnableBankingGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The banks Enable Banking offers, for the "add a connection" picker in Pico.
 */
final class BankController extends Controller
{
    public function __construct(private readonly EnableBankingGateway $gateway) {}

    public function index(Request $request): JsonResponse
    {
        $country = strtoupper((string) $request->query('country'));
        if (1 !== preg_match('/^[A-Z]{2}$/', $country)) {
            return response()->json(['error' => 'country must be a two-letter code, e.g. SI'], 422);
        }

        try {
            return response()->json(['data' => $this->gateway->listBanks($country)]);
        } catch (Throwable $e) {
            Log::error(sprintf('FireflyX: could not list the banks of %s: %s', $country, $e->getMessage()));

            return response()->json(['error' => 'Enable Banking could not list the banks.', 'detail' => $e->getMessage()], 502);
        }
    }
}
