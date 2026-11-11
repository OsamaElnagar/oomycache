<?php

declare(strict_types=1);

namespace Oomycache\Oomycache\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Oomycache\Oomycache\Relations\Concerns\FlushesPivotWrites;

/**
 * BelongsToMany relation that invalidates both sides of the relation after a
 * pivot-table write. Pivot writes do not dispatch Eloquent model events.
 *
 * @extends BelongsToMany<Model, Model, Pivot, 'pivot'>
 */
final class CachingBelongsToMany extends BelongsToMany
{
    use FlushesPivotWrites;
}
