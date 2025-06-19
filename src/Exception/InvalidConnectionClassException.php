<?php

declare(strict_types=1);

namespace Giginc\Mongodb\Exception;

use Exception;
use Giginc\Mongodb\Database\MongoDb\Connection;

class InvalidConnectionClassException extends Exception
{
    /**
     * @param class-string $class
     */
    public function __construct(string $class) {
        parent::__construct("Invalid connection class: {$class}, expected: " . Connection::class);
    }
}