<?php

declare(strict_types=1);

namespace Giginc\Mongodb\ORM;

use Cake\Core\Exception\CakeException;
use Cake\Core\InstanceConfigTrait;
use Cake\Event\EventListenerInterface;
use ReflectionClass;
use ReflectionMethod;
use function Cake\Core\deprecationWarning;

class Behavior implements EventListenerInterface
{
    use InstanceConfigTrait;

    /**
     * Table instance.
     *
     * @var Table
     */
    protected $_table;

    /**
     * Reflection method cache for behaviors.
     *
     * Stores the reflected method + finder methods per class.
     * This prevents reflecting the same class multiple times in a single process.
     *
     * @var array<string, array>
     */
    protected static $_reflectionCache = [];

    /**
     * Default configuration
     *
     * These are merged with user-provided configuration when the behavior is used.
     *
     * @var array<string, mixed>
     */
    protected $_defaultConfig = [];

    /**
     * Constructor
     *
     * Merges config with the default and store in the config property
     *
     * @param Table $table The table this behavior is attached to.
     * @param array<string, mixed> $config The config for this behavior.
     */
    public function __construct(Table $table, array $config = [])
    {
        $config = $this->_resolveMethodAliases(
            'implementedFinders',
            $this->_defaultConfig,
            $config
        );
        $config = $this->_resolveMethodAliases(
            'implementedMethods',
            $this->_defaultConfig,
            $config
        );
        $this->_table = $table;
        $this->setConfig($config);
        $this->initialize($config);
    }

    /**
     * Constructor hook method.
     *
     * Implement this method to avoid having to overwrite
     * the constructor and call parent.
     *
     * @param array<string, mixed> $config The configuration settings provided to this behavior.
     * @return void
     */
    public function initialize(array $config): void
    {
    }

    /**
     * Get the table instance this behavior is bound to.
     *
     * @return \Cake\ORM\Table The bound table instance.
     * @deprecated 4.2.0 Use table() instead.
     */
    public function getTable(): Table
    {
        deprecationWarning('Behavior::getTable() is deprecated. Use table() instead.');

        return $this->table();
    }

    /**
     * Get the table instance this behavior is bound to.
     *
     * @return \Cake\ORM\Table The bound table instance.
     */
    public function table(): Table
    {
        return $this->_table;
    }

    /**
     * Removes aliased methods that would otherwise be duplicated by userland configuration.
     *
     * @param string $key The key to filter.
     * @param array<string, mixed> $defaults The default method mappings.
     * @param array<string, mixed> $config The customized method mappings.
     * @return array A de-duped list of config data.
     */
    protected function _resolveMethodAliases(string $key, array $defaults, array $config): array
    {
        if (!isset($defaults[$key], $config[$key])) {
            return $config;
        }
        if (isset($config[$key]) && $config[$key] === []) {
            $this->setConfig($key, [], false);
            unset($config[$key]);

            return $config;
        }

        $indexed = array_flip($defaults[$key]);
        $indexedCustom = array_flip($config[$key]);
        foreach ($indexed as $method => $alias) {
            if (!isset($indexedCustom[$method])) {
                $indexedCustom[$method] = $alias;
            }
        }
        $this->setConfig($key, array_flip($indexedCustom), false);
        unset($config[$key]);

        return $config;
    }

    /**
     * verifyConfig
     *
     * Checks that implemented keys contain values pointing at callable.
     *
     * @return void
     * @throws \Cake\Core\Exception\CakeException if config are invalid
     */
    public function verifyConfig(): void
    {
        $keys = ['implementedFinders', 'implementedMethods'];
        foreach ($keys as $key) {
            if (!isset($this->_config[$key])) {
                continue;
            }

            foreach ($this->_config[$key] as $method) {
                if (!is_callable([$this, $method])) {
                    throw new CakeException(sprintf(
                        'The method %s is not callable on class %s',
                        $method,
                        static::class
                    ));
                }
            }
        }
    }

