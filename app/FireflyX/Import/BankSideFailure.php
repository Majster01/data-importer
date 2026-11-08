<?php

/*
 * BankSideFailure.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace App\FireflyX\Import;

/**
 * Enable Banking answers "400 ASPSP_ERROR: Error interacting with ASPSP" when
 * the bank itself failed (typically an internal server error on its side).
 * The importer did nothing wrong and the user can do nothing about it, so the
 * last error and the notification say exactly that instead of repeating the
 * raw HTTP error. The raw error stays in the importer logs.
 */
final class BankSideFailure
{
    public const string SUMMARY = 'Bank-side failure: the bank returned an error to Enable Banking (not a problem with the importer). It is usually temporary and the next import tries again.';

    /** True for a raw importer error that is the bank failing, or for SUMMARY itself. */
    public static function matches(string $error): bool
    {
        $error = strtolower($error);

        return str_contains($error, 'aspsp_error')
            || str_contains($error, 'error interacting with aspsp')
            || str_contains($error, 'bank-side failure');
    }
}
