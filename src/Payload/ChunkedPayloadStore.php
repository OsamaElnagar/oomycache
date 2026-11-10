<?php

declare(strict_types=1);

namespace Oomycache\Oomycache\Payload;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Throwable;

/**
 * Transparent chunked storage for encoded payloads that exceed the configured
 * chunk size.
 *
 * Write pipeline: encode → compress → chunk.
 *   $payload = $codec->encode($value);        // JSON string, never an object
 *   $payload = $compressor->compress($payload); // optional compressed string
 *   put(): $payload is split into `{$key}:c:{$index}` chunks and a manifest
 *   string is written under `{$key}` (marker + chunk count + total bytes +
 *   xxh128 hash of the compressed payload).
 *
 * Read pipeline: manifest → chunks → concat → verify → decompress → decode.
 *   read() returns the exact compressed string the engine originally stored,
 *   or null when the manifest/chunks are missing or corrupt (the caller treats
 *   null as a cache miss).
 *
 * Invariants:
 *  - Chunks and manifest share the same TTL; forever payloads use forever()
 *    for all keys.
 *  - The manifest is written last, so a crash between writes leaves orphaned
 *    chunks that expire naturally instead of a manifest pointing at nothing.
 *  - Corrupt/partial data never throws out of the cache path: cleanup is
 *    best-effort and the method returns null.
 *  - Invalidation stays version-token based (the logical key embeds the
 *    version vector), so stale chunk generations become unreachable and are
 *    reclaimed by TTL — this class never needs to find them for correctness.
 */
final class ChunkedPayloadStore
{
    public const string MANIFEST_PREFIX = 'OOMYCACHEC1:';

    public const int MANIFEST_VERSION = 1;

    /**
     * Hard structural bounds applied to manifests read from the cache; they
     * protect against corrupt or malicious manifests with absurd chunk counts
     * while staying independent of the current configuration.
     */
    private const int MAX_MANIFEST_CHUNKS = 4096;

    private const int MAX_MANIFEST_BYTES = 268_435_456;

    private const string CHUNK_SEPARATOR = ':c:';

    public function __construct(private readonly ConfigRepository $config) {}

    public function enabled(): bool
    {
        return (bool) $this->config->get('oomycache.payload.chunking.enabled', true);
    }

    /**
     * @return int<1, max>
     */
    public function chunkBytes(): int
    {
        return max(1, (int) $this->config->get('oomycache.payload.chunking.chunk_bytes', 262_144));
    }

    public function maxChunks(): int
    {
        $configured = (int) $this->config->get('oomycache.payload.chunking.max_chunks', 64);

        return max(1, min($configured, self::MAX_MANIFEST_CHUNKS));
    }

    /**
     * Maximum total (reconstructed) payload size accepted by this store.
     *
     * 0 disables the cap. Applies to compressed/encoded payload byte length at
     * write time and to the manifest's declared size at read time.
     */
    public function maxTotalBytes(): int
    {
        return max(0, (int) $this->config->get('oomycache.payload.chunking.max_total_bytes', 2_097_152));
    }

    /**
     * Whether a payload of the given byte length exceeds the total-size budget.
     */
    public function exceedsTotalBudget(int $bytes): bool
    {
        $max = $this->maxTotalBytes();

        return $max > 0 && $bytes > $max;
    }

    /**
     * Number of chunks a payload of the given byte length needs.
     */
    public function chunkCount(int $bytes): int
    {
        return max(1, (int) ceil($bytes / $this->chunkBytes()));
    }

    /**
     * Whether a payload of the given byte length must be split into chunks.
     */
    public function shouldChunk(int $bytes): bool
    {
        return $this->enabled() && $bytes > $this->chunkBytes();
    }

    public function isManifest(string $payload): bool
    {
        return str_starts_with($payload, self::MANIFEST_PREFIX);
    }

    public function chunkKey(string $key, int $index): string
    {
        return $key.self::CHUNK_SEPARATOR.$index;
    }

    /**
     * @return list<string>
     */
    public function chunkKeys(string $key, int $count): array
    {
        $keys = [];

        for ($index = 0; $index < $count; $index++) {
            $keys[] = $this->chunkKey($key, $index);
        }

        return $keys;
    }

