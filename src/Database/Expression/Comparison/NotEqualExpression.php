<?php

declare(strict_types=1);

namespace Giginc\Mongodb\Database\Expression\Comparison;

use Giginc\Mongodb\Database\Expression\MongoExpression;

class NotEqualExpression implements MongoExpression
{
    public function __construct(
        protected mixed $value
    ) {}

    public function compile(): array
    {
        if ($this->value instanceof MongoExpression) {
            return [
                '$eq' => [
                    $this->value->compile()
                ],
            ];
        }

        return [
            '$ne' => $this->value,
        ];
    }
}