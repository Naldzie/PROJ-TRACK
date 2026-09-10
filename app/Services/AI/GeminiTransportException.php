<?php

namespace App\Services\AI;

use RuntimeException;

/**
 * Carrying exception for Gemini transport failures (E3 fallback policy).
 *
 * Extends RuntimeException so every existing catch (controllers, tests) keeps
 * working untouched — messages and shapes are unchanged. The added status +
 * quotaId let the NORMAL-DOC sender decide a one-shot Lite fallback, which the
 * Excel sender deliberately never takes (a Lite S-curve in a baseline is the
 * exact failure two weeks removed).
 */
class GeminiTransportException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly ?string $quotaId = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }
}
