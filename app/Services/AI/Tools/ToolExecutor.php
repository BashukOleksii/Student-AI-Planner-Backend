<?php

namespace App\Services\AI\Tools;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

class ToolExecutor
{
    public function __construct(private ToolRegistry $registry, private ToolArgumentValidator $validator, private ReadOnlyTools $tools) {}

    /** The caller must supply the User from trusted Laravel authentication, never from model arguments. */
    public function execute(User $authenticatedUser, string $toolName, array $arguments): array
    {
        try {
            $definition = $this->registry->find($toolName);
            if ($definition === null) {
                return $this->error($toolName, 'unknown_tool', 'Unknown tool.');
            }
            try {
                $this->validator->validate($definition, $arguments);
            } catch (InvalidArgumentException) {
                return $this->error($toolName, 'invalid_arguments', 'Invalid tool arguments.');
            }
            if (! $authenticatedUser->exists || $authenticatedUser->getKey() === null) {
                return $this->error($toolName, 'access_denied', 'Access denied.');
            }

            return ['ok' => true, 'tool' => $toolName, 'data' => $this->tools->execute($authenticatedUser, $toolName, $arguments)];
        } catch (ValidationException) {
            return $this->error($toolName, 'invalid_arguments', 'Invalid tool arguments.');
        } catch (ModelNotFoundException) {
            return $this->error($toolName, 'not_found', 'Resource not found.');
        } catch (AuthorizationException $exception) {
            return $exception->status() === 404
                ? $this->error($toolName, 'not_found', 'Resource not found.')
                : $this->error($toolName, 'access_denied', 'Access denied.');
        } catch (Throwable) {
            // In particular, malformed persisted source relationships are not argument-validation failures.
            return $this->error($toolName, 'execution_failed', 'Tool execution failed.');
        }
    }

    private function error(string $name, string $code, string $message): array
    {
        return ['ok' => false, 'tool' => $name, 'error' => ['code' => $code, 'message' => $message]];
    }
}
