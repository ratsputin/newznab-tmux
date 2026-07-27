<?php

declare(strict_types=1);

namespace App\Services\Binaries;

use App\Support\Utf8;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Handles binary record creation and updates during header storage.
 */
final class BinaryHandler
{
    /**
     * Hard upper bound on the number of rows packed into a single SQL
     * statement (multi-row INSERT, OR-clause SELECT, etc.). Keeps the
     * generated SQL string and PDO parameter list bounded regardless of
     * how many headers the caller passes in.
     */
    private const MAX_SQL_ROWS_PER_STATEMENT = 500;

    /** @var array<int, array{Size: int, Parts: int}> Pending binary updates */
    private array $binariesUpdate = [];

    /** @var array<int, true> IDs of binaries created in this batch */
    private array $insertedBinaryIds = [];

    /** @var array<string, array{CollectionID: int, BinaryID: int}> Processed articles */
    private array $articles = [];

    public function __construct() {}

    /**
     * Reset state for a new batch.
     */
    public function reset(): void
    {
        $this->binariesUpdate = [];
        $this->insertedBinaryIds = [];
        $this->articles = [];
    }

    /**
     * Get or create a binary for the given header.
     *
     * @param  array<string, mixed>  $header
     * @return int|null Binary ID or null on failure
     */
    public function getOrCreateBinary(
        array $header,
        int $collectionId,
        int $groupId,
        int $fileNumber
    ): ?int {
        $articleKey = $this->articleKey($collectionId, $header['matches'][1]);

        // Return cached if already processed
        if (isset($this->articles[$articleKey])) {
            $binaryId = $this->articles[$articleKey]['BinaryID'];
            $this->binariesUpdate[$binaryId]['Size'] += $header['Bytes'];
            $this->binariesUpdate[$binaryId]['Parts']++;

            return $binaryId;
        }

        $hash = md5((string) ($header['matches'][1] ?? '').(string) ($header['From'] ?? '').(string) $groupId);
        $driver = DB::getDriverName();

        try {
            $binaryId = $this->insertOrGetBinary(
                $driver,
                $hash,
                $header,
                $collectionId,
                $fileNumber
            );

            if ($binaryId > 0) {
                $this->binariesUpdate[$binaryId] = ['Size' => 0, 'Parts' => 0];
                $this->articles[$articleKey] = [
                    'CollectionID' => $collectionId,
                    'BinaryID' => $binaryId,
                ];

                return $binaryId;
            }
        } catch (\Throwable $e) {
            if (config('app.debug') === true) {
                Log::error('Binary insert failed: '.$e->getMessage());
            }
        }

        return null;
    }

    /**
     * Resolve binaries for a chunk of headers with one bulk insert and one id lookup.
     *
     * @param  array<int, array{header: array<string, mixed>, collection_id: int, file_number: int}>  $records
     * @return array<int, int> Binary ids keyed by header index
     */
    public function getOrCreateBinaries(array $records, int $groupId): array
    {
        $resolved = [];
        $pending = [];
        $indexesByArticleKey = [];
        $extraUpdatesByArticleKey = [];

        foreach ($records as $index => $record) {
            $header = $record['header'];
            $collectionId = (int) $record['collection_id'];
            $fileNumber = (int) $record['file_number'];
            $articleKey = $this->articleKey($collectionId, $header['matches'][1]);

            if (isset($this->articles[$articleKey])) {
                $binaryId = $this->articles[$articleKey]['BinaryID'];
                $this->binariesUpdate[$binaryId]['Size'] += (int) $header['Bytes'];
                $this->binariesUpdate[$binaryId]['Parts']++;
                $resolved[$index] = $binaryId;

                continue;
            }

            $indexesByArticleKey[$articleKey][] = $index;
            if (isset($pending[$articleKey])) {
                $extraUpdatesByArticleKey[$articleKey]['Size'] = ($extraUpdatesByArticleKey[$articleKey]['Size'] ?? 0) + (int) $header['Bytes'];
                $extraUpdatesByArticleKey[$articleKey]['Parts'] = ($extraUpdatesByArticleKey[$articleKey]['Parts'] ?? 0) + 1;

                continue;
            }

            $hash = md5((string) ($header['matches'][1] ?? '').(string) ($header['From'] ?? '').(string) $groupId);
            $pending[$articleKey] = [
                'hash' => $hash,
                'name' => Utf8::clean($header['matches'][1]),
                'collections_id' => $collectionId,
                'totalparts' => (int) $header['matches'][3],
                'filenumber' => $fileNumber,
                'partsize' => (int) $header['Bytes'],
            ];
        }

        if ($pending === []) {
            return $resolved;
        }

        try {
            $result = $this->bulkInsertAndResolve($pending);
            $idsByKey = $result['ids'];
            $existingKeys = $result['existing'];
            $driver = DB::getDriverName();
            foreach ($pending as $articleKey => $row) {
                $lookupKey = $this->binaryLookupKey($row['hash'], (int) $row['collections_id']);
                $binaryId = $idsByKey[$lookupKey] ?? 0;
                if ($binaryId <= 0) {
                    continue;
                }

                $this->binariesUpdate[$binaryId] = $this->binariesUpdate[$binaryId] ?? ['Size' => 0, 'Parts' => 0];
                if ($driver === 'sqlite' && isset($existingKeys[$lookupKey])) {
                    $this->binariesUpdate[$binaryId]['Size'] += (int) $row['partsize'];
                    $this->binariesUpdate[$binaryId]['Parts']++;
                }
                if (isset($extraUpdatesByArticleKey[$articleKey])) {
                    $this->binariesUpdate[$binaryId]['Size'] += $extraUpdatesByArticleKey[$articleKey]['Size'];
                    $this->binariesUpdate[$binaryId]['Parts'] += $extraUpdatesByArticleKey[$articleKey]['Parts'];
                }

                $this->articles[$articleKey] = [
                    'CollectionID' => (int) $row['collections_id'],
                    'BinaryID' => $binaryId,
                ];

                foreach ($indexesByArticleKey[$articleKey] ?? [] as $index) {
                    $resolved[$index] = $binaryId;
                }
            }
        } catch (\Throwable $e) {
            if (config('app.debug') === true) {
                Log::error('Bulk binary insert failed: '.$e->getMessage());
            }
        }

        return $resolved;
    }

