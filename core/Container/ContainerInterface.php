<?php
// core/Container/ContainerInterface.php
namespace Core\Container;

/**
 * Minimal container contract — matches PSR-11 method signatures exactly so we
 * can switch to the real psr/container package later with a one-line change
 * in Container.php (swap `implements Core\Container\ContainerInterface` for
 * `implements Psr\Container\ContainerInterface`).
 */
interface ContainerInterface
{
    /**
     * Resolve $id from the container.
     *
     * @throws ContainerException when the binding cannot be resolved.
     */
    public function get(string $id): mixed;

    /** True when the container can resolve $id (bound, registered, or an instantiable class). */
    public function has(string $id): bool;
}
