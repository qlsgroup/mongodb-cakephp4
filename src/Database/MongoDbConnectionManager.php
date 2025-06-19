<?php

declare(strict_types=1);

namespace Giginc\Mongodb\Database;

use Cake\Core\StaticConfigTrait;
use Cake\Database\Connection;
use Cake\Datasource\Exception\MissingDatasourceConfigException;
use Giginc\Mongodb\Database\Driver\Mongodb;

class MongoDbConnectionManager
{
    use StaticConfigTrait {
        setConfig as protected _setConfig;
        parseDsn as protected _parseDsn;
    }

    /**
     * @var array<string, string>
     */
    protected static array $_aliasMap = [];

    protected static array $_dsnClassMap = [
        'mongodb' => Mongodb::class,
    ];

    protected static ?MongoDbConnectionRegistry $_registry;

    public static function setConfig($key, $config = null): void
    {
        if (is_array($config)) {
            $config['name'] = $key;
        }

        static::_setConfig($key, $config);
    }

    public static function parseDsn(string $config): array
    {
        $config = static::_parseDsn($config);

        if (isset($config['path']) && empty($config['database'])) {
            $config['database'] = substr($config['path'], 1);
        }

        if (empty($config['driver'])) {
            $config['driver'] = $config['className'];
            $config['className'] = Connection::class;
        }

        unset($config['path']);

        return $config;
    }

    public static function alias(string $source, string $alias): void
    {
        static::$_aliasMap[$alias] = $source;
    }

    public static function dropAlias(string $alias): void
    {
        unset(static::$_aliasMap[$alias]);
    }

    public static function aliases(): array
    {
        return static::$_aliasMap;
    }

    public static function get(string $name, bool $useAliases = true): \Giginc\Mongodb\Database\MongoDb\Connection
    {
        if ($useAliases && isset(static::$_aliasMap[$name])) {
            $name = static::$_aliasMap[$name];
        }

        if (!isset(static::$_config[$name])) {
            throw new MissingDatasourceConfigException(['name' => $name]);
        }

        if (!isset(static::$_registry)) {
            static::$_registry = new MongoDbConnectionRegistry();
        }

        return static::$_registry->{$name} ?? static::$_registry->load($name, static::$_config[$name]);
    }
}