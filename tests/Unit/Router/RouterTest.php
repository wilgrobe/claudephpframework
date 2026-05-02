<?php
// tests/Unit/Router/RouterTest.php
namespace Tests\Unit\Router;

use Core\Router\Router;
use InvalidArgumentException;
use Tests\TestCase;

final class RouterTest extends TestCase
{
    public function test_registers_all_http_verbs(): void
    {
        $r = new Router();
        $r->get('/a',     fn() => null);
        $r->post('/b',    fn() => null);
        $r->put('/c',     fn() => null);
        $r->patch('/d',   fn() => null);
        $r->delete('/e',  fn() => null);
        $r->options('/f', fn() => null);
        $r->head('/g',    fn() => null);

        $methods = array_unique(array_column($r->routes(), 'method'));
        sort($methods);
        $this->assertSame(['DELETE','GET','HEAD','OPTIONS','PATCH','POST','PUT'], $methods);
    }

    public function test_any_registers_for_all_verbs(): void
    {
        $r = new Router();
        $r->any('/health', fn() => null);
        $methods = array_unique(array_column($r->routes(), 'method'));
        $this->assertCount(7, $methods);
    }

    public function test_named_route_urlFor_substitutes_params(): void
    {
        $r = new Router();
        $r->get('/users/{id}', fn() => null)->name('users.show');
        $this->assertSame('/users/42', $r->urlFor('users.show', ['id' => 42]));
    }

    public function test_urlFor_spills_extra_params_to_query_string(): void
    {
        $r = new Router();
        $r->get('/users/{id}', fn() => null)->name('users.show');
        $this->assertSame('/users/42?tab=bio', $r->urlFor('users.show', ['id' => 42, 'tab' => 'bio']));
    }

    public function test_urlFor_throws_for_missing_placeholder(): void
    {
        $r = new Router();
        $r->get('/users/{id}', fn() => null)->name('users.show');
        $this->expectException(InvalidArgumentException::class);
        $r->urlFor('users.show');
    }

    public function test_urlFor_throws_for_unknown_name(): void
    {
        $r = new Router();
        $this->expectException(InvalidArgumentException::class);
        $r->urlFor('no.such.route');
    }

    public function test_group_applies_prefix_and_middleware(): void
    {
        $r = new Router();
        $r->group(
            ['prefix' => '/admin', 'middleware' => ['AuthMw', 'AdminMw'], 'name' => 'admin.'],
            function ($r) {
                $r->get('/users', fn() => null)->name('users.index');
            }
        );
        $routes = $this->readRoutes($r);
        $this->assertSame('/admin/users', $routes[0]['path']);
        $this->assertSame(['AuthMw', 'AdminMw'], $routes[0]['middleware']);
        $this->assertSame('admin.users.index', $routes[0]['name']);
    }

    public function test_nested_groups_compound_prefix_middleware_and_name(): void
    {
        $r = new Router();
        $r->group(['prefix' => '/a', 'middleware' => ['M1'], 'name' => 'a.'], function ($r) {
            $r->group(['prefix' => '/b', 'middleware' => ['M2'], 'name' => 'b.'], function ($r) {
                $r->get('/c', fn() => null)->name('c');
            });
        });
        $routes = $this->readRoutes($r);
        $this->assertSame('/a/b/c', $routes[0]['path']);
        $this->assertSame(['M1', 'M2'], $routes[0]['middleware']);
        $this->assertSame('a.b.c', $routes[0]['name']);
    }

    public function test_name_without_prior_route_throws(): void
    {
        $r = new Router();
        $this->expectException(\LogicException::class);
        $r->name('orphan');
    }

    /** @return array<int, array<string, mixed>> */
    private function readRoutes(Router $r): array
    {
        $ref = new \ReflectionObject($r);
        $prop = $ref->getProperty('routes');
        return $prop->getValue($r);
    }
}
