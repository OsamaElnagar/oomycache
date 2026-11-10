<?php

declare(strict_types=1);

namespace Oomycache\Oomycache\Builder;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Database\Query\Processors\Processor;
use Oomycache\Oomycache\Query\BaseQueryContext;
use Oomycache\Oomycache\Query\TableRecorder;

/**
 * Base query builder returned by Model::newBaseQueryBuilder() for cacheable
 * models.
 *
 * Two jobs only:
 *  - cache Filament's default `$query->toBase()->getCountForPagination()`;
 *  - record from/join/subquery tables for dependency-aware invalidation.
 *
 * Every override calls parent; SQL generation is untouched.
 */
class QueryCacheQueryBuilder extends QueryBuilder
{
    private ?BaseQueryContext $cacheContext = null;

    private TableRecorder $tableRecorder;

    /**
     * @param  ConnectionInterface  $connection
     * @param  Grammar|null  $grammar
     * @param  Processor|null  $processor
     */
    public function __construct($connection, $grammar = null, $processor = null)
    {
        parent::__construct($connection, $grammar, $processor);

        $this->tableRecorder = new TableRecorder;
    }

    public function tableRecorder(): TableRecorder
    {
        return $this->tableRecorder;
    }

    public function setCacheContext(?BaseQueryContext $context): static
    {
        $this->cacheContext = $context;

        return $this;
    }

    public function getCountForPagination($columns = ['*']): int
    {
        if ($this->cacheContext === null) {
            return parent::getCountForPagination($columns);
        }

        $context = $this->cacheContext;

        return $context->rememberCount(
            $this,
            fn (): mixed => parent::getCountForPagination($columns),
        );
    }

    public function from($table, $as = null): static
    {
        $this->tableRecorder->record($table);

        return parent::from($table, $as);
    }

    public function fromSub($query, $as): static
    {
        $this->recordSubQuery($query);
        $this->tableRecorder->recordAlias((string) $as);

        return parent::fromSub($query, $as);
    }

    public function join($table, $first, $operator = null, $second = null, $type = 'inner', $where = false): static
    {
        $this->tableRecorder->record($table);

        return parent::join($table, $first, $operator, $second, $type, $where);
    }

    public function joinSub($query, $as, $first, $operator = null, $second = null, $type = 'inner', $where = false): static
    {
        $this->recordSubQuery($query);
        $this->tableRecorder->recordAlias((string) $as);

        return parent::joinSub($query, $as, $first, $operator, $second, $type, $where);
    }

    public function leftJoin($table, $first, $operator = null, $second = null): static
    {
        $this->tableRecorder->record($table);

        return parent::leftJoin($table, $first, $operator, $second);
    }

    public function leftJoinSub($query, $as, $first, $operator = null, $second = null): static
    {
        $this->recordSubQuery($query);
        $this->tableRecorder->recordAlias((string) $as);

        return parent::leftJoinSub($query, $as, $first, $operator, $second);
    }

    public function rightJoin($table, $first, $operator = null, $second = null): static
    {
        $this->tableRecorder->record($table);

        return parent::rightJoin($table, $first, $operator, $second);
    }

    public function rightJoinSub($query, $as, $first, $operator = null, $second = null): static
    {
        $this->recordSubQuery($query);
        $this->tableRecorder->recordAlias((string) $as);

        return parent::rightJoinSub($query, $as, $first, $operator, $second);
    }

    public function crossJoin($table, $first = null, $operator = null, $second = null): static
    {
        $this->tableRecorder->record($table);

        return parent::crossJoin($table, $first, $operator, $second);
    }

    public function newQuery(): static
    {
        /** @var static $query */
        $query = parent::newQuery();
        $query->tableRecorder = $this->tableRecorder;

        return $query;
    }

    private function recordSubQuery(mixed $query): void
    {
        if ($query instanceof self) {
            foreach ($query->tableRecorder->tables() as $table) {
                $this->tableRecorder->record($table);
            }
        }
    }
}
