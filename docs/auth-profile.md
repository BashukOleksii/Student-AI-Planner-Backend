# Authentication + User Profile

Stage 12 adds `PATCH /api/profile/password`, `POST /api/auth/forgot-password` and
`POST /api/auth/reset-password`. Existing routes below retain their response
contracts. Password updates use the existing Broker/token table and database
sessions; reset delivery is queued and links use a trusted frontend origin.
See the [exact password contract](stage12/password-api-contract.md) for JSON,
CSRF, throttles, session revocation and operational requirements.

H4 verifies the existing backend through cookie/CSRF HTTP workflows and adds strict
configuration checks, safe AI diagnostics, retry-header CORS exposure and offline CI.
Domain rules and confirmation transactions remain unchanged. See [backend readiness](backend-integration-readiness.md)
and [the frontend REST contract](frontend-api-contract.md) for current setup and recovery.

Implemented mode: Laravel Sanctum first-party SPA cookies/sessions with Laravel's
`web` guard. No bearer token is returned or required. The existing token table is
preserved. Controllers use Form Requests and UserResource without additional
authentication packages. Profile updates now use `ProfileService` to serialize
email changes with voluntary verification; see [the verification contract](email-verification.md).

| Method | Route | Access | Success |
| --- | --- | --- | --- |
| POST | `/api/auth/register` | Public SPA | 201 |
| POST | `/api/auth/login` | Public SPA | 200 |
| POST | `/api/auth/logout` | `auth:sanctum` | 204 |
| GET | `/api/profile` | `auth:sanctum` | 200 |
| PATCH | `/api/profile` | `auth:sanctum` | 200 |
| POST | `/api/profile/email/verification-notification` | `auth:sanctum`, throttle | 200 |
| POST | `/api/profile/email/verify/{id}/{hash}?expires=…&signature=…` | `auth:sanctum`, throttle, relative signature | 200 |

`GET /sanctum/csrf-cookie` is Sanctum's existing web endpoint (204), outside
`/api`. The scaffold `/api/user` is removed. No `/api/v1` prefix is used.

Registration accepts `name`, `email`, `password`, `password_confirmation`, and
optional `timezone`. Password validation uses Laravel's default Password rule
(minimum eight characters) and confirmation; the existing hashed model cast
stores it. Omitted timezone uses the existing database default `UTC`.
Login accepts `email` and `password`. Invalid credentials return the same standard
422 validation error for unknown email and incorrect password. Registration/login
reject requests without an attached SPA session before account creation or
authentication, returning standard 422 errors under `session`. This occurs when
Sanctum does not recognize the Origin/Referer as a configured stateful frontend.
The check avoids a server exception and partial registration. A configured Referer alone is valid.

PATCH accepts any subset of `name`, `email`, and `timezone`; supplied values must
be nonempty. Email is unique, ignoring only the trusted authenticated user's
record. Timezone uses Laravel's IANA identifier validation, such as `Europe/Kyiv`
or `UTC`; raw offsets such as `+03:00` are rejected. Unknown fields are ignored.
Profile passwords, client-supplied IDs, verification timestamps, and remember
tokens cannot be changed. Profile operations derive the user solely from request
authentication context. There is no arbitrary-user profile route.

Registration, login, and profile return the same standard Resource envelope:

```json
{"data":{"id":1,"name":"Student","email":"student@example.test","email_verified":false,"timezone":"UTC"}}
```

Password/hash, remember token, authentication metadata, and model relationships
are never serialized. Validation errors use Laravel's `message` / `errors`
shape and 422 status. Private endpoints return 401 for unauthenticated requests.
Logout returns an empty 204, invalidates the current session, and regenerates the
CSRF token. Password reset/change uses the existing [password contract](stage12/password-api-contract.md).
Email verification is voluntary: only an explicit request sends a letter; an
authenticated signed POST verifies the current address. Actual email changes
atomically reset verification; other profile changes preserve it. Unverified
accounts keep application access. The Vue confirmation page is not implemented
by this backend change; see [link format, API examples and checks](email-verification.md).

## Authentication throttles and trusted proxy deployment

Stage 20B.15A attaches named Laravel limiters to the existing public SPA endpoints:

| Endpoint | Limiter | Limits |
| --- | --- | --- |
| `POST /api/auth/login` | `auth-login` | 5 requests/minute per normalized email + client IP, and 30 requests/minute per client IP |
| `POST /api/auth/register` | `auth-register` | 10 requests/hour per client IP |

Every request reaching the throttle counts, including successful authentication
and validation failures. Login identities use trimmed, lowercase email only for
the limiter; credential validation and authentication remain unchanged. SHA-256
replaces the email in the identity key. Explicit `auth:login:identity:`,
`auth:login:ip:` and `auth:register:ip:` prefixes keep buckets separate; Laravel
also namespaces/hashes named-limiter cache keys. Malformed non-string emails use
an empty identity for throttling and still receive normal validation errors.
Changing identities cannot bypass the aggregate login IP limit. These are
temporary request limits, not persistent account locks.

