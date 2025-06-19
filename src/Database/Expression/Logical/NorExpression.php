<?php

declare(strict_types=1);

namespace Giginc\Mongodb\Database\Expression\Logical;

use Giginc\Mongodb\Database\Expression\MongoExpression;
use Giginc\Mongodb\Database\Expression\Traits\HasMultipleExpressions;

class NorExpression implements MongoExpression
{
    use HasMultipleExpressions;

    public function compile(): array
    {
        return [
            '$nor' => $this->compileChildren(),
        ];
    }
}