<?php

declare(strict_types=1);

namespace Giginc\Mongodb\Database\Expression\Element;

use Giginc\Mongodb\Database\Expression\MongoExpression;

class ExistsExpression implements MongoExpression
{
    public function __construct(
        protected bool $shouldExist
    ) {}

    public function compile(): array
    {
        return [
            '$exists' => $this->shouldExist,
        ];
    }
}