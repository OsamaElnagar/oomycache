<?php

declare(strict_types=1);

namespace Oomycache\Oomycache\Payload;

use Illuminate\Contracts\Config\Repository;
use UnexpectedValueException;

/**
 * Optional compression envelope around an already-encoded payload string.
 *
 * Compression is transparent to the codec and to the cache store: a prefixed
 * string is still a string, so Laravel 13's `serializable_classes` policy is
 * irrelevant. A corrupt/truncated compressed value is treated as a cache miss
 * by the engine.
 */
final class PayloadCompressor
{
    private const string PREFIX = 'OOMYCACHE1C';

    public function __construct(private readonly Repository $config) {}

    public function enabled(): bool
    {
        return (bool) $this->config->get('oomycache.payload.compression.enabled', false);
    }

    public function threshold(): int
    {
        return max(1, (int) $this->config->get('oomycache.payload.compression.threshold_bytes', 65_536));
    }

    /**
     * Maximum decompressed size (bytes) accepted from a compressed payload.
     *
     * Guards against decompression bombs: 0 disables the cap. Both the
     * decompression call (max_length where supported) and the resulting string
     * are checked; oversized data raises UnexpectedValueException so the
     * engine treats it as a corrupt-payload miss.
     */
    public function maxBytes(): int
    {
        return max(0, (int) $this->config->get('oomycache.payload.compression.max_bytes', 2_097_152));
    }

    public function algorithm(): string
    {
        $algorithm = (string) $this->config->get('oomycache.payload.compression.algorithm', 'gzip');

        return in_array($algorithm, ['gzip', 'deflate', 'zstd'], true) ? $algorithm : 'gzip';
    }

    public function compress(string $payload): string
    {
        if (! $this->enabled() || strlen($payload) < $this->threshold()) {
            return $payload;
        }

        $max = $this->maxBytes();

        // Never create a compressed envelope the read path would reject: an
        // uncompressed payload above max_bytes is left as-is (chunking/the
        // engine's size guards handle it).
        if ($max > 0 && strlen($payload) > $max) {
            return $payload;
        }

        $compressed = match ($this->algorithm()) {
            'deflate' => function_exists('gzdeflate') ? gzdeflate($payload, 1) : false,
            'zstd' => function_exists('zstd_compress') ? zstd_compress($payload, 1) : false,
            default => function_exists('gzencode') ? gzencode($payload, 1) : false,
        };

        if (! is_string($compressed)) {
            return $payload;
        }

        $code = $this->algorithmCode();

        return self::PREFIX.$code.':'.$compressed;
    }

    public function decompress(string $payload): string
    {
        if (! str_starts_with($payload, self::PREFIX)) {
            return $payload;
        }

        $separator = strpos($payload, ':', strlen(self::PREFIX));
        $code = substr($payload, strlen(self::PREFIX), $separator === false ? 1 : $separator - strlen(self::PREFIX));
        $data = $separator === false ? '' : substr($payload, $separator + 1);

        $max = $this->maxBytes();
        $limit = $max > 0 ? $max : 0;

        $decoded = match ($code) {
            'G' => function_exists('gzdecode') ? @gzdecode($data, $limit) : false,
            'D' => function_exists('gzinflate') ? @gzinflate($data, $limit) : false,
            'Z' => $this->decompressZstd($data, $limit),
            default => false,
        };

        if (! is_string($decoded)) {
            throw new UnexpectedValueException('Compressed OoMyCache payload could not be decompressed.');
        }

        if ($max > 0 && strlen($decoded) > $max) {
            throw new UnexpectedValueException("Decompressed OoMyCache payload exceeds the [{$max}] byte safety cap.");
        }

        return $decoded;
    }

    /**
     * zstd_uncompress() has no max_length parameter, so decompress and check
     * immediately; the extension is optional, hence the capability guard.
     */
    private function decompressZstd(string $data, int $limit): false|string
    {
        if (! function_exists('zstd_uncompress')) {
            return false;
        }

        $decoded = @zstd_uncompress($data);

        if (! is_string($decoded)) {
            return false;
        }

        if ($limit > 0 && strlen($decoded) > $limit) {
            return false;
        }

        return $decoded;
    }

    private function algorithmCode(): string
    {
        return match ($this->algorithm()) {
            'deflate' => 'D',
            'zstd' => 'Z',
            default => 'G',
        };
    }
}
