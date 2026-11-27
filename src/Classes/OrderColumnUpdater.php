<?php

namespace WebRegulate\LaravelAdministration\Classes;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Expression;

/**
 * Persists a complete record ordering by updating existing rows.
 *
 * The read of current ids and the order updates run in one transaction.
 */
class OrderColumnUpdater
{
    /**
     * Store $orderedIds as positions on rows that already exist.
     *
     * Returns false when the submitted ids are not exactly the current primary
     * keys, so a stale list does not write a partial order.
     *
     * @param  array<int, int|string>  $orderedIds
     */
    public function persist(Model $model, OrderableOptions $options, array $orderedIds): bool
    {
        $keyName = $model->getKeyName();
        $orderedIds = array_map('strval', $orderedIds);
        $connection = $model->getConnection();

        return $connection->transaction(function () use ($connection, $keyName, $model, $options, $orderedIds): bool {
            $expectedIds = $model->newQuery()
                ->lockForUpdate()
                ->pluck($keyName)
                ->map(fn ($id) => (string) $id)
                ->all();
            $submittedIds = $orderedIds;
            sort($expectedIds, SORT_STRING);
            sort($submittedIds, SORT_STRING);

            if (count($orderedIds) !== count(array_unique($orderedIds)) || $submittedIds !== $expectedIds) {
                return false;
            }

            foreach (array_chunk($orderedIds, max(1, $options->updateChunkSize), true) as $chunk) {
                $this->updateChunk(
                    $connection,
                    $model->getTable(),
                    $keyName,
                    $options->orderColumn,
                    $chunk,
                    $options->startAt,
                );
            }

            return true;
        });
    }

    /**
     * Update one chunk of positions on rows that already exist.
     *
     * upsert() compiles to INSERT ... ON DUPLICATE KEY UPDATE. MySQL strict
     * mode validates NOT NULL columns without defaults on that INSERT, so a
     * table such as portfolios (url NOT NULL) rejects the statement even
     * though every id is already present. Reorder only changes the order column.
     *
     * @param  array<int, string>  $chunk  Original list position => id.
     */
    protected function updateChunk(
        Connection $connection,
        string $table,
        string $keyName,
        string $orderColumn,
        array $chunk,
        int $startAt,
    ): void {
        if ($chunk === []) {
            return;
        }

        $grammar = $connection->getQueryGrammar();
        $wrappedKey = $grammar->wrap($keyName);
        $wrappedOrder = $grammar->wrap($orderColumn);
        $cases = [];
        $caseBindings = [];

        foreach ($chunk as $index => $id) {
            $cases[] = "when {$wrappedKey} = ? then ?";
            $caseBindings[] = $id;
            $caseBindings[] = $startAt + $index;
        }

        $query = $connection->table($table)->whereIn($keyName, array_values($chunk));
        $sql = $grammar->compileUpdate($query, [
            $orderColumn => new Expression('case '.implode(' ', $cases).' else '.$wrappedOrder.' end'),
        ]);

        $connection->update($sql, array_merge($caseBindings, $query->getBindings()));
    }
}
