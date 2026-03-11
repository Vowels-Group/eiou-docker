<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Database;

use PDO;
use InvalidArgumentException;

/**
 * Factory for creating and caching repository instances (ARCH-05).
 *
 * Centralizes the lazy-instantiation + caching logic that was previously
 * duplicated across 25 nearly-identical getter methods in ServiceContainer.
 * All repositories extend AbstractRepository and accept ?PDO in their
 * constructor, so a single generic factory method handles them all.
 *
 * Usage:
 *   $factory = new RepositoryFactory($pdo);
 *   $repo = $factory->get(ContactRepository::class);   // cached
 *   $same = $factory->get(ContactRepository::class);   // same instance
 */
class RepositoryFactory
{
    private PDO $pdo;

    /** @var array<class-string<AbstractRepository>, AbstractRepository> */
    private array $instances = [];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Get (or create) a repository instance.
     *
     * @template T of AbstractRepository
     * @param class-string<T> $class Fully-qualified repository class name
     * @return T
     * @throws InvalidArgumentException If $class is not a subclass of AbstractRepository
     */
    public function get(string $class): AbstractRepository
    {
        if (!isset($this->instances[$class])) {
            if (!is_subclass_of($class, AbstractRepository::class)) {
                throw new InvalidArgumentException(
                    "$class is not a subclass of " . AbstractRepository::class
                );
            }
            $this->instances[$class] = new $class($this->pdo);
        }
        return $this->instances[$class];
    }

    /**
     * Check whether a repository instance has already been created.
     */
    public function has(string $class): bool
    {
        return isset($this->instances[$class]);
    }

    /**
     * Replace a cached instance (useful for testing).
     *
     * @template T of AbstractRepository
     * @param class-string<T> $class
     * @param T $instance
     */
    public function set(string $class, AbstractRepository $instance): void
    {
        $this->instances[$class] = $instance;
    }

    /**
     * Get the PDO connection used by this factory.
     */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }
}
