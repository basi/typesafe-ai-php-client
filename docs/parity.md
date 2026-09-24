# Parity with the official Python SDK

Comparison against `typesafe-sdk` 0.7.1, the official Python client for the same API. This client
intentionally mirrors most of that SDK's HTTP behaviour, so that request formation and retry rules
are unsurprising to anyone coming from Python — but it diverges in a few places, called out below,
either to suit PHP or to make one specific consequence of retries (double-charging a probabilistic
POST) opt-in rather than a silent default.

| Feature | Python (`typesafe-sdk` 0.7.1) | This client (`basi/typesafe-ai-php-client`) | Note |
|---|---|---|---|
| Endpoints | `POST /v1/systemone`, `GET /v1/models` | Same | Full parity. |
| Auth | `Authorization: Bearer <key>` | Same | Full parity. |
| Per-attempt timeout | Yes, configurable | Yes: `ClientOptions::$timeoutSeconds` (default 30s), plus a separate `$connectTimeoutSeconds` (default 5s) | Full parity; this client additionally exposes the connect timeout on its own. |
| Retryable statuses | 408, 429, 5xx | Same: 408, 429, 500-599 (including 529 "overloaded") | Full parity. |
| Retryable exceptions | Connection errors, timeouts | Same, surfaced as `TransportException` / its subclass `TimeoutException` | Full parity. |
| Backoff | 0.5s × 2^attempt, capped at 5s, minus up to 25% random jitter | Same (`Http\RetryPolicy`) | Full parity. |
| `retry-after-ms` handling | Preferred over `Retry-After` | Same | Full parity. |
| `Retry-After` handling | Integer seconds, or an HTTP-date | Same | Full parity. |
| Server delay ceiling | A requested delay over 60s falls back to computed backoff | Same | Full parity. |
| `maxRetries` for `POST /v1/systemone` | Retried like any other request, with a small default retry count | **0 by default** | **Deliberate divergence.** A retried POST can be billed twice, and a System One answer is probabilistic, so a "successful" retry is not guaranteed to reproduce the original response. This client requires an explicit opt-in (`ClientOptions::$maxRetries`) before retrying a POST for any reason other than the 429 case below. |
| Retrying a rate-limited POST | Subject to the same retry count as anything else | **Always eligible for one retry**, via `ClientOptions::$retryRateLimitedPost` (default `true`) | **Deliberate divergence**, in the opposite direction: a 429 means the request was rejected *before* it was processed, so it cannot have been billed or answered — retrying it is safe even with `maxRetries: 0`. |
| `maxRetries` for `GET /v1/models` | Small default retry count | `ClientOptions::$modelsMaxRetries`, default 2 | Same idea; the endpoint is read-only, so there is no billing/probability concern and thus no divergence in spirit — the exact default count was simply chosen independently. |
| `User-Agent` | Identifies the SDK and its version | `typesafe-ai-php-client/<version>` | Full parity in intent. |
| SDK identification header | `X-TypeSafe-SDK` | Same | Full parity. |
| Runtime header | `X-TypeSafe-Runtime: python/<version>` | `X-TypeSafe-Runtime: php/<version>` | Full parity in intent. |
| Retry count header | `X-TypeSafe-Retry-Count`, present only on retries | Same | Full parity. |
| Request id | Read from `x-typesafe-request-id`; exposed on the response and on every API error | Same (`SystemOneResponse::requestId()`, `ApiException::getRequestId()`) | Full parity. |
| Error classes | One exception per status family: auth, not found, validation, rate limit, server | Same shape: `AuthenticationException`, `NotFoundException`, `ValidationException`, `RateLimitException`, `ServerException`, all extending `ApiException` | Full parity. |
| Unknown answer type | Skipped, with a warning logged | **Kept**, as `UnknownAnswer` (the raw decoded data is preserved) | **Deliberate divergence.** Silently dropping a future answer type is worse for a statically-typed consumer than an explicit "unknown" case; this client never discards part of a response body. |
| Unknown answer `confidence` | Not applicable (the answer itself is skipped) | Not applicable as a typed accessor; `UnknownAnswer::toArray()` / `raw()` exposes whatever the server sent, unmodified, including a `confidence` field if one is present | See above. |
| Configuration via environment variables | Reads an API key (and a few other settings) from the process environment automatically | **None.** The API key and every option are constructor arguments | **Deliberate divergence.** Implicit environment configuration is a common source of surprise in a library — for example, the wrong key being silently picked up from the process environment. This client always makes configuration explicit at the call site; reading an environment variable, if wanted, is left to the application (`Client::withGuzzle(getenv('TYPESAFE_API_KEY'))`). |
| Streaming responses | Not offered | Not offered | Full parity — there is nothing to diverge from. `POST /v1/systemone` always returns a single JSON document; the API has no streaming endpoint. |
| Mock/test HTTP client | Provided for the SDK's own test suite | `TypesafeAi\Testing\MockHttpClient`, shipped in `src/` | Broader parity: available to integrators' test suites, not just to this package's own tests. |
| Question set / result builder | Not offered | `TypesafeAi\Request\Questions`: a fluent builder that packs several questions into one request without naming the set, using positional wire keys (`question_0`, `question_1`, ...); decoded back into a tuple by `TypedSystemOneResponse` | This pattern originates in an unofficial Swift SDK, not the official Python one; included here because this client offers the same idea. |
| Typed results / `response_model` | Yes, via `response_model` (0.7.0+) | `TypedSystemOneResponse::decode()`: strictly matches each answer to its question's type, by position or by name | Full parity in intent: both fail fast when a response does not match the expected shape. |
| Parallel evaluation | All questions in a request are evaluated against the state in parallel, in one query (the vendor calls this its "parallel sampler") | Same — this is unchanged request/response behaviour; `Questions` and `Client::evaluate()` are sugar for packing more questions into one request instead of issuing one request per question | This is API behaviour, not an SDK feature: every SDK that talks to this API sends all of a request's questions in a single call. No SDK exposes sampling controls (seed, temperature, sample count), and answers remain probabilistic. |

## Keeping this current

This table is a point-in-time comparison. If the official SDK's retry defaults, headers, or error
handling change, or this client's own OpenAPI snapshot drifts (`composer spec:check`, or the
weekly `spec-drift` workflow that opens a pull request when it detects a change), revisit this
table together with the affected DTOs and tests.
