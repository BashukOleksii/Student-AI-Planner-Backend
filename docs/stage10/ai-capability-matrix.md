# AI capability matrix — Backend Stage 11 addendum

Baseline: Backend `f618bba`, Frontend `d6e03fe`. The full Stage 10 matrix and A2/R21
audit were read from the independent Frontend repository's `docs/stage10/` at that
HEAD. Those files did not exist in this Backend checkout. This Backend-local
addendum records the approved extension without editing the Frontend repository.

| Capability | Tools | Backend state | Frontend state |
| --- | --- | --- | --- |
| Original reads | `get_schedule_for_date`, `list_tasks`, `find_free_study_slots`, `list_study_sessions`, `get_progress_statistics`, `get_daily_progress_trends`, `compare_progress_periods` | Preserved | Existing integration |
| Additional reads | `list_subtasks_for_task`, `list_reminders` | Preserved | Existing integration |
| Tasks | `propose_task_create`, `propose_task_update`, `propose_task_complete`, `propose_task_reopen` | Preserved confirmed actions | Existing reviews |
| Subtasks | `propose_subtask_create`, `propose_subtask_update`, `propose_subtask_complete`, `propose_subtask_reopen` | Preserved confirmed actions | Existing reviews |
| Reminders | `propose_reminder_create`, `propose_reminder_update`, `propose_reminder_cancel` | Preserved confirmed actions | Existing reviews |
| Planning | `propose_study_plan_create`, `propose_adaptive_replanning`, `propose_session_reschedule` | Preserved confirmed actions | Existing reviews |
| New Lesson | `propose_lesson_create` | Implemented, confirmed `LessonService::create` | Pending; unknown-type confirmation blocked |
| Lesson update | `propose_lesson_update` | Implemented, confirmed `LessonService::update` | Pending |
| Lesson cancellation | `propose_lesson_cancel` | Implemented, confirmed `LessonService::cancel` | Pending |
| Lesson replacement | `propose_lesson_replacement` | Implemented, confirmed `LessonService::createReplacement` | Pending |

Current Backend total: **27 definitions = 9 reads + 18 proposals**. All proposals
require explicit REST confirmation. One mutation attempt per turn applies across
all domains; invalid attempts count. The LLM cannot execute confirmations.

No AI Lesson delete/bulk/import, academic catalog discovery, password recovery,
autonomous background relocation or new statistics aggregate was added. Exact IDs
must be grounded in successful reads or explicit user input; missing catalog IDs
need clarification. Local fake-provider tests verify schemas, dispatch and protocol;
real paid provider selection/wording was not verified.

See [Lesson rules](../ai-lesson-actions.md), [REST contract](../stage11/frontend-rest-contract.md)
and [actual test evidence](../stage11/implementation-report.md).
