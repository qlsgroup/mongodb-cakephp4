<?php

namespace Giginc\Mongodb\ORM;

use Cake\Core\App;
use Cake\ORM\Entity;
use Cake\Utility\Inflector;
use Exception;
use Traversable;

class Document
{

    /**
     * store the document
     *
     * @access protected
     */
    protected iterable $_document;

    /**
     * table model name
     *
     * @var string $_registryAlias
     * @access protected
     */
    protected string $_registryAlias;

    /**
     * The name of the class that represent a single row for this table
     *
     * @var class-string
     */
    protected string $_entityClass;

    /**
     * set document and table name
     *
     * @param iterable $document
     * @param string $table
     * @access public
     */
    public function __construct(iterable $document, string $table)
    {
        $this->_document = $document;
        $this->_registryAlias = $table;
    }

    /**
     * convert mongo document into cake entity
     *
     * @return \Cake\ORM\Entity
     * @access public
     * @throws Exception
     */
    public function cakefy()
    {
        $document = [];
        foreach ($this->_document as $field => $value) {
            $type = gettype($value);
            if ($type == 'object') {
                switch (get_class($value)) {
                    case 'MongoDB\BSON\ObjectId':
                        $document[$field] = $value->__toString();
                        break;

                    case 'MongoDB\BSON\UTCDateTime':
                        $document[$field] = $value->toDateTime();
                        break;

                    case 'MongoDB\Model\BSONDocument':
                    default:
                        if ($value instanceof \MongoDB\BSON\Serializable) {
                            $document[$field] = $value->bsonSerialize();
                        } else {
                            throw new Exception(get_class($value) . ' conversion not implemented.');
                        }
                }
            } elseif ($type == 'array') {
                $document[$field] = $this->cakefy();
            } else {
                $document[$field] = $value;
            }
        }

        /** $var EntityInterface $class */
        $class = $this->getEntityClass();

        return new $class($document, ['markClean' => true, 'markNew' => false, 'source' => $this->_registryAlias]);
    }

    /**
     * @return class-string
     */
    public function getEntityClass(): string
    {
        if (!$this->_entityClass) {
            $default = Entity::class;
            $self = $this->_registryAlias;
            $alias = Inflector::classify($self);

            $class = App::className($alias, 'Model/Entity');
            if (!$class) {
                return $this->_entityClass = $default;
            }

            $this->_entityClass = $class;
        }

        return $this->_entityClass;
    }
}
