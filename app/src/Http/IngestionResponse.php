<?php

declare(strict_types=1);

namespace App\Http;

/**
 * The result of handling an ingestion request: an HTTP status code and an optional,
 * already-encoded JSON body. The IngestionController returns this; the front
 * controller (T1.6) renders it. Keeping it a value object — rather than letting the
 * controller write to globals — is what makes the controller testable without a live
 * request.
 *
 * The status CODE is the contract with providers; they ignore the body. So bodies are
 * deliberately tiny and constant. NFR-1: a body NEVER carries a secret, a signature,
 * a verification reason, or any payload byte.
 */
final readonly class IngestionResponse
{
    public function __construct(
        private int $statusCode,
        private ?string $body = null,
    ) {
    }

    /** 202 Accepted: the event was verified and recorded (or already was — dedupe). */
    public static function accepted(): self
    {
        return new self(202, '{"status":"accepted"}');
    }

    /** A non-2xx rejection (401 bad signature, 502 verifier outage, 500 misconfig/infra, 404 unknown). */
    public static function rejected(int $statusCode): self
    {
        return new self($statusCode, '{"status":"rejected"}');
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function body(): ?string
    {
        return $this->body;
    }
}
