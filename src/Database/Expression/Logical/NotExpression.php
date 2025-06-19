<?php

declare(strict_types=1);

namespace Giginc\Mongodb\Database\Expression\Logical;

use Giginc\Mongodb\Database\Expression\MongoExpression;

class NotExpression implements MongoExpression
{
    public function __construct(
        protected MongoExpression $expression,
    ) {}

    public function compile(): array
    {
        return [
            '$not' => $this->expression->compile(),
        ];
    }
}