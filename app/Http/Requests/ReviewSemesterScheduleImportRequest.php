<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ReviewSemesterScheduleImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('view', $this->route('semesterScheduleImport'));

        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [];
    }
}
