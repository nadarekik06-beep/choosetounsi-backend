<?php

namespace App\Http\Resources\Admin;

use App\Models\OrderExport;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property OrderExport $resource
 */
class OrderExportResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'              => $this->id,
            'type'            => $this->type,
            'seller_order_id' => $this->seller_order_id,
            'exported_by'     => $this->exporter?->name,
            'created_at'      => optional($this->created_at)->toIso8601String(),
        ];
    }
}
