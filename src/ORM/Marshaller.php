<?php

declare(strict_types=1);

namespace Giginc\Mongodb\ORM;

use ArrayObject;
use Cake\Collection\Collection;
use Cake\Datasource\EntityInterface;
use Cake\Datasource\InvalidPropertyInterface;
use Cake\I18n\FrozenTime;
use Cake\ORM\PropertyMarshalInterface;
use Cake\Utility\Hash;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use RuntimeException;
use Traversable;

class Marshaller extends \Cake\ORM\Marshaller
{
    protected Table $table;

    public function __construct(Table $table)
    {
        $this->table = $table;
        $this->_table = $table;
    }

    public function one(array $data, array $options = []): EntityInterface
    {
        [$data, $options] = $this->prepareDataAndOptions($data, $options);

        $entityClass = $this->table->getEntityClass();
        $entity = new $entityClass();
        $entity->setSource($this->table->getRegistryAlias());
        $primaryKey = $this->table->getPrimaryKey();

        if (isset($options['accessibleFields'])) {
            foreach ((array) $options['accessibleFields'] as $key => $value) {
                $entity->setAccess($key, $value);
            }
        }

        $errors = $this->validate($data, $options, true);

        $options['isMerge'] = false;
        $propertyMap = $this->buildPropertyMap($data, $options);
        $properties = [];
        foreach ($data as $key => $value) {
            if (!empty($errors[$key])) {
                if ($entity instanceof InvalidPropertyInterface) {
                    $entity->setInvalidField($key, $value);
                }

                continue;
            }

            if ($value === '' && in_array($key, $primaryKey, true)) {
                continue;
            }

            if (isset($propertyMap[$key])) {
                $properties[$key] = $propertyMap[$key]($value, $entity);
            } else {
                if ($value instanceof ObjectId) {
                    $value = $value->__toString();
                }

                if ($value instanceof UTCDateTime) {
                    $value = new FrozenTime($value->toDateTime());
                }

                $properties[$key] = $value;
            }
        }

        if (isset($options['fields'])) {
            foreach ((array) $options['fields'] as $field) {
                if (array_key_exists($field, $properties)) {
                    $entity->set($field, $properties[$field], ['guard' => false]);
                }
            }
        } else {
            $entity->set($properties, ['guard' => false]);
        }

        foreach ($properties as $field => $value) {
            if ($value instanceof EntityInterface) {
                $entity->setDirty($field, $value->isDirty());
            }
        }

        $entity->setErrors($errors);
        $this->dispatchAfterMarshal($entity, $data, $options);

        return $entity;
    }

    protected function buildPropertyMap(array $data, array $optoins): array
    {
        $map = [];

        $behaviors = $this->table->behaviors();

        foreach ($behaviors->loaded() as $name) {
            $behavior = $behaviors->get($name);

            if ($behavior instanceof PropertyMarshalInterface) {
                $map += $behavior->buildMarshalMap($this, $map, $optoins);
            }
        }

        return $map;
    }

    protected function validate(array $data, array $options, bool $isNew): array
    {
        if (!$options['validate']) {
            return [];
        }

        $validator = null;
        if ($options['validate'] === true) {
            $validator = $this->table->getValidator();
        } elseif (is_string($options['validate'])) {
            $validator = $this->table->getValidator($options['validate']);
        }

        if ($validator === null) {
            throw new RuntimeException(
                sprintf('validate must be a boolean or a string. Got %s.', getTypeName($options['validate']))
            );
        }

        return $validator->validate($data, $isNew);
    }

    protected function prepareDataAndOptions(array $data, array $options): array
    {
        $options += ['validate' => true];

        $tableName = $this->table->getAlias();
        if (isset($data[$tableName]) && is_array($data[$tableName])) {
            $data += $data[$tableName];
            unset($data[$tableName]);
        }

        $data = new ArrayObject($data);
        $options = new ArrayObject($options);
        $this->table->dispatchEvent('Model.beforeMarshal', compact('data', 'options'));

        return [(array) $data, (array) $options];
    }

    public function many(iterable $data, array $options = []): array
    {
        $output = [];
        foreach ($data as $record) {
            if (!is_array($record)) {
                continue;
            }

            $output[] = $this->one($record, $options);
        }

        return $output;
    }

