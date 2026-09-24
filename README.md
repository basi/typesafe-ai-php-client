# typesafe-ai-php-client

PHP client for the [typesafe.ai](https://typesafe.ai) System One API (Jev).

The API takes a `state` (any text, JSON object, or array describing the content you want to
reason about) and one or more named questions, and answers each of them with calibrated
probabilities:

- **noul** — a yes/no question or statement, answered with the probability that it is true.
- **choice** — pick one option out of a named set, answered with a probability for every option.
- **score** — rate `state` against an ordered rubric, answered with an expected score and a
  probability for every rubric level.

Every `choice` and `score` answer also carries a **`confidence`**: how sure the model is about the
value it picked. This is the single most important field on those answers — a low-confidence
answer is a signal to fall back to a human or a different process rather than to act on the answer
directly. See [Quick start](#quick-start) below for where to read it.

## Installation

This package is not (yet) listed on Packagist. Point Composer at the GitHub repository directly
with a [VCS repository](https://getcomposer.org/doc/05-repositories.md#vcs) and require it as
usual — a Packagist listing is not required for this to work:

```bash
composer config repositories.typesafe-ai-php-client vcs https://github.com/basi/typesafe-ai-php-client
composer require basi/typesafe-ai-php-client:^0.1
```

Requires PHP 8.4 or newer.

## Quick start

```php
use TypesafeAi\Client;
use TypesafeAi\Request\Question\ChoiceQuestion;
use TypesafeAi\Request\Question\NoulQuestion;
use TypesafeAi\Request\Question\ScoreQuestion;
use TypesafeAi\Request\SystemOneRequest;

$client = Client::withGuzzle(getenv('TYPESAFE_API_KEY') ?: throw new RuntimeException('Set TYPESAFE_API_KEY'));

$request = new SystemOneRequest(
    state: [
        'subject' => 'Duplicate charge',
        'body' => 'I was charged twice for my subscription this month. Please help!',
    ],
    questions: [
        'billing' => new NoulQuestion(
            'Is this message about a billing problem?',
            whenTrue: 'A billing, payment, or invoice issue',
            whenFalse: 'Not related to billing',
        ),
        'category' => new ChoiceQuestion(
            'Which team should handle this message?',
            options: [
                'billing' => 'Payments, invoices, refunds',
                'technical' => 'Bugs, outages, how-to questions',
                'other' => 'Anything else',
            ],
        ),
        'urgency' => new ScoreQuestion('How urgent is this message?', [
            'Can wait',
            'Needs attention this week',
            'Needs attention today',
        ]),
    ],
);

$response = $client->systemOne($request);

// noul: the probability that the statement is true.
$billing = $response->answer('billing');
printf("Billing-related probability: %.2f\n", $billing->noul());

// choice: the selected option, its per-option probabilities, and — importantly — confidence.
$category = $response->answer('category');
printf("Category: %s (confidence: %.2f)\n", $category->choice(), $category->confidence());
if ($category->confidence() < 0.6) {
    // Route to a human instead of trusting a low-confidence choice.
}
print_r($category->probabilities());

// score: the expected score, its per-level probabilities, and confidence.
$urgency = $response->answer('urgency');
printf("Urgency: %.1f (confidence: %.2f)\n", $urgency->score(), $urgency->confidence());
print_r($urgency->probabilities());

// Every response also carries token usage and the server's request id, for support requests.
printf(
    "Used %d input / %d output tokens. Request id: %s\n",
    $response->usage()->inputTokens(),
    $response->usage()->outputTokens(),
    $response->requestId() ?? 'n/a',
);

// The exact, unparsed response body, if you ever need more than this client exposes.
$rawJson = $response->raw();
```

## Options

`ClientOptions` configures timeouts, the default model, and retry behaviour. Pass it to
`Client::withGuzzle()` or the main constructor:

```php
use TypesafeAi\Client;
use TypesafeAi\ClientOptions;

$client = Client::withGuzzle($apiKey, new ClientOptions(
    baseUrl: 'https://api.typesafe.ai',
    defaultModel: 'jev-latest',   // used when a SystemOneRequest does not specify one
    timeoutSeconds: 30,           // per-attempt total timeout
    connectTimeoutSeconds: 5,
    maxRetries: 0,                // see below
    modelsMaxRetries: 2,          // retries for the read-only GET /v1/models
    retryRateLimitedPost: true,   // see below
));
```

**`POST /v1/systemone` is not retried by default** (`maxRetries: 0`). A retried POST can be
billed twice, and because answers are probabilistic a retry is not guaranteed to reproduce the
original response — silently retrying on your behalf could both cost you twice and change the
answer you get. If your integration can tolerate that (for example, you deduplicate downstream, or
you would rather retry than fail outright), opt in explicitly:

```php
new ClientOptions(maxRetries: 2);
```

The one exception is a `429` rate-limit response: it means the request was rejected *before* it
was processed, so retrying it cannot double-bill you. That is retried once even with the default
`maxRetries: 0`, as long as `retryRateLimitedPost` stays `true`. `GET /v1/models` is read-only and
always retries up to `modelsMaxRetries` times.

When a retry does happen, this client honours the server's `retry-after-ms` or `Retry-After`
header (seconds or an HTTP-date) when present; otherwise it backs off from 0.5s, doubling up to a
5s cap, minus up to 25% random jitter — mirroring the official SDKs.

## Errors

Every exception this client throws implements `TypesafeAi\Exception\TypesafeAiException`:

```php
use TypesafeAi\Exception\ApiException;
use TypesafeAi\Exception\RateLimitException;
use TypesafeAi\Exception\ResponseFormatException;
use TypesafeAi\Exception\TimeoutException;
use TypesafeAi\Exception\TransportException;

try {
    $response = $client->systemOne($request);
} catch (RateLimitException $e) {
    // HTTP 429. getRetryAfterMs() is the server's requested backoff, if it sent one.
    $waitMs = $e->getRetryAfterMs();
} catch (ApiException $e) {
    // Any other non-2xx response (also the parent class of RateLimitException and friends).
    $e->getCode();        // the HTTP status code
    $e->getRequestId();   // the x-typesafe-request-id header, if the server sent one
    $e->getErrorType();   // detail.error_type from the response body, if present
} catch (TimeoutException $e) {
    // The request did not complete before the configured timeout. A subclass of
    // TransportException, so catching TransportException also catches this.
} catch (TransportException $e) {
    // A network-level failure with no HTTP response at all. getCode() is always 0.
} catch (ResponseFormatException $e) {
    // A 2xx response body did not match the shape this client expects.
}
```

`ApiExceptionFactory` also builds `AuthenticationException` (401/403), `NotFoundException` (404),
and `ValidationException` (422, with `getValidationErrors()`), all extending `ApiException`.

## Models

```php
foreach ($client->models() as $model) {
    printf("%s (released %s): %s\n", $model->name(), $model->releaseDate(), $model->description());
}
```

Pass a returned `name()` as `model:` on a `SystemOneRequest`, or as `ClientOptions::$defaultModel`.

## Keeping up with the API

This package tracks the API's OpenAPI schema in `spec/`. To check whether the live API has drifted
from the committed snapshot:

```bash
composer spec:check
```

A scheduled workflow runs the same check weekly and opens a pull request with the updated snapshot
when it finds a difference, so drift is never more than a week old (see `docs/parity.md` for the
current state of this client relative to the official SDK).

## Testing your integration

`TypesafeAi\Testing\MockHttpClient` is a PSR-18 client that replays a queue of canned responses
(or throwables) instead of making real network calls, so you can test your own code against this
client without hitting the network:

```php
use TypesafeAi\Client;
use TypesafeAi\Testing\JsonResponse;
use TypesafeAi\Testing\MockHttpClient;

$mock = new MockHttpClient([
    JsonResponse::fromArray(200, [
        'model' => 'jev-latest',
        'answers' => [
            'billing' => ['type' => 'noul', 'noul' => 0.92],
        ],
        'usage' => ['input_tokens' => 42, 'output_tokens' => 5],
    ]),
]);

$client = new Client('test-key', $mock);
$response = $client->systemOne($request);

// Inspect what was actually sent:
$mock->lastRequest()?->getHeaderLine('Authorization'); // "Bearer test-key"
```

It ships in `src/`, not as a dev dependency, so it is available to your test suite without pulling
in this package's own development tooling.

## Versioning and releases

This package follows `0.x` versioning: breaking changes may still happen in a minor release until
`1.0`. Every merge to `main` opens an automated pull request that bumps the patch version in
`Client::VERSION`; merging that pull request tags `v<version>` and publishes a GitHub release,
which is what Composer installs when you require a version constraint.

## Contributing

`main` only accepts changes through pull requests. Before opening one, make sure the following all
pass (CI runs the same checks against PHP 8.4 and 8.5, with both locked and lowest dependency
versions):

```bash
composer test     # PHPUnit
composer analyse  # PHPStan
composer cs       # PHP_CodeSniffer (PSR-12)
```

## License

MIT. See [LICENSE](LICENSE).
