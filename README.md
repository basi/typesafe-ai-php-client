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

## Packing questions with the builder

`Questions` packs several questions into one request without giving the set itself a name: each
question gets a positional wire key (`question_0`, `question_1`, ...) unless you name it yourself,
and the answers come back as a tuple in the same order you declared the questions — handy when you
always ask the same few things about a piece of content and would rather not invent (and thread
through) a name for every one of them.

```php
use TypesafeAi\Request\Questions;

$result = $client->evaluate('I was charged twice for my subscription.', Questions::create()
    ->choice('What is this ticket about?', ['billing' => null, 'technical' => null, 'other' => null])
    ->noul('Does this need urgent attention?')
    ->score('How severe is the issue?', ['minor', 'moderate', 'severe']));

[$category, $urgent, $severity] = $result->answers();   // ChoiceAnswer, NoulAnswer, ScoreAnswer — in declaration order
printf("%s (confidence %.2f)\n", $category->choice(), $category->confidence());
printf("urgent: %.2f, severity: %.1f (confidence %.2f)\n", $urgent->noul(), $severity->score(), $severity->confidence());
$result->requestId();
```

Name a question yourself with the `name:` argument (e.g. `->choice(..., name: 'category')`) when you
would rather look it up by name than by position; an explicit name does not shift the positional
numbering of the questions declared around it. `Client::evaluate()` returns a `TypedSystemOneResponse`
(see below), whose `answer()`, `noul()`, `choice()`, and `score()` accept either a position or a name.

## Typed decoding

`Client::evaluate()` decodes its response for you, but the same strict decoding step is available
directly if you build a `SystemOneRequest` yourself, for example because its questions were not
built with `Questions`:

```php
use TypesafeAi\Response\TypedSystemOneResponse;

$response = $client->systemOne($request);
$typed = TypedSystemOneResponse::decode($response, $questions);
```

`$questions` is either the `Questions` instance used to build the request, or a plain
`name => QuestionInterface` array in the same order the request declared them. Decoding fails fast
with a `ResponseFormatException` when a question has no matching answer, or when an answer's kind
(`noul`/`choice`/`score`) does not match its question's — a mismatch this client would otherwise let
you discover later, further from the cause, as a `LogicException` from a typed accessor. An answer
present in the response but not in `$questions` is ignored by `TypedSystemOneResponse`, though it
stays reachable via `TypedSystemOneResponse::response()`.

## How questions are evaluated

All questions in a single request are evaluated against the same `state` in parallel, in one query
— the vendor calls this its "parallel sampler." Prefer packing related questions into one request
(with `Questions`, or a multi-question `SystemOneRequest`) over sending one request per question: it
is both cheaper and faster than issuing separate requests. The API exposes no controls over that
sampling — no seed, temperature, or number-of-samples parameter — and, being probabilistic, the same
question can get a different answer (or confidence) on a repeated call even with identical input.

## Images (experimental)

A request can include images. An image can go anywhere text can go: in the `state`, in a question's
`instructions`, or in its criteria (a choice option's description, a score level, the `whenTrue` or
`whenFalse` of a noul question). The model receives the image in place of the text.

**This is experimental.** The provider labels images as not yet ready for production: the request
format, the answers, and the limits can change between releases, and accuracy changes as the models
are updated. They are fine for prototypes. Expect this part of the client to change with the API.

An image is an `ImageAsset`. List each one once in the request's `assets` map, under a *reference*:
a string that you also write wherever the image belongs.

```php
use TypesafeAi\Request\Asset\ImageAsset;
use TypesafeAi\Request\Question\ChoiceQuestion;
use TypesafeAi\Request\Question\NoulQuestion;
use TypesafeAi\Request\SystemOneRequest;

// A new random reference for each image, so that it cannot occur in your own text by accident.
// A UUID works just as well.
$door = bin2hex(random_bytes(16));

$request = new SystemOneRequest(
    state: ['door' => $door],
    questions: [
        'person_present' => new NoulQuestion('Is there a person in `door`?'),
        'carrying' => new ChoiceQuestion('What is the person in `door` carrying?', [
            'package' => 'A box or parcel.',
            'bag' => 'A bag of groceries or shopping.',
            'nothing' => 'Empty hands.',
        ]),
    ],
    assets: [$door => ImageAsset::fromFile('door-camera.jpg')],
);

$response = $client->systemOne($request);
```

