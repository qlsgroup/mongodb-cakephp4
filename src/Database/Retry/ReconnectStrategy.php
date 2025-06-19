<?php

declare(strict_types=1);

namespace Giginc\Mongodb\Database\Retry;

use Cake\Core\Retry\RetryStrategyInterface;
use Exception;
use Giginc\Mongodb\Database\MongoDb\Connection;

class ReconnectStrategy implements RetryStrategyInterface
{
    protected static $causes = [
        'gone away',
        'Lost connection',
        'Transaction() on null',
        'closed the connection unexpectedly',
        'closed unexpectedly',
        'deadlock avoided',
        'decryption failed or bad record mac',
        'is dead or not enabled',
        'no connection to the server',
        'query_wait_timeout',
        'reset by peer',
        'terminate due to client_idle_limit',
        'while sending',
        'writing data to the connection',
    ];

    protected Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function shouldRetry(Exception $exception, int $retryCount): bool
    {
        $message = $exception->getMessage();

        foreach (static::$causes as $cause) {
            if (str_contains($message, $cause)) {
                return $this->reconnect();
            }
        }

        return false;
    }

    protected function reconnect(): bool
    {

        try {
            // Make sure we free any resources associated with the old connection
            $this->connection->driver()->disconnect();
        } catch (Exception $e) {
        }

        try {
            $this->connection->connect();

            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}
