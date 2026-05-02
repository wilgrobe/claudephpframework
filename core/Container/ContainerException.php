<?php
// core/Container/ContainerException.php
namespace Core\Container;

/**
 * Thrown when the container cannot resolve a binding — missing class,
 * non-instantiable abstract, or an unresolvable primitive constructor parameter.
 *
 * Extends \RuntimeException for broad catchability; when we adopt psr/container
 * later, this will also implement Psr\Container\ContainerExceptionInterface.
 */
class ContainerException extends \RuntimeException
{
}
