<?php

declare(strict_types=1);

namespace Giginc\Mongodb\ORM;

use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\Datasource\ResultSetDecorator;
use Cake\Datasource\ResultSetInterface;
use Cake\I18n\FrozenTime;
use Cake\Utility\Hash;
use Giginc\Mongodb\Database\Expression\BaseExpression;
use Giginc\Mongodb\Database\Expression\Comparison\EqualExpression;
use Giginc\Mongodb\Database\Expression\Comparison\GreaterThanEqualsExpression;
use Giginc\Mongodb\Database\Expression\Comparison\GreaterThanExpression;
use Giginc\Mongodb\Database\Expression\Comparison\InExpression;
use Giginc\Mongodb\Database\Expression\Comparison\LessThanEqualsExpression;
use Giginc\Mongodb\Database\Expression\Comparison\LessThanExpression;
use Giginc\Mongodb\Database\Expression\Comparison\NotEqualExpression;
use Giginc\Mongodb\Database\Expression\Comparison\NotInExpression;
use Giginc\Mongodb\Database\Expression\Logical\AndExpression;
use Giginc\Mongodb\Database\Expression\Logical\NotExpression;
use Giginc\Mongodb\Database\Expression\Logical\OrExpression;
use Giginc\Mongodb\Database\Expression\MongoExpression;
use Giginc\Mongodb\Database\Expression\RegexExpression;
use Giginc\Mongodb\Database\Expression\Update\SetExpression;
use Giginc\Mongodb\Database\MongoDb\Connection;
use Giginc\Mongodb\Database\Query as DatabaseQuery;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\Regex;
use MongoDB\BSON\UTCDateTime;
use MongoDB\DeleteResult;
use MongoDB\InsertManyResult;
use MongoDB\InsertOneResult;
use MongoDB\UpdateResult;
use Traversable;

class Query extends DatabaseQuery
{
    protected BaseExpression|null $expression = null;
    protected array|null $orderBy = null;

    public function __construct(
        protected Connection $connection,
        protected Table $table,
        protected array $options = [],
    ) {
        parent::__construct($this->connection);

        if (isset($options['conditions'])) {
            $this->where($options['conditions']);
        }

        if (isset($options['order'])) {
            $this->orderBy = $options['order'];
        }
    }

    public function where(array|BaseExpression $conditions): self {
        return $this->andWhere($conditions);
    }

    public function andWhere(array|BaseExpression $conditions): self {
        if (null === $this->expression) {
            $this->expression = $this->toBaseExpression($conditions);
        } else {
            if ($this->expression->hasAnd()) {
                $this->expression->addToAnd($this->toBaseExpression($conditions));

                return $this;
            }

            $expression = $this->expression;

            $newExpression = new BaseExpression();

            $newExpression->addToAnd($expression);
            $newExpression->addToAnd($this->toBaseExpression($conditions));

            $this->expression = $newExpression;
        }

        return $this;
    }

    public function orWhere(array|BaseExpression $conditions): self {
        if (null === $this->expression) {
            $this->expression = $this->toBaseExpression($conditions);
        } else {
            if ($this->expression->hasOr()) {
                $this->expression->addToOr($this->toBaseExpression($conditions));

                return $this;
            }

            $expression = $this->expression;

            $newExpression = new BaseExpression();

            $newExpression->addToOr($expression);
            $newExpression->addToOr($this->toBaseExpression($conditions));

            $this->expression = $newExpression;
        }

        return $this;
    }

    protected function toBaseExpression(array|BaseExpression &$conditions): BaseExpression {
        if ($conditions instanceof BaseExpression) {
            return $conditions;
        }

        $sqlOperators = '<|>|<=|>=|!=|=|<>|IN|LIKE';

        $baseExpression = new BaseExpression();

        foreach ($conditions as $key => $value) {
            if (is_int($key)) {
                $subExpression = $this->toBaseExpression($value);

                $baseExpression->addToAnd($subExpression);

                continue;
            }

            if ($value instanceof MongoExpression) {
                $baseExpression->add($key, $value);

                continue;
            }

            if (preg_match("/^(.+) ($sqlOperators)$/", $key, $matches)) {
                list(, $field, $operator) = $matches;

                if (str_ends_with($field, 'NOT')) {
                    $field = substr($field, 0, strlen($field) -4);
                    $operator = 'NOT '.$operator;
                }

                $expression = $this->createExpressionFromOperator($operator, $value);

                $baseExpression->add($field, $expression);
            } elseif (preg_match('/^OR|AND$/i', $key, $match)) {
                $operator = strtolower($match[0]);

                $expression = match ($operator) {
                    'or' => new AndExpression(),
                    'and' => new OrExpression(),
                };

                foreach ($value as $nestedValue) {
                    if (!is_array($nestedValue)) {
                        $subExpression = $this->toBaseExpression($value);

                        $expression->add($subExpression);

                        break;
                    } else {
                        $subExpression = $this->toBaseExpression($nestedValue);

                        $expression->add($subExpression);
                    }
                }
            } else {
                $expression = new EqualExpression($value);

                $baseExpression->add($key, $expression);
            }
        }

        return $baseExpression;
    }

    protected function createExpressionFromOperator(string $operator, mixed $value): MongoExpression {
        return match ($operator) {
            '<' => new LessThanExpression($value),
            '<=' => new LessThanEqualsExpression($value),
            '>' => new GreaterThanExpression($value),
            '>=' => new GreaterThanEqualsExpression($value),
            '!=', '<>' => new NotEqualExpression($value),
            'NOT IN' => new NotInExpression($value),
            'IN' => new InExpression($value),
            'LIKE' => new RegexExpression($this->translateToRegex($value)),
            'NOT LIKE' => new NotExpression(new RegexExpression($this->translateToRegex($value, true))),
            default => new EqualExpression($value),
        };
    }

