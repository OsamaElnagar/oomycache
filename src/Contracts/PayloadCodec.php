<?php

declare(strict_types=1);

namespace Oomycache\Oomycache\Contracts;

/**
 * Encodes and decodes cache payloads.
 *
 * Implementations must only ever return strings from encode(), never live
 * PHP objects, so the cache store's own serialisation/`allowed_classes`
 * policy can never break the payload (see research/07 §5).
 */
interface PayloadCodec
{
    public function encode(mixed $value): string;

    public function decode(string $payload): mixed;
}
