<?php

/*
 * PostSessionRawRequest.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace App\FireflyX\EnableBanking;

use App\Exceptions\ImporterHttpException;
use App\Services\EnableBanking\Request\Request;
use App\Services\Shared\Response\Response;

/**
 * POST /sessions as the raw JSON, so the consent trace can show exactly what
 * Enable Banking answered (upstream's SessionResponse keeps only some fields).
 */
final class PostSessionRawRequest extends Request
{
    public function __construct(string $url, private readonly string $code)
    {
        $this->setBase($url);
        $this->setUrl('sessions');
    }

    public function fetch(): array
    {
        return $this->authenticatedPost(['code' => $this->code]);
    }

    public function get(): Response
    {
        throw new ImporterHttpException('Use fetch()');
    }

    public function post(): Response
    {
        throw new ImporterHttpException('Use fetch()');
    }
}
