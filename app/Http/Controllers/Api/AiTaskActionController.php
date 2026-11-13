<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AiTaskActionRequest;
use App\Http\Resources\AiPendingActionResource;
use App\Services\AI\Actions\TaskActionConfirmationService;
use App\Services\AI\CompletionReviewPrivacy;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AiTaskActionController extends Controller
{
    public function index(AiTaskActionRequest $request, string $conversation, TaskActionConfirmationService $service): AnonymousResourceCollection
    {
        return AiPendingActionResource::collection($service->query($request->user(), $conversation)->orderByDesc('created_at')->orderByDesc('id')->paginate($request->validated('per_page', 20))->withQueryString());
    }

    public function show(AiTaskActionRequest $request, string $conversation, string $action, TaskActionConfirmationService $service): AiPendingActionResource
    {
        return new AiPendingActionResource($service->query($request->user(), $conversation)->whereKey($action)->firstOrFail());
    }

    public function confirm(AiTaskActionRequest $request, string $conversation, string $action, TaskActionConfirmationService $service): JsonResponse
    {
        return $this->decide($request, $conversation, $action, $service, 'confirm');
    }

    public function reject(AiTaskActionRequest $request, string $conversation, string $action, TaskActionConfirmationService $service): JsonResponse
    {
        return $this->decide($request, $conversation, $action, $service, 'reject');
    }

    private function decide(AiTaskActionRequest $request, string $conversation, string $action, TaskActionConfirmationService $service, string $operation): JsonResponse
    {
        try {
            $outcome = $service->decide($request->user(), $conversation, $action, $operation, $request->validated());
        } catch (HttpResponseException $exception) {
            // WorkCompletionService also serves manual REST; filter only at this AI boundary.
            $response = $exception->getResponse();
            if ($response instanceof JsonResponse) {
                $response->setData(CompletionReviewPrivacy::structured($response->getData(true)));
            }
            throw $exception;
        }

        return response()->json(['data' => (new AiPendingActionResource($outcome['action']))->resolve($request),
            'replayed' => $outcome['replayed']], $outcome['status']);
    }
}
