# Explicit work completion and session accounting

Work status is a student decision. Session status and actual minutes are independent.

Stage 1 adds authenticated SELECT-only `GET /api/tasks/{task}/completion-review`
and `GET /api/tasks/{task}/subtasks/{subtask}/completion-review`. Response fields:
`future_sessions_count`, `message`, `completion_token` (encrypted exact owned
session identities and intervals). The existing completion POST accepts that token.
A missing token with future sessions or any changed reviewed set returns 409 with
`completion_review`; no work, reminder or session write survives. Zero-session
legacy completion remains supported. A supplied zero-session token is rechecked.

Owner/work/session locks precede the operation clock. Only planned sessions with
`starts_at > now` are cancelled using model writes, preserving history and the
existing after-commit plan-attention observer. Existing work reminder completion
rules remain authoritative. Reopening never resurrects cancelled data. AI work
proposals show the server review and use its token only on explicit authenticated
pending-action confirmation. No model tool can grant consent.

Frontend task/subtask completion shares one review-before-write API adapter.
Each explicit action reads a fresh review; stale/unknown writes are never retried
automatically. The next attempt requires a fresh review and consent.
