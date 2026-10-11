<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FreeSlotsRequest;
use App\Http\Resources\FreeSlotResource;
use App\Services\Planning\FreeTimeService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FreeSlotController extends Controller
{
    public function index(FreeSlotsRequest $request, FreeTimeService $service): AnonymousResourceCollection
    {
        $values = $request->validated();
        $user = $request->user();
        $minimumSeconds = array_key_exists('min_slot_minutes', $values) ? (int) $values['min_slot_minutes'] * 60 : null;
        $intervals = $service->calculate($user, $values['start_date'], $values['end_date'], $minimumSeconds);

        return FreeSlotResource::collection($intervals)->additional(['meta' => [
            'timezone' => $user->timezone,
            'start_date' => $values['start_date'],
            'end_date' => $values['end_date'],
        ]]);
    }
}
