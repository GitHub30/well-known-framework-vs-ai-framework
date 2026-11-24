<?php
declare(strict_types=1);

namespace AiFramework;

/**
 * Lightweight lazy singleton Dependency Injection container.
 */
final class Container
{
    /** @var array<string, callable(self): mixed> */
    private array $definitions = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /** @param callable(self): mixed $factory */
    public function singleton(string $id, callable $factory): void
    {
        $this->definitions[$id] = $factory;
    }

    public function set(string $id, mixed $instance): void
    {
        $this->instances[$id] = $instance;
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }
        if (isset($this->definitions[$id])) {
            return $this->instances[$id] = ($this->definitions[$id])($this);
        }
        throw new \RuntimeException("Service not registered in container: {$id}");
    }
}
