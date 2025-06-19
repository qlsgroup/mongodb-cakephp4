<?php

declare(strict_types=1);

namespace Giginc\Mongodb\Database\Expression\Update;

use Giginc\Mongodb\Database\Expression\MongoExpression;

class SetExpression implements MongoExpression
{
    public function __construct(
        protected array $document
    )
    {}

    public function compile(): array
    {
        return [
            '$set' => $this->document,
        ];
    }
}