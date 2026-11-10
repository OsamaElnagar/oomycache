<?php

declare(strict_types=1);

use Oomycache\Oomycache\Exceptions\UnkeyableValueException;
use Oomycache\Oomycache\Query\Fingerprint;
use Oomycache\Oomycache\Query\TableRecorder;

it('canonicalises equivalent bindings to the same hash', function (): void {
    $first = Fingerprint::hash([
        'sql' => 'select * from products where id = ?',
        'bindings' => Fingerprint::canonicalizeBindings([5, true, null]),
    ]);

    $second = Fingerprint::hash([
        'sql' => 'select * from products where id = ?',
        'bindings' => Fingerprint::canonicalizeBindings([5, true, null]),
    ]);

    expect($first)->toBe($second)
        ->and(strlen($first))->toBe(32);
});

it('normalises date bindings by value', function (): void {
    $date = new DateTimeImmutable('2026-11-09 12:00:00 UTC');

    $first = Fingerprint::hash(['bindings' => Fingerprint::canonicalizeBindings([$date])]);
    $second = Fingerprint::hash(['bindings' => Fingerprint::canonicalizeBindings([clone $date])]);

    expect($first)->toBe($second);
});

it('refuses to fingerprint unrepresentable objects', function (): void {
    Fingerprint::canonicalizeBindings([new stdClass]);
})->throws(UnkeyableValueException::class);

it('records and normalises table aliases and joins', function (): void {
    $recorder = new TableRecorder;

    $recorder->record('products as p');
    $recorder->record('`categories`');
    $recorder->recordAlias('latest_orders');

    expect($recorder->tables())->toBe(['products', 'categories', 'latest_orders']);
});
