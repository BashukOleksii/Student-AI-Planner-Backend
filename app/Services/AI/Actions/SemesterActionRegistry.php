<?php

namespace App\Services\AI\Actions;

use App\Enums\LessonType;

/** Explicit semester operations; occurrence identities are never numeric legacy Lesson IDs. */
class SemesterActionRegistry
{
    public const NAMES = ['propose_semester_rule_create', 'propose_semester_rule_update', 'propose_semester_rule_delete',
        'propose_semester_date_replace', 'propose_semester_date_delete', 'propose_semester_working_saturday',
        'propose_semester_occurrence_update', 'propose_semester_occurrence_cancel', 'propose_semester_settings_update'];

    public function definitions(): array
    {
        $id = $this->id();
        $date = $this->date();
        $target = ['semester_schedule_id' => $id];
        $rule = [...$target, 'weekly_lesson_rule_id' => $id];
        $day = [...$target, 'date' => $date];
        $occurrence = [...$day, 'occurrence_id' => ['type' => 'string', 'maxLength' => 160,
            'description' => 'Exact virtual semester occurrence identity returned by an owned effective calendar read.']];
        $fields = $this->lessonFields();
        $weekly = ['weekday' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5],
            'week_pattern' => ['type' => 'string', 'enum' => ['all', 'numerator', 'denominator']], ...$fields];
        $required = ['pair_number', 'starts_at_local', 'ends_at_local', 'subject_id', 'teacher_id'];
        $change = ['weekly_lesson_rule_id' => $id, 'cancelled' => ['type' => 'boolean'], ...$fields];

        return [
            $this->definition(self::NAMES[0], 'Propose a recurring semester rule, affecting its matching weekdays/parities through the semester. Does not edit frozen date snapshots. Discover owned schedule/subject/teacher IDs first.', [...$target, ...$weekly], ['semester_schedule_id', 'weekday', 'week_pattern', ...$required]),
            $this->definition(self::NAMES[1], 'Propose a partial edit of one entire recurring weekly rule. Frozen date exceptions stay independent. Clarify recurring versus one-date intent.', [...$rule, ...$weekly], array_keys($rule)),
            $this->definition(self::NAMES[2], 'Propose deleting one recurring rule for the semester. Preserves frozen date lessons and intentionally suppresses its imported slot. Requires explicit deletion intent.', $rule, array_keys($rule)),
            $this->definition(self::NAMES[3], 'Propose an explicit whole-day arrangement. replace requires the COMPLETE intended lesson list (empty cancels the day); copy_base starts from recurring base rules, applies changes/removals and extra lessons, replacing an existing snapshot. Never use for a single occurrence when its targeted tool suffices.', [...$day,
                'mode' => ['type' => 'string', 'enum' => ['replace', 'copy_base']],
                'lessons' => $this->listOf($this->object(['id' => $id, ...$fields], $required)),
                'changes' => $this->listOf($this->object($change, ['weekly_lesson_rule_id'])),
            ], ['semester_schedule_id', 'date', 'mode']),
            $this->definition(self::NAMES[4], 'Propose removing one date exception, including a working Saturday. Restores the base day; does not cancel the base lessons. Requires explicit restore/delete intent.', $day, array_keys($day)),
            $this->definition(self::NAMES[5], 'Propose a working Saturday by copying the same week effective weekday, or an explicit nonempty subset of owned weekly rules with source parity. Preserves a frozen snapshot; never guesses weekday/parity.', [...$target,
                'occurs_on' => $date, 'copied_weekday' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5],
                'source_week_parity' => ['type' => 'string', 'enum' => ['numerator', 'denominator']],
                'selected_weekly_rule_ids' => ['type' => 'array', 'items' => $id, 'minItems' => 1, 'maxItems' => 255],
            ], ['semester_schedule_id', 'occurs_on', 'copied_weekday']),
            $this->definition(self::NAMES[6], 'Propose changing exactly one occurrence on one date. Preserves all other lessons on that day; recurring rules stay unchanged. Use a current virtual occurrence_id, never a legacy numeric ID.', [...$occurrence, ...$fields], array_keys($occurrence)),
            $this->definition(self::NAMES[7], 'Propose cancelling exactly one effective occurrence on one date, preserving every other lesson and all recurring rules. Never cancels the entire day implicitly.', $occurrence, array_keys($occurrence)),
            $this->definition(self::NAMES[8], 'Propose semester SETTINGS only: group name or parity anchor/first parity. May change effective parity throughout the semester. Never use for one occurrence or a rule edit; explicit settings intent required.', [...$target,
                'group_name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                'parity_anchor_on' => $date, 'first_week_parity' => ['type' => 'string', 'enum' => ['numerator', 'denominator']],
            ], array_keys($target)),
        ];
    }

    public function readDefinitions(): array
    {
        $page = ['page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000000],
            'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50]];

        return [
            $this->definition('list_semester_schedules', 'List owned semester schedule IDs, periods and parity settings with pagination. No writes.', $page, []),
            $this->definition('get_semester_schedule_context', 'Read owned semester metadata, paginated weekly definitions and intentionally suppressed slots, optionally a saved date snapshot with paginated lessons. Use before semester edits. Null snapshot means the date uses base rules; use get_schedule_for_date for its effective occurrences. Omitted records are explicitly marked truncated; fetch further pages before choosing targets.', ['semester_schedule_id' => $this->id(), 'date' => $this->date(), ...$page], ['semester_schedule_id']),
            $this->definition('search_academic_catalog', 'Find owned subject or teacher IDs by optional literal name substring. Paginated; multiple matches require clarification. Never invent or silently choose an association.', ['kind' => ['type' => 'string', 'enum' => ['subject', 'teacher']],
                'query' => ['type' => 'string', 'maxLength' => 100], ...$page], ['kind']),
        ];
    }

    public function find(string $name): ?array
    {
        return collect($this->definitions())->firstWhere('name', $name);
    }

    public function findRead(string $name): ?array
    {
        return collect($this->readDefinitions())->firstWhere('name', $name);
    }

    public function lessonFields(): array
    {
        $time = ['type' => 'string', 'pattern' => '^(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$'];

        return ['pair_number' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 255],
            'pair_label' => ['type' => ['string', 'null'], 'maxLength' => 32],
            'starts_at_local' => $time, 'ends_at_local' => $time,
            'subject_id' => $this->id(), 'teacher_id' => $this->id(),
            'room' => ['type' => ['string', 'null'], 'maxLength' => 100],
            'lesson_type' => ['type' => ['string', 'null'], 'enum' => [null, ...array_column(LessonType::cases(), 'value')]]];
    }

    private function id(): array
    {
        return ['type' => 'integer', 'minimum' => 1, 'maximum' => PHP_INT_MAX];
    }

    private function date(): array
    {
        return ['type' => 'string', 'format' => 'date', 'pattern' => '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'];
    }

    private function object(array $properties, array $required): array
    {
        return ['type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false];
    }

    private function listOf(array $items): array
    {
        return ['type' => 'array', 'items' => $items, 'maxItems' => 255];
    }

    private function definition(string $name, string $description, array $properties, array $required): array
    {
        return compact('name') + ['description' => $description.(str_starts_with($name, 'propose_') ? ' Authenticated confirmation is mandatory.' : ''),
            'strict' => false, 'parameters' => $this->object($properties, $required)];
    }
}
