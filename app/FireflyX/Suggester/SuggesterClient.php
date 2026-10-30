<?php

/*
 * SuggesterClient.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace App\FireflyX\Suggester;

use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Asks the category suggester to look at the uncategorized withdrawals of a
 * time frame. It answers once the run is over, which can take minutes.
 */
class SuggesterClient
{
    public function configured(): bool
    {
        return '' !== (string) config('fireflyx.suggester_url') && '' !== (string) config('fireflyx.suggester_token');
    }

    /**
     * The suggester's run history and what became of its suggestions, as it
     * answers GET /stats (see services/category-suggester/suggester/serve.py).
     *
     * @throws RuntimeException
     */
    public function stats(int $days): array
    {
        try {
            $response = Http::withToken((string) config('fireflyx.suggester_token'))
                ->acceptJson()
                ->timeout((int) config('fireflyx.suggester_stats_timeout'))
                ->get(rtrim((string) config('fireflyx.suggester_url'), '/').'/stats', ['days' => $days])
            ;
        } catch (ConnectionException $e) {
            throw new RuntimeException('The category suggester is not reachable.', 0, $e);
        }
        if (!$response->successful()) {
            throw new RuntimeException((string) ($response->json('error') ?? sprintf('The category suggester answered %d.', $response->status())));
        }

        return (array) $response->json();
    }

    /**
     * @return array{written: int}
     *
     * @throws RuntimeException
     */
    public function suggest(Carbon $from, Carbon $to): array
    {
        try {
            $response = Http::withToken((string) config('fireflyx.suggester_token'))
                ->acceptJson()
                ->timeout((int) config('fireflyx.suggester_timeout'))
                ->post(rtrim((string) config('fireflyx.suggester_url'), '/').'/suggest', [
                    'since' => $from->format('Y-m-d'),
                    'until' => $to->format('Y-m-d'),
                ])
            ;
        } catch (ConnectionException $e) {
            throw new RuntimeException('The category suggester is not reachable.', 0, $e);
        }
        if (!$response->successful()) {
            throw new RuntimeException((string) ($response->json('error') ?? sprintf('The category suggester answered %d.', $response->status())));
        }

        return ['written' => (int) $response->json('written', 0)];
    }
}
