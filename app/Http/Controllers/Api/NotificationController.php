<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\NotificationIndexRequest;
use App\Http\Requests\ReadNotificationRequest;
use App\Http\Resources\NotificationResource;
use App\Services\Notifications\NotificationInboxService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class NotificationController extends Controller
{
    public function index(NotificationIndexRequest $request, NotificationInboxService $service): AnonymousResourceCollection
    {
        return NotificationResource::collection($service->listing($request->user(), $request->validated()));
    }

    public function read(ReadNotificationRequest $request, string $notification, NotificationInboxService $service): NotificationResource
    {
        return new NotificationResource($service->read($request->user(), $notification));
    }
}
