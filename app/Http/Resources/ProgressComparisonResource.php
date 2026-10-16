<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProgressComparisonResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'as_of_utc' => $this->resource['as_of_utc'],
            'timezone' => $this->resource['timezone'],
            'comparison' => $this->resource['comparison'],
            'baseline' => $this->resource['baseline'],
            'current' => $this->resource['current'],
            'delta' => $this->resource['delta'],
            'observations' => $this->resource['observations'],
        ];
    }
}
