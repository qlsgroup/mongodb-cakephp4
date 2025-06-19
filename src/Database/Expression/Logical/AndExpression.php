<?php

declare(strict_types=1);

namespace Giginc\Mongodb\Database\Expression\Logical;

use Giginc\Mongodb\Database\Expression\MongoExpression;
use Giginc\Mongodb\Database\Expression\Traits\HasMultipleExpressions;

class AndExpression implements MongoExpression
{
    use HasMultipleExpressions;

    public function compile(): array
    {
        $children = $this->compileChildren();

        return [
            '$and' => $children,
        ];
    }
}