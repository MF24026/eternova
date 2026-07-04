<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\User;
use App\Modules\Settings\Models\BranchSetting;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ActingAsTenantMember;
use Tests\TestCase;

/**
 * Feature tests for the Settings Admin API (S8-E2).
 *
 * Verifies:
 *   - show returns every group resolved + catalog meta (owner/admin only)
 *   - update persists tenant-row groups (brand, locale, quotations, reservations)
 *   - update persists branch_settings groups (contact, tax, orders, notifications)
 *   - validation rejects bad currency / out-of-range tax bps (422)
 *   - unknown group → 404
 *   - logo upload stores the file and sets logo_url
 *   - auth gates: 401 unauthenticated, 403 for staff
 *   - multi-tenant boundary: tenant B sees its own settings, not tenant A's
 */
final class SettingsApiTest extends TestCase
{
    use ActingAsTenantMember;
    use RefreshDatabase;

    /**
     * @return array{tenant: Tenant, owner: User}
     */
    private function setupTenant(array $tenantOverrides = []): array
    {
        $tenant = Tenant::factory()->create($tenantOverrides);
        $owner = User::factory()->forTenant($tenant, role: 'owner')->create();

        app()->instance('currentTenant', $tenant);

        return compact('tenant', 'owner');
    }

    public function test_show_returns_all_groups_and_catalog_meta(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant([
            'business_name' => 'Rosa Eterna',
            'currency' => 'USD',
        ]);

        $response = $this->tenantGetJson($tenant, $owner, '/api/v1/settings');

        $response->assertOk()
            ->assertJsonPath('data.brand.business_name', 'Rosa Eterna')
            ->assertJsonPath('data.locale.currency', 'USD')
            ->assertJsonPath('data.tax.rate_bps', 1300)            // coded default
            ->assertJsonPath('data.notifications.new_order', true) // coded default
            ->assertJsonStructure([
                'data' => ['brand', 'locale', 'contact', 'tax', 'orders', 'quotations', 'reservations', 'notifications'],
                'meta' => ['groups', 'catalog' => ['countries', 'currencies', 'languages']],
            ]);
    }