    /**
     * Store a payload as a chunk set plus manifest.
     *
     * @param  int  $ttl  seconds, or 0 for "forever"
     */
    public function put(Repository $repository, string $key, string $payload, int $ttl): bool
    {
        $bytes = strlen($payload);

        // Defence in depth: the engine already bypasses oversized payloads, but
        // the store refuses them independently so no caller can create a chunk
        // set above payload.chunking.max_total_bytes.
        if ($this->exceedsTotalBudget($bytes)) {
            return false;
        }

        $values = [];

        foreach (str_split($payload, $this->chunkBytes()) as $index => $chunk) {
            $values[$this->chunkKey($key, (int) $index)] = $chunk;
        }

        $manifest = $this->encodeManifest(count($values), $bytes, hash('xxh128', $payload));

        try {
            $chunksStored = $ttl === 0
                ? $repository->setMultiple($values, null)
                : $repository->setMultiple($values, $ttl);

            if (! $chunksStored) {
                $this->cleanup($repository, $key, array_keys($values));

                return false;
            }

            $manifestStored = $ttl === 0
                ? $repository->forever($key, $manifest)
                : $repository->put($key, $manifest, $ttl);

            if (! $manifestStored) {
                $this->cleanup($repository, $key, array_keys($values));

                return false;
            }

            return true;
        } catch (Throwable) {
            $this->cleanup($repository, $key, array_keys($values));

            return false;
        }
    }

    /**
     * Resolve a manifest into the original compressed payload string.
     *
     * Returns null for a corrupt/partial entry after best-effort cleanup.
     */
    public function read(Repository $repository, string $key, string $manifest): ?string
    {
        try {
            $meta = $this->decodeManifest($manifest);

            if ($meta === null) {
                $this->forget($repository, $key);

                return null;
            }

            // Read-time total-size cap: check the declared size before fetching
            // any chunk keys so a forged manifest cannot force a huge read.
            if ($this->exceedsTotalBudget($meta['bytes'])) {
                $this->forget($repository, $key);

                return null;
            }

            $chunks = [];

            foreach ($repository->getMultiple($this->chunkKeys($key, $meta['chunks'])) as $chunkKey => $value) {
                $chunks[(string) $chunkKey] = $value;
            }

            $payload = '';

            for ($index = 0; $index < $meta['chunks']; $index++) {
                $chunk = $chunks[$this->chunkKey($key, $index)] ?? null;

                if (! is_string($chunk)) {
                    $this->forget($repository, $key);

                    return null;
                }

                $payload .= $chunk;
            }

            if (strlen($payload) !== $meta['bytes'] || ! hash_equals($meta['hash'], hash('xxh128', $payload))) {
                $this->forget($repository, $key);

                return null;
            }

            return $payload;
        } catch (Throwable) {
            $this->forget($repository, $key);

            return null;
        }
    }

    /**
     * Best-effort removal of a payload and, when the value is a manifest, its
     * chunks. Never throws: cleanup only supports the miss path.
     */
    public function forget(Repository $repository, string $key): void
    {
        try {
            $manifest = $repository->get($key);
            $meta = is_string($manifest) ? $this->decodeManifest($manifest) : null;

            $this->cleanup($repository, $key, $meta !== null ? $this->chunkKeys($key, $meta['chunks']) : []);
        } catch (Throwable) {
            // Best effort only: the entry is already unreachable as a valid hit.
        }
    }

    /**
     * Best-effort removal of the manifest, the given chunk keys and the
     * logical key. Never throws.
     *
     * @param  array<int, string>  $chunkKeys
     */
    private function cleanup(Repository $repository, string $key, array $chunkKeys): void
    {
        try {
            if ($chunkKeys !== []) {
                $repository->deleteMultiple(array_values($chunkKeys));
            }

            $repository->delete($key);
        } catch (Throwable) {
            // Best effort only: the entry is already unreachable as a valid hit.
        }
    }

    /**
     * @return array{chunks: int, bytes: int, hash: string}|null
     */
    public function decodeManifest(string $manifest): ?array
    {
        if (! $this->isManifest($manifest)) {
            return null;
        }

        try {
            $data = json_decode(substr($manifest, strlen(self::MANIFEST_PREFIX)), true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($data)) {
            return null;
        }

        $version = $data['v'] ?? null;
        $chunks = $data['n'] ?? null;
        $bytes = $data['bytes'] ?? null;
        $hash = $data['hash'] ?? null;

        if ($version !== self::MANIFEST_VERSION) {
            return null;
        }

        if (! is_int($chunks) || $chunks < 1 || $chunks > self::MAX_MANIFEST_CHUNKS) {
            return null;
        }

        if (! is_int($bytes) || $bytes < 1 || $bytes > self::MAX_MANIFEST_BYTES) {
            return null;
        }

        if (! is_string($hash) || strlen($hash) !== 32 || ! ctype_xdigit($hash)) {
            return null;
        }

        return [
            'chunks' => $chunks,
            'bytes' => $bytes,
            'hash' => strtolower($hash),
        ];
    }

    private function encodeManifest(int $chunks, int $bytes, string $hash): string
    {
        return self::MANIFEST_PREFIX.json_encode([
            'v' => self::MANIFEST_VERSION,
            'n' => $chunks,
            'bytes' => $bytes,
            'hash' => $hash,
        ], JSON_THROW_ON_ERROR);
    }
}
