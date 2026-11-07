# Stage 12 — Password API contract

Backend base: `628ec5da71acb5c560afd82c812d9effcbad0182` (Gemini/OpenAI support preserved).
All responses are JSON. Send `Accept: application/json` and `Content-Type: application/json`.
No endpoint returns a password, password hash, remember token, reset token, reset URL or job ID.
Existing registration/login/logout/profile routes and their response shapes are unchanged.

| Method | Endpoint | Access | Success |
| --- | --- | --- | --- |
| PATCH | `/api/profile/password` | Authenticated first-party SPA session | 200 |
| POST | `/api/auth/forgot-password` | Public | 202 |
| POST | `/api/auth/reset-password` | Public | 200 |

## Authentication and CSRF

Use the existing Sanctum cookie flow: `GET /sanctum/csrf-cookie`, then send cookies
with `credentials: include` / `withCredentials: true` and the decoded `XSRF-TOKEN`
cookie in `X-XSRF-TOKEN`. Send the configured Vue `Origin` or `Referer`.
All three writes enforce the existing CSRF middleware for stateful SPA requests;
there are no CSRF exemptions. A missing/invalid CSRF header returns 419. An
unauthenticated password change with valid CSRF returns 401
`{"message":"Unauthenticated."}`. A password change also requires an attached SPA
session, consistently with login/registration (otherwise 422 under `session`).

The two recovery endpoints also accept stateless public JSON requests without
SPA cookies. They do not authenticate a user, issue a bearer token or bypass
reset-token validation. Browser clients should use the normal stateful flow.

## Change password

```http
PATCH /api/profile/password
```

```json
{
  "current_password": "existing-password",
  "password": "new-secure-password",
  "password_confirmation": "new-secure-password"
}
```

```json
{"message":"Password changed successfully."}
```

All three fields are required strings. `current_password` is checked with Laravel's
`current_password:web` rule and rechecked against the fresh locked User before any
write. New passwords use `Password::defaults()` (currently minimum 8 characters),
confirmation and an upper bound of 1024 characters. With the configured bcrypt
hasher, they must additionally contain no NUL bytes and fit in **72 UTF-8 bytes**;
this prevents truncation and hashing errors for multibyte input. Passwords are not
trimmed. Unknown request fields, including `user_id` and `remember_token`, are
ignored and never select an account.

The password is hashed using Laravel. The remember token is rotated; outstanding
recovery links for this account are invalidated. Other database sessions for the
account are deleted, including legacy sessions lacking a password fingerprint.
The current authenticated session survives with a **new session ID** and an updated
Sanctum password fingerprint. Other users' sessions are untouched.

An incorrect current password returns normal 422 validation errors, for example:

```json
{
  "message": "The password is incorrect.",
  "errors": {"current_password": ["The password is incorrect."]}
}
```

## Request recovery

```http
POST /api/auth/forgot-password
```

```json
{"email":"student@example.test"}
```

Always **202** for syntactically valid requests accepted by the queue:

```json
{"message":"If the email address is eligible, you will receive a password reset link."}
```

This is identical for existing, unknown and Broker-throttled accounts. There is no
`exists` validation or account lookup in the HTTP request. Every accepted address
queues the same encrypted, email-only `SendPasswordResetLink` job. A worker invokes
the standard Laravel Password Broker, which owns hashing, token storage and resend
throttling. SMTP latency/failure and account lookup therefore do not affect the
HTTP response. Acceptance does not guarantee delivery; the queue worker must run.

Tokens use the **existing `password_reset_tokens` table**, expire after **60 minutes**,
and may be regenerated at most once per account per **60 seconds**, as configured
in `config/auth.php`. Throttled jobs send no additional notification. After the
resend delay, a new token replaces the old token.

The standard Laravel reset notification contains this exact link shape:

```text
https://planner.example.com/reset-password?token=<URL-encoded-token>&email=<URL-encoded-email>
```

