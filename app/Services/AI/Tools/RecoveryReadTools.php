<?php

namespace App\Services\AI\Tools;

use App\Exceptions\RecoverySuggestionException;
use App\Http\Resources\RecoverySuggestionResource;
use App\Models\User;
use App\Services\Planning\LearningRecoveryAnalysisService;
use App\Services\Planning\RecoverySuggestionService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/** Three read-only adapters. No suggestion confirmation, refresh or business writer is exposed to the model. */
class RecoveryReadTools
{
    public function __construct(private ToolArgumentValidator $validator, private RecoverySuggestionService $suggestions,
        private LearningRecoveryAnalysisService $analysis) {}

    public static function definitions(): array
    {
        $integer = ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000000];
        $date = ['type' => 'string', 'format' => 'date', 'pattern' => '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'];
        $definition = fn ($name, $description, $properties, $required) => compact('name', 'description') + ['parameters' => [
            'type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false]];

        return [
            $definition('list_recovery_suggestions', 'Read owned automatic learning recovery review summaries. Pending is a suggestion, not an applied plan. Expired or changed reviews cannot be applied. No mutation or confirmation.', [
                'status' => ['type' => 'string', 'enum' => ['all', 'pending', 'applied', 'rejected', 'expired', 'invalidated']],
                'page' => $integer, 'per_page' => [...$integer, 'maximum' => 10],
            ], []),
            $definition('get_recovery_suggestion', 'Read the complete owned automatic recovery review and immutable receipt by its exact UUID. Explain unknown outcomes, partial allocation and required consent. You cannot confirm or refresh this review; the student uses the authenticated review interface.', [
                'suggestion_id' => ['type' => 'string'],
            ], ['suggestion_id']),
            $definition('get_recovery_diagnostics', 'Read current bounded server-calculated recovery needs, academic conflicts, effort, overload warnings and a single deterministic suggestion for 1–31 local dates. Does not create a durable suggestion or save sessions. Unknown planned session outcomes need explicit student decisions; missing estimates are not zero. This is not a globally optimal plan.', [
                'start_date' => $date, 'end_date' => $date,
            ], ['start_date', 'end_date']),
        ];
    }

    public static function find(string $name): ?array
    {
        return collect(self::definitions())->firstWhere('name', $name);
    }

    public function execute(User $owner, string $name, array $arguments): array
    {
        try {
            $definition = self::find($name);
            if ($definition === null) {
                throw new InvalidArgumentException;
            }
            $this->validator->validate($definition, $arguments);
            if (! $owner->exists || $owner->getKey() === null) {
                return $this->error($name, 'access_denied');
            }
            if ($name === 'get_recovery_diagnostics') {
                $data = $this->analysis->analyze($owner, $arguments['start_date'], $arguments['end_date'])['review'];
            } elseif ($name === 'get_recovery_suggestion') {
                if (! Str::isUuid($arguments['suggestion_id'])) {
                    throw new InvalidArgumentException;
                }
                $data = (new RecoverySuggestionResource($this->suggestions->show($owner, $arguments['suggestion_id'])))->resolve(new Request);
            } else {
                $page = $this->suggestions->listing($owner, $arguments);
                $data = ['suggestions' => array_map(fn ($row) => Arr::except((new RecoverySuggestionResource($row))->resolve(new Request), ['review', 'receipt']), $page->items()),
                    'automation' => $this->suggestions->automation($owner),
                    'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()]];
            }

            return ['ok' => true, 'tool' => $name, 'data' => $data];
        } catch (InvalidArgumentException) {
            return $this->error($name, 'invalid_arguments');
        } catch (ModelNotFoundException) {
            return $this->error($name, 'not_found');
        } catch (RecoverySuggestionException $exception) {
            return $this->error($name, $exception->errorCode);
        } catch (Throwable) {
            return $this->error($name, 'execution_failed');
        }
    }

    private function error(string $name, string $code): array
    {
        return ['ok' => false, 'tool' => $name, 'error' => ['code' => $code, 'message' => match ($code) {
            'invalid_arguments' => 'Invalid tool arguments.', 'not_found' => 'Resource not found.',
            'analysis_limit_exceeded' => 'Recovery analysis exceeds its safety limits. Use the existing task-scoped manual recovery workflow.',
            'access_denied' => 'Access denied.', default => 'Recovery information could not be read.',
        }]];
    }
}
