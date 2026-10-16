<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AiConversationIndexRequest;
use App\Http\Requests\AiConversationRequest;
use App\Http\Requests\SendAiMessageRequest;
use App\Http\Resources\AiConversationResource;
use App\Http\Resources\AiMessageResource;
use App\Services\AI\AiConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AiConversationController extends Controller
{
    public function store(AiConversationRequest $request, AiConversationService $service): JsonResponse
    {
        return (new AiConversationResource($service->create($request->user())))->response()->setStatusCode(201);
    }

    public function index(AiConversationIndexRequest $request, AiConversationService $service): AnonymousResourceCollection
    {
        return AiConversationResource::collection($service->listing($request->user(), $request->validated()));
    }

    public function show(AiConversationRequest $request, string $conversation, AiConversationService $service): AiConversationResource
    {
        return new AiConversationResource($service->find($request->user(), $conversation));
    }

    public function messages(AiConversationIndexRequest $request, string $conversation, AiConversationService $service): AnonymousResourceCollection
    {
        return AiMessageResource::collection($service->messages($request->user(), $conversation, $request->validated()));
    }

    public function send(SendAiMessageRequest $request, string $conversation, AiConversationService $service): JsonResponse
    {
        return response()->json(['data' => $service->send($request->user(), $conversation, $request->validated('content'), strtolower($request->header('Idempotency-Key')))]);
    }
}
