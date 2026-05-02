<?php
// core/Request.php — thin alias so controllers can use \Core\Request
namespace Core;

/**
 * Alias: Core\Request → Core\Http\Request
 * Controllers throughout the framework type-hint against Core\Request.
 */
class Request extends \Core\Http\Request {}
