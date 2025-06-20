<?php

namespace Giginc\Mongodb\ORM;

use ArrayObject;
use BadMethodCallException;
use Cake\Core\App;
use Cake\Core\Exception\CakeException;
use Cake\Datasource\EntityInterface;
use Cake\Datasource\Exception\InvalidPrimaryKeyException;
use Cake\Datasource\RulesAwareTrait;
use Cake\Event\EventDispatcherTrait;
use Cake\ORM\Behavior;
use Cake\ORM\Entity;
use Cake\ORM\Exception\MissingEntityException;
use Cake\ORM\Exception\PersistenceFailedException;
use Cake\ORM\RulesChecker;
use Cake\Utility\Inflector;
use Cake\Validation\ValidatorAwareTrait;
use Exception;
use Giginc\Mongodb\Database\MongoDb\Connection;
use Giginc\Mongodb\Database\MongoDbConnectionManager;
use InvalidArgumentException;
use MongoDB\BSON\ObjectId;
use RuntimeException;
use function Cake\Core\namespaceSplit;

class Table
{
    public const DEFAULT_VALIDATOR = 'default';
    public const VALIDATOR_PROVIDER_NAME = 'table';
    public const BUILD_VALIDATOR_EVENT = 'Model.buildValidator';

    use EventDispatcherTrait;
    use RulesAwareTrait;
    use ValidatorAwareTrait;

    private ?string $_table = null;
    private ?string $_alias = null;
    private Marshaller $marshaller;

    private Connection $connection;
    /** @var class-string  */
    protected string $entityClass;
    protected string $registryAlias;
    protected BehaviorRegistry $behaviors;
    private array $primaryKey = [];
    /** @var string|string[]|null  */
    protected string|array|null $displayField = null;

    public static function defaultConnectionName(): string
    {
        return 'default';
    }

    public function __construct(array $config = [])
    {
        $this->marshaller = new Marshaller($this);
        $this->behaviors = new BehaviorRegistry($this);

        $this->initialize($config);
    }

    public function initialize(array $config): void
    {

    }

    public function newEntity(array $data, array $options = []): EntityInterface
    {
        return (new Marshaller($this))->one($data, $options);
    }

    public function getConnection(): Connection
    {
        if (!isset($this->connection)) {
            /** @var Connection $connection */
            $connection = MongoDbConnectionManager::get(static::defaultConnectionName());
            $this->connection = $connection;
        }

        return $this->connection;
    }

    public function getTable(): string
    {
        if ($this->_table === null) {
            $table = namespaceSplit(static::class);
            $table = substr(end($table), 0, -5) ?: $this->_alias;
            if (!$table) {
                throw new CakeException(
                    'You must specify either the `alias` or the `table` option for the constructor.'
                );
            }
            $this->_table = Inflector::underscore($table);
        }

        return $this->_table;
    }

    public function setTable(string $table): void
    {
        $this->_table = $table;
    }

    public function setAlias(string $alias): void
    {
        $this->_alias = $alias;
    }

    public function getAlias(): string
    {
        if ($this->_alias === null) {
            $alias = namespaceSplit(static::class);
            $alias = substr(end($alias), 0, -5) ?: $this->_table;
            if (!$alias) {
                throw new CakeException(
                    'You must specify either the `alias` or the `table` option for the constructor.'
                );
            }
            $this->_alias = $alias;
        }

        return $this->_alias;
    }

    /**
     * return MongoCollection object
     *
     * @return \MongoDB\Collection
     * @throws Exception
     */
    private function __getCollection()
    {
        $driver = $this->getConnection()->getDriver();
        if (!$driver instanceof \Giginc\Mongodb\Database\Driver\Mongodb) {
            throw new Exception("Driver must be an instance of 'Giginc\Mongodb\Database\Driver\Mongodb'");
        }
        $collection = $driver->getCollection($this->getTable());

        return $collection;
    }

    /**
     * always return true because mongo is schemaless
     *
     * @param string $field
     * @return bool
     * @access public
     */
    public function hasField(string $field): bool
    {
        return true;
    }

    /**
     * find documents
     *
     * @param string $type
     * @param array $options
     * @access public
     * @throws Exception
     */
    public function find(string $type = 'all', array $options = []): Query
    {
        return $this->callFinder($type, new Query($this->getConnection(), $this, $options), $options);
    }

    public function findAll(Query $query, array $options = []): Query
    {
        return $query;
    }

