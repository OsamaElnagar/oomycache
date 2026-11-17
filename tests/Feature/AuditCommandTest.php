<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

/**
 * Shared temp-directory state for the audit command tests.
 */
function oomycacheAuditTempDir(?string $set = null): string
{
    static $dir = '';

    if ($set !== null) {
        $dir = $set;
    }

    return $dir;
}

function oomycacheAuditRemoveDir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($items as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }

    rmdir($dir);
}

function oomycacheAuditWriteFixture(string $dir): void
{
    file_put_contents($dir.'/BadWriter.php', <<<'PHP'
<?php

class BadWriter
{
    public function quiet($model): void
    {
        $model->saveQuietly();
        \Illuminate\Database\Eloquent\Model::withoutEvents(function () {});
    }

    public function raw(): void
    {
        \Illuminate\Support\Facades\DB::table('users')->where('id', 1)->update(['name' => 'x']);
        \Illuminate\Support\Facades\DB::statement('UPDATE users SET name = ?', ['x']);
    }

    public function pivots($user): void
    {
        $user->roles()->attach(1);
        $user->roles()->sync([3]);
    }
}
PHP);
}

beforeEach(function (): void {
    $dir = sys_get_temp_dir().'/oomycache-audit-'.bin2hex(random_bytes(6));
    mkdir($dir, 0777, true);
    oomycacheAuditTempDir($dir);
    $this->app->useAppPath($dir);
});

afterEach(function (): void {
    oomycacheAuditRemoveDir(oomycacheAuditTempDir());
});

it('detects quiet, raw DB and pivot writes grouped by category', function (): void {
    oomycacheAuditWriteFixture(oomycacheAuditTempDir());

    $this->artisan('oomycache:audit')
        ->expectsOutputToContain('Quiet writes')
        ->expectsOutputToContain('Raw DB writes')
        ->expectsOutputToContain('Pivot writes')
        ->expectsOutputToContain('BadWriter.php:')
        ->assertSuccessful();
});

it('reports path and line for a raw DB write with a hint', function (): void {
    oomycacheAuditWriteFixture(oomycacheAuditTempDir());

    Artisan::call('oomycache:audit');
    $output = Artisan::output();

    expect($output)->toContain('BadWriter.php:')
        ->and($output)->toContain('saveQuietly() skips model events')
        ->and($output)->toContain('Raw DB::table() write bypasses invalidation');
});

it('emits structured findings with --json', function (): void {
    oomycacheAuditWriteFixture(oomycacheAuditTempDir());

    Artisan::call('oomycache:audit', ['--json' => true]);
    $data = json_decode(Artisan::output(), true);

    expect($data)->toBeArray()
        ->and($data['Quiet writes'])->not->toBeEmpty()
        ->and($data['Raw DB writes'])->not->toBeEmpty()
        ->and($data['Pivot writes'])->not->toBeEmpty()
        ->and($data['Raw DB writes'][0]['path'])->toBe('BadWriter.php')
        ->and($data['Raw DB writes'][0]['line'])->toBeInt();
});

it('handles a missing application path gracefully', function (): void {
    $missing = oomycacheAuditTempDir().'/does-not-exist';
    $this->app->useAppPath($missing);

    $this->artisan('oomycache:audit')
        ->expectsOutputToContain('No unobservable write paths found')
        ->assertSuccessful();
});