    /**
     * @param  array<string, array<string, mixed>>  $rowsByArticleKey
     * @return array{ids: array<string, int>, existing: array<string, true>}
     */
    private function bulkInsertAndResolve(array $rowsByArticleKey): array
    {
        $lookupRows = array_values($rowsByArticleKey);

        // One SELECT establishes both "did this row exist?" and "what is its
        // id?", instead of two identical SELECTs (existingBinaryKeys +
        // resolveBinaryIds) that the previous implementation issued.
        $idsByKey = $this->selectBinaryIdsByKey($lookupRows);
        $existingKeys = [];
        foreach ($idsByKey as $key => $_id) {
            $existingKeys[$key] = true;
        }

        if (DB::getDriverName() === 'sqlite') {
            $this->bulkInsertBinariesSqlite($lookupRows);
        } else {
            $this->bulkInsertBinariesMysql($lookupRows);
        }

        // Only re-query for rows we couldn't satisfy from the prefetch
        // (i.e. those that didn't exist before the bulk INSERT).
        $missingRows = [];
        foreach ($lookupRows as $row) {
            $key = $this->binaryLookupKey((string) $row['hash'], (int) $row['collections_id']);
            if (! isset($idsByKey[$key])) {
                $missingRows[] = $row;
            }
        }

        if ($missingRows !== []) {
            foreach ($this->selectBinaryIdsByKey($missingRows) as $key => $id) {
                $idsByKey[$key] = $id;
            }
        }

        foreach ($idsByKey as $key => $id) {
            if (! isset($existingKeys[$key])) {
                $this->insertedBinaryIds[$id] = true;
            }
        }

        return ['ids' => $idsByKey, 'existing' => $existingKeys];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int> id keyed by binaryLookupKey(hash, collectionId)
     */
    private function selectBinaryIdsByKey(array $rows): array
    {
        $resolved = [];
        foreach ($this->selectBinaryRows($rows) as $row) {
            $resolved[$this->binaryLookupKey((string) $row->hashvalue, (int) $row->collections_id)] = (int) $row->id;
        }

        return $resolved;
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function bulkInsertBinariesSqlite(array $rows): void
    {
        foreach (array_chunk($rows, self::MAX_SQL_ROWS_PER_STATEMENT) as $chunk) {
            $insertRows = [];
            foreach ($chunk as $row) {
                $insertRows[] = [
                    'binaryhash' => $row['hash'],
                    'name' => $row['name'],
                    'collections_id' => $row['collections_id'],
                    'totalparts' => $row['totalparts'],
                    'currentparts' => 1,
                    'filenumber' => $row['filenumber'],
                    'partsize' => $row['partsize'],
                ];
            }

            DB::table('binaries')->insertOrIgnore($insertRows);
        }
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function bulkInsertBinariesMysql(array $rows): void
    {
        // Sort rows by collections_id + hash to ensure consistent InnoDB locking order
        usort($rows, static function (array $a, array $b): int {
            $cmp = ($a['collections_id'] ?? 0) <=> ($b['collections_id'] ?? 0);
            return $cmp !== 0 ? $cmp : strcmp((string) ($a['hash'] ?? ''), (string) ($b['hash'] ?? ''));
        });

        foreach (array_chunk($rows, self::MAX_SQL_ROWS_PER_STATEMENT) as $chunk) {
            $placeholders = [];
            $bindings = [];
            foreach ($chunk as $row) {
                $placeholders[] = '(UNHEX(?), ?, ?, ?, 1, ?, ?)';
                array_push(
                    $bindings,
                    $row['hash'],
                    $row['name'],
                    $row['collections_id'],
                    $row['totalparts'],
                    $row['filenumber'],
                    $row['partsize']
                );
            }

            $sql = 'INSERT INTO binaries (binaryhash, name, collections_id, totalparts, currentparts, filenumber, partsize) VALUES '
                .implode(',', $placeholders)
                .' ON DUPLICATE KEY UPDATE currentparts = currentparts + 1, partsize = partsize + VALUES(partsize)';

            $attempts = 0;
            while (true) {
                try {
                    DB::statement($sql, $bindings);
                    break;
                } catch (\Illuminate\Database\QueryException $e) {
                    $errorCode = $e->errorInfo[1] ?? 0;
                    if (in_array($errorCode, [1213, 1020, 1205], true) && $attempts < 3) {
                        $attempts++;
                        usleep(random_int(20000, 100000) * $attempts);
                        continue;
                    }
                    throw $e;
                }
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<object>
     */
    private function selectBinaryRows(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $driver = DB::getDriverName();
        $hashExpression = $driver === 'sqlite' ? 'binaryhash' : 'LOWER(HEX(binaryhash))';

        // Group lookups by collections_id and run small `binaryhash IN (...)`
        // queries per collection. This avoids producing a single
        // `WHERE (... AND ...) OR (... AND ...) OR ...` expression with
        // thousands of clauses and bindings, which both bloats the SQL
        // string in PHP and can force MySQL into pathological plans.
        $rowsByCollection = [];
        foreach ($rows as $row) {
            $rowsByCollection[(int) $row['collections_id']][] = (string) $row['hash'];
        }

        $results = [];
        foreach ($rowsByCollection as $collectionId => $hashes) {
            $hashes = array_values(array_unique($hashes));
            foreach (array_chunk($hashes, self::MAX_SQL_ROWS_PER_STATEMENT) as $chunk) {
                $placeholders = implode(',', array_fill(0, \count($chunk), $driver === 'sqlite' ? '?' : 'UNHEX(?)'));
                $bindings = $chunk;
                $bindings[] = $collectionId;

                $rowsResult = DB::select(
                    "SELECT id, {$hashExpression} AS hashvalue, collections_id FROM binaries "
                    ."WHERE binaryhash IN ({$placeholders}) AND collections_id = ?",
                    $bindings
                );

                foreach ($rowsResult as $r) {
                    $results[] = $r;
                }
            }
        }

        return $results;
    }

    private function binaryLookupKey(string $hash, int $collectionId): string
    {
        return strtolower($hash).':'.$collectionId;
    }

    /**
     * @param  array<string, mixed>  $header
     */
    private function insertOrGetBinary(
        string $driver,
        string $hash,
        array $header,
        int $collectionId,
        int $fileNumber
    ): int {
        $name = Utf8::clean($header['matches'][1]);
        $totalParts = (int) $header['matches'][3];
        $partSize = (int) $header['Bytes'];

        if ($driver === 'sqlite') {
            return $this->insertBinarySqlite($hash, $name, $collectionId, $totalParts, $fileNumber, $partSize);
        }

        return $this->insertBinaryMysql($hash, $name, $collectionId, $totalParts, $fileNumber, $partSize);
    }

    private function insertBinarySqlite(
        string $hash,
        string $name,
        int $collectionId,
        int $totalParts,
        int $fileNumber,
        int $partSize
    ): int {
        $affected = DB::table('binaries')->insertOrIgnore([
            'binaryhash' => $hash,
            'name' => $name,
            'collections_id' => $collectionId,
            'totalparts' => $totalParts,
            'currentparts' => 1,
            'filenumber' => $fileNumber,
            'partsize' => $partSize,
        ]);

        if ($affected > 0 && ($lastId = (int) DB::connection()->getPdo()->lastInsertId()) > 0) {
            $this->insertedBinaryIds[$lastId] = true;

            return $lastId;
        }

        $bin = DB::selectOne(
            'SELECT id FROM binaries WHERE binaryhash = ? AND collections_id = ? LIMIT 1',
            [$hash, $collectionId]
        );

        return (int) ($bin->id ?? 0);
    }

    private function insertBinaryMysql(
        string $hash,
        string $name,
        int $collectionId,
        int $totalParts,
        int $fileNumber,
        int $partSize
    ): int {

        $sql = 'INSERT INTO binaries '
            .'(binaryhash, name, collections_id, totalparts, currentparts, filenumber, partsize) '
            .'VALUES (UNHEX(?), ?, ?, ?, 1, ?, ?) '
            .'ON DUPLICATE KEY UPDATE currentparts = currentparts + 1, partsize = partsize + VALUES(partsize)';

        DB::statement($sql, [$hash, $name, $collectionId, $totalParts, $fileNumber, $partSize]);

        $lastId = (int) DB::connection()->getPdo()->lastInsertId();
        if ($lastId > 0) {
            $this->insertedBinaryIds[$lastId] = true;

            return $lastId;
        }

        $bin = DB::selectOne(
            'SELECT id FROM binaries WHERE binaryhash = UNHEX(?) AND collections_id = ? LIMIT 1',
            [$hash, $collectionId]
        );

        return (int) ($bin->id ?? 0);
    }

    /**
     * Flush accumulated size/parts updates to the database.
     */
    public function flushUpdates(int $chunkSize = 1000): bool
    {
        $updates = $this->getPendingUpdates();
        if (empty($updates)) {
            return true;
        }

        $driver = DB::getDriverName();

        try {
            if ($driver === 'sqlite') {
                return $this->flushUpdatesSqlite($updates); // @phpstan-ignore argument.type
            }

            return $this->flushUpdatesMysql($updates, $chunkSize); // @phpstan-ignore argument.type
        } catch (\Throwable $e) {
            if (config('app.debug') === true) {
                Log::error('Binaries aggregate update failed: '.$e->getMessage());
            }

            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $updates
     */
    private function flushUpdatesSqlite(array $updates): bool
    {
        foreach ($updates as $row) {
            DB::statement(
                'UPDATE binaries SET partsize = partsize + ?, currentparts = currentparts + ? WHERE id = ?',
                [$row['partsize'], $row['currentparts'], $row['id']]
            );
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $updates
     */
    private function flushUpdatesMysql(array $updates, int $chunkSize): bool
    {
        // Clamp the caller-provided chunk size to our hard upper bound so an
        // unusually large config value cannot produce a 100 KB+ UNION ALL
        // query that exhausts memory on either side of the connection.
        $chunkSize = max(1, min($chunkSize, self::MAX_SQL_ROWS_PER_STATEMENT));

        foreach (array_chunk($updates, $chunkSize) as $chunk) {
            $selects = [];
            $bindings = [];

            foreach ($chunk as $row) {
                $selects[] = 'SELECT ? AS id, ? AS partsize, ? AS currentparts';
                $bindings[] = $row['id'];
                $bindings[] = $row['partsize'];
                $bindings[] = $row['currentparts'];
            }

            $sql = 'UPDATE binaries b INNER JOIN ('
                .implode(' UNION ALL ', $selects)
                .') u ON u.id = b.id '
                .'SET b.partsize = b.partsize + u.partsize, '
                .'b.currentparts = b.currentparts + u.currentparts';

            DB::statement($sql, $bindings);
        }

        return true;
    }

    /**
     * Check if article is already processed.
     */
    public function hasArticle(string $articleKey): bool
    {
        return isset($this->articles[$articleKey]);
    }

    private function articleKey(int $collectionId, string $name): string
    {
        return $collectionId.':'.$name;
    }

    /**
     * Get IDs created in this batch.
     *
     * @return list<int>
     */
    public function getInsertedIds(): array
    {
        return array_keys($this->insertedBinaryIds);
    }

    /**
     * Get pending binary updates that haven't been flushed.
     *
     * @return list<array<string, int>>
     */
    private function getPendingUpdates(): array
    {
        $rows = [];
        foreach ($this->binariesUpdate as $binaryId => $binary) {
            if (($binary['Size'] ?? 0) > 0 || ($binary['Parts'] ?? 0) > 0) {
                $rows[] = [
                    'id' => $binaryId,
                    'partsize' => $binary['Size'],
                    'currentparts' => $binary['Parts'],
                ];
            }
        }

        return $rows;
    }
}
