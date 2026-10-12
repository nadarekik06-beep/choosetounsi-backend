<?php

namespace App\Services\Intigo;

/**
 * Intigo status codes → our parcel workflow (App\Services\Orders\ParcelStatus).
 *
 *   1000–1008          pickup phase           stays 'confirmed' (awaiting pickup)
 *   2000–3100          warehouse / transfer   'handed_to_courier' (in transit)
 *   4000               out for delivery       'out_for_delivery'
 *   5000               delivered              'delivered' (cash collected: commission, payout)
 *   3201, 6000, 6001   return in progress     'refused' (return fee booked, no payout)
 *   6900               returned to us         'returned_to_seller' (stock back)
 *   1100–1102, 9000–9004  cancelled by Intigo  no move: flagged to the admin (re-send or cancel)
 *   99, 6500           rerouting / exchange   informational only
 *
 * rank() orders codes along the parcel's life, so a late webhook never
 * overwrites a newer status.
 */
class IntigoStatusMap
{
    public const CARRIER_CANCELLED = 'carrier_cancelled';

    public const LABELS = [
        1000 => 'En attente de ramassage',
        2000 => 'Au dépôt',
        2001 => 'En attente d\'appel du client',
        2004 => 'Reçu au dépôt',
        2100 => 'Vérification',
        3100 => 'En transfert vers le point de livraison',
        3201 => 'En transfert pour retour',
        4000 => 'En cours de livraison',
        5000 => 'Livré',
        6000 => 'Préparation du retour',
        6001 => 'Retour prêt',
        6500 => 'Échange',
        6900 => 'Retour reçu par l\'expéditeur',
        99   => 'Réacheminement',
    ];

    /** Our status the parcel should reach for this code (null = informational). */
    public static function target(int $code): ?string
    {
        return match (true) {
            $code >= 1000 && $code <= 1008 => 'confirmed',
            $code >= 1100 && $code <= 1102 => self::CARRIER_CANCELLED,
            $code === 3201                 => 'refused',
            $code >= 2000 && $code < 4000  => 'handed_to_courier',
            $code === 4000                 => 'out_for_delivery',
            $code === 5000                 => 'delivered',
            $code === 6000, $code === 6001 => 'refused',
            $code === 6900                 => 'returned_to_seller',
            $code >= 9000 && $code <= 9004 => self::CARRIER_CANCELLED,
            default                        => null,   // 99 rerouting, 6500 exchange, unknown
        };
    }

    /** null (just created) counts as waiting for pickup. */
    public static function isPickupPhase(?int $code): bool
    {
        return $code === null || ($code >= 1000 && $code <= 1008);
    }

    public static function isCancelled(?int $code): bool
    {
        return $code !== null && self::target($code) === self::CARRIER_CANCELLED;
    }

    /** Delivered, returned or cancelled: Intigo won't move it any more. */
    public static function isFinal(?int $code): bool
    {
        return $code !== null && ($code === 5000 || $code === 6900 || self::isCancelled($code));
    }

    /** Position along the parcel's life; higher = later. 0 = informational (never stored as current). */
    public static function rank(?int $code): int
    {
        if ($code === null) return 1;
        return match (true) {
            $code >= 1000 && $code <= 1008 => 100 + ($code - 1000),
            $code >= 1100 && $code <= 1102 => 900 + ($code - 1100),
            $code >= 2000 && $code < 3000  => 200 + intdiv($code - 2000, 10),
            $code === 3100                 => 300,
            $code === 4000                 => 400,
            $code === 5000                 => 500,
            $code === 3201                 => 600,
            $code === 6000                 => 610,
            $code === 6001                 => 620,
            $code === 6500                 => 0,
            $code === 6900                 => 700,
            $code >= 9000 && $code <= 9004 => 950 + ($code - 9000),
            default                        => 0,
        };
    }

    public static function label(int $code, ?string $given = null): string
    {
        if (is_string($given) && trim($given) !== '') {
            return mb_substr(trim($given), 0, 120);
        }
        return self::LABELS[$code] ?? match (true) {
            $code >= 1001 && $code <= 1008 => 'Ramassage en cours',
            $code >= 1100 && $code <= 1102 => 'Annulé au ramassage',
            $code >= 9000 && $code <= 9004 => 'Annulé',
            default                        => "Statut {$code}",
        };
    }
}
