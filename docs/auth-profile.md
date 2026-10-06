# Authentication + User Profile

Implemented mode: Laravel Sanctum first-party SPA cookies/sessions with Laravel's
`web` guard. No bearer token is returned or required. The existing token table is
preserved. Controllers use Form Requests and UserResource without additional
Services or authentication packages.

| Method | Route | Access | Success |
| --- | --- | --- | --- |
| POST | `/api/auth/register` | Public SPA | 201 |
| POST | `/api/auth/login` | Public SPA | 200 |
| POST | `/api/auth/logout` | `auth:sanctum` | 204 |
| GET | `/api/profile` | `auth:sanctum` | 200 |
| PATCH | `/api/profile` | `auth:sanctum` | 200 |

`GET /sanctum/csrf-cookie` is Sanctum's existing web endpoint (204), outside
`/api`. The scaffold `/api/user` is removed. No `/api/v1` prefix is used.

Registration accepts `name`, `email`, `password`, `password_confirmation`, and
optional `timezone`. Password validation uses Laravel's default Password rule
(minimum eight characters) and confirmation; the existing hashed model cast
stores it. Omitted timezone uses the existing database default `UTC`.
Login accepts `email` and `password`. Invalid credentials return the same standard
422 validation error for unknown email and incorrect password.

PATCH accepts any subset of `name`, `email`, and `timezone`; supplied values must
be nonempty. Email is unique, ignoring only the trusted authenticated user's
record. Timezone uses Laravel's IANA identifier validation, such as `Europe/Kyiv`
or `UTC`; raw offsets such as `+03:00` are rejected. Unknown fields are ignored.
Profile passwords, client-supplied IDs, verification timestamps, and remember
tokens cannot be changed. Profile operations derive the user solely from request
authentication context. There is no arbitrary-user profile route.

Registration, login, and profile return the same standard Resource envelope:

```json
{"data":{"id":1,"name":"Student","email":"student@example.test","timezone":"UTC"}}
```

Password/hash, remember token, authentication metadata, and model relationships
are never serialized. Validation errors use Laravel's `message` / `errors`
shape and 422 status. Private endpoints return 401 for unauthenticated requests.
Logout returns an empty 204, invalidates the current session, and regenerates the
CSRF token. Password reset/change and email verification are deferred.

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
