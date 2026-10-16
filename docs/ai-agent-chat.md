# Stage H2 — Authenticated read-only AI chat

H2 adds synchronous conversational orchestration over the seven unchanged
[H1 read tools](ai-tools-foundation.md). The LLM selects tools and explains their
results. PHP Services still decide schedule membership, free time, attribution,
constraints and statistics. No business writer, frontend, streaming, queue,
reminder delivery or mutating tool is introduced.

## Application boundary

```text
auth:sanctum -> Form Request / AiConversationPolicy -> AiConversationService
  -> short owned conversation lock + durable lease/idempotency claim
  -> bounded owned history + fresh persisted User timezone
  -> AgentOrchestrator -> LlmProvider -> OpenAiResponsesProvider -> Responses API
  -> H1 ToolExecutor -> existing deterministic read Services
  -> provider continuation / final answer
  -> short owned conversation lock + lease verification + atomic transcript commit
  -> committed public turn
```

`AgentOrchestrator` uses a narrow `LlmProvider` interface, not OpenAI wire shapes.
History events have `role`, `content`, `calls`, `call_id` and opaque adapter-approved
`state`. Provider responses have `text`, `calls` (`id`, `name`, parsed `arguments`)
and `state`. Only the concrete adapter translates this contract to Responses JSON.
Laravel's HTTP client is sufficient; no SDK or other dependency was added.

Every tool call, including an unknown name, reaches `ToolExecutor::execute()` after
provider ID/JSON validation. Names cannot resolve classes or methods. All seven H1
definitions are supplied on every provider request; no built-in OpenAI tools are
enabled. H2 does not map phrases to tools or run a fixed seven-tool workflow.

The system instructions identify tool results as authoritative data, require
clarification for ambiguous dates/IDs, distinguish failed reads from success, and
explain the read-only boundary. They include a captured trusted UTC clock, current
local date/time/weekday and freshly persisted IANA timezone. Relative dates can be
derived by the model from these values. No user ID, email, password, API key or
user-authored database field is inserted into system instructions.

## Configuration and local setup

Copy the new `.env.example` values into your local untracked `.env`, keep the
existing MySQL/Sanctum setup, and apply migrations with `php artisan migrate` in
the intended development database. Never point automated tests at that database.
Run `php artisan config:clear` after local configuration changes.

```dotenv
AI_PROVIDER=openai
OPENAI_API_KEY=your-local-secret
OPENAI_MODEL=gpt-5.4-mini
AI_REQUEST_TIMEOUT=30
AI_MAX_TOOL_ROUNDS=5
AI_MAX_TOOL_CALLS=8
AI_MAX_HISTORY_MESSAGES=20
AI_MAX_CONTEXT_BYTES=131072
AI_MAX_TOOL_RESULT_BYTES=65536
AI_MAX_OUTPUT_TOKENS=2048
AI_MAX_TURN_SECONDS=180
AI_TURN_LEASE_SECONDS=240
AI_REQUESTS_PER_MINUTE=10
```

