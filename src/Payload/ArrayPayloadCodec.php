<?php

declare(strict_types=1);

namespace Oomycache\Oomycache\Payload;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Oomycache\Oomycache\Contracts\PayloadCodec;
use stdClass;
use UnexpectedValueException;

/**
 * Default payload codec: stores raw model attributes + relation tree as a
 * JSON string and rehydrates through newFromBuilder()/setRelation().
 *
 * Storing a string (never live objects) means Laravel's default
 * `cache.serializable_classes = false` policy cannot break the payload; the
 * decoded values are exactly what a fresh DB read would hydrate.
 */
final class ArrayPayloadCodec implements PayloadCodec
{
    private const string HEADER = 'OOMYCACHE1:';

    public function encode(mixed $value): string
    {
        return self::HEADER.json_encode(
            $this->normalize($value),
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    public function decode(string $payload): mixed
    {
        if (! str_starts_with($payload, self::HEADER)) {
            throw new UnexpectedValueException('Unknown OoMyCache payload header.');
        }

        $data = json_decode(substr($payload, strlen(self::HEADER)), true, 512, JSON_THROW_ON_ERROR);

        return $this->denormalize($data);
    }

    private function normalize(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            return (array) $value;
        }

        if ($value instanceof Model) {
            $relations = [];

            foreach ($value->getRelations() as $name => $relation) {
                $relations[$name] = $this->normalize($relation);
            }

            return [
                '__model' => $value::class,
                'connection' => $value->getConnectionName(),
                'attributes' => $value->getAttributes(),
                'relations' => $relations,
            ];
        }

        if ($value instanceof EloquentCollection) {
            return [
                '__eloquent_collection' => $value::class,
                'items' => $value->map(fn (Model $model): mixed => $this->normalize($model))->all(),
            ];
        }

        if ($value instanceof Collection) {
            return [
                '__collection' => $value->map(fn (mixed $item): mixed => $this->normalize($item))->all(),
            ];
        }

        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->normalize($item), $value);
        }

        return $value;
    }

    private function denormalize(mixed $value): mixed
    {
        if (! is_array($value) || ! isset($value['__model']) && ! isset($value['__eloquent_collection']) && ! isset($value['__collection'])) {
            return $value;
        }

        if (isset($value['__model'])) {
            /** @var class-string<Model> $class */
            $class = $value['__model'];

            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                throw new UnexpectedValueException("Cached payload references an unknown model [{$class}].");
            }

            /** @var Model $model */
            $model = new $class;
            $model = $model->newFromBuilder(
                is_array($value['attributes'] ?? null) ? $value['attributes'] : [],
                $value['connection'] ?? null,
            );

            foreach ((array) ($value['relations'] ?? []) as $name => $relation) {
                $model->setRelation((string) $name, $this->denormalize($relation));
            }

            return $model;
        }

        if (isset($value['__eloquent_collection'])) {
            $collectionClass = $value['__eloquent_collection'];
            $items = array_map(fn (mixed $item): mixed => $this->denormalize($item), (array) ($value['items'] ?? []));

            // L2 allow-list: only instantiate a class that actually is an
            // Eloquent collection subclass. A forged/corrupt class name falls
            // back to the base EloquentCollection instead of `new $arbitrary`.
            if (is_string($collectionClass)
                && class_exists($collectionClass)
                && is_subclass_of($collectionClass, EloquentCollection::class)) {
                /** @var class-string<EloquentCollection<int, Model>> $collectionClass */
                return new $collectionClass($items);
            }

            return new EloquentCollection($items);
        }

        return new Collection(array_map(fn (mixed $item): mixed => $this->denormalize($item), (array) ($value['__collection'] ?? [])));
    }
}
