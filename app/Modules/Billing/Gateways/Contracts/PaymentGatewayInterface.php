<?php

declare(strict_types=1);

namespace App\Modules\Billing\Gateways\Contracts;

use App\Modules\Billing\Gateways\Data\CardData;
use App\Modules\Billing\Gateways\Data\ChargeData;
use App\Modules\Billing\Gateways\Data\ChargeResult;
use App\Modules\Billing\Gateways\Data\RecurringPaymentLink;
use App\Modules\Billing\Gateways\Data\RecurringPlanData;
use App\Modules\Billing\Gateways\Data\RefundResult;
use App\Modules\Billing\Gateways\Data\TokenResult;
use App\Modules\Billing\Gateways\Data\TransactionResult;
use SensitiveParameter;

/**
 * The seam between billing logic and any payment provider. Everything above this interface
 * speaks app-domain DTOs and GatewayError codes; only the concrete implementations
 * (WompiGateway, FakeGateway) know the provider's wire format.
 *
 * Swapping Wompi for Stripe/Mercadopago means writing one new implementor + ErrorTranslator,
 * with zero changes to the state machine, crons, webhooks or UI.
 */
interface PaymentGatewayInterface
{
    /**
     * Create a gateway-managed recurring payment link. The tenant affiliates their card once on
     * the returned hosted URL; the gateway then charges automatically each period and notifies us
     * via webhook. (This is the Wompi SV recurring model — the gateway owns the schedule, not us.)
     */
    public function createRecurringPaymentLink(RecurringPlanData $data): RecurringPaymentLink;

    /** Deactivate a recurring link so the gateway stops charging. Returns false on failure. */
    public function cancelRecurringPaymentLink(string $linkId): bool;

    /** Fetch a recurring link's current state (for reconciliation), or null. @return array<string,mixed>|null */
    public function getRecurringLink(string $linkId): ?array;

    public function charge(#[SensitiveParameter] ChargeData $data): ChargeResult;

    public function refund(string $transactionId, int $amountCents, string $idempotencyKey): RefundResult;

    public function tokenize(#[SensitiveParameter] CardData $card): TokenResult;

    /**
     * Display metadata for a stored token (last4, brand, expiry). Never returns a PAN.
     *
     * @return array<string, mixed>|null
     */
    public function detokenize(string $token): ?array;

    public function verifyWebhookSignature(string $payload, string $signature): bool;

    public function getTransaction(string $transactionId): ?TransactionResult;
}
