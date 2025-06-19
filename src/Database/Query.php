<?php

declare(strict_types=1);

namespace Giginc\Mongodb\Database;

use Cake\Database\StatementInterface;
use Giginc\Mongodb\Database\MongoDb\Connection;

class Query
{
    private Connection $connection;
    protected bool $dirty = false;

    public function __construct(Connection $connection)
    {
        $this->setConnection($connection);
    }

    public function setConnection(Connection $connection): self
    {
        $this->connection = $connection;

        return $this;
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    protected function dirty(): void
    {
        $this->dirty = true;
    }
}