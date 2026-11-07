# Stage 12 Backend result

Approved base: `628ec5da71acb5c560afd82c812d9effcbad0182`.
Refreshed local and remote `main` matched this SHA with a clean working tree.
Implementation branch: `feat/stage12-password-management`.

## Implemented contract

The exact request/response JSON, authentication, CSRF, errors, reset-link format
and session policy are in [password-api-contract.md](password-api-contract.md).

| Endpoint | Success | Behavior |
| --- | --- | --- |
| `PATCH /api/profile/password` | 200 | Verify current password, hash new password, rotate remember token, invalidate outstanding reset links and other owned sessions; preserve current login with rotated session ID. |
| `POST /api/auth/forgot-password` | 202 | Same encrypted queue job and acknowledgement for every valid email; worker calls Laravel Password Broker; no account lookup or SMTP delivery in the request. |
| `POST /api/auth/reset-password` | 200 | Broker validation, password/remember-token update, all owned session revocation, reset event and single-use token consumption in one transaction; no automatic login. |

Recovery tokens retain the existing 60-minute expiry and 60-second resend throttle.
Per-minute route limits are 5/IP for recovery, 10/IP for reset and 5/user for change.
Invalid/expired/reused/wrong-account tokens share a generic 422 response. Production
mail and queue guards prevent token logging and synchronous enumeration channels.
Trusted reset links use only `FRONTEND_URL`, never request hosts or redirects.

## Changed files

New application files:

- `app/Http/Controllers/Api/PasswordController.php`
- `app/Http/Requests/Auth/ChangePasswordRequest.php`
- `app/Http/Requests/Auth/ForgotPasswordRequest.php`
- `app/Http/Requests/Auth/ResetPasswordRequest.php`
- `app/Services/Auth/PasswordManagementService.php`
- `app/Jobs/SendPasswordResetLink.php`
- `app/Rules/BcryptPassword.php`

Modified application/configuration files:

- `app/Http/Controllers/Api/AuthController.php`: initialize Sanctum password
  fingerprints at login/registration, including sessions that have not yet read
  a protected endpoint; public response shapes remain unchanged.
- `app/Providers/AppServiceProvider.php`: trusted reset URL callback and route limits.
- `bootstrap/app.php`: scoped, redacted password failure reporting/responses and
  reset-token exclusion from flashed input.
- `routes/api.php`: three additive routes; existing routes retained.
- `config/app.php`, `config/mail.php`, `.env.example`: trusted frontend origin,
  SMTP example and bounded SMTP timeout.

Tests/documentation:

- `tests/Feature/PasswordManagementTest.php`: seven focused security/use-case tests.
- `tests/Concerns/InteractsWithSpaBrowser.php`: clear the cached guard binding and
  restore the web default between simulated browser requests, matching fresh HTTP
  requests and correctly recording database-session ownership.
- `docs/stage12/password-api-contract.md`, `docs/stage12/backend-result.md`.
- `docs/auth-profile.md`, `docs/backend-integration-readiness.md`, `README.md`.

## Verification

Targeted validation passed: **71 tests, 8,279 assertions** across the seven new
password tests plus existing AuthProfile, BackendIntegrationSmoke and
FrontendApiContract tests, using a newly initialized isolated MySQL 8.4 instance.
Notification fakes prevented real delivery; queue tests exercised encryption,
actual queue consumption and Broker behavior. No real SMTP or paid AI calls ran.

Coverage includes current-password rejection, validation/CSRF/401 handling,
current-session preservation, other-session revocation/owner isolation, uniform
recovery acknowledgement, trusted URL encoding, hashed token storage, resend/IP
limits, successful reset, invalid/expired/reused tokens, remember-token rotation,
old-password rejection, explicit new-password login, own-browser logout and
redacted delivery/configuration failures.

Pint passed. The **single complete PHPUnit run** is delegated to the established
[GitHub Backend verification workflow for this branch](https://github.com/BashukOleksii/Student-AI-Planner-Backend/actions/workflows/backend-verification.yml?query=branch%3Afeat%2Fstage12-password-management).
Its check status and retained JUnit artifact provide the full-suite result for the
exact final commit. The workflow also retains its existing Gemini action regression
step. The final SHA, run URL and outcome are included in the PR/completion report;
local verification runs only the focused set above.

## Limits and operations

- No new migration, dependency, Frontend change or production deployment.
- Gemini/OpenAI adapters, provider configuration, all 27 AI tools, domain
  confirmation workflows and their Services remain unchanged.
- Full atomic session invalidation requires existing database sessions and Broker
  storage on the User connection. Other storage configurations fail closed.
- Existing app authentication is first-party Sanctum sessions; it does not issue
  personal access tokens. This feature does not add token authentication.
- Production must provide a stable APP_KEY, asynchronous queue worker, direct mail
  transport and HTTPS FRONTEND_URL. Mail delivery is operationally asynchronous;
  202 means accepted, not delivered. Delivery failures use a fixed diagnostic and
  are not automatically retried. No live SMTP acceptance/delivery was verified.
- HTTP operation diagnostics omit secrets; deployments must also avoid external
  request-body capture and frontend URL analytics containing reset tokens.
- Full-suite database rebuilds are restricted to the existing disposable hosted CI
  MySQL service. No retained or application database was altered.
