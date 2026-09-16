<?php

declare(strict_types=1);

namespace MiGears\Dao;

use PDO;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use MiGears\Sql\SqlBuilder;
use MiGears\Sql\Exception\RecordNotFoundException;
use MiGears\Sql\Exception\SqlException;

/**
 * Single-table CRUD trait for DAO classes.
 *
 * Provides common read/write operations without forcing inheritance.
 * User DAO classes use this trait and define $table + $idColumn.
 *
 *   class UserDao
 *   {
 *       use SingleTableDao;
 *
 *       protected string $table = 'users';
 *       protected string $idColumn = 'id';
 *   }
 *
 *   $dao = new UserDao($pdo);
 *   $row = $dao->getById(1);     // array|null
 *   $id  = $dao->insert($data);  // last insert id
 *   $n   = $dao->update($data);  // affected rows
 *   $n   = $dao->delete(1);      // affected rows
 */
trait SingleTableDao
{
    protected SqlBuilder $sql;

    /**
     * Initializes the DAO with a PDO connection.
     *
     * Call this from the using class constructor.
     * The using class must define $table and $idColumn properties.
     */
    protected function initDao(PDO $pdo, ?LoggerInterface $logger = null): void
    {
        if (!isset($this->table) || $this->table === '') {
            throw new SqlException(static::class . ' must define $table property');
        }
        $this->sql = new SqlBuilder($pdo, $logger ?? new NullLogger());
    }

    /**
     * Queries a single record by primary key, returns null if not found.
     *
     * @return array<string, mixed>|null
     */
    public function getById(int|string $id): ?array
    {
        return $this->sql->select()
            ->from($this->table)
            ->filter([$this->idColumn => $id])
            ->single();
    }

    /**
     * Queries a single record by primary key, throws if not found.
     *
     * @return array<string, mixed>
     * @throws RecordNotFoundException
     */
    public function getByIdOrFail(int|string $id): array
    {
        $row = $this->getById($id);
        if ($row === null) {
            throw new RecordNotFoundException($this->table, $id);
        }
        return $row;
    }

    /**
     * Batch queries by primary key list, returns array keyed by primary key.
     *
     * @param array<int|string> $ids
     * @return array<string|int, array<string, mixed>>
     */
    public function getByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $ids = array_values(array_unique($ids));
        $placeholders = [];
        $params = [];

        foreach ($ids as $i => $id) {
            $name = "id_{$i}";
            $placeholders[] = ":{$name}";
            $params[$name] = $id;
        }

        $placeholderStr = implode(', ', $placeholders);
        $rows = $this->sql->select()
            ->from($this->table)
            ->where("`{$this->idColumn}` IN ({$placeholderStr})", $params)
            ->execute();

        $result = [];
        foreach ($rows as $row) {
            $result[$row[$this->idColumn]] = $row;
        }

        return $result;
    }

    /**
     * Queries all records, sorted by primary key descending.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAll(): array
    {
        return $this->sql->select()
            ->from($this->table)
            ->orderBy("`{$this->idColumn}` DESC")
            ->execute();
    }

    /**
     * Inserts a record, returns the auto-increment ID.
     *
     * @param array<string, mixed> $data
     */
    public function insert(array $data): string
    {
        return $this->sql->insert($this->table)
            ->values($data)
            ->execute()
            ->lastInsertId();
    }

    /**
     * Updates a record by primary key, returns affected row count.
     *
     * @param array<string, mixed> $data Must contain the primary key field
     * @throws SqlException Missing primary key in data
     */
    public function update(array $data): int
    {
        if (!isset($data[$this->idColumn])) {
            throw new SqlException("Update data must contain the primary key column '{$this->idColumn}'");
        }

        $id = $data[$this->idColumn];
        unset($data[$this->idColumn]);

        if ($data === []) {
            return 0;
        }

        return $this->sql->update($this->table)
            ->set($data)
            ->filter([$this->idColumn => $id])
            ->execute();
    }

    /**
     * Deletes a record by primary key, returns affected row count.
     */
    public function delete(int|string $id): int
    {
        return $this->sql->delete($this->table)
            ->filter([$this->idColumn => $id])
            ->execute();
    }

    /**
     * Counts total records.
     */
    public function count(): int
    {
        return $this->sql->select()
            ->from($this->table)
            ->count();
    }

    /**
     * Paginated query.
     *
     * @return array{records: array<int, array<string, mixed>>, total: int}
     */
    public function paginate(int $page, int $pageSize): array
    {
        return $this->sql->select()
            ->from($this->table)
            ->orderBy("`{$this->idColumn}` DESC")
            ->paginate($page, $pageSize);
    }

    /**
     * Gets the underlying SqlBuilder for complex queries.
     */
    public function getSqlBuilder(): SqlBuilder
    {
        return $this->sql;
    }

    /**
     * Gets the table name.
     */
    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * Gets the primary key column name.
     */
    public function getIdColumn(): string
    {
        return $this->idColumn;
    }
}
