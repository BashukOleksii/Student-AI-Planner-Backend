<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListTasksRequest;
use App\Http\Requests\SaveTaskRequest;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use App\Services\Tasks\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class TaskController extends Controller
{
    public function index(ListTasksRequest $request, TaskService $service): AnonymousResourceCollection
    {
        return TaskResource::collection($service->paginate($request->user(), $request->validated()));
    }

    public function store(SaveTaskRequest $request, TaskService $service): JsonResponse
    {
        return (new TaskResource($service->create($request->user(), $request->validated())))->response()->setStatusCode(201);
    }

    public function show(Request $request, Task $task): TaskResource
    {
        Gate::authorize('view', $task);

        return new TaskResource($task);
    }

    public function update(SaveTaskRequest $request, Task $task, TaskService $service): TaskResource
    {
        return new TaskResource($service->update($request->user(), $task, $request->validated()));
    }

    public function complete(Request $request, Task $task, TaskService $service): TaskResource
    {
        Gate::authorize('update', $task);

        return new TaskResource($service->complete($request->user(), $task));
    }

    public function reopen(Request $request, Task $task, TaskService $service): TaskResource
    {
        Gate::authorize('update', $task);

        return new TaskResource($service->reopen($request->user(), $task));
    }
}