    public function test_owner_can_update_brand_group(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        $response = $this->tenantPostJson($tenant, $owner, '/api/v1/settings/brand', [
            'business_name' => 'Nueva Marca',
            'primary_color' => '#7c545d',
            'secondary_color' => '#5a4b71',
        ]);

        $response->assertOk()->assertJsonPath('data.brand.business_name', 'Nueva Marca');
        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->id,
            'business_name' => 'Nueva Marca',
            'primary_color' => '#7c545d',
        ]);
    }

    public function test_update_locale_rejects_unsupported_currency(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        $response = $this->tenantPostJson($tenant, $owner, '/api/v1/settings/locale', [
            'currency' => 'XYZ',
            'country_code' => 'SV',
            'language' => 'es',
            'timezone' => 'America/El_Salvador',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrorFor('currency');
    }

    public function test_owner_can_update_contact_group_into_branch_settings(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        $response = $this->tenantPostJson($tenant, $owner, '/api/v1/settings/contact', [
            'phone' => '+503 7892-1234',
            'email' => 'hola@rosa.test',
            'website' => 'rosa.test',
            'address' => 'San Salvador',
        ]);

        $response->assertOk()->assertJsonPath('data.contact.phone', '+503 7892-1234');

        // Stored as a tenant default row (branch_id null).
        $this->assertDatabaseHas('branch_settings', [
            'tenant_id' => $tenant->id,
            'branch_id' => null,
            'group' => 'contact',
            'key' => 'phone',
        ]);
    }

    public function test_update_tax_validates_rate_bps_range(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        $response = $this->tenantPostJson($tenant, $owner, '/api/v1/settings/tax', [
            'enabled' => true,
            'rate_bps' => 10000, // over the 9999 max
        ]);

        $response->assertStatus(422)->assertJsonValidationErrorFor('rate_bps');
    }

    public function test_update_tax_rejects_invalid_fiscal_id_for_sv(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant(['country_code' => 'SV']);

        $response = $this->tenantPostJson($tenant, $owner, '/api/v1/settings/tax', [
            'enabled' => true,
            'rate_bps' => 1300,
            'id_label' => 'DUI',
            'id_number' => '04210323-5', // wrong DUI check digit
        ]);

        $response->assertStatus(422)->assertJsonValidationErrorFor('id_number');
    }

    public function test_update_tax_accepts_and_persists_a_valid_dui(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant(['country_code' => 'SV']);

        $this->tenantPostJson($tenant, $owner, '/api/v1/settings/tax', [
            'enabled' => true,
            'rate_bps' => 1300,
            'id_label' => 'DUI',
            'id_number' => '04210323-4',
        ])->assertOk();

        $stored = BranchSetting::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('group', 'tax')
            ->where('key', 'id_number')
            ->value('value');

        $this->assertSame('04210323-4', $stored);
    }

    public function test_owner_can_toggle_notifications(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        $response = $this->tenantPostJson($tenant, $owner, '/api/v1/settings/notifications', [
            'new_order' => false,
            'order_pending' => true,
            'low_stock' => true,
            'reservation_confirmed' => true,
            'quotation_accepted' => false,
            'payment_received' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.notifications.new_order', false)
            ->assertJsonPath('data.notifications.reservation_confirmed', true);
    }

    public function test_unknown_group_returns_404(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        $response = $this->tenantPostJson($tenant, $owner, '/api/v1/settings/hacking', [
            'foo' => 'bar',
        ]);

        $response->assertStatus(404);
    }

    public function test_brand_update_stores_logo_and_sets_url(): void
    {
        Storage::fake('public');
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        $file = UploadedFile::fake()->image('logo.png', 200, 200);

        $response = $this->actingAs($owner)->post(
            $this->tenantUrl($tenant, '/api/v1/settings/brand'),
            [
                'business_name' => 'Con Logo',
                'logo' => $file,
            ],
            ['Accept' => 'application/json'],
        );

        $response->assertOk();

        $tenant->refresh();
        $this->assertNotNull($tenant->logo_url);
        $this->assertStringStartsWith('/storage/tenants/'.$tenant->id.'/brand', $tenant->logo_url);
        Storage::disk('public')->assertExists(substr($tenant->logo_url, strlen('/storage/')));
    }

    public function test_brand_update_stores_favicon_and_sets_url(): void
    {
        Storage::fake('public');
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        $file = UploadedFile::fake()->image('favicon.png', 64, 64);

        $response = $this->actingAs($owner)->post(
            $this->tenantUrl($tenant, '/api/v1/settings/brand'),
            [
                'business_name' => 'Con Favicon',
                'favicon' => $file,
            ],
            ['Accept' => 'application/json'],
        );

        $response->assertOk();

        $tenant->refresh();
        $this->assertNotNull($tenant->favicon_url);
        $this->assertStringStartsWith('/storage/tenants/'.$tenant->id.'/brand', $tenant->favicon_url);
        Storage::disk('public')->assertExists(substr($tenant->favicon_url, strlen('/storage/')));
    }

    public function test_brand_update_rejects_svg_logo(): void
    {
        // An SVG can carry a <script> that runs when the asset is opened directly;
        // logos are served from the public disk (often by nginx/CDN, bypassing our
        // CSP), so accepting one is a stored-XSS vector. Must be rejected (422).
        Storage::fake('public');
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        $malicious = UploadedFile::fake()->createWithContent(
            'logo.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.cookie)</script></svg>',
        );

        $response = $this->actingAs($owner)->post(
            $this->tenantUrl($tenant, '/api/v1/settings/brand'),
            ['business_name' => 'XSS Intento', 'logo' => $malicious],
            ['Accept' => 'application/json'],
        );

        $response->assertStatus(422)->assertJsonValidationErrors(['logo']);

        $tenant->refresh();
        $this->assertNull($tenant->logo_url);
    }

    public function test_brand_update_rejects_svg_favicon(): void
    {
        Storage::fake('public');
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        $malicious = UploadedFile::fake()->createWithContent(
            'favicon.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
        );

        $response = $this->actingAs($owner)->post(
            $this->tenantUrl($tenant, '/api/v1/settings/brand'),
            ['business_name' => 'XSS Intento', 'favicon' => $malicious],
            ['Accept' => 'application/json'],
        );

        $response->assertStatus(422)->assertJsonValidationErrors(['favicon']);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        ['tenant' => $tenant] = $this->setupTenant();

        $this->getJson($this->tenantUrl($tenant, '/api/v1/settings'))->assertStatus(401);
    }

    public function test_staff_cannot_view_or_manage_settings(): void
    {
        ['tenant' => $tenant] = $this->setupTenant();
        $staff = User::factory()->forTenant($tenant, role: 'staff')->create();

        $this->tenantGetJson($tenant, $staff, '/api/v1/settings')->assertStatus(403);

        $this->tenantPostJson($tenant, $staff, '/api/v1/settings/brand', [
            'business_name' => 'No permitido',
        ])->assertStatus(403);
    }

    public function test_settings_are_isolated_per_tenant(): void
    {
        ['tenant' => $tenantA, 'owner' => $ownerA] = $this->setupTenant(['business_name' => 'Negocio A']);

        // Owner A writes a contact default.
        $this->tenantPostJson($tenantA, $ownerA, '/api/v1/settings/contact', [
            'phone' => '+503 1111-1111',
        ])->assertOk();

        // Tenant B is a separate business with its own owner.
        $tenantB = Tenant::factory()->create(['business_name' => 'Negocio B']);
        $ownerB = User::factory()->forTenant($tenantB, role: 'owner')->create();
        app()->instance('currentTenant', $tenantB);

        $response = $this->tenantGetJson($tenantB, $ownerB, '/api/v1/settings');

        $response->assertOk()
            ->assertJsonPath('data.brand.business_name', 'Negocio B')
            // B never sees A's contact phone — falls back to coded default (null).
            ->assertJsonPath('data.contact.phone', null);
    }
}
