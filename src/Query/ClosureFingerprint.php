<?php

declare(strict_types=1);

namespace Oomycache\Oomycache\Query;

use Closure;
use Oomycache\Oomycache\Exceptions\UnkeyableValueException;
use ReflectionException;
use ReflectionFunction;

/**
 * Produces a stable-ish fingerprint for an eager-load constraint closure.
 *
 * Two `with(['posts' => fn ($q) => $q->where(...)])` calls must never share a
 * cache entry unless the closures are identical. Reflection position + static
 * variables is a pragmatic fingerprint; if anything cannot be represented the
 * query runs uncached.
 */
final class ClosureFingerprint
{
    public static function of(?Closure $closure): ?string
    {
        if ($closure === null) {
            return null;
        }

        try {
            $reflection = new ReflectionFunction($closure);
        } catch (ReflectionException) {
            throw UnkeyableValueException::forEagerLoad('closure');
        }

        $file = $reflection->getFileName();

        if ($file === false) {
            throw UnkeyableValueException::forEagerLoad('closure-without-file');
        }

        return Fingerprint::hash([
            'file' => self::relativePath($file),
            'start' => $reflection->getStartLine(),
            'end' => $reflection->getEndLine(),
            'scope' => $reflection->getClosureScopeClass()?->getName(),
            'static' => self::canonicalizeStaticVariables($reflection->getStaticVariables()),
        ]);
    }

    /**
     * Laravel wraps every eager load in a closure; for plain `with('x')` the
     * closure's static variables include the parsed relation array, whose
     * values are further closures. Recursively fingerprint those instead of
     * treating them as unkeyable bindings.
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    private static function canonicalizeStaticVariables(array $variables): array
    {
        $canonical = [];

        foreach ($variables as $key => $value) {
            $canonical[(string) $key] = self::canonicalizeStaticValue($value);
        }

        return $canonical;
    }

    private static function canonicalizeStaticValue(mixed $value): mixed
    {
        if ($value instanceof Closure) {
            return ['__closure' => self::of($value)];
        }

        if (is_array($value)) {
            return array_map(self::canonicalizeStaticValue(...), $value);
        }

        if (is_object($value)) {
            // Unknown object state cannot be represented deterministically.
            throw UnkeyableValueException::forBinding($value);
        }

        return Fingerprint::canonicalizeValue($value);
    }

    private static function relativePath(string $file): string
    {
        $base = function_exists('base_path') ? base_path() : '';

        if ($base !== '' && str_starts_with($file, $base)) {
            return ltrim(substr($file, strlen($base)), '\\/');
        }

        return $file;
    }
}
