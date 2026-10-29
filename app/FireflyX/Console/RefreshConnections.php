<?php

/*
 * RefreshConnections.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace App\FireflyX\Console;

use App\FireflyX\EnableBanking\SessionRefresher;
use App\FireflyX\Models\Connection;
use Illuminate\Console\Command;

/**
 * The nightly consent check. Exits non-zero when a consent has ended, or when
 * one is exactly --warn-at days from ending, so the nightly run alerts on
 * those days without nagging every day in between.
 */
final class RefreshConnections extends Command
{
    protected $description = 'Refresh every bank connection\'s consent status from Enable Banking.';
    protected $signature   = 'fireflyx:connections:refresh
        {--warn-at=14,7,3 : Days left on which to alert}
        {--json : Print the result as JSON}';

    public function handle(SessionRefresher $refresher): int
    {
        $warnAt = array_map('intval', array_filter(explode(',', (string) $this->option('warn-at')), 'is_numeric'));
        $rows   = [];
        $alert  = false;

        foreach (Connection::with('accounts')->orderBy('name')->get() as $connection) {
            $connection = $refresher->refresh($connection);
            $status     = $connection->status();
            $daysLeft   = $connection->daysLeft();
            $warn       = 'expired' === $status || (null !== $daysLeft && in_array($daysLeft, $warnAt, true));
            $alert      = $alert || $warn;
            $rows[]     = [
                'name'       => $connection->name,
                'status'     => $status,
                'days_left'  => $daysLeft,
                'expires_at' => $connection->valid_until?->toW3cString(),
                'error'      => $connection->last_error,
                'alert'      => $warn,
            ];
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($rows as $row) {
                $this->line(sprintf(
                    '%s%s: %s, %s',
                    $row['alert'] ? '! ' : '',
                    $row['name'],
                    $row['status'],
                    null === $row['days_left'] ? 'expiry unknown' : sprintf('%d day(s) left', $row['days_left'])
                ));
            }
        }

        return $alert ? self::FAILURE : self::SUCCESS;
    }
}