Both query values use RFC 3986 encoding. The origin comes exclusively from
server-side `FRONTEND_URL`; Host, Origin, Referer, email domain and client redirect
fields cannot change it. Configure an absolute HTTP(S) **origin**, with no user
credentials, path, query or fragment; a trailing slash is allowed. HTTPS is
required in production. The Backend does not add a reset-page GET route.

## Reset password

```http
POST /api/auth/reset-password
```

```json
{
  "email": "student@example.test",
  "token": "token-from-the-email-link",
  "password": "new-secure-password",
  "password_confirmation": "new-secure-password"
}
```

Success, **200**:

```json
{"message":"Password reset successfully. Sign in with your new password."}
```

Email, token, password and confirmation are required strings. Email must be valid
and at most 255 characters; the token is at most 256 characters. The new password
uses the same rules as change-password. Validation errors do not consume a token.

Unknown accounts, wrong-email tokens, invalid, expired and reused tokens all return
this same **422** response:

```json
{
  "message":"This password reset token is invalid or expired.",
  "errors":{"token":["This password reset token is invalid or expired."]}
}
```

The Broker validates the token; a User row lock serializes changes/resets before
validation. Password save, remember-token rotation, all target-account session
revocations, `PasswordReset` event and Broker token consumption share one database
transaction. A successful token cannot be reused. No automatic login occurs.

**Every existing session for the reset account is revoked.** If this request is
made from that account's logged-in browser, its guard/session/CSRF token are also
cleared. An anonymous browser stays anonymous. If an unrelated account is already
logged into the requesting browser, that unrelated login is retained; resetting a
target account never revokes another account's sessions.

## Other errors and limits

Laravel's normal **422** `message` + `errors` format applies to missing/malformed
fields, password length, confirmation and current-password errors. For example,
a malformed email returns an `errors.email` array. Clients should use the field
arrays; Laravel's aggregate validation message may summarize multiple failures.

- Forgot-password: **5 requests/minute/IP**.
- Reset-password: **10 requests/minute/IP**.
- Change-password: **5 requests/minute/authenticated user**.

Limits are independent. Exceeding one returns **429** with Laravel's standard
`{"message":"Too Many Attempts."}` and `Retry-After` header. Use a shared production
cache and correctly configured trusted proxies. Broker resend throttling is separate
and never changes the public 202 response.

Unsupported storage, invalid delivery configuration, enqueue failure or unexpected
password-operation errors return **503**, even with `APP_DEBUG=true`:

```json
{"message":"Password management is temporarily unavailable."}
```

Password writes require the project's configured **database session driver**, with
sessions, users and the database Password Broker on the same Laravel connection.
Other session drivers/cross-connection configurations fail before password writes;
the API does not promise partial revocation on unsupported storage.

## Frontend and deployment integration

Configure server secrets and mail delivery as described in
[backend setup](../backend-integration-readiness.md#password-management-stage-12).
Production recovery requires an asynchronous database/Redis/SQS/Beanstalkd queue
and a direct delivery mail transport. Sync/deferred/null/failover queues and
log/array/composite mailers are rejected in production. SMTP timeout is 15 seconds.
The encrypted recovery job makes one delivery attempt. Failures log only the fixed
`password.delivery_failed` marker; operators should fix delivery and request a new
link after the Broker delay rather than replaying failed delivery automatically.

Frontend `/reset-password` should read the two query values, collect/confirm a new
password, initialize CSRF and POST them to the Backend. Remove the token from
browser history when practical, avoid URL analytics, and use `Referrer-Policy:
no-referrer` on that page. Show the same recovery acknowledgement for every 202.
After reset, discard target-account client auth state, fetch a fresh CSRF cookie
and show login. After change, keep the current login and consume rotated cookies.
Handle 401/419/422/429/503 without displaying internal diagnostics. No Frontend
implementation was changed in this milestone.