    public function callFinder(string $type, Query $query, array $options = []): Query
    {
        $finder = 'find' . $type;
        if (method_exists($this, $finder)) {
            return $this->$finder($query, $options);
        }

        throw new BadMethodCallException(sprintf(
            'Unknown finder "%s" in table "%s".',
            $type,
            static::class,
        ));
    }

    /**
     * get the document by _id
     *
     * @param string $primaryKey
     * @param array $options
     * @access public
     * @throws Exception
     */
    public function get($primaryKey, $options = []): EntityInterface
    {
        $query = new Query($this->getConnection(), $this, $options);

        return $query->where(['_id' => new ObjectId($primaryKey)])->first();
    }

    /**
     * remove one document
     *
     * @param \Cake\Datasource\EntityInterface $entity
     * @param array $options
     * @return bool
     * @access public
     */
    public function delete(EntityInterface $entity, $options = []): bool
    {
        try {
            $collection = $this->__getCollection();
            $delete = $collection->deleteOne(['_id' => new ObjectId($entity->_id)], $options);
            return (bool)$delete->getDeletedCount();
        } catch (Exception $e) {
            trigger_error($e->getMessage());
            return false;
        }
    }

    public function deleteOrFail(EntityInterface $entity, $options = []): bool
    {
        try {
            $collection = $this->__getCollection();
            $delete = $collection->deleteOne(['_id' => new ObjectId($entity->_id)], $options);
            $count = $delete->getDeletedCount();

            if ($count > 0) {
                return $count;
            }

            throw new PersistenceFailedException($entity, ['delete']);
        } catch (Exception $e) {
            trigger_error($e->getMessage());
            return false;
        }
    }

    /**
     * delete all rows matching $conditions
     * @param $conditions
     * @return int
     * @throws Exception
     */
    public function deleteAll($conditions = null): int
    {
        try {
            $query = new Query($this->getConnection(), $this);

            $delete = $query->deleteMany($conditions);
            return $delete->getDeletedCount();
        } catch (Exception $e) {
            trigger_error($e->getMessage());
            return false;
        }
    }

    /**
     * save the document
     *
     * @param EntityInterface $entity
     * @param array $options
     * @return mixed $success
     * @access public
     * @throws Exception
     */
    public function save(EntityInterface $entity, $options = [])
    {
        $options = new ArrayObject($options + [
            'checkRules' => true,
            'checkExisting' => true,
            '_primary' => true,
        ]);

        if ($entity->getErrors()) {
            return false;
        }

        if ($entity->isNew() === false && !$entity->isDirty()) {
            return $entity;
        }

        $success = $this->_processSave($entity, $options);
        if ($success) {
            if ($options['_primary']) {
                $this->dispatchEvent('Model.afterSaveCommit', compact('entity', 'options'));
                $entity->isNew(false);
                $entity->setSource($this->getRegistryAlias());
            }
        }

        return $success;
    }

    /**
     * insert or update the document
     *
     * @param EntityInterface $entity
     * @param array|ArrayObject $options
     * @return mixed $success
     * @access protected
     * @throws Exception
     */
    protected function _processSave($entity, $options)
    {
        $mode = $entity->isNew() ? RulesChecker::CREATE : RulesChecker::UPDATE;
        if ($options['checkRules'] && !$this->checkRules($entity, $mode, $options)) {
            return false;
        }

        $event = $this->dispatchEvent('Model.beforeSave', compact('entity', 'options'));
        if ($event->isStopped()) {
            return $event->getResult();
        }

        $data = $entity->toArray();
        $isNew = $entity->isNew();

        if (isset($data['created'])) {
            $data['created'] = new \MongoDB\BSON\UTCDateTime($data['created']);
        }
        if (isset($data['modified'])) {
            $data['modified'] = new \MongoDB\BSON\UTCDateTime($data['modified']);
        }

        if ($isNew) {
            $success = $this->_insert($entity, $data);
        } else {
            $success = $this->_update($entity, $data);
        }

        if ($success) {
            $this->dispatchEvent('Model.afterSave', compact('entity', 'options'));
            $entity->clean();
            if (!$options['_primary']) {
                $entity->isNew(false);
                $entity->setSource($this->getRegistryAlias());
            }

            $success = true;
        }

        if (!$success && $isNew) {
            $entity->unset($this->getPrimaryKey());
            $entity->isNew(true);
        }

        if ($success) {
            return $entity;
        }

        return false;
    }

