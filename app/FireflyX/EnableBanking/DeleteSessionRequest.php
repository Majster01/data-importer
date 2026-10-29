<?php

/*
 * DeleteSessionRequest.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace App\FireflyX\EnableBanking;

use App\Exceptions\ImporterHttpException;
use App\Services\EnableBanking\Request\Request;
use App\Services\Shared\Response\Response;
use GuzzleHttp\Exception\GuzzleException;

/**
 * DELETE /sessions/{id}: "PSU's bank consent will be closed automatically if
 * possible." Upstream's Request has no DELETE; this reuses its client and
 * signed headers.
 */
final class DeleteSessionRequest extends Request
{
    public function __construct(string $url, string $sessionId)
    {
        $this->setBase($url);
        $this->setUrl(sprintf('sessions/%s', $sessionId));
    }

    public function delete(): void
    {
        try {
            $this->getClient()->request('DELETE', sprintf('%s/%s', $this->getBase(), $this->getUrl()), ['headers' => $this->getHeaders()]);
        } catch (GuzzleException $e) {
            throw new ImporterHttpException(sprintf('Enable Banking API error: %s', $e->getMessage()), 0, $e);
        }
    }

    public function get(): Response
    {
        throw new ImporterHttpException('DeleteSessionRequest does not support GET');
    }

    public function post(): Response
    {
        throw new ImporterHttpException('DeleteSessionRequest does not support POST');
    }
}
