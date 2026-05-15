---
name: laravel-saas-email-transactional
description: Use when implementing transactional email in a multi-tenant Laravel SaaS — welcome, password reset, billing notifications, order confirmations, receipts, digital customer flows. Captures the provider choice (Resend > SES > Postmark for LatAm), TenantAware Mailable/Notification traits, PII-safe log channel, soft email verification with grace period, Google OAuth auto-verified, per-tenant suppression list via webhook (bounce/complaint/unsubscribe), MailSendingGate. The doctrine: every email that leaves your SaaS carries the TENANT's brand, not yours.
---

# Laravel SaaS Email Transactional

You are building transactional email in a multi-tenant SaaS where **each tenant has its own brand**. An email to a customer of Tenant A should come from Tenant A's name, with Tenant A's reply-to, in Tenant A's locale — NOT from "Your SaaS Platform". This skill captures the multi-tenant email infrastructure that handles this without leaking domains, bouncing into the void, or losing emails to silent suppression.

**Origin:** A Laravel SaaS that started with `Mail::raw()` calls everywhere, then survived: an email provider migration (Mailtrap → Resend), a soft-bounce loop that wasted 30k API calls, a customer who couldn't get reset-password emails because their domain marked us as spam, a tenant whose employees saw "Your SaaS" in From headers and asked us to rebrand. **Each of these is now codified here.**

## When to use this skill

Activate when:
- Adding any transactional email (welcome, reset, receipt, notification)
- Migrating email providers
- Adding email verification flow
- Investigating why some emails aren't delivered
- A tenant requests white-label email
- Setting up bounce/complaint handling
- Building the email log + audit
- Designing the suppression list

## Provider choice — Resend by default

For LatAm SaaS solo-dev / small team, **Resend** is the default. Reasons:
- Simple API + Laravel driver out-of-the-box
- Reasonable pricing ($20/month gets 50k emails)
- Solid deliverability (uses AWS SES under the hood + their own routing)
- Built-in webhook for bounces/complaints (via Svix)
- Domain verification flow is fast (~30 min DNS propagation)

**Alternatives**:
- **Postmark**: best deliverability, but pricier. Use if you absolutely need transactional purity.
- **AWS SES**: cheapest at scale, but requires more setup (SNS topics for bounces, separate from sending API).
- **Mailgun**: solid, used in EU more. Pricing similar to Resend.
- **Sendgrid**: AVOID for transactional — they're known for marketing emails being lumped with yours.

**Anti-pattern**: starting with Mailtrap in dev and "we'll switch to a real provider later." The driver-level differences are minor; the DNS / domain / SPF / DKIM / DMARC config is what takes time. Set up real Resend domain in staging from day 1.

## .env config

```ini
# Production
MAIL_MAILER=resend
MAIL_FROM_ADDRESS=noreply@yourdomain.com
MAIL_FROM_NAME="${APP_NAME}"
RESEND_API_KEY=re_...
RESEND_WEBHOOK_SECRET=whsec_...

# Local development (no real sending)
MAIL_MAILER=log
# All emails go to storage/logs/laravel.log

# Staging — send to real domain you control to verify rendering
MAIL_MAILER=resend
MAIL_FROM_ADDRESS=noreply@staging.yourdomain.com
RESEND_API_KEY=re_...  # separate key with rate limit
```

## DNS setup (do this BEFORE writing code)

For your sending domain (e.g. `yourdomain.com`):

1. **SPF record** (TXT at root): `v=spf1 include:amazonses.com include:_spf.resend.com -all`
2. **DKIM** (provided by Resend, CNAME): `resend._domainkey.yourdomain.com → ...`
3. **DMARC** (TXT at `_dmarc.yourdomain.com`): `v=DMARC1; p=quarantine; rua=mailto:dmarc@yourdomain.com`

Verify in https://mxtoolbox.com/SPFRecordTest. **If any of the 3 fail, deliverability tanks within 24h.**