Create a project API key in the official [OpenAI API dashboard](https://platform.openai.com/api-keys).
Supply it through local environment configuration or a deployment secret manager;
never put it in source, a client request or frontend configuration. The example is
a placeholder, not a working credential. Missing key/model, an unsupported provider
setting, or invalid budget configuration fails safely. Empty conversation creation
and history reads work without provider credentials.

The current official [GPT-5.4 Mini model page](https://developers.openai.com/api/docs/models/gpt-5.4-mini)
lists Responses and function calling support, including snapshot
`gpt-5.4-mini-2026-03-17`. The example alias is configurable and is verified against
that documentation as of 2026-10-16. No paid live inference was used for automated
verification. Actual project access, billing, limits and future model retirement
remain provider/account concerns. Configuration can use another compatible
Responses text/function-calling model; unsupported response item types fail closed.

Use a shared production cache (the project's database cache or Redis) for the
per-user message rate limit. Lease/idempotency correctness always uses MySQL and
does not depend on cache, web-server process affinity or process-local state.
Keep normal shared Sanctum session, HTTPS, stateful-domain, CORS and CSRF settings;
see [authentication/profile](auth-profile.md). Provider URL is fixed to
`https://api.openai.com/v1/responses`; redirects are disabled. Neither request fields
nor environment values can supply a provider URL.

## Responses compatibility and continuation

The adapter posts native Responses function definitions (`type: function`, `name`,
`description`, `parameters`, `strict: true`), uses `tool_choice: auto`, supports
multiple calls per response and sends `max_output_tokens`. It explicitly sets
`store: false` and `truncation: disabled`. There is no remote conversation ID,
`previous_response_id`, automatic retry, SDK convenience `output_text` assumption
or Chat Completions request wrapper.

OpenAI [strict function calling](https://developers.openai.com/api/docs/guides/function-calling)
requires all declared properties to be required and objects to disallow additional
properties. `OpenAiToolSchema` copies the H1 definitions, marks every property
required, and adds null to the type (and enum where applicable) of originally
optional properties. For example, provider-facing `list_tasks.status` is
`{"type":["string","null"],"enum":["pending","completed",null]}` and all five
filters/pagination fields are required. Required dates remain non-null strings.

When decoding a function call, the adapter removes null only for a known,
originally optional H1 property. It does not remove unknown properties or null
required dates, coerce numeric strings/floats, infer dates or repair arguments.
H1 performs authoritative server validation. Its registry and validator are not
weakened or altered. Unknown valid function names produce the normal H1
`unknown_tool` result; malformed names/IDs/JSON fail the turn before dispatch.

Each `function_call.call_id` is validated as a nonempty bounded identifier and
must be unique within the batch and the retained conversation/turn context.
Arguments must be real JSON objects (at most 16384 bytes), not a list/scalar or
malformed JSON. The adapter accepts only completed message/function/reasoning
items, parses output text/refusals, and rejects incomplete/failed/empty or
unsupported outputs. A meaningful provider refusal is a completed non-tool answer;
an empty/no-answer response is a failure.

For continuation, sanitized output items are replayed in their original order,
followed by matching `function_call_output` items whose `output` is the complete
serialized H1 result and whose `call_id` is the original identifier. Required
message/function item IDs and valid message phase metadata are retained when
present. Reasoning items retain only ID, encrypted content and an empty summary;
raw reasoning and summaries are not stored. The adapter requests
`reasoning.encrypted_content` and rejects reasoning items without it. Replay state
is revalidated through the same output parser. See official
[conversation state](https://developers.openai.com/api/docs/guides/conversation-state)
and [stateless reasoning continuation](https://developers.openai.com/api/docs/guides/reasoning).

The application replays selected complete recent turns from MySQL, including
structured assistant calls, encrypted reasoning and tool outputs. It does not
substitute final assistant text for a function-calling round. Model/API changes
that make older encrypted items incompatible may require a new conversation;
the application never silently drops required continuation items to repair an
upstream error.

## REST contract

All routes require `auth:sanctum`. Responses use Laravel's normal Resource `data`
envelope and paginated `links`/`meta`, or the documented completed-turn envelope.
No arbitrary tool-execution/debug endpoint is exposed.

| Method | Route | Input | Success |
| --- | --- | --- | --- |
| POST | `/api/ai/conversations` | Empty JSON object or absent body; no query/body fields | 201, conversation metadata |
| GET | `/api/ai/conversations` | Optional query `page`, `per_page` | 200, owned conversation page |
| GET | `/api/ai/conversations/{conversation}` | No fields | 200, owned metadata |
| GET | `/api/ai/conversations/{conversation}/messages` | Optional query `page`, `per_page`, `through_id` | 200, public chronological message page |
| POST | `/api/ai/conversations/{conversation}/messages` | JSON object with only `content`; required UUID `Idempotency-Key` header (versions 1–5) | 200, durably completed/replayed turn |

Conversation metadata is exactly `id`, `created_at`, `updated_at`. Conversation
list order is updated_at descending, then ID descending. `page` is a canonical
positive decimal query integer up to 2147483647; `per_page` defaults to 20 and is
bounded at 50. Explicit nulls, arrays, numeric lookalikes and unknown query fields
fail. Client body parameters cannot substitute for query parameters.

Message pages use ascending ID as the unique chronology, including messages
created in the same second. The first page captures a high-water `through_id` and
adds it to pagination links; following those links keeps the page set stable as
new completed turns are appended. `through_id` can be zero for an empty snapshot
and never changes ownership scope. To refresh, omit it. Conversation list ordering
is deterministic but naturally moves when conversations receive new turns.

Public message fields are `id`, `role`, `content`, `content_truncated`, `tool_calls`,
`tool_calls_truncated`, `tool_result`, `created_at`. System-role records are excluded.
User/assistant text is visible; legacy content above 16000 characters is explicitly
truncated. Tool content is always null. Assistant tool activity exposes only
`call_id`/`name` (maximum 32); tool results expose only `call_id`, `name`, `ok` and
an allowlisted H1 `error_code`. Arguments, full tool data, encrypted state and
provider internals are never public. Newly generated H2 content is already within
these limits, so public truncation primarily protects existing legacy records.

Sending requires a meaningful UTF-8 string of at most 4000 characters, not merely
whitespace/invisible characters or invalid control characters. Content is preserved,
not silently trimmed. Unknown fields, body lists/scalars, malformed JSON, form
submissions and POST query parameters fail. Owner IDs, timezone, model, prompts,
keys, tool definitions and budgets are never client-selectable. Nonempty GET bodies
are rejected; conventional empty test/client payloads `[]`/`{}` are treated as no body.
Authorization runs before content validation. Foreign/missing/invalid conversation
IDs produce the same `404 {"message":"Not found."}`.

```http
POST /api/ai/conversations/12/messages
Content-Type: application/json
Accept: application/json
Idempotency-Key: 8dc76914-94ef-4460-92ce-e572638ad9ba

{"content":"What classes do I have tomorrow?"}
```

```json
{
  "data": {
    "conversation_id": 12,
    "user_message_id": 40,
    "assistant_message_id": 43,
    "content": "Your classes for tomorrow are ...",
    "tools": [{"call_id":"call_1","name":"get_schedule_for_date","ok":true,"error_code":null}]
  }
}
```

The answer above is illustrative; real text comes from the provider after tool
execution. A send is never successful before the transcript and replay result
commit. Resending the same UUID/content returns the same persisted result and IDs
without another provider request. Reusing the key with different content is 409.
Idempotency scope is one conversation; another conversation may use the same key.

## Persistence, concurrency and failure policy

Existing `AiConversation`, `AiMessage`, roles, fields and historical records are
reused unchanged. Successful transcripts append a user message, one assistant
message for each provider round, correlated tool messages, and the final assistant
message. Intermediate assistant content may be null. Assistant `tool_calls` holds
versioned metadata `{version: 1, calls, state}`. Tool `content` holds the exact H1
envelope; `tool_call_id` holds the provider call identifier. Internal system
instructions, credentials, headers and arbitrary raw provider response objects
are not persisted. The conversation timestamp is touched only on completed turns.

One additive migration creates `ai_conversation_turns` and a
`(conversation_id, id)` message index for bounded chronological/history queries.
The turn table has:

- conversation FK with cascade deletion;
- unique `(conversation_id, request_id)` UUID key;
- SHA-256 input hash binding the key to exact content;
- nullable UUID lease token and microsecond UTC lease expiry;
- nullable safe public completed-result JSON for durable replay;
- normal timestamps and a conversation/lease lookup index.

This is execution coordination, not a second conversation system, permanent
memory, business-data snapshot or generic audit framework. It is needed because
existing conversation/message fields cannot safely represent a durable active
claim and every committed request key across later turns. No existing columns,
constraints or message contents are replaced. Migration rollback/reapply tests
verify existing conversations/messages survive.

Claiming locks only the owned conversation row inside a short outermost transaction.
It checks key/hash/replay, rejects any other unexpired claim, and creates/replaces
a random lease token. The lock is released before history loading, tools or HTTP.
Lease comparisons and expiry use MySQL `UTC_TIMESTAMP(6)`, avoiding inter-worker
application-clock skew. The orchestrator checks claim currency between operations;
final persistence locks the conversation again and rechecks token and expiry.

All transcript inserts, conversation touch, replay-result persistence and claim
release commit together. A stale worker gets `turn_expired` and writes no messages.
An ordinary failure clears only its own token. Process death/database outage may
leave a claim until its finite expiry; a later request can recover without a manual
unlock. An old worker's cleanup cannot release a replacement token. Different
conversations have independent locks and can run concurrently.

Failed turns retain only the request coordination row/hash, not a partial user,
assistant or dangling tool transcript. A client may retry the same key/content
after failure or expiry. An ambiguous provider timeout can cost another read-only
inference on client retry, but cannot duplicate a committed turn or mutate study
data. The server performs no automatic provider retries. Durable replay also
handles a lost HTTP response after successful database commit. Request records
are retained until conversation/account deletion; pruning/export policies are
future operational work.

Both the conversation send Service and real provider adapter reject an enclosing
database transaction. Network work therefore cannot accidentally run under a
caller-owned transaction. No database lock or transaction spans provider waiting.

## Execution limits and public failures

| Setting | Default | Accepted maximum |
| --- | --- | --- |
| Provider requests per turn (`max_tool_rounds`) | 5 | 20 |
| Total tool calls, including failures | 8 | 32 |
| Historical messages selected | 20 | 100 |
| Serialized context/request/transcript bytes | 131072 | 1048576 |
| One complete serialized tool result | 65536 | 262144 |
| Provider output tokens | 2048 | 8192 |
| Provider timeout seconds | 30 | 120 |
| Turn wall-clock budget seconds | 180 | 600 |
| Durable lease seconds | 240 | 900 |
| Message requests per user/minute | 10 | 60 |

Every configured value must be a positive integer; lease duration must exceed the
turn budget. The provider limit includes the final-answer request, so five rounds
permit at most four tool-bearing rounds followed by an answer. Multiple calls in
one response are supported and executed deterministically in response order.
If an entire batch exceeds the remaining call budget, none of it executes.

History selection drops a leading partial turn rather than retaining orphaned
tool outputs. This can supply fewer than the configured message count. Complete
selected history/context that exceeds the byte budget fails explicitly rather
than silently trimming tool results. H1 task/session truncation metadata remains
intact. A 1 MiB provider-body cap is checked before parsing; assistant text is
bounded at 16000 characters and output arrays at 64 items. The byte cap is checked
after the HTTP body is received, not a streaming download quota. Wall-clock/lease
checks run between bounded operations; an in-flight provider request can run until
its own timeout before the turn budget is rechecked.

Validation uses standard Laravel 422 `message`/`errors`. Unauthenticated access is
401; CSRF and rate limiting retain Laravel 419/429 behavior. Application errors use
`{"message":"fixed safe message","code":"stable_category"}`:

| Code | Status | Meaning |
| --- | --- | --- |
| `ai_unavailable` | 503 | Missing/unsupported configuration, invalid limits, upstream 401/403/404 |
| `provider_error` | 502 | Timeout/connection failure, 429, 5xx, other non-success responses including 400 and redirects |
| `invalid_provider_response` | 502 | Bad JSON/structure/call IDs, duplicate IDs, missing encrypted reasoning, no answer, incomplete output or detected secret/instruction echo |
| `conversation_busy` | 409 | An unexpired turn already owns this conversation |
| `turn_expired` | 409 | Worker no longer owns a current lease |
| `idempotency_conflict` | 409 | Same request key, different exact content |
| `execution_limit_reached` | 422 | Call/round/time/context/tool-output budget exceeded; narrow the request |
| `persistence_error` | 500 | Transaction misuse, database failure or unexpected internal failure |

No upstream error body, SQL, stack trace, foreign identifier or credential is
echoed, including when APP_DEBUG is enabled. Actual H1 error results may be passed
back to the model for clarification/explanation and remain `ok: false` in history
and public tool summaries. They are never fabricated as successful results.

## Security and privacy boundaries

The authenticated User comes exclusively from Laravel. Services scope conversation
reads and locks through that owner's relationships, and the Policy hides foreign
records. H1 retains all owner checks and safe presentation. No model output can
change the registry, lease ownership, provider credentials or authorization rules.

User messages, prior assistant text and user-authored tool fields remain untrusted
lower-authority data. Instructions explicitly reject injected policies. Server
capabilities, not model compliance, enforce read-only behavior. Verbatim long
instruction-line echoes and transport-key echoes are rejected; this is an extra
disclosure check, not proof against all semantic prompt injection or hallucination.
The LLM's factual explanation/clarification behavior still requires real-model
evaluation; deterministic ownership, tool validity and mutation restrictions do not.
Clients must render assistant text safely rather than treating it as trusted HTML.

`store: false` avoids provider stored-response conversation state; MySQL is the
authoritative history. Bounded user text, authorized tool results and encrypted
continuation still cross the provider boundary. Do not describe this as automatic
Zero Data Retention: official [OpenAI data controls](https://developers.openai.com/api/docs/guides/your-data)
describe separate abuse-monitoring retention and account-specific ZDR controls.
The provider's normal API data-training/retention policies and project access
settings still apply. No remote Conversations API object is created.

## Testing and optional manual smoke

Automated tests require no real API key or external inference. Pure orchestration
tests mock the narrow provider and H1 executor and inspect definitions, arguments,
owner identity, handoff, budgets and event order. Provider tests use Laravel HTTP
fakes against native Responses fixtures. Chat Feature and independent-process
tests run on guarded real MySQL `student_planner_testing`, using committed fixtures
and DatabaseTruncation/DatabaseMigrations rather than SQLite.

Independent workers exercise actual authenticated HTTP requests in separate PHP
processes/PDO connections. Gates hold the fake provider outside transactions while
other requests contend, recover expired leases, reject stale finalization, replay
committed keys and complete separate conversations. Workers never contact an LLM,
migrate or truncate. Business-table snapshots, Queue/Bus/Notification fakes, safe
public history and persisted call-ID associations verify the read-only boundary.

For an optional manual smoke test, configure a real project key/model locally,
follow the existing [Sanctum login/CSRF sequence](auth-profile.md#local-vue-integration),
then use a credentialed API client to create a conversation and send the example
above with a fresh UUID. Keep the rotated session cookie and URL-decoded
`X-XSRF-TOKEN` header. Ask about tomorrow, pending tasks, free time and a progress
comparison; inspect the bounded tool summary and saved history. Retry one exact
request key to confirm identical message IDs. Such manual calls incur provider
usage; none were performed for this implementation.

Verification uses the guarded MySQL test database and PHP 8.5.11 CLI. Production
syntax retains the repository's PHP ^8.3 / Laravel ^13.17 conventions. No live
inference/key, SQLite substitute or additional package was used.

| Focused class | Tests | Assertions |
| --- | ---: | ---: |
| `AgentOrchestratorTest` (pure Unit) | 23 | 199 |
| `OpenAiResponsesProviderTest` (HTTP fakes) | 37 | 294 |
| `AiChatTest` (real MySQL/REST) | 45 | 524 |
| `AiChatConcurrencyTest` (independent processes) | 4 | 61 |
| `AiChatPersistenceTest` (schema/rollback) | 2 | 36 |
| Total H2 | 111 | 1114 |

| Command | Exit | Result |
| --- | ---: | --- |
| `vendor/bin/phpunit --filter='AgentOrchestratorTest\|OpenAiResponsesProviderTest\|AiChat' --log-junit /tmp/h2-focused.xml` | 0 | 111 tests, 1114 assertions |
| Affected regression command below | 0 | 2780 tests, 26768 assertions |
| `vendor/bin/phpunit --log-junit /tmp/h2-full.xml` | 0 | 4729 tests, 40053 assertions |
| `vendor/bin/pint --dirty` | 0 | Passed after formatting new files |
| `vendor/bin/pint --test` | 0 | Passed |
| `git diff --check` | 0 | Passed |

```sh
vendor/bin/phpunit --filter='AiTool|AiPersistence|AuthProfile|Security|ScheduleView|Lesson|Task|StudySession|FreeTimeService|FreeSlotsApi|TimeIntervalService|AvailabilityOccurrenceService|Progress|Reminder|Notification|PlanningMigration' --log-junit /tmp/h2-regression.xml
```

The affected run covers unchanged H1 tools/integration, AI persistence, Sanctum
authentication/profile, schedule/Lesson and Task security/lifecycle, StudySession
and PF1 reads, G1/G2a/G2b, reminders/inbox, and migration rollback. It reported no
failures or skips. The final H2 run also had no failures or skips. Initial test
failures exposed empty GET-payload handling, MySQL JSON key ordering on replay and
fixture normalization; these were corrected without weakening existing tests.

Stage H2 acceptance: complete. The full suite passed without failures or skips.
Starting and ending HEAD are `dc0ab1b6fc87ae6b0f122cc849491789ec0937a1`.
The starting working tree was clean; the final tree has 10 modified tracked files
and 24 new files (34 total), all left uncommitted. No commit or push was performed.

## Changed files

- `.env.example`
- `app/Exceptions/AiAgentException.php`
- `app/Http/Controllers/Api/AiConversationController.php`
- `app/Http/Requests/AiConversationIndexRequest.php`
- `app/Http/Requests/AiConversationRequest.php`
- `app/Http/Requests/SendAiMessageRequest.php`
- `app/Http/Resources/AiConversationResource.php`
- `app/Http/Resources/AiMessageResource.php`
- `app/Policies/AiConversationPolicy.php`
- `app/Providers/AppServiceProvider.php`
- `app/Services/AI/AgentLimits.php`
- `app/Services/AI/AgentOrchestrator.php`
- `app/Services/AI/AiConversationService.php`
- `app/Services/AI/LlmProvider.php`
- `app/Services/AI/OpenAiResponsesProvider.php`
- `app/Services/AI/OpenAiToolSchema.php`
- `bootstrap/app.php`
- `config/ai.php`
- `database/migrations/2026_10_16_000001_create_ai_conversation_turns_table.php`
- `docs/ai-agent-chat.md`
- `docs/ai-tools-foundation.md`
- `docs/architecture.md`
- `docs/business-rules.md`
- `docs/database-review-notes.md`
- `docs/database-schema.md`
- `docs/domain-model.md`
- `routes/api.php`
- `tests/Concerns/InteractsWithAiResponses.php`
- `tests/Feature/AiChatConcurrencyTest.php`
- `tests/Feature/AiChatPersistenceTest.php`
- `tests/Feature/AiChatTest.php`
- `tests/Feature/OpenAiResponsesProviderTest.php`
- `tests/Support/ai_chat_worker.php`
- `tests/Unit/AgentOrchestratorTest.php`

## Remaining boundaries

H3 still owns all mutating tools, confirmation policy and writer integration.
Streaming, voice/images, retrieval/vector storage, permanent agent memory,
multi-provider routing, pruning and a frontend chat UI are not implemented.
H1's full-range source-memory and mutable-history limitations remain unchanged.
Separate read tools do not share one global snapshot. Large contexts fail safely;
there is no summarization, token estimation or silent provider truncation.
Legacy unstructured tool-message histories may lack required call associations;
they are not rewritten into fabricated H2 turns and may require a new conversation.
