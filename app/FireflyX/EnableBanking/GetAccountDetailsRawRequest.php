<?php

/*
 * GetAccountDetailsRawRequest.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace App\FireflyX\EnableBanking;

use App\Exceptions\ImporterHttpException;
use App\Services\EnableBanking\Request\Request;
use App\Services\Shared\Response\Response;

/**
 * GET /accounts/{uid}/details as the raw JSON, so BankAccount can keep Enable
 * Banking's own identification_hash (upstream's response re-hashes it).
 */
final class GetAccountDetailsRawRequest extends Request
{
    public function __construct(string $url, string $accountUid)
    {
        $this->setBase($url);
        $this->setUrl(sprintf('accounts/%s/details', $accountUid));
    }

    public function fetch(): array
    {
        return $this->authenticatedGet();
    }

    public function get(): Response
    {
        throw new ImporterHttpException('Use fetch()');
    }

    public function post(): Response
    {
        throw new ImporterHttpException('GetAccountDetailsRawRequest does not support POST');
    }
}
