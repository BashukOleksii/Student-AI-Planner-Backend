<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RecoveryDiagnosticsRequest;
use App\Http\Requests\RecoverySuggestionRequest;
use App\Http\Resources\RecoverySuggestionResource;
use App\Services\Planning\LearningRecoveryAnalysisService;
use App\Services\Planning\RecoverySuggestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RecoverySuggestionController extends Controller
{
    public function __construct(private RecoverySuggestionService $suggestions) {}

    public function index(RecoverySuggestionRequest $request): AnonymousResourceCollection
    {
        return RecoverySuggestionResource::collection($this->suggestions->listing($request->user(), $request->validated()))
            ->additional(['automation' => $this->suggestions->automation($request->user())]);
    }

    public function show(RecoverySuggestionRequest $request, string $recoverySuggestion): RecoverySuggestionResource
    {
        return new RecoverySuggestionResource($this->suggestions->show($request->user(), $recoverySuggestion));
    }

    public function confirm(RecoverySuggestionRequest $request, string $recoverySuggestion): JsonResponse
    {
        $outcome = $this->suggestions->confirm($request->user(), $recoverySuggestion,
            $request->validated('accept_partial', false), $request->validated('accept_unplaced_cancellation', false), $request->validated('accept_split_replacements', false));

        return response()->json(['data' => (new RecoverySuggestionResource($outcome['suggestion']))->resolve($request),
            'replayed' => $outcome['replayed']]);
    }

    public function reject(RecoverySuggestionRequest $request, string $recoverySuggestion): RecoverySuggestionResource
    {
        return new RecoverySuggestionResource($this->suggestions->reject($request->user(), $recoverySuggestion));
    }

    public function refresh(RecoverySuggestionRequest $request, string $recoverySuggestion): RecoverySuggestionResource
    {
        return new RecoverySuggestionResource($this->suggestions->refresh($request->user(), $recoverySuggestion));
    }

    public function diagnostics(RecoveryDiagnosticsRequest $request, LearningRecoveryAnalysisService $analysis): JsonResponse
    {
        return response()->json(['data' => $analysis->analyze($request->user(), $request->validated('start_date'), $request->validated('end_date'))['review'],
            'automation' => $this->suggestions->automation($request->user())]);
    }
}