`Client::evaluate()` and `Questions::toRequest()` take the same map as their last argument:

```php
$result = $client->evaluate(
    ['door' => $door],
    Questions::create()->noul('Is there a person in `door`?'),
    assets: [$door => ImageAsset::fromFile('door-camera.jpg')],
);
```

`ImageAsset::fromFile($path)` reads a file on the local filesystem; `ImageAsset::fromBinary($bytes)`
takes the bytes of an image you already hold in memory, such as an upload; and
`new ImageAsset('png', $base64)` takes text that is already base64-encoded (the standard alphabet
with `=` padding, no line breaks, no `data:` prefix). The first two detect the format from the
file's PNG or JPEG signature unless you pass it as their second argument (`png`, `jpg`, or `jpeg`).

**The images header is added for you.** The API only accepts an image in a request that carries the
header `TypeSafe-Danger-Zone-Images: true` (answering `400` otherwise). This client sends it whenever
the request has assets (`Client::HEADER_DANGER_ZONE_IMAGES`), on every attempt including retries, and
sends exactly the headers it always sent when the request has none.

### How references work

- A reference goes inside a *string value* — the whole value, or part of a longer sentence — of the
  `state`, of a question's instructions, or of its criteria, at any depth. A question refers to an
  image in the state by its name in backticks, like the questions above.
- **Object keys are never replaced.** A choice option's name, a question's name, and a key in the
  state stay text. To define a choice option with a picture, put the reference in the option's
  description.
- **Every asset must be referenced at least once**, or the API answers `400`. `SystemOneRequest`
  checks this when it is built: it throws an `\InvalidArgumentException` naming the first asset that
  no string value refers to, including one that appears only as an object key.
- References are matched as plain text, so a short one such as `cat` also matches inside
  `category`. Use references that cannot occur in your text by accident: a new UUID for each image in
  each request is the safest choice. A reference that PHP turns into an integer array key, such as
  `123`, is rejected.
- A reference is not empty or whitespace-only, and is at most 1,024 bytes of UTF-8
  (`Limits::MAX_ASSET_REFERENCE_BYTES`); all the references of one request together are at most
  65,536 bytes (`Limits::MAX_TOTAL_ASSET_REFERENCE_BYTES`). These count bytes, not characters, and
  `SystemOneRequest` checks them.
- Each use of a reference adds the image again to what the model reads, and to the size of the
  request as the API expands it. When several questions need the same image, put it in the `state`
  once and point at it from each question, instead of repeating the reference in every question.

### Limits and preparing images

The API validates these limits, and an image outside them is rejected, never resized to fit. This
client exposes them as `Limits` constants but does not enforce them, so a violation surfaces as an
`ApiException` with the HTTP status `400` or `413`.

| Limit | Value | Constants |
|---|---|---|
| Format | PNG or JPEG, still images only (animated PNGs are rejected) | |
| File size | 16 MiB per image; for base64, the size after decoding | `Limits::MAX_IMAGE_BYTES` |
| Width and height | 256 to 1568 pixels each | `MIN_IMAGE_SIDE_PIXELS`, `MAX_IMAGE_SIDE_PIXELS` |
| Total pixels (width × height) | 78,400 to 2,458,624 (a 280 × 280 to a 1568 × 1568 square, so 256 × 256 is too small) | `MIN_IMAGE_PIXELS`, `MAX_IMAGE_PIXELS` |
| Request body | 32 MiB including the JSON and the base64 text of every image; compression does not raise it | `Limits::MAX_REQUEST_BODY_BYTES` |

