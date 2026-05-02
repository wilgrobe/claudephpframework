<?php
// tests/Unit/Http/ResourceTest.php
namespace Tests\Unit\Http;

use Core\Http\Resource;
use Tests\TestCase;

/** Minimal resource used only by these tests. */
final class ArrayResource extends Resource
{
    public function toArray(): array
    {
        return ['id' => (int) $this->resource['id'], 'name' => $this->resource['name']];
    }
}

final class ResourceTest extends TestCase
{
    public function test_from_transforms_a_single_resource(): void
    {
        $out = ArrayResource::from(['id' => '1', 'name' => 'Ada', 'secret' => 'hide']);
        $this->assertSame(['id' => 1, 'name' => 'Ada'], $out);
        $this->assertArrayNotHasKey('secret', $out);
    }

    public function test_collection_transforms_each_item(): void
    {
        $out = ArrayResource::collection([
            ['id' => 1, 'name' => 'A'],
            ['id' => 2, 'name' => 'B'],
        ]);
        $this->assertCount(2, $out);
        $this->assertSame(1, $out[0]['id']);
        $this->assertSame('B', $out[1]['name']);
    }

    public function test_paginated_preserves_meta(): void
    {
        $paginator = [
            'items'        => [['id' => 1, 'name' => 'A']],
            'total'        => 7,
            'per_page'     => 1,
            'current_page' => 3,
            'last_page'    => 7,
        ];
        $out = ArrayResource::paginated($paginator);
        $this->assertCount(1, $out['data']);
        $this->assertSame(7, $out['meta']['total']);
        $this->assertSame(3, $out['meta']['current_page']);
    }
}
