<?php

namespace Tests\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait AssertsMysqlPersistence
{
    private function assertTableDefinition(string $table, array $expected): void
    {
        $columns = collect(Schema::getColumns($table));
        $this->assertSame($expected, $columns->mapWithKeys(fn (array $column) => [
            $column['name'] => [$column['type'], $column['nullable'], $column['default']],
        ])->all());
        $this->assertTrue($columns->firstWhere('name', 'id')['auto_increment']);
        $this->assertTrue(collect(Schema::getIndexes($table))->contains(
            fn (array $index) => $index['primary'] && $index['columns'] === ['id'],
        ));
    }

    private function assertForeignKeys(string $table, array $expected): void
    {
        $keys = collect(Schema::getForeignKeys($table))->keyBy('name');
        $this->assertCount(count($expected), $keys);
        foreach ($expected as $column => [$target, $action]) {
            $key = $keys->get($table.'_'.$column.'_foreign');
            $this->assertNotNull($key);
            $this->assertSame([$column], $key['columns']);
            $this->assertSame($target, $key['foreign_table']);
            $this->assertSame(['id'], $key['foreign_columns']);
            $this->assertSame($action, $key['on_delete']);
        }
    }

    private function assertIndex(string $table, string $name, array $columns, bool $unique = false): void
    {
        $index = collect(Schema::getIndexes($table))->firstWhere('name', $name);
        $this->assertNotNull($index);
        $this->assertSame($columns, $index['columns']);
        $this->assertSame($unique, $index['unique']);
    }

    private function assertEnforcedChecks(string $table, array $names): void
    {
        $checks = DB::select(<<<'SQL'
            SELECT CONSTRAINT_NAME AS name, ENFORCED AS enforced
            FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'
            SQL, [$table]);
        $this->assertEqualsCanonicalizing($names, collect($checks)->pluck('name')->all());
        foreach ($checks as $check) {
            $this->assertSame('YES', $check->enforced);
        }
    }

    private function assertInsertAndUpdateRejected(Model $record, array $changes, int $error, string|array|null $constraint = null): void
    {
        $values = $record->getAttributes();
        unset($values['id']);
        $this->assertMysqlRejects(fn () => DB::table($record->getTable())->insert([...$values, ...$changes]), $error, $constraint);
        $this->assertMysqlRejects(fn () => DB::table($record->getTable())->where('id', $record->id)->update($changes), $error, $constraint);
    }

    private function assertMysqlRejects(callable $operation, int $error, string|array|null $constraint = null): void
    {
        try {
            $operation();
        } catch (QueryException $exception) {
            $this->assertSame($error, $exception->errorInfo[1]);
            if ($constraint !== null) {
                // A value can violate multiple CHECKs; MySQL's evaluation order is not a contract.
                $this->assertTrue(collect((array) $constraint)->contains(
                    fn (string $name) => str_contains($exception->getMessage(), "Check constraint '".$name."'")
                        || str_contains($exception->getMessage(), $name) && $error !== 3819,
                ), $exception->getMessage());
            }

            return;
        }

        $this->fail('MySQL accepted an operation that should violate a database constraint.');
    }
}
