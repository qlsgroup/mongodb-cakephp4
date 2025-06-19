<?php

namespace Giginc\Mongodb\Database\MongoDb;

use Cake\Core\Retry\CommandRetry;
use Cake\Database\Exception\MissingConnectionException;
use Giginc\Mongodb\Database\Driver\Mongodb;
use Giginc\Mongodb\Database\Driver\Mongodb as Gigincdb;
use Giginc\Mongodb\Database\Retry\ReconnectStrategy;
use Giginc\Mongodb\Database\Schema\MongoSchema;
use Iterator;
use MongoDB\DeleteResult;
use MongoDB\Driver\CursorInterface;
use MongoDB\InsertManyResult;
use MongoDB\InsertOneResult;
use MongoDB\UpdateResult;

class Connection
{

    /**
     * Contains the configuration param for this connection
     *
     * @var array
     */
    protected $_config;

    /**
     * Database Driver object
     *
     * @var \Giginc\Mongodb\Database\Driver\Mongodb;
     */
    protected $_driver = null;

    /**
     * MongoSchema
     *
     * @var MongoSchema
     * @access protected
     */
    protected $_schemaCollection;

    public function __construct(array $config)
    {
        $this->_config = $config;

        $this->driver(null, $config);
    }

    /**
     * disconnect existent connection
     *
     * @access public
     * @return void
     */
    public function __destruct()
    {
        if ($this->_driver->connected) {
            $this->_driver->disconnect();
            unset($this->_driver);
        }
    }

    /**
     * return configuration
     *
     * @return array $_config
     * @access public
     */
    public function config(): array
    {
        return $this->_config;
    }

    /**
     * return configuration name
     *
     * @return string
     * @access public
     */
    public function configName(): string
    {
        return 'mongodb';
    }

    /**
     * @param null $driver
     * @param array $config
     * @return Gigincdb|resource
     */
    public function driver($driver = null, $config = [])
    {
        if ($this->_driver !== null) {
            return $this->_driver;
        }
        $this->_driver = new Gigincdb($config);

        return $this->_driver;
    }

    /**
     * connect to the database
     *
     * @return boolean
     * @access public
     */
    public function connect(): bool
    {
        try {
            $this->_driver->connect();
            return true;
        } catch (\Exception $e) {
            throw new MissingConnectionException(['reason' => $e->getMessage()]);
        }
    }

    /**
     * disconnect from the database
     *
     * @access public
     */
    public function disconnect(): void
    {
        if ($this->_driver->isConnected()) {
            $this->_driver->disconnect();
        }
    }

    /**
     * database connection status
     *
     * @return bool
     * @access public
     */
    public function isConnected(): bool
    {
        return $this->_driver->isConnected();
    }

    /**
     * Gets a Schema\Collection object for this connection.
     */
    public function getSchemaCollection(): \Cake\Database\Schema\CollectionInterface
    {
        if ($this->_schemaCollection !== null) {
            return $this->_schemaCollection;
        }

        $this->createDriver();

        return $this->_schemaCollection = new MongoSchema($this->_driver);
    }

    /**
     * @throws \Exception
     */
    public function find(string $collection, array $query, array $options = []): CursorInterface&Iterator
    {
        return $this->getDisconnectRetry()->run(function () use ($collection, $query, $options) {
            $collection = $this->driver()->getCollection($collection);

            return $collection->find($query, $options);
        });
    }

    public function findOne(string $collection, array $filters, array $options = []): array|null|object
    {
        return $this->getDisconnectRetry()->run(function () use ($collection, $filters, $options) {
            $collection = $this->driver()->getCollection($collection);

            return $collection->findOne($filters, $options);
        });
    }

    public function deleteOne(string $collection, array|object $filters, array $options = []): DeleteResult
    {
        return $this->getDisconnectRetry()->run(function () use ($collection, $filters, $options) {
            $collection = $this->driver()->getCollection($collection);

            return $collection->deleteOne($filters, $options);
        });
    }

    public function deleteMany(string $collection, array|object $filters, array $options = []): DeleteResult
    {
        return $this->getDisconnectRetry()->run(function () use ($collection, $filters, $options) {
            $collection = $this->driver()->getCollection($collection);

            return $collection->deleteMany($filters, $options);
        });
    }

    public function updateOne(string $collection, array|object $filter, array|object $update, array $options = []): UpdateResult
    {
        return $this->getDisconnectRetry()->run(function () use ($collection, $filter, $update, $options) {
            $collection = $this->driver()->getCollection($collection);

            return $collection->updateOne($filter, $update, $options);
        });
    }

    public function updateMany(string $collection, array|object $filter, array|object $update, array $options = []): UpdateResult
    {
        return $this->getDisconnectRetry()->run(function () use ($collection, $filter, $update, $options) {
            $collection = $this->driver()->getCollection($collection);

            return $collection->updateMany($filter, $update, $options);
        });
    }

    public function insertOne(string $collection, array|object $insert, array $options = []): InsertOneResult
    {
        return $this->getDisconnectRetry()->run(function () use ($collection, $insert, $options) {
            $collection = $this->driver()->getCollection($collection);

            return $collection->insertOne($insert, $options);
        });
    }

    public function insertMany(string $collection, array $inserts, array $options = []): InsertManyResult
    {
        return $this->getDisconnectRetry()->run(function () use ($collection, $inserts, $options) {
            $collection = $this->driver()->getCollection($collection);

            return $collection->insertMany($inserts, $options);
        });
    }

    public function getDisconnectRetry(): CommandRetry
    {
        return new CommandRetry(new ReconnectStrategy($this));
    }

    public function getDriver(): Mongodb
    {
        return $this->_driver;
    }
}