**For LatAm specifically**: Yahoo, Hotmail, and Gmail are aggressive on unsigned mail from new domains. DKIM is non-negotiable. DMARC = quarantine (not reject) for the first 30 days.

## TenantAware Mailable trait

Every Mailable in the SaaS uses this trait:

```php
namespace App\Mail\Concerns;

trait TenantAwareMailable
{
    public function buildTenantContext(?Tenant $tenant = null): self
    {
        $tenant ??= app()->bound('current_tenant') ? app('current_tenant') : null;
        if (! $tenant) return $this;  // graceful fallback

        // From = tenant's brand (or fallback to SaaS default)
        $fromEmail = $tenant->email_from_address ?? config('mail.from.address');
        $fromName = $tenant->email_from_name ?? $tenant->name;
        $this->from($fromEmail, $fromName);

        // Reply-To = tenant owner's inbox
        if ($owner = $tenant->owner()) {
            $this->replyTo($owner->email, $owner->name);
        }

        // Locale matches tenant
        $this->locale($tenant->locale);

        // Headers for routing / debugging / suppression list scoping
        $this->withSymfonyMessage(function ($msg) use ($tenant) {
            $msg->getHeaders()->addTextHeader('X-Tenant-Id', (string) $tenant->id);
            $msg->getHeaders()->addTextHeader('X-Tenant-Slug', $tenant->slug);
        });

        return $this;
    }
}
```

Usage in a Mailable:

```php
final class OrderConfirmation extends Mailable
{
    use TenantAwareMailable;

    public function __construct(public Order $order) {}

    public function build(): self
    {
        return $this->buildTenantContext($this->order->tenant)
            ->subject(__('emails.order_confirmation.subject', ['number' => $this->order->number]))
            ->view('emails.order-confirmation');
    }
}
```

**Equivalent trait for Notifications** (`TenantAwareNotification`) for the database/notification system.

## Log channel `mail` — PII-safe

```php
// config/logging.php
'mail' => [
    'driver' => 'daily',
    'path' => storage_path('logs/mail/mail.log'),
    'level' => 'info',
    'days' => 30,
    'permission' => 0600,
],
```

Use a `MailSendingListener` to log every send:

```php
final class LogMailDispatch
{
    public function handle(MessageSending $event): void
    {
        Log::channel('mail')->info('mail_sending', [
            'tenant_id' => $event->message->getHeaders()->get('X-Tenant-Id')?->getBodyAsString(),
            'subject' => $event->message->getSubject(),
            'recipients' => $this->extractRecipients($event->message->getTo()),
            'from' => $event->message->getFrom()[0]?->getAddress(),
            // NEVER log the body — contains PII, possibly reset tokens
        ]);
    }
}
```

**Doctrine**: log subject + recipient + tenant_id + correlation_id. **NEVER** body, headers with tokens, attachment names with PII. Audit-grade, not debug-grade.

## Email verification — soft with grace period

Use Laravel's `MustVerifyEmail` interface but with a grace period (e.g. 7 days). The first 7 days post-signup, the user can use the app normally with a banner. Day 8+, sensitive operations (POS, billing) are blocked.

```ini
AUTH_VERIFICATION_GRACE_DAYS=7
```

```php
final class EnforceEmailVerificationAfterGrace
{
    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();
        if (! $user) return $next($request);
        if ($user->hasVerifiedEmail()) return $next($request);

        $graceDays = (int) config('auth.verification_grace_days', 7);
        if ($user->created_at->addDays($graceDays)->isFuture()) {
            return $next($request);  // still in grace
        }
        return redirect()->route('verification.notice');
    }
}
```

**Google OAuth users** are auto-verified (Google already verified the email). Set `email_verified_at = now()` on first successful OAuth callback.

## Resend webhook receiver — bounces, complaints, unsubscribes

