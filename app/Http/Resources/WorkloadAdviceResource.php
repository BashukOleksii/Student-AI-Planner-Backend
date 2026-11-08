<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkloadAdviceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'as_of_utc' => $this->resource['as_of_utc'],
            'timezone' => $this->resource['timezone'],
            'period' => $this->resource['period'],
            'summary' => $this->resource['summary'],
            'alerts' => $this->resource['alerts'],
            'work_advice' => $this->resource['work_advice'],
            'excluded_items' => $this->resource['excluded_items'],
            'warnings' => $this->resource['warnings'],
            'persisted_workload_violations' => $this->resource['persisted_workload_violations'],
        ];
    }
}