    /**
     * Insert new document
     *
     * @param EntityInterface $entity
     * @param array $data
     * @return mixed $success
     * @access protected
     * @throws Exception
     */
    protected function _insert($entity, $data)
    {
        $primary = $this->getPrimaryKey();
        if (empty($primary)) {
            $msg = sprintf(
                'Cannot insert row in "%s" table, it has no primary key.',
                $this->getTable()
            );
            throw new RuntimeException($msg);
        }
        $primary = ['_id' => $this->_newId($primary)];

        $filteredKeys = array_filter($primary, 'strlen');
        $data = $data + $filteredKeys;

        $success = false;
        if (empty($data)) {
            return $success;
        }

        $query = new Query($this->getConnection(), $this);


        $success = $entity;
        $result = $query->insertOne($data);
        if ($result->isAcknowledged()) {
            $entity->set('_id', $result->getInsertedId());
        }
        return $success;
    }

    /**
     * Update one document
     *
     * @param EntityInterface $entity
     * @param array $data
     * @return mixed $success
     * @access protected
     * @throws Exception
     */
    protected function _update($entity, $data)
    {
        $query = new Query($this->getConnection(), $this);
        unset($data['_id']);
        $update = $query->updateOne(
            ['$set' => $data],
            ['_id' => new ObjectId($entity->_id)],
        );
        return (bool)$update->getModifiedCount();
    }

    /**
     * Update $fields for rows matching $conditions
     * @param array $fields
     * @param array $conditions
     */
    public function updateAll($fields, $conditions): int
    {
        try {
            $query = new Query($this->getConnection(), $this);
            $update = $query->updateMany($fields, $conditions);
            return $update->getModifiedCount();
        } catch (Exception $e) {
            trigger_error($e->getMessage());
            return false;
        }
    }

    /**
     * create new MongoDB\BSON\ObjectId
     *
     * @param mixed $primary
     * @return ObjectId
     * @access public
     */
    protected function _newId($primary)
    {
        if (!$primary || count((array)$primary) > 1) {
            return null;
        }

        return new ObjectId();
    }

    /**
     * @return class-string<EntityInterface>
     */
    public function getEntityClass(): string
    {
        if (empty($this->entityClass)) {
            $default = Entity::class;
            $self = static::class;
            $parts = explode('\\', $self);

            if ($self === self::class || count($parts) < 3) {
                return $this->entityClass = $default;
            }

            $alias = Inflector::classify(Inflector::underscore(substr(array_pop($parts), 0, -5)));
            $name = implode('\\', array_slice($parts, 0, -1)) . '\\Entity\\' . $alias;
            if (!class_exists($name)) {
                return $this->entityClass = $default;
            }

            /** @var class-string<\Cake\Datasource\EntityInterface>|null $class */
            $class = App::className($name, 'Model/Entity');
            if (!$class) {
                throw new MissingEntityException([$name]);
            }

            $this->entityClass = $class;
        }

        return $this->entityClass;
    }

    public function getRegistryAlias(): string
    {
        if (empty($this->registryAlias)) {
            $this->registryAlias = $this->getAlias();
        }

        return $this->registryAlias;
    }

    /**
     * @return $this
     * @throws Exception
     */
    public function addBehavior(string $name, array $options = []): self
    {
        $this->behaviors->load($name, $options);

        return $this;
    }

    public function addBehaviors(array $behaviors): self
    {
        foreach ($behaviors as $name => $options) {
            if (is_int($name)) {
                $name = $options;
                $options = [];
            }

            $this->addBehavior($name, $options);
        }

        return $this;
    }

    public function removeBehavior(string $name)
    {
        $this->behaviors->unload($name);

        return $this;
    }

    public function behaviors(): BehaviorRegistry
    {
        return $this->behaviors;
    }

    public function getBehavior(string $name): Behavior
    {
        if (!$this->behaviors->has($name)) {
            throw new InvalidArgumentException(sprintf(
                'The %s behavior is not defined on %s.',
                $name,
                static::class
            ));
        }

        return $this->behaviors->get($name);
    }

    public function hasBehavior(string $name): bool
    {
        return $this->behaviors->has($name);
    }

    public function getPrimaryKey(): array
    {
        return $this->primaryKey;
    }

    public function setPrimaryKey(array|string $primaryKey): self
    {
        if (is_string($primaryKey)) {
            $primaryKey = [$primaryKey];
        }

        $this->primaryKey = $primaryKey;

        return $this;
    }

    public function setDisplayField(string|array|null $displayField): self
    {
        $this->displayField = $displayField;

        return $this;
    }

    public function getDisplayField(): string|array
    {
        if ($this->displayField !== null) {
            return $this->displayField;
        }

        return $this->displayField = $this->getPrimaryKey();
    }

    public function getMarshaller(): Marshaller
    {
        return $this->marshaller;
    }
}
