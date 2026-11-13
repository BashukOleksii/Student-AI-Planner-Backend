# ADR-014: Owner-scoped after-commit plan checks and attention status

Status: accepted by the explicit product request, 2026-11-13.

Extend recovery_scan_states with a durable dirty generation, delivery lease and
success/error timestamps. Observe only planning source model writes and reuse
one owner trigger. Coalesce transaction callbacks including nested rollback;
enqueue a bounded, debounced database job after commit. Existing owner-locked
RecoverySuggestionService detection and source/need deduplication remain the only
analysis mechanism. The five-minute bounded Scheduler is the durable fallback
for dispatch loss, crash, time boundaries and external writes, not replaced.

GET attention-status returns compact current counts and checking/error metadata,
never review/tokens. Dirty or stale reviews cannot imply all clear. Preserve the
existing explicit refresh policy for expired unchanged reviews and represent it
separately. Review decisions and notifications/inbox remain separate domains.

One Pinia store supplies the authenticated global header. Coalesce local mutation
signals, bound rapid reads, poll only visible tabs, clear on logout/account change,
and fence stale responses. No automatic session mutation, allocator change,
second detector, WebSocket service or global design change is introduced.

See [contract, local commands, verification and DEMO G](../planning-attention.md).