Exceeded limits use Laravel's normal 429 response and `Retry-After`,
`X-RateLimit-Limit`, `X-RateLimit-Remaining` and `X-RateLimit-Reset` headers.
The existing CORS configuration exposes retry/rate headers for browser clients.
Production `CACHE_STORE=database` shares counters between PHP workers using the
existing cache table. No migration is needed. Password-recovery and AI limiters
retain their existing definitions.

`bootstrap/app.php` trusts only the immediate proxy address `127.0.0.1` and only
`X-Forwarded-For`, `X-Forwarded-Proto`, `X-Forwarded-Host` and `X-Forwarded-Port`.
Laravel/Symfony derives the real client IP and external HTTPS URL from that
trusted peer. Other peers, including other loopback addresses and `::1`, cannot
override their IP, scheme, host or port using forwarded headers. RFC `Forwarded`
and forwarded-prefix headers are outside this trust configuration.

For the Lightsail topology, keep the internal Nginx listener bound to
`127.0.0.1:8080`. The public TLS Nginx must overwrite incoming forwarded headers
with values established by that Nginx, for both `/api/*` and `/sanctum/*`:

```nginx
proxy_set_header Host $host;
proxy_set_header X-Forwarded-For $remote_addr;
proxy_set_header X-Forwarded-Proto $scheme;
proxy_set_header X-Forwarded-Host $host;
proxy_set_header X-Forwarded-Port $server_port;
```

Restrict the public virtual host to the intended domain. The internal Nginx must
preserve these external forwarded values through FastCGI instead of replacing
the scheme/port with its internal HTTP values. PHP's `REMOTE_ADDR` must remain
the immediate local proxy address; review any Nginx real-IP rewriting before
deployment. Header trust depends on the public proxy sanitizing client input.
No Nginx configuration is changed by this backend patch.

Keep HTTPS secure cookies, stateful domains, CORS origins and `APP_URL` aligned
with `https://student-ai-planner.duckdns.org` in the deployment environment.
Refresh Laravel's configuration/route caches and reload long-running application
workers as part of the normal reviewed deployment. Verify a complete CSRF cookie,
login, profile and logout cycle through the public TLS endpoint, including 429
retry headers and separate client-IP buckets. Do not log credentials or cookies.
This change does not modify `.env`, deploy to AWS, or alter migrations.

## Local Vue integration

Use backend `http://localhost:8000` and frontend `http://localhost:5173`:

```dotenv
APP_URL=http://localhost:8000
SANCTUM_STATEFUL_DOMAINS=localhost:5173,localhost:8000
CORS_ALLOWED_ORIGINS=http://localhost:5173
SESSION_DRIVER=database
SESSION_DOMAIN=null
SESSION_SECURE_COOKIE=false
```

Copy the relevant `.env.example` values into the local `.env`; existing `.env`
files do not gain new entries automatically. Set `APP_KEY`, MySQL credentials,
and apply the existing migrations (`php artisan migrate`) so users and sessions
tables exist. After configuration changes, run `php artisan config:clear`.

Stateful domains are comma-separated hosts with ports and no scheme. CORS
origins are comma-separated full origins including scheme and port; credentials
are enabled and wildcard origins should not be used. CORS defaults to no allowed
cross-origin callers unless the environment supplies origins. Do not mix
`localhost` and `127.0.0.1`; if using the latter, configure both origins/hosts
consistently. First-party production SPA and API must share the same top-level
domain. Configure actual deployment origins/stateful domains in the environment,
a shared session cookie domain when using subdomains, and secure cookies over
HTTPS. No production domain is hard-coded.

The browser sequence is:

1. Enable credentialed requests (`credentials: 'include'` for fetch, or Axios
   `withCredentials: true` and `withXSRFToken: true`) and `Accept: application/json`.
2. Request `GET /sanctum/csrf-cookie` on the backend before registration/login.
3. Send the URL-decoded `XSRF-TOKEN` cookie value as `X-XSRF-TOKEN` on mutations
   (Axios performs this with the options above). Send the session cookie on all
   API requests. Browsers supply Origin/Referer; manual clients must supply a
   configured frontend Origin/Referer to activate Sanctum's stateful API stack.
4. POST registration/login, then access `/api/profile` with cookies. Authentication
   rotates the session, so retain the latest cookies and CSRF token.
5. POST logout with credentials/CSRF. Obtain fresh CSRF cookies before logging in
   again. Handle 401 as unauthenticated and 419 as expired/missing CSRF state.

HTTP Feature tests use the existing MySQL `student_planner_testing` setup.
`AuthProfileTest` covers public shape, validation, isolation, session lifecycle,
CSRF enforcement (explicitly enabled for its browser test), and CORS preflight.
