<?php

namespace Tests\Unit;

use App\Core\Navigation\NavItem;
use PHPUnit\Framework\TestCase;

class NavItemTest extends TestCase
{
    public function test_from_manifest_array_maps_all_fields(): void
    {
        $data = [
            'key' => 'shipments',
            'title' => 'Shipments',
            'to' => ['name' => 'company-shipments'],
            'icon' => 'tabler-truck',
            'permission' => 'shipments.view',
            'surface' => 'operations',
            'operationalOnly' => true,
            'group' => 'operations',
            'parent' => 'logistics',
            'jobdomains' => ['logistics'],
            'plans' => ['pro', 'enterprise'],
            'tags' => ['beta'],
            'sort' => 50,
        ];

        $item = NavItem::fromManifestArray($data, 100);

        $this->assertSame('shipments', $item->key);
        $this->assertSame('Shipments', $item->title);
        $this->assertSame(['name' => 'company-shipments'], $item->to);
        $this->assertSame('tabler-truck', $item->icon);
        $this->assertSame('shipments.view', $item->permission);
        $this->assertSame('operations', $item->surface);
        $this->assertTrue($item->operationalOnly);
        $this->assertSame('operations', $item->group);
        $this->assertSame('logistics', $item->parent);
        $this->assertSame(['logistics'], $item->jobdomains);
        $this->assertSame(['pro', 'enterprise'], $item->plans);
        $this->assertSame(['beta'], $item->tags);
        $this->assertSame(50, $item->sort);
    }

    public function test_from_manifest_array_defaults(): void
    {
        $data = [
            'key' => 'dashboard',
            'title' => 'Dashboard',
            'to' => ['name' => 'platform'],
            'icon' => 'tabler-dashboard',
        ];

        $item = NavItem::fromManifestArray($data, 10);

        $this->assertNull($item->permission);
        $this->assertNull($item->surface);
        $this->assertFalse($item->operationalOnly);
        $this->assertNull($item->group);
        $this->assertNull($item->parent);
        $this->assertNull($item->jobdomains);
        $this->assertNull($item->plans);
        $this->assertNull($item->tags);
        $this->assertSame(10, $item->sort);
    }

    public function test_to_array_round_trip(): void
    {
        $data = [
            'key' => 'members',
            'title' => 'Members',
            'to' => ['name' => 'company-members'],
            'icon' => 'tabler-users',
            'permission' => 'members.view',
            'surface' => 'structure',
            'group' => 'company',
        ];

        $item = NavItem::fromManifestArray($data, 5);
        $array = $item->toArray();

        $this->assertSame('members', $array['key']);
        $this->assertSame('Members', $array['title']);
        $this->assertSame(['name' => 'company-members'], $array['to']);
        $this->assertSame('tabler-users', $array['icon']);
        $this->assertSame('members.view', $array['permission']);
        $this->assertSame('structure', $array['surface']);
        $this->assertSame('company', $array['group']);
    }

    public function test_is_clickable_with_route(): void
    {
        $item = NavItem::fromManifestArray([
            'key' => 'test',
            'title' => 'Test',
            'to' => ['name' => 'some-route'],
            'icon' => 'tabler-test',
        ]);

        $this->assertTrue($item->isClickable());
    }

    public function test_is_not_clickable_without_route(): void
    {
        $item = NavItem::fromManifestArray([
            'key' => 'parent',
            'title' => 'Parent',
            'to' => [],
            'icon' => 'tabler-folder',
        ]);

        $this->assertFalse($item->isClickable());
    }

    public function test_is_not_clickable_without_to_key(): void
    {
        $item = NavItem::fromManifestArray([
            'key' => 'parent',
            'title' => 'Parent',
            'icon' => 'tabler-folder',
        ]);

        $this->assertFalse($item->isClickable());
    }
}
