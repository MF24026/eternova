<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use Tests\TestCase;

/**
 * Guards the Resend mail transport wiring. No DB needed — pure config + container
 * resolution, so this stays fast and does not use RefreshDatabase.
 */
final class ResendConfigTest extends TestCase
{
    public function test_resend_mailer_and_service_key_are_configured(): void
    {
        $this->assertSame('resend', config('mail.mailers.resend.transport'));
        $this->assertArrayHasKey('key', config('services.resend'));
    }

    public function test_resend_transport_resolves(): void
    {
        // The resend/resend-php package must be installed for this transport to
        // register; otherwise resolving throws "Unsupported mail transport [resend]".
        config(['services.resend.key' => 're_dummy_for_resolution_only']);

        $mailer = app('mail.manager')->mailer('resend');

        $this->assertNotNull($mailer);
    }
}
