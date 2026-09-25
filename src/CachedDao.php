<?php

declare(strict_types=1);

namespace MiGears\Dao;

use PDO;
use Psr\Log\LoggerInterface;
use MiGears\Cache\CacheInterface;
use MiGears\Sql\Exception\SqlException;
use MiGears\Sql\Exception\RecordNotFoundException;

/**
 * Cached single-table DAO trait.
 *
 * Adds a caching layer on top of SingleTableDao:
 *   - getById / getByIds check the cache first, fall back to the database,
 *     then hydrate the row into a Domain object via $domainClass::fromArray()
 *   - insert / update / delete invalidate the primary-key cache automatically
 *   - subclasses may override deleteCacheFor() to clear extra cache keys
 *     (e.g. byOpenId / byNonstandard) that reference the same Domain
 *
 * The using class must declare $table, $idColumn and $domainClass:
 *
 *   class UserDao
 *   {
 *       use CachedDao;
 *
 *       protected string $table = 'users';
 *       protected string $idColumn = 'id';
 *       protected string $domainClass = UserDomain::class;
 *   }
 */
trait CachedDao
{
    use SingleTableDao {
        getById as protected rawGetById;
        getByIds as protected rawGetByIds;
        getAll as protected rawGetAll;
        count as protected rawCount;
        insert as protected rawInsert;
        update as protected rawUpdate;
        delete as protected rawDelete;
        paginate as protected rawPaginate;
    }

    protected CacheInterface $cache;

    protected function initCachedDao(
        PDO $pdo,
        LoggerInterface $logger,
        CacheInterface $cache,
    ): void {
        $this->initDao($pdo, $logger);
        $this->cache = $cache;
    }

    protected function cacheKey(string $name, string|int $id): string
    {
        return $name . '_' . $id;
    }

    protected function hydrate(array $row): object
    {
        $class = $this->domainClass;
        return $class::fromArray($row);
    }

    public function getById(int|string $id): ?object
    {
        $cached = $this->cache->get($this->cacheKey($this->table, $id));
        if (is_object($cached)) {
            return $cached;
        }

        $row = $this->rawGetById($id);
        if ($row === null) {
            return null;
        }

        $domain = $this->hydrate($row);
        $this->cache->set($this->cacheKey($this->table, $id), $domain);
        return $domain;
    }

    /**
     * Returns the hydrated Domain object for the given primary key,
     * or throws RecordNotFoundException if no such record exists.
     *
     * Overrides SingleTableDao::getByIdOrFail() so the return type matches
     * CachedDao::getById() (Domain object instead of raw array).
     *
     * @throws RecordNotFoundException
     */
    public function getByIdOrFail(int|string $id): object
    {
        $domain = $this->getById($id);
        if ($domain === null) {
            throw new RecordNotFoundException($this->table, $id);
        }
        return $domain;
    }

    public function getByIds(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }

        $result = [];
        $missing = [];
        foreach ($ids as $id) {
            $cached = $this->cache->get($this->cacheKey($this->table, $id));
            if (is_object($cached)) {
                $result[$id] = $cached;
            } else {
                $missing[] = $id;
            }
        }

        if ($missing !== []) {
            foreach ($this->rawGetByIds($missing) as $id => $row) {
                $domain = $this->hydrate($row);
                $this->cache->set($this->cacheKey($this->table, $id), $domain);
                $result[$id] = $domain;
            }
        }

        return $result;
    }

    public function getAll(): array
    {
        return array_map(fn(array $row) => $this->hydrate($row), $this->rawGetAll());
    }

    /**
     * Paginated query with hydrated Domain objects in records.
     *
     * @return array{records: array<int, object>, total: int}
     */
    public function paginate(int $page, int $pageSize): array
    {
        $result = $this->rawPaginate($page, $pageSize);
        $result['records'] = array_map(fn(array $row) => $this->hydrate($row), $result['records']);
        return $result;
    }

    public function count(): int
    {
        return $this->rawCount();
    }

    public function insert(array $data): string
    {
        $explicitId = $data[$this->idColumn] ?? null;
        $id = $this->rawInsert($data);
        try {
            $this->cacheRemove($explicitId ?? $id);
        } catch (\Throwable $e) {
        }
        return $id;
    }

    public function update(array $data): int
    {
        if (!isset($data[$this->idColumn])) {
            throw new SqlException("Update data must contain the primary key column '{$this->idColumn}'");
        }
        $id = $data[$this->idColumn];
        unset($data[$this->idColumn]);

        $affected = $data === [] ? 0 : $this->rawUpdate($data + [$this->idColumn => $id]);
        try {
            $this->cacheRemove($id);
        } catch (\Throwable $e) {
        }
        return $affected;
    }

    public function delete(int|string $id): int
    {
        $affected = $this->rawDelete($id);
        try {
            $this->cacheRemove($id);
        } catch (\Throwable $e) {
        }
        return $affected;
    }

    /**
     * Clears the primary-key cache entry. Extra related keys are cleared by
     * subclasses overriding deleteCacheFor(), which receives the fresh row
     * (uncached read) so it reflects the state after the write.
     */
    public function cacheRemove(int|string $id): void
    {
        $this->cache->delete($this->cacheKey($this->table, $id));
        $row = $this->rawGetById($id);
        $this->deleteCacheFor($row === null ? null : $this->hydrate($row));
    }

    /** Subclass hook: clear extra cache keys referencing the same Domain. */
    protected function deleteCacheFor(?object $domain): void
    {
    }
}
