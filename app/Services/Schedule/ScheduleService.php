<?php

namespace App\Services\Schedule;

use App\Enums\LessonStatus;
use App\Models\User;
use Carbon\CarbonImmutable;

class ScheduleService
{
    public function today(User $user): array
    {
        return $this->date($user, CarbonImmutable::now($user->timezone)->toDateString());
    }

    public function date(User $user, string $date): array
    {
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC');

        return $this->range($user, $date, $start->addDay()->toDateString());
    }

    public function week(User $user, string $date): array
    {
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC')->startOfWeek(CarbonImmutable::MONDAY);

        return $this->range($user, $start->toDateString(), $start->addWeek()->toDateString());
    }

    private function range(User $user, string $startDate, string $endDate): array
    {
        // Resolve both local midnights independently, including zones with midnight DST jumps.
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $startDate, $user->timezone);
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $endDate, $user->timezone);
        $lessons = $user->lessons()->where('status', LessonStatus::Active)
            ->when($start->greaterThanOrEqualTo($end), fn ($query) => $query->whereRaw('1 = 0'))
            ->where('starts_at', '<', $end->utc()->format('Y-m-d H:i:s'))
            ->where('ends_at', '>', $start->utc()->format('Y-m-d H:i:s'))
            ->with(['subject', 'teacher', 'replacedLesson'])
            ->orderBy('starts_at')->orderBy('ends_at')->orderBy('id')->get();

        return [
            'lessons' => $lessons,
            'meta' => [
                'timezone' => $user->timezone,
                'start_date' => $startDate,
                'end_date' => CarbonImmutable::createFromFormat('!Y-m-d', $endDate, 'UTC')->subDay()->toDateString(),
            ],
        ];
    }
}
