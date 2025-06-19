<?php

declare(strict_types=1);

namespace Giginc\Mongodb\Database\Expression;

use Giginc\Mongodb\Database\Expression\Logical\AndExpression;
use Giginc\Mongodb\Database\Expression\Logical\OrExpression;
use Giginc\Mongodb\Database\Expression\Traits\HasMultipleExpressions;

class BaseExpression implements MongoExpression
{
    /** @var array<string, MongoExpression> */
    protected array $expressions = [];

    public function add(string $key, MongoExpression $expression): void
    {
        $this->expressions[$key] = $expression;
    }

    public function addToOr(BaseExpression $expression): void
    {
        $orExpression = $this->findOrCreateOrExpression();

        $orExpression->add($expression);
    }

    public function addToAnd(BaseExpression $expression): void
    {
        $andExpression = $this->findOrCreateAndExpression();

        $andExpression->add($expression);
    }

    public function compile(): array
    {
        return array_map(fn(MongoExpression $expression) => $expression->compile(), $this->expressions);
    }

    /**
     * @template T of MongoExpression
     *
     * @param class-string<T> $expressionType
     *
     * @return T|null
     */
    protected function findExpressionOfType(string $expressionType)
    {
        foreach ($this->expressions as $expression) {
            if ($expression instanceof $expressionType) {
                return $expression;
            }
        }

        return null;
    }

    protected function findOrCreateOrExpression(): OrExpression
    {
        $orExpression = $this->expressions['$or'] ?? null;

        if ($orExpression instanceof OrExpression) {
            return $orExpression;
        }

        $or = new OrExpression();

        $this->add('$or', $or);

        return $or;
    }

    protected function findOrCreateAndExpression(): AndExpression
    {
        $andExpression = $this->expressions['$and'] ?? null;

        if ($andExpression instanceof AndExpression) {
            return $andExpression;
        }

        $and = new AndExpression();

        $this->add('$and', $and);

        return $and;
    }

    public function hasAnd(): bool
    {
        return $this->findExpressionOfType(AndExpression::class) !== null;
    }

    public function hasOr(): bool
    {
        return $this->findExpressionOfType(OrExpression::class) !== null;
    }
}