<?php

declare(strict_types=1);

namespace Giginc\Mongodb\Database\Expression\Comparison;

use Giginc\Mongodb\Database\Expression\MongoExpression;

class InExpression implements MongoExpression
{
    /**
     * @param mixed[] $values
     */
    public function __construct(
        protected array $values,
    ) {}

    public function compile(): array
    {
        return [
            '$in' => $this->values,
        ];
    }
}