<?php

declare(strict_types=1);

namespace Giginc\Mongodb\Database\Expression\Comparison;

use Giginc\Mongodb\Database\Expression\Abstracts\AbstractCompareOperator;

class LessThanExpression extends AbstractCompareOperator
{
    public const OPERATOR = '$lt';
}