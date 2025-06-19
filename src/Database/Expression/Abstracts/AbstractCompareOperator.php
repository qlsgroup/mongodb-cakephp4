<?php

declare(strict_types=1);

namespace Giginc\Mongodb\Database\Expression\Abstracts;

use Giginc\Mongodb\Database\Expression\MongoExpression;

abstract class AbstractCompareOperator implements MongoExpression
{
    const OPERATOR = '$gt';

    public function __construct(
        protected mixed $value
    ) {}

    public function compile(): array
    {
        return [
            self::OPERATOR => $this->value,
        ];
    }
}