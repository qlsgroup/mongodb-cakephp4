<?php

declare(strict_types=1);

namespace Giginc\Mongodb\Database;

use Cake\Core\App;
use Cake\Core\ObjectRegistry;
use Cake\Datasource\Exception\MissingDatasourceException;

class MongoDbConnectionRegistry extends ObjectRegistry
{
    protected function _resolveClassName(string $class): ?string
    {
        return App::className($class, 'Database/MongoDb');
    }

    protected function _throwMissingClassError(string $class, ?string $plugin): void
    {
        throw new MissingDatasourceException([
            'class' => $class,
            'plugin' => $plugin,
        ]);
    }

    protected function _create($class, string $alias, array $config)
    {
        if (is_callable($class)) {
            return $class($alias);
        }

        if (is_object($class)) {
            return $class;
        }

        unset($config['MongoDbConnectionRegistry']);

        return new $class($config);
    }

    public function unload(string $name): MongoDbConnectionRegistry|static
    {
        unset($this->_loaded[$name]);

        return $this;
    }
}