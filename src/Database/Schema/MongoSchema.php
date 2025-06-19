<?php

namespace Giginc\Mongodb\Database\Schema;

use Cake\Collection\CollectionTrait;
use Cake\Database\Schema\CollectionInterface;
use Cake\Database\Schema\TableSchemaInterface;
use Giginc\Mongodb\Database\Driver\Mongodb;
use Cake\Database\Schema\TableSchema;

/**
 * @method array<string> listTablesWithoutViews()
 */
class MongoSchema implements CollectionInterface
{
    use CollectionTrait;

    /**
     * Database Connection
     *
     * @var resource
     * @access protected
     */
    protected $_connection = null;

    /**
     * Constructor
     *
     * @param Mongodb $conn
     * @access public
     */
    public function __construct(Mongodb $conn)
    {
        $this->_connection = $conn;
    }

    /**
     * Describe
     *
     * @access public
     * @param $name
     * @return TableSchema
     */
    public function describe(string $name, array $options = []): TableSchemaInterface
    {
        if (strpos($name, '.')) {
            list(, $name) = explode('.', $name);
        }

        $table = new TableSchema($name);

        if (empty($table->getPrimaryKey())) {
            $table->addColumn('_id', ['type' => 'string', 'default' => new \MongoDB\BSON\ObjectId(), 'null' => false]);
            $table->addConstraint('_id', ['type' => 'primary', 'columns' => ['_id']]);
        }

        return $table;
    }

    public function listTables(): array
    {
        return $this->_connection->listAllCollections();
    }
}
