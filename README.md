# typesafe-ai-php-client

PHP client for the [typesafe.ai](https://typesafe.ai) System One API (Jev).

The API takes a `state` (any text, JSON object, or array describing the content you want to
reason about) and one or more named questions, and answers each of them with calibrated
probabilities:

- **noul** — a yes/no question or statement, answered with the probability that it is true.
- **choice** — pick one option out of a named set, answered with a probability for every option.
- **score** — rate `state` against an ordered rubric, answered with an expected score and a
  probability for every rubric level.

A fourth kind, **box**, finds where something is in an image and is answered with a box around it.
It needs [images](#images-experimental), which are experimental, and is described under
[Box questions](#box-questions).

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
composer require basi/typesafe-ai-php-client:^0.2
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

// The answers of one kind, by question name, instead of filtering `answers()` yourself.
$response->nouls();     // array<string, NoulAnswer>
$response->choices();   // array<string, ChoiceAnswer>
$response->scores();    // array<string, ScoreAnswer>
$response->boxes();     // array<string, BoxAnswer>
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
(see below), whose `answer()`, `noul()`, `choice()`, `score()`, and `box()` accept either a position or
a name.

Questions take JSON content, not only text: the instructions of any question, both sides of a noul
question, the description of a choice option, and the levels of a score can each be a string, a
nested list or map of values, or an object that encodes as JSON. A string instruction, or a string
score level, must not be empty; the rest is sent as it is. The `ScoreAnswer::legend()` of such a
score returns the decoded content of each level that was not text. This is also how an
[image](#images-experimental) goes into a question.

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
(`noul`/`choice`/`score`/`box`) does not match its question's — a mismatch this client would otherwise let
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
instructions, or in its criteria (a choice option's description, a score level, the `whenTrue` or
`whenFalse` of a noul question). The model receives the image in place of the text.

**This is experimental.** The provider labels images as not yet ready for production: the request
format, the answers, and the limits can change between releases, and accuracy changes as the models
are updated. They are fine for prototypes. Expect this part of the client to change with the API.

**You have to opt in, and this client does not do it for you.** The API rejects a request that holds
an image (or a [Box question](#box-questions)) unless it carries the header
`TypeSafe-Danger-Zone-Images: true`; without it the answer is a `400` saying "Images are
experimental. Send the header TypeSafe-Danger-Zone-Images: true to use them." The official SDK does
not add the header either. Set it for every request of a client with `ClientOptions::$headers`, or
for one request with its `extraHeaders`:

```php
use TypesafeAi\Client;
use TypesafeAi\ClientOptions;
use TypesafeAi\Request\Image;
use TypesafeAi\Request\Questions;

$client = Client::withGuzzle($apiKey, new ClientOptions(
    timeoutSeconds: 120,   // a request with images is large: see "Limits and preparing images"
    headers: ['TypeSafe-Danger-Zone-Images' => 'true'],
));

$result = $client->evaluate(
    ['door' => Image::fromPath('door-camera.jpg')],
    Questions::create()
        ->noul('Is there a person in `door`?')
        ->choice('What is the person in `door` carrying?', [
            'package' => 'A box or parcel.',
            'bag' => 'A bag of groceries or shopping.',
            'nothing' => 'Empty hands.',
        ]),
);

printf("Person present: %.2f\n", $result->noul(0)->noul());
```

### Creating an image

The official SDK has one `Image` constructor that takes bytes or a path. A PHP string cannot say
which of the two it holds, so this client has one named constructor for each:

| Constructor | Use it for |
|---|---|
| `Image::fromPath($path)` | A file on the local filesystem; `$path` is a string or a `\SplFileInfo`. The file is read when a request that carries the image is first sent, not when the `Image` is created. |
| `Image::fromBytes($bytes)` | The bytes of an image file that you already hold, such as an upload. |
| `Image::fromBase64($text)` | Base64 text, decoded at once. Spaces and line breaks, such as the line breaks of wrapped (MIME) base64, are ignored. Unpadded text and a `data:` prefix are rejected. |
| `Image::fromGd($gd, 'jpeg')` | A `GdImage` (needs the GD extension), encoded at once as `jpeg` (the default, at quality 95), `png`, `gif`, `webp`, `bmp` or `avif`, as far as your build of GD can. JPEG cannot hold transparency: pass `png` for an image that has some. The API accepts PNG and JPEG only at the moment: the other formats are sent as binary data and rejected with a `400`. |

- Anything invalid at this point throws an `\InvalidArgumentException`: empty data, invalid base64, an
  empty path or one with a NUL byte, a stream wrapper such as `php://` or `data:`, a format that GD
  cannot write.
- The client does not decode, resize or convert an image. It only labels the upload `image/png` or
  `image/jpeg` when the file starts with that format's signature, and `application/octet-stream`
  otherwise; the API detects the format itself and rejects what it cannot read.
- **Never pass `fromPath()` a path that came from an untrusted source.** The client reads that file
  and sends its contents to the API.
- A path image is read once, on the first request that carries it, and the contents are kept: a later
  request reuses them even if the file changes or disappears. A read that failed is not remembered.
- An `Image` is its own identity. Two images made from the same bytes are two images with two
  references, and `===` is the only way two `Image` objects are the same one. A clone keeps the
  reference of its original.
- `var_dump()` and `print_r()` of an image show where it comes from and how big it is, never its bytes
  or its reference.

### Placing images

Put an `Image` where the model should see it: as the whole state, or at any depth inside an array or
an object, or inside a question. Questions take JSON content, so instructions and criteria can be a
list or a map that mixes text and images:

```php
use TypesafeAi\Request\Question\ChoiceQuestion;
use TypesafeAi\Request\Question\NoulQuestion;
use TypesafeAi\Request\SystemOneRequest;

$request = new SystemOneRequest(
    state: ['The customer returned this item:', $returned],
    questions: [
        'same_item' => new NoulQuestion(['Is it the item in this listing photo?', $listing]),
        'condition' => new ChoiceQuestion('What condition is the item in?', [
            'new' => $newExample,                        // an image on its own
            'used' => ['Worn, like this:', $usedExample], // text and an image
            'damaged' => 'Broken or with missing parts.', // text only
        ]),
    ],
);
```

Images are found while the request is encoded as JSON, so wherever `json_encode()` meets an `Image` —
in an array, in a plain object, in a `JsonSerializable`, in an `ArrayObject` — it is uploaded. An
image that appears several times is uploaded once. An image that is encoded by a `json_encode()`
call made while the request is being encoded — for example inside your own `jsonSerialize()`, which
embeds a JSON document in a string — counts as placed in the request.

### Referring to an image in text

Every image has a unique reference, its string form. It looks like
`<image:0c0ea0b06f1641dd8e4b64f2cbbd4c25>`, so you can write it into a sentence, for instance by
interpolating the image into a string: `"Compare {$photo} with the listing."`. A reference in text
only stands for the image when the request also holds the image, in one of three ways. Otherwise it
is sent as plain text, without the image.

1. **The image is named in `assets`.** Choose a name, write it in the text, and pass the image under
   it. The name works in any string of the state, the instructions and the criteria:

   ```php
   $request = new SystemOneRequest(
       state: 'Compare <product> with the listing photo <listing>.',
       questions: ['same' => new NoulQuestion('Is <product> the item in <listing>?')],
       assets: ['<product>' => $product, '<listing>' => $listing],
   );
   ```

   `Questions::toRequest()` and `Client::evaluate()` take the same `assets:` argument. Names are
   matched as plain text, so a short one such as `cat` also matches inside `category`: use
   delimiters or a UUID. A name is a non-empty string that PHP keeps as a string: a name such as
   `"1"` becomes the integer array key `1`, which cannot be told from a position in a list, so an
   integer key is rejected with an `\InvalidArgumentException`. So is a list such as
   `assets: [$photo]`, which would name the image `"0"`; the API replaces a name wherever it appears
   in a text. Use a name that is not a number, such as `<photo>`. An image named here is also
   written as its name where it is placed in the content, and uploaded once.

2. **The image is attached to the client** while a callback runs. Anything the callback sends, and
   anything sent meanwhile by the same client, uploads each attached image whose reference it
   mentions:

   ```php
   $response = $client->attachAssets($photo, fn () => $client->systemOne(new SystemOneRequest(
       state: "Product: {$photo}",
       questions: ['damaged' => new NoulQuestion('Is the product damaged?')],
   )));
   ```

   `attachAssets()` takes one image or an array of them, returns what the callback returns, and
   ends the attachment when the callback returns or throws. The official SDK uses a context manager
   (`with client.attach_assets(...)`); PHP has none. Attachments nest, and an image attached again
   is held again, so it stays attached until the last of them ends; within one flow of execution
   they nest, and only fibers that interleave can overlap them. The attachments belong to the
   client: another client does not see them. Attached images that a request does not mention are
   not sent.

3. **The image is placed elsewhere in the request**, as in the previous section, and the text
   repeats its reference: `["Compare {$photo} with:", $photo]` uploads `$photo` once.

Some rules of the API that matter here:

- References and names are matched as plain text anywhere in a string. **An object key is never
  replaced**: a choice option's name or a question's name stays text. To define a choice option with
  a picture, put the image in the option's description, as above.
- **Every named image must be referred to**, or the API answers `400`. This client uploads every
  image in `assets`, referred to or not, and leaves the answer to the API.
- Each place that refers to an image adds it again to what the model reads, and to the size of the
  request as the API expands it. When several questions need the same image, put it in the `state`
  once and refer to it by its path from each question, rather than repeating it in every question.
- An answer repeats your criteria where it can: the `legend()` of a score whose levels were images
  holds the references of those images, not the images.

### How a request with images is sent

A request with no image is the same JSON document as before. A request with images is a
`multipart/form-data` upload, which the provider recommends: it sends each file as it is, where
base64 inside the JSON would make it a third larger. The first part, `request`, is the JSON document,
with an `assets` object that maps the name of each image to the part that holds it. One file part per
image follows, named `asset-0`, `asset-1` and so on. An image that has two names, such as a name and
its reference, is sent in two parts, because the API wants each part claimed by exactly one asset.
The named images come first, in the order given, then the others in the order they appear in the
request.

The body is built once for a call, so a retry sends the very same bytes, boundary included, and a
file that changes between two attempts is not read again. The Guzzle client that
`Client::withGuzzle()` builds never sends `Expect: 100-Continue`, which Guzzle would otherwise add
to a body of 1 MiB or more and wait on (the official SDK's HTTP client never sends it either); if you
pass your own HTTP client to `new Client()`, its configuration applies. Use
`TypesafeAi\Testing\RequestBody` to read such a request back in your tests (see
[Testing your integration](#testing-your-integration)).

### Errors with images

- `\InvalidArgumentException` for anything invalid in an object you build: an asset that is not an
  `Image`, an asset name that is empty or a number, an unusable path, bad base64, a bad header.
- `TypesafeAi\Exception\AssetException` for an image that cannot be read when a request is sent: a
  file that does not exist, is a directory, cannot be opened, or is empty. It is thrown by
  `systemOne()` and `evaluate()` **before any HTTP request is sent**, so it is never retried and
  costs nothing, and its message names the image by the name it has in the request, or by its
  reference when it has none: `Asset '<product>': Image file 'product.png' could not be read: No
  such file or directory.` The first image that fails, in the order they are sent, is the one
  reported. It implements `TypesafeAiException` like every exception of this client.
- An `ApiException` for what the API rejects: `400` without the header, for an image that is not a
  valid PNG or JPEG, is too small, or that nothing refers to; `413` for a size limit.

### Limits and preparing images

The API validates these limits, and an image outside them is rejected, never resized to fit. This
client exposes them as `Limits` constants but does not enforce them, so a violation surfaces as an
`ApiException` with the HTTP status `400` or `413`.

| Limit | Value | Constants |
|---|---|---|
| Format | PNG or JPEG, still images only (animated PNGs are rejected) | |
| File size | 16 MiB per image | `Limits::MAX_IMAGE_BYTES` |
| Width and height | 256 to 1568 pixels each | `MIN_IMAGE_SIDE_PIXELS`, `MAX_IMAGE_SIDE_PIXELS` |
| Total pixels (width × height) | 78,400 to 2,458,624: a 280 × 280 to a 1568 × 1568 square, so 256 × 256 is too small | `MIN_IMAGE_PIXELS`, `MAX_IMAGE_PIXELS` |
| Request body | 32 MiB, including the JSON and the multipart framing; compression does not raise it | `Limits::MAX_REQUEST_BODY_BYTES` |
| References | 1,024 bytes each and 65,536 bytes together, counting bytes of UTF-8 | `MAX_ASSET_REFERENCE_BYTES`, `MAX_TOTAL_ASSET_REFERENCE_BYTES` |

The API also limits all the images of a request together, and the request after every reference is
replaced by its image, to 32 MiB each. A request with images can be large, so you may want a higher
`timeoutSeconds` in `ClientOptions` (see [Options](#options)) than the default of 30.

**Prepare each image before you create the `Image`.** This client does not decode, resize, rotate or
convert images, and the API does not do it either:

- **Resize.** Scale an image so that its longest side is at most 1568 pixels (a 12-megapixel phone
  photo becomes 1568 × 1176), and scale up or pad one that is below the minimum.
- **Rotate the pixels upright.** The API removes all metadata, including the EXIF orientation, and
  does not apply it, so a photo that looks upright in your photo viewer can reach the model sideways.
- **Send a still PNG or JPEG.** Convert GIF, WebP, HEIC and other formats, and export one frame of an
  animation or video. A standard baseline 8-bit JPEG is the safest JPEG; re-save a progressive one.

### Memory

An `Image` holds its bytes once, and a request holds the whole body once more while it is built and
sent; the PSR-7 stream that your HTTP client sends from may hold a third copy. Plan for about two to
three times the size of the images in a request.

## Box questions

A box question finds where something is in an image. Its answer is a box: the four edges of the
smallest rectangle around the target, as fractions of the image's size, measured from its top-left
corner. `top` and `bottom` are fractions of the height, `left` and `right` of the width. Like every
use of images, Box is experimental and needs the [images header](#images-experimental).

```php
use TypesafeAi\Request\Image;
use TypesafeAi\Request\Question\BoxQuestion;
use TypesafeAi\Request\SystemOneRequest;

$response = $client->systemOne(new SystemOneRequest(
    state: Image::fromPath('kitchen.jpg'),
    questions: ['mug' => new BoxQuestion('the coffee mug in the image')],
    model: 'jev-1.14.0',
));

$mug = $response->boxes()['mug'];     // a BoxAnswer; $response->answer('mug') is the same object
$edges = $mug->box();
printf(
    "left %.2f, top %.2f, right %.2f, bottom %.2f (confidence %s)\n",
    $edges->left(),
    $edges->top(),
    $edges->right(),
    $edges->bottom(),
    $mug->confidence() === null ? 'not given' : sprintf('%.2f', $mug->confidence()),
);
```

The builder has `Questions::box($instructions = null, ?string $name = null)`, and
`TypedSystemOneResponse::box()` reads the answer. `BoxQuestion` takes the same text or JSON content
as the other questions, an `Image` included ("the person in the state who matches this badge photo:
{$badge}" works once the badge image is placed, named or attached); its instructions may be left
out. To draw or crop, multiply each edge by the image's width or height in pixels.

- **Box needs Jev 1.14**: send `jev-1.14.0` or `jev-preview` as the `model`. At the time of writing
  `jev-latest` is Jev 1.13, which answers a box question with a `400`.
- **An answer is always a box.** There is no "not found": a question about something absent still
  returns four edges. Ask a noul question alongside ("Is there a coffee mug in the image?") and
  ignore the box when its answer is low.
- **`confidence()` is `null`** when the model gives none. The field itself is always in the answer.
- The client does not check that the edges are between 0 and 1, or that `left` is less than `right`
  and `top` less than `bottom`, as the API promises; it only requires the four numbers.
- **One box per question.** To find several things, ask one box question for each. To find something
  small in a large image, find the region first, for example with a choice whose options are tiles
  of the image, then ask the box question on that tile and convert the edges back.

Before this version, an answer of type `box` was read as an `UnknownAnswer`. It is now a `BoxAnswer`,
and code that checks for `UnknownAnswer` to find box answers must change.

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
    headers: [],                  // sent with every request; see below
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

**`headers` are sent with every request**, `GET /v1/models` included; a single request can add its
own with `extraHeaders` (an argument of `SystemOneRequest`, `Questions::toRequest()` and
`Client::evaluate()`). The official SDK has the same two levels. They combine in its order, with
header names compared without regard to case: the client-wide headers, then the request's, then the
headers this client always sets — `Authorization`, `Accept`, `User-Agent`, `X-TypeSafe-SDK` and
`X-TypeSafe-Runtime`, which cannot be replaced — then `Content-Type` on a request with a body. An
`X-TypeSafe-Retry-Count` that you supply is dropped, because the client sets it on each retry. A
header name must be an HTTP token and a value must not contain a line break or another control
character, or the constructor throws an `\InvalidArgumentException`. The images header is the usual
reason to set one; see [Images](#images-experimental).

```php
new ClientOptions(headers: ['TypeSafe-Danger-Zone-Images' => 'true']);
$client->systemOne(new SystemOneRequest($state, $questions, extraHeaders: ['X-Request-Source' => 'nightly']));
```

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
use TypesafeAi\Exception\AssetException;
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
} catch (AssetException $e) {
    // An image could not be read (for example, the file does not exist). Thrown before any HTTP
    // request is sent, so never retried. getCode() is always 0.
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
current state of this client relative to the official SDK). The snapshot describes images and Box
questions too: the `assets` field, the `Asset` and Box schemas, and the images header.

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

A request that carries images is a `multipart/form-data` upload with a random boundary, so a test
cannot compare its body with a fixed string. `TypesafeAi\Testing\RequestBody` reads one back:

```php
use TypesafeAi\Testing\RequestBody;

$body = RequestBody::decode($mock->lastRequest());
$body['state'];                    // the state, with each image as its reference
$body['assets'][(string) $image];  // ['type' => 'image', 'content_type' => 'image/png', 'data' => '<bytes>']

RequestBody::parts($mock->lastRequest());  // each part by name: ['headers' => '...', 'content' => '...']
```

`decode()` also reads a plain JSON request, so it works on any request that `systemOne()` or
`evaluate()` sends (`models()` has no body to read). The references of images are random: build your
expectations from `(string) $image`.

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

The tests of `Image::fromGd()` need the GD extension and are skipped without it. The image tests
in `tests/Live` talk to the real API, so they are not part of `composer test`; run them by hand with
a key that has images enabled:

```bash
TYPESAFE_API_KEY=... TYPESAFE_LIVE_IMAGES=1 vendor/bin/phpunit tests/Live
```

## License

MIT. See [LICENSE](LICENSE).
