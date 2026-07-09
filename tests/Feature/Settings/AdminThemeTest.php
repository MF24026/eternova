<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AdminThemeTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_tenant_defaults_to_ethereal_theme(): void
    {
        $tenant = Tenant::factory()->create();

        $this->assertSame('ethereal', $tenant->admin_theme);
    }
}
