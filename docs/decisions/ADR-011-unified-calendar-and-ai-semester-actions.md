# ADR-011: Unified academic reads and confirmed semester AI adapters

Status: implemented; behavioral verification deferred by task instruction.

Extend EffectiveScheduleReadService to inclusive range presentation over the existing CalendarBusyIntervalService/EffectiveScheduleService. Add separate academic-calendar today/date/week REST routes and an explicit Resource. Preserve legacy/semester response contracts and the current AI date shape. Do not include study sessions in academic presentation or add another source-selection algorithm.

Extend existing AiPendingAction discovery/confirmation with nine fixed semester proposals and three bounded owned discovery reads. Preview invokes the management writer's existing projected validation branch with an explicit server-only flag, returning before persistence. Confirmation reuses graph fingerprints, owner serialization, complete projected semester validation, existing receipt/history transactions and MySQL-clock expiry. No trial writes, second approval system, repository layer or new planner is introduced.

Single-occurrence changes preserve the other lessons by creating/updating a full-date snapshot; whole-day replacement and recurring-rule changes are explicitly different operations. Settings edits are explicit semester-wide parity/group operations. No legacy Lesson workaround is permitted. Import suppressions/manual protections remain authoritative. Academic edits do not automatically move self-study sessions.

One forward migration extends only the existing action-type CHECK. Existing state/receipt CHECKs and all old migrations remain intact. Public previews omit the graph token. Large reviews fail existing byte limits rather than presenting truncated consent. API and frontend details: [unified academic calendar](../unified-academic-calendar.md).
