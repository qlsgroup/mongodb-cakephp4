<?php

declare(strict_types=1);

namespace Giginc\Mongodb\Database\Expression\Traits;

use Giginc\Mongodb\Database\Expression\BaseExpression;

trait HasMultipleExpressions
{
    /** @var BaseExpression[] */
    protected array $expressions = [];

    public function add(BaseExpression $expression): self
    {
        $this->expressions[] = $expression;

        return $this;
    }

    protected function compileAndMergeChildren(): array
    {
        return array_merge(...$this->compileChildren());
    }

    protected function compileChildren(): array
    {
        return array_map(fn(BaseExpression $expression) => $expression->compile(), $this->expressions);
    }


}