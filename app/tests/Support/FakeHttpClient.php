<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Http\HttpClient;
use App\Http\HttpException;
use App\Http\HttpResponse;
use LogicException;

/**
 * Scripted stand-in for HttpClient, used to unit-test PayPalVerifier without the network.
 *
 * PayPal verification makes TWO calls in order — fetch an OAuth2 token, then post the
 * verify-webhook-signature request. This fake is constructed with that script: a list whose entries
 * are each either an HttpResponse to return or an HttpException to throw, consumed in call order. So
 * a test reproduces any branch — token ok + verify SUCCESS, token ok + verify FAILURE, token call
 * throws, verify call non-2xx, etc. — purely in memory.
 *
 * It also records the (url, headers, body) of every call so a test can assert what was sent if it
 * wants; running out of scripted responses is a test-authoring bug, surfaced as a LogicException.
 */
final class FakeHttpClient implements HttpClient
{
    /** @var list<HttpResponse|HttpException> */
    private array $script;

    /** @var list<array{url: string, headers: array<string, string>, body: string}> */
    public array $calls = [];

    /**
     * @param list<HttpResponse|HttpException> $script Responses/throwables to play back in order.
     */
    public function __construct(array $script)
    {
        $this->script = $script;
    }

    public function post(string $url, array $headers, string $body): HttpResponse
    {
        $this->calls[] = ['url' => $url, 'headers' => $headers, 'body' => $body];

        if ($this->script === []) {
            throw new LogicException('FakeHttpClient: no scripted response left for ' . $url);
        }

        $next = array_shift($this->script);

        if ($next instanceof HttpException) {
            throw $next;
        }

        return $next;
    }
}
