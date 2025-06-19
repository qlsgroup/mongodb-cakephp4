<?php

declare(strict_types=1);

namespace Giginc\Mongodb\Database\Expression;

interface MongoExpression
{
    public function compile(): array;
}