    public function merge(EntityInterface $entity, array $data, array $options = []): EntityInterface
    {
        [$data, $options] = $this->prepareDataAndOptions($data, $options);

        $isNew = $entity->isNew();
        $keys = [];

        if (!$isNew) {
            $keys = $entity->extract($this->table->getPrimaryKey());
        }

        if (isset($options['accessibleFields'])) {
            foreach ((array) $options['accessibleFields'] as $key => $value) {
                $entity->setAccess($key, $value);
            }
        }

        $errors = $this->validate($data + $keys, $options, $isNew);
        $options['isMerge'] = true;
        $propertyMap = $this->buildPropertyMap($data, $options);
        $properties = [];
        foreach ($data as $key => $value) {
            if (!empty($errors[$key])) {
                if ($entity instanceof InvalidPropertyInterface) {
                    $entity->setInvalidField($key, $value);
                }

                continue;
            }
            $original = $entity->get($key);

            if (isset($propertyMap[$key])) {
                $value = $propertyMap[$key]($value, $entity);

                if (
                    (
                        is_scalar($value)
                        && $original === $value
                    )
                    || (
                        $value === null
                        && $original === $value
                    )
                    || (
                        is_object($value)
                        && !($value instanceof EntityInterface)
                        && $original == $value
                    )
                ) {
                    continue;
                }
            }
            $properties[$key] = $value;
        }

        $entity->setErrors($errors);
        if (!isset($options['fields'])) {
            $entity->set($properties);

            foreach ($properties as $field => $value) {
                if ($value instanceof EntityInterface) {
                    $entity->setDirty($field, $value->isDirty());
                }
            }

            $this->dispatchAfterMarshal($entity, $data, $options);

            return $entity;
        }

        foreach ((array) $options['fields'] as $field) {
            if (!array_key_exists($field, $properties)) {
                continue;
            }

            $entity->set($field, $properties[$field]);
            if ($properties[$field] instanceof EntityInterface) {
                $entity->setDirty($field, $properties[$field]->isDirty());
            }
        }

        $this->dispatchAfterMarshal($entity, $data, $options);

        return $entity;
    }

    public function mergeMany(iterable $entities, array $data, array $options = []): array
    {
        $primary = $this->table->getPrimaryKey();

        $indexed = (new Collection($data))
            ->groupBy(function ($el) use ($primary) {
                $keys = [];
                foreach ($primary as $key) {
                    $keys[] = $el[$key] ?? '';
                }

                return implode(';', $keys);
            })
            ->map(function ($element, $key) {
                return $key === '' ? $element : $element[0];
            })
            ->toArray();

        $new = $indexed[''] ?? [];
        unset($indexed['']);
        $output = [];

        foreach ($entities as $entity) {
            if (!($entity instanceof EntityInterface)) {
                continue;
            }

            $key = implode(';', $entity->extract($primary));
            if (!isset($indexed[$key])) {
                continue;
            }

            $output[] = $this->merge($entity, $indexed[$key], $options);
            unset($indexed[$key]);
        }

        $conditions = (new Collection($indexed))
            ->map(function ($data, $key) {
                return explode(';', (string)$key);
            })
            ->filter(function ($keys) use ($primary) {
                return count(Hash::filter($keys)) === count($primary);
            })
            ->reduce(function ($conditions, $keys) use ($primary) {
                $fields = array_map([$this->table, 'aliasField'], $primary);
                $conditions['OR'][] = array_combine($fields, $keys);

                return $conditions;
            }, ['OR' => []]);
        $maybeExistentQuery = $this->table->find()->where($conditions);

        if (!empty($indexed) && count($maybeExistentQuery->clause('where'))) {
            foreach ($maybeExistentQuery as $entity) {
                $key = implode(';', $entity->extract($primary));
                if (isset($indexed[$key])) {
                    $output[] = $this->merge($entity, $indexed[$key], $options);
                    unset($indexed[$key]);
                }
            }
        }

        foreach ((new Collection($indexed))->append($new) as $value) {
            if (!is_array($value)) {
                continue;
            }
            $output[] = $this->one($value, $options);
        }

        return $output;
    }
}