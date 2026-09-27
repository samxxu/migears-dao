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
 *     (e.g. byEmail / bySku) that reference the same Domain
 *
 * The using class must declare $table, $idColumn and $domainClass, and call
 * initCachedDao() from its constructor:
 *
 *   class UserDao
 *   {
 *       use CachedDao;
 *
 *       protected string $table = 'users';
 *       protected string $idColumn = 'id';
 *       protected string $domainClass = UserDomain::class;
 *
 *       public function __construct(PDO $pdo, LoggerInterface $logger, CacheInterface $cache)
 *       {
 *           $this->initCachedDao($pdo, $logger, $cache);
 *       }
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

    protected ?LoggerInterface $logger = null;

    protected function initCachedDao(
        PDO $pdo,
        LoggerInterface $logger,
        CacheInterface $cache,
    ): void {
        $this->initDao($pdo, $logger);
        if (!isset($this->domainClass) || $this->domainClass === '') {
            throw new SqlException(static::class . ' must define $domainClass property');
        }
        $this->logger = $logger;
        $this->cache = $cache;
    }

    /**
     * Returns the cache layer, ensuring initCachedDao() has been called.
     *
     * @throws SqlException if initCachedDao() has not been called
     */
    private function cache(): CacheInterface
    {
        if (!isset($this->cache)) {
            throw new SqlException(static::class . ' has not been initialized; call initCachedDao() from the constructor.');
        }
        return $this->cache;
    }

    protected function cacheKey(string $name, string|int $id): string
    {
        return $name . '_' . $this->normalizeId($id);
    }

    /**
     * A numeric primary key reaches the DAO either as an int or as a string
     * ('1', '01'). The database always returns the canonical integer, so both
     * address the same row and must share one cache key; otherwise the row is
     * cached twice and invalidating one key leaves the other stale.
     */
    private function normalizeId(string|int $id): string|int
    {
        return is_string($id) && ctype_digit($id) ? (int) $id : $id;
    }

    protected function hydrate(array $row): object
    {
        $class = $this->domainClass;
        return $class::fromArray($row);
    }

    /**
     * Reads the Domain's own primary key so that a cache hit and a database
     * row are indexed the same way. They disagree for non-canonical numeric
     * strings such as '01': PHP keeps '01' as a string array key, while the
     * database returns the primary key as an int — the same row then lands in
     * the result twice.
     */
    private function domainId(object $domain): int|string|null
    {
        $value = get_object_vars($domain)[$this->idColumn] ?? null;
        return is_int($value) || is_string($value) ? $value : null;
    }

    public function getById(int|string $id): ?object
    {
        $cached = $this->cache()->get($this->cacheKey($this->table, $id));
        if (is_object($cached)) {
            return $cached;
        }

        $row = $this->rawGetById($id);
        if ($row === null) {
            return null;
        }

        $domain = $this->hydrate($row);
        $this->cache()->set($this->cacheKey($this->table, $id), $domain);
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
            $cached = $this->cache()->get($this->cacheKey($this->table, $id));
            if (is_object($cached)) {
                $result[$this->domainId($cached) ?? $id] = $cached;
            } else {
                $missing[] = $id;
            }
        }

        if ($missing !== []) {
            foreach ($this->rawGetByIds($missing) as $id => $row) {
                $domain = $this->hydrate($row);
                $this->cache()->set($this->cacheKey($this->table, $id), $domain);
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
            $this->logger?->warning('CachedDao: cache invalidation failed after insert', ['exception' => $e]);
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
            $this->logger?->warning('CachedDao: cache invalidation failed after update', ['exception' => $e]);
        }
        return $affected;
    }

    public function delete(int|string $id): int
    {
        $affected = $this->rawDelete($id);
        try {
            $this->cacheRemove($id);
        } catch (\Throwable $e) {
            $this->logger?->warning('CachedDao: cache invalidation failed after delete', ['exception' => $e]);
        }
        return $affected;
    }

    /**
     * Clears the primary-key cache entry. Extra related keys are cleared by
     * subclasses overriding deleteCacheFor(), which receives the fresh row
     * (uncached read) so it reflects the state after the write. When the hook
     * is not overridden the read-back is skipped: there is no one to receive
     * the row, so the extra SELECT would be pure overhead on every write.
     */
    public function cacheRemove(int|string $id): void
    {
        $this->cache()->delete($this->cacheKey($this->table, $id));
        if (!$this->deleteCacheForIsOverridden()) {
            return;
        }
        $row = $this->rawGetById($id);
        $this->deleteCacheFor($row === null ? null : $this->hydrate($row));
    }

    /**
     * Detects, once per class, whether deleteCacheFor() is overridden. A trait
     * method keeps the trait's file as its source, so a differing file means the
     * using class (or a parent) supplied its own implementation.
     */
    private function deleteCacheForIsOverridden(): bool
    {
        static $overridden = [];
        return $overridden[static::class] ??= (new \ReflectionMethod($this, 'deleteCacheFor'))->getFileName() !== __FILE__;
    }

    /** Subclass hook: clear extra cache keys referencing the same Domain. */
    protected function deleteCacheFor(?object $domain): void
    {
    }
}
