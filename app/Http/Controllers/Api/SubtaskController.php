<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveSubtaskRequest;
use App\Http\Resources\SubtaskResource;
use App\Models\Subtask;
use App\Models\Task;
use App\Services\Tasks\SubtaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class SubtaskController extends Controller
{
    public function index(Request $request, Task $task, SubtaskService $service): AnonymousResourceCollection
    {
        Gate::authorize('view', $task);

        return SubtaskResource::collection($service->all($request->user(), $task));
    }

    public function store(SaveSubtaskRequest $request, Task $task, SubtaskService $service): JsonResponse
    {
        return (new SubtaskResource($service->create($request->user(), $task, $request->validated())))->response()->setStatusCode(201);
    }

    public function show(Request $request, Task $task, Subtask $subtask, SubtaskService $service): SubtaskResource
    {
        Gate::authorize('view', $task);
        Gate::authorize('view', [$subtask, $task]);

        return new SubtaskResource($service->find($request->user(), $task, $subtask));
    }

    public function update(SaveSubtaskRequest $request, Task $task, Subtask $subtask, SubtaskService $service): SubtaskResource
    {
        return new SubtaskResource($service->update($request->user(), $task, $subtask, $request->validated()));
    }

    public function complete(Request $request, Task $task, Subtask $subtask, SubtaskService $service): SubtaskResource
    {
        Gate::authorize('update', $task);
        Gate::authorize('update', [$subtask, $task]);

        return new SubtaskResource($service->complete($request->user(), $task, $subtask));
    }

    public function reopen(Request $request, Task $task, Subtask $subtask, SubtaskService $service): SubtaskResource
    {
        Gate::authorize('update', $task);
        Gate::authorize('update', [$subtask, $task]);

        return new SubtaskResource($service->reopen($request->user(), $task, $subtask));
    }
}