```php
final class ResendWebhookController
{
    public function __invoke(Request $request): Response
    {
        $payload = $request->getContent();
        $signature = $request->header('Svix-Signature');
        // Resend uses Svix for signing
        $valid = (new \Svix\Webhook(config('services.resend.webhook_secret')))
            ->verify($payload, [
                'svix-id' => $request->header('Svix-Id'),
                'svix-timestamp' => $request->header('Svix-Timestamp'),
                'svix-signature' => $signature,
            ]);

        if (! $valid) abort(401);

        $event = json_decode($payload, true);
        $eventType = $event['type'];
        $data = $event['data'];

        match ($eventType) {
            'email.bounced' => $this->handleBounce($data),
            'email.complained' => $this->handleComplaint($data),
            'email.unsubscribed' => $this->handleUnsubscribe($data),
            default => null,  // ignore other event types
        };

        return response()->noContent();
    }
}
```

## Suppression list per-tenant

When a bounce / complaint / unsubscribe arrives for `customer@example.com` against `Tenant A`, you add them to **Tenant A's suppression list only**. They might still receive emails from Tenant B (different sender, different relationship).

```php
Schema::create('email_suppressions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
    $table->string('email');
    $table->enum('reason', ['bounce_hard', 'bounce_soft', 'complaint', 'unsubscribe', 'manual']);
    $table->json('metadata')->nullable();    // provider event payload
    $table->timestamp('suppressed_at');
    $table->timestamp('expires_at')->nullable();  // soft bounces expire after 7 days
    $table->timestamps();
    $table->unique(['tenant_id', 'email']);
});
```

### MailSendingGate — pre-flight check

```php
final class MailSendingGate
{
    public function handle(MessageSending $event): bool
    {
        $tenantId = $event->message->getHeaders()->get('X-Tenant-Id')?->getBodyAsString();
        if (! $tenantId) return true;

        foreach ($event->message->getTo() as $recipient) {
            $isSupressed = EmailSuppression::where('tenant_id', $tenantId)
                ->where('email', $recipient->getAddress())
                ->where(function ($q) {
                    $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })
                ->exists();

            if ($isSupressed) {
                Log::channel('mail')->warning('mail_suppressed', [
                    'tenant_id' => $tenantId,
                    'email' => $recipient->getAddress(),
                ]);
                return false; // cancels the send
            }
        }
        return true;
    }
}
```

Registered as a listener on `MessageSending` event. Returning `false` cancels the send.

### Soft vs hard bounce

