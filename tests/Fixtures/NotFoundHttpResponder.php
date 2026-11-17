<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Response factory of the test HTTP client: every request answers 404, so
 * tests never reach the network and the code takes its offline paths.
 */
final class NotFoundHttpResponder
{
    public function __invoke(string $method, string $url): MockResponse
    {
        return new MockResponse('', ['http_code' => 404]);
    }
}
