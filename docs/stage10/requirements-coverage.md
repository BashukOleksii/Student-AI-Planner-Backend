# Requirements coverage — Backend Stage 11 addendum

The original Stage 10 audit was inspected in the independent Frontend repository
at `d6e03fe`, especially A2 and R21 (`S2:L220–222`, AI schedule/substitution
management). This Backend HEAD (`f618bba`) did not contain that audit or Stage 9's
`docs/defense/` documents. This is a scoped Backend update, not a replacement or
reclassification of unrelated R01–R48 findings in the Frontend audit.

| Item | Backend implementation / evidence | Remaining work | Status |
| --- | --- | --- | --- |
| A2 architectural approval | Operator explicitly approved four proposals, existing confirmation and forward-only migration; [ADR-005](../decisions/ADR-005-ai-lesson-confirmation.md) | No additional architecture approval needed within this scope | Approved / implemented Backend |
| R21 AI Lesson and replacement management | Four allowlisted actions; shared LessonService preflight/writes; ownership/Policy, exact UTC, readable before/after reviews, explicit confirm/reject, graph/reference fingerprints, expiry, rollback and immutable replay receipts | Frontend type support, four reviews, schedule refresh and UI tests | **PARTIALLY IMPLEMENTED system-wide; Backend implemented/tested** |
| R17/R18 intent/tool coverage | Registry grows from 23 to 27 tools; fake-provider orchestration tests and global one-proposal limit retained | Real paid provider behavior remains unverified | Backend structured behavior tested |
| R19 existing schedule/task reads | Existing read schemas and Services preserved | None introduced by Stage 11 | Preserved |
| R20 Task/Subtask management | Existing proposals/receipts retained and regression tested | Existing delete/reorder restrictions unchanged | Preserved |
| R23/R25 planning and multi-step protocol | Existing planning/reminder adapters and confirmation flow retained; one global mutation proposal | No multi-mutation turn capability added | Preserved |
| A1/A3/A4 (password recovery, autonomous relocation, new statistics) | No implementation changes | Separate approval/scope required | Outside Stage 11 |

Backend evidence lives in `tests/Unit/LessonActionTest.php`,
`tests/Feature/AiLessonActionsTest.php`, `AiLessonActionConcurrencyTest.php`,
`AiLessonActionMigrationTest.php`, existing Lesson tests and full Backend regression.
See [implementation report](../stage11/implementation-report.md) for measured results.

The Frontend remains intentionally unchanged. Its current `reviewSupported` guard
prevents confirming these unknown types. Acceptance criteria 6 and the Frontend
part of 8 remain pending. No full Stage 11 completion or real OpenAI success is
claimed. [Exact integration contract](../stage11/frontend-rest-contract.md).
