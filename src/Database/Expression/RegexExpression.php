<?php

declare(strict_types=1);

namespace Giginc\Mongodb\Database\Expression;

use MongoDB\BSON\Regex;

class RegexExpression implements MongoExpression
{
    public function __construct(
        protected Regex $value,
    ) {}

    public function compile(): array
    {
        return [
            '$regex' => $this->value,
        ];
    }
}