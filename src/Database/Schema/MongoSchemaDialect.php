<?php

declare(strict_types=1);

namespace Giginc\Mongodb\Database\Schema;

use Cake\Database\Schema\SchemaDialect;
use Cake\Database\Schema\TableSchema;

class MongoSchemaDialect extends SchemaDialect
{

    public function listTablesSql(array $config): array
    {
        // TODO: Implement listTablesSql() method.
    }

    public function describeColumnSql(string $tableName, array $config): array
    {
        // TODO: Implement describeColumnSql() method.
    }

    public function describeIndexSql(string $tableName, array $config): array
    {
        // TODO: Implement describeIndexSql() method.
    }

    public function describeForeignKeySql(string $tableName, array $config): array
    {
        // TODO: Implement describeForeignKeySql() method.
    }

    public function convertColumnDescription(TableSchema $schema, array $row): void
    {
        // TODO: Implement convertColumnDescription() method.
    }

    public function convertIndexDescription(TableSchema $schema, array $row): void
    {
        // TODO: Implement convertIndexDescription() method.
    }

    public function convertForeignKeyDescription(TableSchema $schema, array $row): void
    {
        // TODO: Implement convertForeignKeyDescription() method.
    }

    public function createTableSql(TableSchema $schema, array $columns, array $constraints, array $indexes): array
    {
        // TODO: Implement createTableSql() method.
    }

    public function columnSql(TableSchema $schema, string $name): string
    {
        // TODO: Implement columnSql() method.
    }

    public function addConstraintSql(TableSchema $schema): array
    {
        // TODO: Implement addConstraintSql() method.
    }

    public function dropConstraintSql(TableSchema $schema): array
    {
        // TODO: Implement dropConstraintSql() method.
    }

    public function constraintSql(TableSchema $schema, string $name): string
    {
        // TODO: Implement constraintSql() method.
    }

    public function indexSql(TableSchema $schema, string $name): string
    {
        // TODO: Implement indexSql() method.
    }

    public function truncateTableSql(TableSchema $schema): array
    {
        // TODO: Implement truncateTableSql() method.
    }
}