<?php
// tests/Unit/Container/ContainerTest.php
namespace Tests\Unit\Container;

use Core\Container\Container;
use Core\Container\ContainerException;
use Tests\TestCase;

final class ContainerTest extends TestCase
{
    public function test_resolves_concrete_class_with_no_dependencies(): void
    {
        $c = new Container();
        $obj = $c->make(NoDepsFixture::class);
        $this->assertInstanceOf(NoDepsFixture::class, $obj);
    }

    public function test_autowires_constructor_dependencies_by_type(): void
    {
        $c = new Container();
        $obj = $c->make(NeedsDepFixture::class);
        $this->assertInstanceOf(NoDepsFixture::class, $obj->dep);
    }

    public function test_singleton_binding_returns_same_instance(): void
    {
        $c = new Container();
        $c->singleton('shared', fn() => new \stdClass());
        $a = $c->get('shared');
        $b = $c->get('shared');
        $this->assertSame($a, $b);
    }

    public function test_transient_binding_returns_fresh_instance(): void
    {
        $c = new Container();
        $c->bind('transient', fn() => new \stdClass());
        $a = $c->get('transient');
        $b = $c->get('transient');
        $this->assertNotSame($a, $b);
    }

    public function test_instance_registration_returns_same_object(): void
    {
        $c = new Container();
        $obj = new \stdClass();
        $c->instance('prebuilt', $obj);
        $this->assertSame($obj, $c->get('prebuilt'));
    }

    public function test_container_resolves_itself(): void
    {
        $c = new Container();
        $this->assertSame($c, $c->get(Container::class));
    }

    public function test_make_accepts_parameter_overrides_by_name(): void
    {
        $c = new Container();
        $obj = $c->make(NeedsPrimitiveFixture::class, ['id' => 42]);
        $this->assertSame(42, $obj->id);
    }

    public function test_unresolvable_primitive_throws_when_no_default(): void
    {
        $this->expectException(ContainerException::class);
        (new Container())->make(NeedsPrimitiveFixture::class);
    }

    public function test_unresolvable_primitive_uses_default_when_available(): void
    {
        $c = new Container();
        $obj = $c->make(PrimitiveWithDefaultFixture::class);
        $this->assertSame('default', $obj->name);
    }

    public function test_contextual_binding_switches_implementation_per_caller(): void
    {
        $c = new Container();
        $c->when(CallerAFixture::class)->needs(DriverInterfaceFixture::class)->give(DriverAFixture::class);
        $c->when(CallerBFixture::class)->needs(DriverInterfaceFixture::class)->give(DriverBFixture::class);

        $a = $c->make(CallerAFixture::class);
        $b = $c->make(CallerBFixture::class);

        $this->assertInstanceOf(DriverAFixture::class, $a->driver);
        $this->assertInstanceOf(DriverBFixture::class, $b->driver);
    }

    public function test_has_returns_true_for_bound_and_instantiable_classes(): void
    {
        $c = new Container();
        $c->bind('bound', fn() => null);
        $this->assertTrue($c->has('bound'));
        $this->assertTrue($c->has(NoDepsFixture::class));
        $this->assertFalse($c->has('completely_unknown_key'));
    }
}

// ── Fixtures ───────────────────────────────────────────────────────────────

class NoDepsFixture {}

class NeedsDepFixture {
    public function __construct(public NoDepsFixture $dep) {}
}

class NeedsPrimitiveFixture {
    public function __construct(public int $id) {}
}

class PrimitiveWithDefaultFixture {
    public function __construct(public string $name = 'default') {}
}

interface DriverInterfaceFixture {}
class DriverAFixture implements DriverInterfaceFixture {}
class DriverBFixture implements DriverInterfaceFixture {}

class CallerAFixture {
    public function __construct(public DriverInterfaceFixture $driver) {}
}
class CallerBFixture {
    public function __construct(public DriverInterfaceFixture $driver) {}
}