The API also limits all the images of a request together, and the request after every reference is
replaced by its image, to 32 MiB each. This client sends images as base64 in the JSON body, which
makes each one about a third larger than the file. The provider recommends multipart uploads, which
send the file as it is; this client does not support them yet, nor Box questions. A request with
images can be large, so you may want a higher `timeoutSeconds` in `ClientOptions` (see
[Options](#options)) than the default of 30.

**Prepare each image before you create the `ImageAsset`.** This client does not decode, resize,
rotate, or convert images, and the API does not do it either:

- **Resize.** Scale an image so that its longest side is at most 1568 pixels (a 12-megapixel phone
  photo becomes 1568 × 1176), and scale up or pad one that is below the minimum.
- **Rotate the pixels upright.** The API removes all metadata, including the EXIF orientation, and
  does not apply it, so a photo that looks upright in your photo viewer can reach the model sideways.
- **Send a still PNG or JPEG.** Convert GIF, WebP, HEIC and other formats, and export one frame of an
  animation or video. A standard baseline 8-bit JPEG is the safest JPEG; re-save a progressive one.

`var_dump()` and `print_r()` of an `ImageAsset`, or of a request that holds one, show the format and
the size of the image instead of its base64 text.

## Options

`ClientOptions` configures timeouts, the default model, and retry behaviour. Pass it to
`Client::withGuzzle()` or the main constructor:

```php
use TypesafeAi\Client;
use TypesafeAi\ClientOptions;

$client = Client::withGuzzle($apiKey, new ClientOptions(
    baseUrl: 'https://api.typesafe.ai',
    defaultModel: 'jev-latest',   // used when a SystemOneRequest does not specify one
    timeoutSeconds: 30,           // per-attempt total timeout; must be at least 1 (see below)
    connectTimeoutSeconds: 5,     // must be at least 1, for the same reason
    maxRetries: 0,                // see below
    modelsMaxRetries: 2,          // retries for the read-only GET /v1/models
    retryRateLimitedPost: true,   // see below
    maxRetryDelayMs: null,        // see below
));
```

**`timeoutSeconds` and `connectTimeoutSeconds` must both be at least 1.** Guzzle treats a
timeout of `0` as "wait indefinitely", which would silently remove any upper bound on how long a
single call can take; `ClientOptions` rejects `0` (and any negative value) for both with an
`\InvalidArgumentException` rather than passing it through.

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
header (seconds or an HTTP-date) when present, up to 60 seconds; otherwise (or beyond that
ceiling) it backs off from 0.5s, doubling up to a 5s cap, minus up to 25% random jitter —
mirroring the official SDKs.

**`maxRetryDelayMs` lowers that 60-second ceiling**, for callers with their own hard upper bound
on how long one call may take — for example, a queue worker whose job is killed after a fixed
timeout. A server-specified delay longer than `maxRetryDelayMs` is not waited out; exponential
backoff is used instead (backoff itself is always subject to its own 5s cap regardless of this
option). `null` (the default) keeps the 60-second ceiling; `0` means a server-specified delay is
never honoured, so every retry uses backoff.

```php
new ClientOptions(maxRetries: 1, maxRetryDelayMs: 2000);
```

For a queue worker, choose `maxRetryDelayMs` so the worst case comfortably fits inside the job
timeout:

```text
timeoutSeconds × (maxRetries + 1) + maxRetryDelayMs < job timeout
```

## Using an AI gateway

The official Python SDK documents pointing itself at a third-party AI gateway instead of the
typesafe.ai API directly, by overriding `baseUrl` and `defaultModel`. **This has not been tested
against either gateway with this client** — the values below are exactly what the vendor documents
for the Python SDK, offered as a starting point rather than a verified configuration:

```php
use TypesafeAi\Client;
use TypesafeAi\ClientOptions;

// OpenRouter
$client = Client::withGuzzle($apiKey, new ClientOptions(
    baseUrl: 'https://openrouter.ai/api',
    defaultModel: '~typesafe/jev-latest',
));

// Vercel AI Gateway
$client = Client::withGuzzle($apiKey, new ClientOptions(
    baseUrl: 'https://ai-gateway.vercel.sh/typesafe',
    defaultModel: 'typesafe-ai/jev',
));
```

Check the gateway's own documentation for the API key and model name it expects — they are not
necessarily your typesafe.ai API key or model name.

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

### Credentials

The API key is validated when the client is constructed: after trimming surrounding spaces, it
must be a non-empty, printable ASCII string with no interior whitespace or control characters, or
the constructor throws an `\InvalidArgumentException`. Every message this client builds from
upstream text — a `TransportException`/`TimeoutException` message, or the reason embedded in an
`ApiException` message — has every form of the key it recognises (raw, `Bearer <key>`,
JSON-escaped, URL-encoded) replaced with `***`. The one exception is `ApiException::getRawBody()`,
which is documented as the exact, unredacted response body, so treat it the same way you would any
other data the server sent back.

A transport-level failure never chains the original exception as `getPrevious()`: that message may
carry the key in a shape redaction cannot reach (for example a string built internally by the HTTP
client), so it is dropped rather than risk leaking it. The original exception's class name is kept
in the new message instead, so the cause stays diagnosable.

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
