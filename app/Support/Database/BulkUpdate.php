<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;

/**
 * Update many rows by primary key in a handful of statements:
 * UPDATE t SET col = CASE id WHEN ? THEN ? … ELSE col END, … WHERE id IN (…).
 *
 * Floats are bound as their shortest round-trip representation, so stored doubles are exact
 * (PDO would otherwise cut them to 14 significant digits).
 */
final class BulkUpdate
{
    /**
     * Stay below SQLite's historic limit of 999 bound variables per statement.
     */
    private const MAX_BINDINGS = 900;

    /**
     * @param  array<int, array<string, int|float|string|bool|null>>  $rows  id => [column => value]; every row has the same columns
     * @return int rows affected
     */
    public static function byId(string $table, array $rows, string $key = 'id'): int
    {
        $first = array_key_first($rows);

        if ($first === null) {
            return 0;
        }

        $columns = array_keys($rows[$first]);

        if ($columns === []) {
            return 0;
        }

        $connection = DB::connection();
        $grammar = $connection->getQueryGrammar();
        $wrappedKey = $grammar->wrap($key);
        $chunkSize = max(1, intdiv(self::MAX_BINDINGS, 2 * count($columns) + 1));
        $affected = 0;

        foreach (array_chunk($rows, $chunkSize, true) as $chunk) {
            $sets = [];
            $bindings = [];

            foreach ($columns as $column) {
                $wrapped = $grammar->wrap($column);
                $cases = '';

                foreach ($chunk as $id => $values) {
                    $cases .= ' when ? then ?';
                    $bindings[] = $id;
                    $bindings[] = self::binding($values[$column] ?? null);
                }

                $sets[] = sprintf('%s = case %s%s else %s end', $wrapped, $wrappedKey, $cases, $wrapped);
            }

            $ids = array_keys($chunk);

            $affected += $connection->update(
                sprintf(
                    'update %s set %s where %s in (%s)',
                    $grammar->wrapTable($table),
                    implode(', ', $sets),
                    $wrappedKey,
                    implode(', ', array_fill(0, count($ids), '?')),
                ),
                [...$bindings, ...$ids],
            );
        }

        return $affected;
    }

    private static function binding(int|float|string|bool|null $value): int|string|bool|null
    {
        if (is_float($value)) {
            return is_finite($value) ? var_export($value, true) : null;
        }

        return $value;
    }
}
