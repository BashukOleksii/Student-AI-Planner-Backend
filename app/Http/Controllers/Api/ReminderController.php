<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReminderActionRequest;
use App\Http\Requests\ReminderIndexRequest;
use App\Http\Requests\StoreReminderRequest;
use App\Http\Requests\UpdateReminderRequest;
use App\Http\Resources\ReminderResource;
use App\Services\Reminders\ReminderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReminderController extends Controller
{
    public function index(ReminderIndexRequest $request, ReminderService $service): AnonymousResourceCollection
    {
        return ReminderResource::collection($service->listing($request->user(), $request->validated()));
    }

    public function store(StoreReminderRequest $request, ReminderService $service): JsonResponse
    {
        return (new ReminderResource($service->create($request->user(), $request->validated())))->response()->setStatusCode(201);
    }

    public function show(Request $request, string $reminder, ReminderService $service): ReminderResource
    {
        return new ReminderResource($service->find($request->user(), $reminder));
    }

    public function update(UpdateReminderRequest $request, string $reminder, ReminderService $service): ReminderResource
    {
        return new ReminderResource($service->update($request->user(), $reminder, $request->validated()));
    }

    public function cancel(ReminderActionRequest $request, string $reminder, ReminderService $service): ReminderResource
    {
        return new ReminderResource($service->cancel($request->user(), $reminder));
    }
}
