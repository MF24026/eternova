<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the reserved_subdomains table with platform-protected slug terms.
 *
 * These slugs cannot be claimed by any tenant during signup. The seeder is idempotent:
 * it uses INSERT IGNORE (upsert with no updates) so it is safe to run multiple times.
 *
 * Total: 86 entries across 6 categories.
 *
 * Categories:
 *   system (24)           — infrastructure paths that would conflict with app routing
 *   brand (4)             — Eternova brand terms including legacy "carol-creaciones"
 *   trademark (18)        — well-known brand names to avoid impersonation
 *   profanity (12)        — placeholder keys used to satisfy category completeness;
 *                           actual terms maintained in a private seed file for each deploy
 *   regulated (16)        — terms implying professional licensing or government authority
 *   security-sensitive (12) — terms that could imply system access or elevated privilege
 */
class ReservedSubdomainsSeeder extends Seeder
{
    public function run(): void
    {
        $entries = $this->entries();

        $now = now()->toDateTimeString();

        $rows = array_map(
            static fn (array $entry): array => [
                'subdomain' => $entry[0],
                'category' => $entry[1],
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $entries
        );

        // Idempotent: skip rows that already exist (unique constraint on subdomain).
        foreach (array_chunk($rows, 50) as $chunk) {
            DB::table('reserved_subdomains')->upsert(
                $chunk,
                ['subdomain'],   // conflict key
                ['category']     // update column on conflict (in case category is wrong)
            );
        }

        $total = count($entries);
        $this->command->info("ReservedSubdomainsSeeder: {$total} entries seeded.");
    }

    /**
     * Returns all reserved subdomain entries as [subdomain, category] tuples.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function entries(): array
    {
        return [
            // ------------------------------------------------------------------ system (24)
            ['admin',    'system'],
            ['api',      'system'],
            ['app',      'system'],
            ['www',      'system'],
            ['auth',     'system'],
            ['login',    'system'],
            ['signup',   'system'],
            ['billing',  'system'],
            ['docs',     'system'],
            ['status',   'system'],
            ['support',  'system'],
            ['help',     'system'],
            ['blog',     'system'],
            ['cdn',      'system'],
            ['static',   'system'],
            ['assets',   'system'],
            ['public',   'system'],
            ['private',  'system'],
            ['dev',      'system'],
            ['staging',  'system'],
            ['prod',     'system'],
            ['test',     'system'],
            ['demo',     'system'],
            ['debug',    'system'],

            // ------------------------------------------------------------------ brand (4)
            ['eternova',          'brand'],
            ['eter',              'brand'],
            ['terno-no-va',       'brand'],
            ['carol-creaciones',  'brand'],

            // ------------------------------------------------------------------ trademark (18)
            ['shopify',      'trademark'],
            ['stripe',       'trademark'],
            ['paypal',       'trademark'],
            ['mercadopago',  'trademark'],
            ['wompi',        'trademark'],
            ['amazon',       'trademark'],
            ['google',       'trademark'],
            ['microsoft',    'trademark'],
            ['apple',        'trademark'],
            ['facebook',     'trademark'],
            ['instagram',    'trademark'],
            ['twitter',      'trademark'],
            ['whatsapp',     'trademark'],
            ['tiktok',       'trademark'],
            ['youtube',      'trademark'],
            ['linkedin',     'trademark'],
            ['github',       'trademark'],
            ['gitlab',       'trademark'],

            // ------------------------------------------------------------------ profanity (12)
            // Placeholder keys per brief instructions. Replace with actual terms per
            // deployment locale before going to production.
            ['profanity-es-01',  'profanity'],
            ['profanity-es-02',  'profanity'],
            ['profanity-es-03',  'profanity'],
            ['profanity-es-04',  'profanity'],
            ['profanity-es-05',  'profanity'],
            ['profanity-es-06',  'profanity'],
            ['profanity-es-07',  'profanity'],
            ['profanity-es-08',  'profanity'],
            ['profanity-es-09',  'profanity'],
            ['profanity-es-10',  'profanity'],
            ['profanity-es-11',  'profanity'],
            ['profanity-es-12',  'profanity'],

            // ------------------------------------------------------------------ regulated (16)
            ['bank',        'regulated'],
            ['banco',       'regulated'],
            ['lawyer',      'regulated'],
            ['abogado',     'regulated'],
            ['doctor',      'regulated'],
            ['medico',      'regulated'],
            ['pharmacy',    'regulated'],
            ['farmacia',    'regulated'],
            ['gobierno',    'regulated'],
            ['government',  'regulated'],
            ['casino',      'regulated'],
            ['lottery',     'regulated'],
            ['weapon',      'regulated'],
            ['arma',        'regulated'],
            ['alcohol',     'regulated'],
            ['tabaco',      'regulated'],

            // ------------------------------------------------------------------ security-sensitive (12)
            ['ssl',       'security-sensitive'],
            ['oauth',     'security-sensitive'],
            ['token',     'security-sensitive'],
            ['password',  'security-sensitive'],
            ['secret',    'security-sensitive'],
            ['root',      'security-sensitive'],
            ['sudo',      'security-sensitive'],
            ['kernel',    'security-sensitive'],
            ['system',    'security-sensitive'],
            ['security',  'security-sensitive'],
            ['vault',     'security-sensitive'],
            ['keychain',  'security-sensitive'],
        ];
    }
}
