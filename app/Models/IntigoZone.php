<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One item of Intigo's "Zone (Délégation/Secteur)" dropdown
 * (App\Services\Intigo\IntigoZones). display_label is exactly what Intigo shows:
 *   DELEGATION  "Bab Bhar (Tunis)"
 *   SECTEUR     "Aeroport Tunis Carthage, La Soukra (Ariana)"
 */
class IntigoZone extends Model
{
    public const DELEGATION = 'DELEGATION';
    public const SECTEUR    = 'SECTEUR';

    protected $guarded = ['id'];

    protected $casts = [
        'intigo_id'      => 'integer',
        'delegation_id'  => 'integer',
        'gouvernorat_id' => 'integer',
        'latitude'       => 'float',
        'longitude'      => 'float',
        'geocoded_at'    => 'datetime',
        'active'         => 'boolean',
    ];

    public function isSecteur(): bool
    {
        return $this->type === self::SECTEUR;
    }

    /** What the public search / the address cards send. */
    public function toOption(): array
    {
        return [
            'id'            => $this->id,
            'type'          => $this->type,
            'intigo_id'     => $this->intigo_id,
            'name'          => $this->name,
            'name_ar'       => $this->name_ar,
            'delegation'    => $this->delegation,
            'gouvernorat'   => $this->gouvernorat,
            'display_label' => $this->display_label,
            'latitude'      => $this->latitude,
            'longitude'     => $this->longitude,
        ];
    }
}
