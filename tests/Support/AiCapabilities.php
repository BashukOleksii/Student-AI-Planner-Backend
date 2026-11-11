<?php

namespace Tests\Support;

use App\Services\AI\Actions\AvailabilityActionRegistry;
use App\Services\AI\Actions\LessonActionRegistry;
use App\Services\AI\Actions\PlanningActionRegistry;
use App\Services\AI\Actions\SemesterActionRegistry;
use App\Services\AI\Actions\SubtaskReminderActionRegistry;
use App\Services\AI\Actions\TaskActionRegistry;
use App\Services\AI\Tools\RecoveryReadTools;
use App\Services\AI\Tools\ToolRegistry;

/** Current registry contracts; no historical numeric discovery snapshots. */
final class AiCapabilities
{
    public static function definitions(): array
    {
        return [...(new ToolRegistry)->definitions(), ...(new TaskActionRegistry)->definitions(),
            ...(new SubtaskReminderActionRegistry)->readDefinitions(), ...(new SubtaskReminderActionRegistry)->definitions(),
            ...(new PlanningActionRegistry)->definitions(), ...(new LessonActionRegistry)->definitions(),
            ...(new AvailabilityActionRegistry)->definitions(), ...(new SemesterActionRegistry)->readDefinitions(),
            ...(new SemesterActionRegistry)->definitions(), ...RecoveryReadTools::definitions()];
    }

    public static function names(): array
    {
        return array_column(self::definitions(), 'name');
    }
}