    protected function translateToRegex(string $value, bool $not = false): Regex
    {
        $value = preg_quote($value);

        $value = str_replace('%', '.*', $value);
        $value = str_replace('\?', '.', $value);

        if ($not) {
            $value = "(?!$value)";
        }

        return new Regex("^$value$", "i");
    }

    public function all(array $options = []) {
        $options = array_merge($this->options, $options);
        $iterator = $this->getConnection()->find($this->table->getTable(), $this->expression->compile(), $this->translateOptions($options));

        return $this->decorateResults($iterator);
    }

    public function first(array $options = []) {
        $options = array_merge($this->options, $options);
        $document = $this->getConnection()->findOne($this->table->getTable(), $this->expression->compile(), $this->translateOptions($options));

        if ($document === null) {
            return null;
        }

        return $this->table->getMarshaller()->one($document);
    }

    public function firstOrFail(array $options = []) {
        $options = array_merge($this->options, $options);
        $document = $this->getConnection()->findOne($this->table->getTable(), $this->expression->compile(), $this->translateOptions($options));

        if ($document === null) {
            return new RecordNotFoundException();
        }

        return $this->table->getMarshaller()->one($document);
    }

    protected function decorateResults(Traversable $traversable): ResultSetInterface
    {
        $decorator = $this->_decoraterClass();

        $array = [];

        foreach ($traversable as $result) {
            $array[] = $result->getArrayCopy();
        }

        $results = $this->table->getMarshaller()->many($array);

        return new $decorator($results);
    }

    public function updateOne(array $data, ?array $conditions = null): UpdateResult
    {
        if (null !== $conditions) {
            $this->where($conditions);
        } elseif (isset($data['_id']) && is_string($data['_id']) ) {
            $this->where([
                '_id' => new ObjectId($data['_id']),
            ]);
        }

        $data = $this->translateValuesToMongo($data);

        return $this->getConnection()->updateOne($this->table->getTable(), $this->expression->compile(), $data);
    }

    public function updateMany(array $data, ?array $conditions = null): UpdateResult
    {
        if (null !== $conditions) {
            $this->where($conditions);
        } elseif (isset($data['_id']) && is_string($data['_id']) ) {
            $this->where([
                '_id' => $data['_id'],
            ]);
        }

        $data = $this->translateValuesToMongo($data);

        $expression = new SetExpression($data);

        return $this->getConnection()->updateMany($this->table->getTable(), $this->expression->compile(), $expression->compile());
    }

    public function insertOne(array $data): InsertOneResult
    {
        $data = $this->translateValuesToMongo($data);

        return $this->getConnection()->insertOne($this->table->getTable(), $data);
    }

    public function insertMany(array $data): InsertManyResult
    {
        $data = array_map(function ($item) {
            return $this->translateValuesToMongo($item);
        }, $data);

        return $this->getConnection()->insertMany($this->table->getTable(), $data);
    }

    protected function translateValuesToMongo(array $data): array
    {
        $mongoData = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $mongoData[$key] = $this->translateValuesToMongo($value);

                continue;
            }

            if ($value instanceof \DateTimeInterface) {
                $mongoData[$key] = new UTCDateTime($value);

                continue;
            }

            if ($key === '_id' && is_string($value)) {
                $mongoData[$key] = $value;

                continue;
            }

            $mongoData[$key] = $value;
        }

        return $mongoData;
    }

    public function deleteOne(array $conditions = null, array $options = []): DeleteResult
    {
        if (null !== $conditions) {
            $this->where($conditions);
        }

        $options = $this->translateOptions(array_merge($this->options, $options));

        return $this->getConnection()->deleteOne($this->table->getTable(), $this->expression->compile(), $options);
    }

    public function deleteMany(array $conditions = null, array $options = []): DeleteResult
    {
        if (null !== $conditions) {
            $this->where($conditions);
        }

        $options = $this->translateOptions(array_merge($this->options, $options));

        return $this->getConnection()->deleteMany($this->table->getTable(), $this->expression->compile(), $options);
    }

    protected function _decoraterClass(): string
    {
        return ResultSetDecorator::class;
    }

    public function orderBy(array $order): self
    {
        $this->orderBy = $order;

        return $this;
    }

    protected function translateOptions(array $options): array
    {
        $options = $this->translateSortOption($options);
        return $this->translateLimitOption($options);
    }

    protected function translateSortOption(array $options): ?array
    {
        if (null === $this->orderBy && !isset($options['sort']) && !isset($options['order'])) {
            return $options;
        }

        $options['sort'] = array_map(
            function ($v) {
                return strtolower((string)$v) === 'desc' ? -1 : 1;
            },
            Hash::get($options, 'sort', [])
            + Hash::get($options, 'order', [])
            + Hash::normalize($this->orderBy ?? [])
        );

        return $options;
    }

    protected function translateLimitOption(array $options): array
    {
        if (!empty($this->_options['limit']) && !isset($options['limit'])) {
            $options['limit'] = $this->_options['limit'];
        }
        if (!empty($this->_options['page']) && $this->_options['page'] > 1
            && !empty($options['limit'])
            && !isset($options['skip'])
        ) {
            $options['skip'] = $options['limit'] * ($this->_options['page'] -1);
        }

        return $options;
    }
}