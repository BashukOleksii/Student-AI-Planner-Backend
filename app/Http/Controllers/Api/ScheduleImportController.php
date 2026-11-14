<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ScheduleImportBatchResource;
use App\Models\ScheduleImportBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ScheduleImportController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $this->unavailable();
    }

    public function show(ScheduleImportBatch $scheduleImportBatch): ScheduleImportBatchResource
    {
        Gate::authorize('view', $scheduleImportBatch);
        $scheduleImportBatch->load(['rows' => fn ($query) => $query->orderBy('row_number')]);

        return new ScheduleImportBatchResource($scheduleImportBatch);
    }

    public function revalidate(Request $request, ScheduleImportBatch $scheduleImportBatch): ScheduleImportBatchResource
    {
        Gate::authorize('revalidate', $scheduleImportBatch);
        $this->unavailable();
    }

    public function commit(Request $request, ScheduleImportBatch $scheduleImportBatch): JsonResponse
    {
        Gate::authorize('commit', $scheduleImportBatch);
        $this->unavailable();
    }

    private function unavailable(): never
    {
        abort(410, 'Імпорт окремих занять за датами більше недоступний. Додайте заняття вручну або скористайтеся імпортом семестрового розкладу.');
    }
}