- **Hard bounce** (`5xx` permanent — invalid email, mailbox doesn't exist): suppress indefinitely until manual unsupress
- **Soft bounce** (`4xx` temporary — mailbox full, server down): suppress for 7 days then auto-expire (Resend handles retries internally; we just track)
- **Complaint** (user marked as spam): suppress indefinitely. **Notify the tenant owner** — complaints damage their sender reputation
- **Unsubscribe** (user clicked link or replied STOP): suppress for transactional optional + ALL marketing

## The 7 transactional templates (minimum)

1. **WelcomeNotification** — signup confirmation + link to set password (if OAuth) or verify email
2. **ResetPasswordNotification** — Breeze-compatible, tenant-aware
3. **EmailVerificationNotification** — verify link with 7-day expiry
4. **OrderConfirmation** / **ReceiptNotification** — order placed, with receipt link / PDF
5. **OrderShipped** / **OrderReady** — status updates
6. **BillingChargeReceipt** — recurring charge succeeded (with invoice PDF attached)
7. **BillingChargeFailed** — charge failed with retry CTA

Plus the **public receipt** link for tenants without an account (digital customer who placed an order without registering).

## Smoke command for sanity check

```php
namespace App\Console\Commands;

final class MailSmokeCommand extends Command
{
    protected $signature = 'mail:smoke {recipient} {--tenant=}';
    protected $description = 'Send a test email to verify config + DNS + provider.';

    public function handle(): int
    {
        $tenant = $this->option('tenant')
            ? Tenant::where('slug', $this->option('tenant'))->firstOrFail()
            : null;

        app()->instance('current_tenant', $tenant);

        Mail::raw('SaaS smoke test — if you got this, mail is configured.', function ($m) {
            $m->to($this->argument('recipient'))->subject('Mail smoke test');
        });

        $this->info("Sent. Check inbox for {$this->argument('recipient')}.");
        return self::SUCCESS;
    }
}
```

Run: `php artisan mail:smoke you@yourdomain.com --tenant=demo`. If you don't receive it within 30 seconds, debug DNS / DKIM / provider key BEFORE writing any feature.

## Operational concerns

### Bounce rate alerting

Set up a Sentry / cron alert: if `(hard_bounces / sent) > 2%` in a 24h window, page on-call. This is a deliverability emergency.

### Per-tenant email cap (silent)

Combine with `saas-plan-gating-billing` — each plan has `max_emails_per_month`. Apply a silent cap: if reached, log + skip the send (don't error). The tenant Owner sees a banner: "950 of 1000 emails used this month."

### Domain rotation for high-volume

If you grow past 50k emails/day per sending domain, consider splitting domains by purpose:
- `notifications@yourdomain.com` — transactional only
- `billing@yourdomain.com` — billing/invoices
- `noreply@marketing.yourdomain.com` — separate domain entirely for marketing (kept off the transactional reputation)

## Anti-patterns — never do this

- Hardcoding `Mail::raw()` calls without going through a Mailable + trait
- Sending email synchronously in the HTTP request (queue all transactional emails — they shouldn't block)
- One global "no-reply" from address — tenants want their brand
- Logging email body to laravel.log — PII / reset token leak
- Letting hard bounces continue to retry — ban them in your suppression list
- Ignoring Resend webhook signature verification
- Setting `MAIL_MAILER=log` in production "temporarily"
- Mailing 1000+ recipients per request (use chunking + queues)
- Sending welcome email from `welcome@your-provider.com` instead of your domain
- Letting the user verify email by clicking a link that uses URL-signed without expiry — set expiry to 60 min
- Sending password reset via SMS as backup without verifying SMS deliverability locally first
- Storing the Resend API key in code — `.env` only, rotate every 6 months
- Treating GDPR / data-protection unsubscribe as "marketing only" — transactional emails sent to someone who unsubscribed are a legal risk in some jurisdictions

## Setup checklist for new SaaS

- [ ] DNS: SPF + DKIM + DMARC configured for sending domain
- [ ] Resend account + domain verified (or chosen alternative)
- [ ] `.env`: `MAIL_MAILER=resend`, `RESEND_API_KEY`, `RESEND_WEBHOOK_SECRET`
- [ ] `TenantAwareMailable` + `TenantAwareNotification` traits created
- [ ] Log channel `mail` configured (30-day retention, PII-safe)
- [ ] `email_suppressions` table migration
- [ ] Resend webhook endpoint (`/webhooks/resend`) with HMAC verify
- [ ] `MailSendingGate` listener registered
- [ ] `mail:smoke` artisan command tested
- [ ] Welcome + Reset Password + Verify Email Mailables (Breeze defaults extended with `TenantAwareMailable`)
- [ ] First production email sent from a real tenant — landed in inbox (not spam)
- [ ] Bounce rate alert set in Sentry / monitoring

## Cross-references

- `laravel-saas-multi-tenant-foundation` — `tenants.email_from_address`, `email_from_name` columns
- `laravel-saas-billing-infrastructure` — 7 billing notification templates, invoice PDF attachment pattern
- `saas-plan-gating-billing` — `max_emails_per_month` cap, silent suppression
- `laravel-saas-i18n-latam` — locale-aware email templates, tenant locale wins over recipient
- `laravel-design-patterns-toolkit` — Mailable / Notification / Listener patterns
