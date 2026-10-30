<?php

/*
 * GetSessionRequest.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace App\FireflyX\EnableBanking;

use App\Exceptions\ImporterHttpException;
use App\Services\EnableBanking\Request\Request;
use App\Services\Shared\Response\Response;

/**
 * Reuses upstream's Request for the JWT, headers and error handling.
 */
final class GetSessionRequest extends Request
{
    public function __construct(string $url, string $sessionId)
    {
        $this->setBase($url);
        $this->setUrl(sprintf('sessions/%s', $sessionId));
    }

    public function get(): Response
    {
        return new SessionDetails($this->authenticatedGet());
    }

    /** The response as Enable Banking sent it (for the consent trace). */
    public function fetch(): array
    {
        return $this->authenticatedGet();
    }

    public function post(): Response
    {
        throw new ImporterHttpException('GetSessionRequest does not support POST');
    }
}
