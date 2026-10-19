# ADR-002 — Durable confirmation for conversational Task actions

Status: accepted for Stage H3a, 2026-10-17.

The first mutating AI capability must preserve H1 read-only execution and H2's
lease/idempotency/atomic transcript contracts. A model-produced mutation call is
insufficient authorization and provider/network retries cannot become business
retries.

Use four server-owned Task proposal tools and one additive pending-action table.
Prepare arguments/previews without business writes. Persist exactly one proposal
only in the successful originating turn transaction. Every action requires an
explicit authenticated confirmation endpoint; it invokes the existing TaskService
inside the same MySQL transaction as its durable receipt and history event.
Conversation/action locks precede existing owner/Task/dependency locks. Active
turns exclude new decisions, and no transaction spans provider I/O. Snapshot
fingerprints reject stale targets; MySQL governs the 15-minute expiration.

A mutable client token or conversational yes is not confirmation. Stateless review
tokens alone do not provide a durable execution receipt for response-loss replay.
A general workflow engine and direct model-to-TaskService dispatch add unnecessary
scope. The chosen UUID identifies protected server data; it carries no embedded
client-editable arguments. H3 provider schemas explicitly preserve omission versus
clearing-null; H1 strict provider schemas are unchanged.

Consequences: one extra table, bounded server action-state history, immutable
receipt replay and intentional owner-level domain serialization. Operational
pruning, other mutation domains, multi-action workflows and automatic low-risk
execution remain future decisions. See [the complete contract](../ai-task-actions.md).