    /**
     * Gets the Model callbacks this behavior is interested in.
     *
     * By defining one of the callback methods a behavior is assumed
     * to be interested in the related event.
     *
     * Override this method if you need to add non-conventional event listeners.
     * Or if you want your behavior to listen to non-standard events.
     *
     * @return array<string, mixed>
     */
    public function implementedEvents(): array
    {
        $eventMap = [
            'Model.beforeMarshal' => 'beforeMarshal',
            'Model.afterMarshal' => 'afterMarshal',
            'Model.beforeFind' => 'beforeFind',
            'Model.beforeSave' => 'beforeSave',
            'Model.afterSave' => 'afterSave',
            'Model.afterSaveCommit' => 'afterSaveCommit',
            'Model.beforeDelete' => 'beforeDelete',
            'Model.afterDelete' => 'afterDelete',
            'Model.afterDeleteCommit' => 'afterDeleteCommit',
            'Model.buildValidator' => 'buildValidator',
            'Model.buildRules' => 'buildRules',
            'Model.beforeRules' => 'beforeRules',
            'Model.afterRules' => 'afterRules',
        ];
        $config = $this->getConfig();
        $priority = $config['priority'] ?? null;
        $events = [];

        foreach ($eventMap as $event => $method) {
            if (!method_exists($this, $method)) {
                continue;
            }
            if ($priority === null) {
                $events[$event] = $method;
            } else {
                $events[$event] = [
                    'callable' => $method,
                    'priority' => $priority,
                ];
            }
        }

        return $events;
    }

    /**
     * implementedFinders
     *
     * Provides an alias->methodname map of which finders a behavior implements. Example:
     *
     * ```
     *  [
     *    'this' => 'findThis',
     *    'alias' => 'findMethodName'
     *  ]
     * ```
     *
     * With the above example, a call to `$table->find('this')` will call `$behavior->findThis()`
     * and a call to `$table->find('alias')` will call `$behavior->findMethodName()`
     *
     * It is recommended, though not required, to define implementedFinders in the config property
     * of child classes such that it is not necessary to use reflections to derive the available
     * method list. See core behaviors for examples
     *
     * @return array
     * @throws \ReflectionException
     */
    public function implementedFinders(): array
    {
        $methods = $this->getConfig('implementedFinders');
        if (isset($methods)) {
            return $methods;
        }

        return $this->_reflectionCache()['finders'];
    }

    /**
     * implementedMethods
     *
     * Provides an alias->methodname map of which methods a behavior implements. Example:
     *
     * ```
     *  [
     *    'method' => 'method',
     *    'aliasedMethod' => 'somethingElse'
     *  ]
     * ```
     *
     * With the above example, a call to `$table->method()` will call `$behavior->method()`
     * and a call to `$table->aliasedMethod()` will call `$behavior->somethingElse()`
     *
     * It is recommended, though not required, to define implementedFinders in the config property
     * of child classes such that it is not necessary to use reflections to derive the available
     * method list. See core behaviors for examples
     *
     * @return array
     * @throws \ReflectionException
     */
    public function implementedMethods(): array
    {
        $methods = $this->getConfig('implementedMethods');
        if (isset($methods)) {
            return $methods;
        }

        return $this->_reflectionCache()['methods'];
    }

    /**
     * Gets the methods implemented by this behavior
     *
     * Uses the implementedEvents() method to exclude callback methods.
     * Methods starting with `_` will be ignored, as will methods
     * declared on Cake\ORM\Behavior
     *
     * @return array
     * @throws \ReflectionException
     */
    protected function _reflectionCache(): array
    {
        $class = static::class;
        if (isset(self::$_reflectionCache[$class])) {
            return self::$_reflectionCache[$class];
        }

        $events = $this->implementedEvents();
        $eventMethods = [];
        foreach ($events as $binding) {
            if (is_array($binding) && isset($binding['callable'])) {
                /** @var string $callable */
                $callable = $binding['callable'];
                $binding = $callable;
            }
            $eventMethods[$binding] = true;
        }

        $baseClass = self::class;
        if (isset(self::$_reflectionCache[$baseClass])) {
            $baseMethods = self::$_reflectionCache[$baseClass];
        } else {
            $baseMethods = get_class_methods($baseClass);
            self::$_reflectionCache[$baseClass] = $baseMethods;
        }

        $return = [
            'finders' => [],
            'methods' => [],
        ];

        $reflection = new ReflectionClass($class);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $methodName = $method->getName();
            if (
                in_array($methodName, $baseMethods, true) ||
                isset($eventMethods[$methodName])
            ) {
                continue;
            }

            if (str_starts_with($methodName, 'find')) {
                $return['finders'][lcfirst(substr($methodName, 4))] = $methodName;
            } else {
                $return['methods'][$methodName] = $methodName;
            }
        }

        return self::$_reflectionCache[$class] = $return;
    }
}
