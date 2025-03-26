<?php

namespace Giginc\Mongodb\ORM;

use Cake\Core\App;
use Cake\ORM\Entity;
use Cake\Utility\Inflector;
use Exception;

class Document
{

    /**
     * store the document
     *
     * @var array $_document
     * @access protected
     */
    protected $_document;

    /**
     * table model name
     *
     * @var string $_registryAlias
     * @access protected
     */
    protected $_registryAlias;

    /**
     * The name of the class that represent a single row for this table
     *
     * @var string
     */
    protected $_entityClass;

    /**
     * set document and table name
     *
     * @param array|\Traversable $document
     * @param string $table
     * @access public
     */
    public function __construct($document, $table)
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

    public function getEntityClass()
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
