<?php

declare(strict_types=1);

namespace Giginc\Mongodb\ORM\Behavior;

use Cake\Database\Type\DateTimeType;
use Cake\Database\TypeFactory;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Cake\I18n\FrozenTime;
use DateTimeInterface;
use Giginc\Mongodb\ORM\Behavior;
use RuntimeException;
use UnexpectedValueException;

class TimestampMongoBehavior  extends Behavior
{
    /**
     * Default config
     *
     * These are merged with user-provided config when the behavior is used.
     *
     * events - an event-name keyed array of which fields to update, and when, for a given event
     * possible values for when a field will be updated are "always", "new" or "existing", to set
     * the field value always, only when a new record or only when an existing record.
     *
     * refreshTimestamp - if true (the default) the timestamp used will be the current time when
     * the code is executed, to set to an explicit date time value - set refreshTimetamp to false
     * and call setTimestamp() on the behavior class before use.
     *
     * @var array<string, mixed>
     */
    protected $_defaultConfig = [
        'implementedFinders' => [],
        'implementedMethods' => [
            'timestamp' => 'timestamp',
            'touch' => 'touch',
        ],
        'events' => [
            'Model.beforeSave' => [
                'created' => 'new',
                'modified' => 'always',
            ],
        ],
        'refreshTimestamp' => true,
    ];

    /**
     * Current timestamp
     *
     * @var \Cake\I18n\FrozenTime|null
     */
    protected $_ts;

    /**
     * Initialize hook
     *
     * If events are specified - do *not* merge them with existing events,
     * overwrite the events to listen on
     *
     * @param array<string, mixed> $config The config for this behavior.
     * @return void
     */
    public function initialize(array $config): void
    {
        if (isset($config['events'])) {
            $this->setConfig('events', $config['events'], false);
        }
    }

    /**
     * There is only one event handler, it can be configured to be called for any event
     *
     * @param \Cake\Event\EventInterface $event Event instance.
     * @param \Cake\Datasource\EntityInterface $entity Entity instance.
     * @throws \UnexpectedValueException if a field's when value is misdefined
     * @return true Returns true irrespective of the behavior logic, the save will not be prevented.
     * @throws \UnexpectedValueException When the value for an event is not 'always', 'new' or 'existing'
     */
    public function handleEvent(EventInterface $event, EntityInterface $entity): bool
    {
        $eventName = $event->getName();
        $events = $this->_config['events'];

        $new = $entity->isNew() !== false;
        $refresh = $this->_config['refreshTimestamp'];

        foreach ($events[$eventName] as $field => $when) {
            if (!in_array($when, ['always', 'new', 'existing'], true)) {
                throw new UnexpectedValueException(sprintf(
                    'When should be one of "always", "new" or "existing". The passed value "%s" is invalid',
                    $when
                ));
            }
            if (
                $when === 'always' ||
                (
                    $when === 'new' &&
                    $new
                ) ||
                (
                    $when === 'existing' &&
                    !$new
                )
            ) {
                $this->_updateField($entity, $field, $refresh);
            }
        }

        return true;
    }

    /**
     * implementedEvents
     *
     * The implemented events of this behavior depend on configuration
     *
     * @return array<string, mixed>
     */
    public function implementedEvents(): array
    {
        return array_fill_keys(array_keys($this->_config['events']), 'handleEvent');
    }

    /**
     * Get or set the timestamp to be used
     *
     * Set the timestamp to the given DateTime object, or if not passed a new DateTime object
     * If an explicit date time is passed, the config option `refreshTimestamp` is
     * automatically set to false.
     *
     * @param \DateTimeInterface|null $ts Timestamp
     * @param bool $refreshTimestamp If true timestamp is refreshed.
     * @return \Cake\I18n\FrozenTime
     */
    public function timestamp(?DateTimeInterface $ts = null, bool $refreshTimestamp = false): DateTimeInterface
    {
        if ($ts) {
            if ($this->_config['refreshTimestamp']) {
                $this->_config['refreshTimestamp'] = false;
            }
            $this->_ts = new FrozenTime($ts);
        } elseif ($this->_ts === null || $refreshTimestamp) {
            $this->_ts = new FrozenTime();
        }

        return $this->_ts;
    }

    /**
     * Touch an entity
     *
     * Bumps timestamp fields for an entity. For any fields configured to be updated
     * "always" or "existing", update the timestamp value. This method will overwrite
     * any pre-existing value.
     *
     * @param \Cake\Datasource\EntityInterface $entity Entity instance.
     * @param string $eventName Event name.
     * @return bool true if a field is updated, false if no action performed
     */
    public function touch(EntityInterface $entity, string $eventName = 'Model.beforeSave'): bool
    {
        $events = $this->_config['events'];
        if (empty($events[$eventName])) {
            return false;
        }

        $return = false;
        $refresh = $this->_config['refreshTimestamp'];

        foreach ($events[$eventName] as $field => $when) {
            if (in_array($when, ['always', 'existing'], true)) {
                $return = true;
                $entity->setDirty($field, false);
                $this->_updateField($entity, $field, $refresh);
            }
        }

        return $return;
    }

    /**
     * Update a field, if it hasn't been updated already
     *
     * @param \Cake\Datasource\EntityInterface $entity Entity instance.
     * @param string $field Field name
     * @param bool $refreshTimestamp Whether to refresh timestamp.
     * @return void
     */
    protected function _updateField(EntityInterface $entity, string $field, bool $refreshTimestamp): void
    {
        if ($entity->isDirty($field)) {
            return;
        }

        $ts = $this->timestamp(null, $refreshTimestamp);

        $entity->set($field, new FrozenTime($ts));
    }
}
