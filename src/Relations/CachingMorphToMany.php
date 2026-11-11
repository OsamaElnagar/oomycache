<?php

declare(strict_types=1);

namespace Oomycache\Oomycache\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Oomycache\Oomycache\Relations\Concerns\FlushesPivotWrites;

/**
 * MorphToMany relation that invalidates both sides after a polymorphic pivot
 * write. Covers morphToMany() and the morphedByMany() inverse.
 *
 * @extends MorphToMany<Model, Model, MorphPivot, 'pivot'>
 */
final class CachingMorphToMany extends MorphToMany
{
    use FlushesPivotWrites;
}
