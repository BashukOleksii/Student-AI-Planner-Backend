<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProgressTrendsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'as_of_utc' => $this->resource['as_of_utc'],
            'timezone' => $this->resource['timezone'],
            'period' => $this->resource['period'],
            'days' => $this->resource['days'],
            'totals' => $this->resource['totals'],
        ];
    }
}
