<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * TunisianEventSignalsSeeder
 *
 * Seeds product_event_signals with known Tunisian demand events for 2025–2027.
 * Islamic event dates are approximate — based on astronomical calculation.
 *
 * Run: php artisan db:seed --class=TunisianEventSignalsSeeder
 */
class TunisianEventSignalsSeeder extends Seeder
{
    public function run(): void
    {
        $events = [

            // ── Ramadan ────────────────────────────────────────────────────
            [
                'event_slug'           => 'ramadan_2025',
                'event_name'           => 'Ramadan 2025',
                'event_type'           => 'ramadan',
                'starts_at'            => '2025-03-01',
                'ends_at'              => '2025-03-30',
                'affected_categories'  => null, // all categories
                'boost_score'          => 1.42,
                'top_regions'          => json_encode(['Tunis', 'Sfax', 'Sousse', 'Ariana', 'Ben Arous']),
                'is_active'            => true,
            ],
            [
                'event_slug'           => 'ramadan_2026',
                'event_name'           => 'Ramadan 2026',
                'event_type'           => 'ramadan',
                'starts_at'            => '2026-02-18',
                'ends_at'              => '2026-03-19',
                'affected_categories'  => null,
                'boost_score'          => 1.42,
                'top_regions'          => json_encode(['Tunis', 'Sfax', 'Sousse', 'Ariana', 'Ben Arous']),
                'is_active'            => true,
            ],
            [
                'event_slug'           => 'ramadan_2027',
                'event_name'           => 'Ramadan 2027',
                'event_type'           => 'ramadan',
                'starts_at'            => '2027-02-07',
                'ends_at'              => '2027-03-08',
                'affected_categories'  => null,
                'boost_score'          => 1.42,
                'top_regions'          => json_encode(['Tunis', 'Sfax', 'Sousse', 'Ariana', 'Ben Arous']),
                'is_active'            => true,
            ],

            // ── Eid Al-Fitr ────────────────────────────────────────────────
            [
                'event_slug'           => 'eid_al_fitr_2025',
                'event_name'           => 'Eid Al-Fitr 2025',
                'event_type'           => 'eid',
                'starts_at'            => '2025-03-30',
                'ends_at'              => '2025-04-02',
                'affected_categories'  => null,
                'boost_score'          => 1.55,
                'top_regions'          => json_encode(['Tunis', 'Sfax', 'Sousse', 'Nabeul', 'Monastir']),
                'is_active'            => true,
            ],
            [
                'event_slug'           => 'eid_al_fitr_2026',
                'event_name'           => 'Eid Al-Fitr 2026',
                'event_type'           => 'eid',
                'starts_at'            => '2026-03-19',
                'ends_at'              => '2026-03-22',
                'affected_categories'  => null,
                'boost_score'          => 1.55,
                'top_regions'          => json_encode(['Tunis', 'Sfax', 'Sousse', 'Nabeul', 'Monastir']),
                'is_active'            => true,
            ],

            // ── Eid Al-Adha ────────────────────────────────────────────────
            [
                'event_slug'           => 'eid_al_adha_2025',
                'event_name'           => 'Eid Al-Adha 2025',
                'event_type'           => 'eid',
                'starts_at'            => '2025-06-06',
                'ends_at'              => '2025-06-10',
                'affected_categories'  => null,
                'boost_score'          => 1.38,
                'top_regions'          => json_encode(['Tunis', 'Sfax', 'Kairouan', 'Gafsa', 'Sidi Bouzid']),
                'is_active'            => true,
            ],
            [
                'event_slug'           => 'eid_al_adha_2026',
                'event_name'           => 'Eid Al-Adha 2026',
                'event_type'           => 'eid',
                'starts_at'            => '2026-05-27',
                'ends_at'              => '2026-05-31',
                'affected_categories'  => null,
                'boost_score'          => 1.38,
                'top_regions'          => json_encode(['Tunis', 'Sfax', 'Kairouan', 'Gafsa', 'Sidi Bouzid']),
                'is_active'            => true,
            ],

            // ── Back to School ─────────────────────────────────────────────
            [
                'event_slug'           => 'back_to_school_2025',
                'event_name'           => 'Back to School 2025',
                'event_type'           => 'school',
                'starts_at'            => '2025-08-15',
                'ends_at'              => '2025-09-15',
                'affected_categories'  => json_encode(['clothing', 'bags', 'stationery', 'electronics', 'shoes']),
                'boost_score'          => 1.28,
                'top_regions'          => json_encode(['Tunis', 'Ariana', 'Ben Arous', 'Sousse', 'Sfax', 'Nabeul']),
                'is_active'            => true,
            ],
            [
                'event_slug'           => 'back_to_school_2026',
                'event_name'           => 'Back to School 2026',
                'event_type'           => 'school',
                'starts_at'            => '2026-08-15',
                'ends_at'              => '2026-09-15',
                'affected_categories'  => json_encode(['clothing', 'bags', 'stationery', 'electronics', 'shoes']),
                'boost_score'          => 1.28,
                'top_regions'          => json_encode(['Tunis', 'Ariana', 'Ben Arous', 'Sousse', 'Sfax', 'Nabeul']),
                'is_active'            => true,
            ],

            // ── Summer Tourism (Tunis coastal cities) ──────────────────────
            [
                'event_slug'           => 'summer_tourism_2025',
                'event_name'           => 'Summer Season 2025',
                'event_type'           => 'summer',
                'starts_at'            => '2025-06-15',
                'ends_at'              => '2025-08-31',
                'affected_categories'  => json_encode(['clothing', 'swimwear', 'accessories', 'beauty', 'home']),
                'boost_score'          => 1.18,
                'top_regions'          => json_encode(['Nabeul', 'Sousse', 'Monastir', 'Mahdia', 'Sfax', 'Jerba']),
                'is_active'            => true,
            ],
            [
                'event_slug'           => 'summer_tourism_2026',
                'event_name'           => 'Summer Season 2026',
                'event_type'           => 'summer',
                'starts_at'            => '2026-06-15',
                'ends_at'              => '2026-08-31',
                'affected_categories'  => json_encode(['clothing', 'swimwear', 'accessories', 'beauty', 'home']),
                'boost_score'          => 1.18,
                'top_regions'          => json_encode(['Nabeul', 'Sousse', 'Monastir', 'Mahdia', 'Sfax', 'Jerba']),
                'is_active'            => true,
            ],

            // ── New Year / Soldes ──────────────────────────────────────────
            [
                'event_slug'           => 'new_year_soldes_2026',
                'event_name'           => 'Soldes & New Year 2026',
                'event_type'           => 'economy',
                'starts_at'            => '2025-12-20',
                'ends_at'              => '2026-01-10',
                'affected_categories'  => null,
                'boost_score'          => 1.22,
                'top_regions'          => json_encode(['Tunis', 'Ariana', 'Sfax', 'Sousse']),
                'is_active'            => true,
            ],
            [
                'event_slug'           => 'new_year_soldes_2027',
                'event_name'           => 'Soldes & New Year 2027',
                'event_type'           => 'economy',
                'starts_at'            => '2026-12-20',
                'ends_at'              => '2027-01-10',
                'affected_categories'  => null,
                'boost_score'          => 1.22,
                'top_regions'          => json_encode(['Tunis', 'Ariana', 'Sfax', 'Sousse']),
                'is_active'            => true,
            ],

            // ── Independence Day (Fête Nationale) — buying spike ──────────
            [
                'event_slug'           => 'independence_day_2026',
                'event_name'           => 'Fête Nationale — Independance Day 2026',
                'event_type'           => 'economy',
                'starts_at'            => '2026-03-20',
                'ends_at'              => '2026-03-23',
                'affected_categories'  => json_encode(['clothing', 'accessories', 'gifts', 'home']),
                'boost_score'          => 1.12,
                'top_regions'          => null,
                'is_active'            => true,
            ],

        ];

        foreach ($events as $event) {
            DB::table('product_event_signals')->updateOrInsert(
                ['event_slug' => $event['event_slug']],
                array_merge($event, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }

        $this->command->info('✅ Tunisian event signals seeded: ' . count($events) . ' events');
    }
}