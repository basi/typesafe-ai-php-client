<?php

declare(strict_types=1);

namespace TypesafeAi\Tests\Unit;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TypesafeAi\Client;
use TypesafeAi\ClientOptions;
use TypesafeAi\Exception\ApiException;
use TypesafeAi\Exception\AuthenticationException;
use TypesafeAi\Exception\NotFoundException;
use TypesafeAi\Exception\RateLimitException;
use TypesafeAi\Exception\ServerException;
use TypesafeAi\Exception\TimeoutException;
use TypesafeAi\Exception\TransportException;
use TypesafeAi\Exception\ValidationException;
use TypesafeAi\Request\Question\NoulQuestion;
use TypesafeAi\Request\Questions;
use TypesafeAi\Request\SystemOneRequest;
use TypesafeAi\Response\Answer\ChoiceAnswer;
use TypesafeAi\Response\Answer\NoulAnswer;
use TypesafeAi\Response\Answer\ScoreAnswer;
use TypesafeAi\Response\SystemOneResponse;
use TypesafeAi\Response\TypedSystemOneResponse;
use TypesafeAi\Testing\JsonResponse;
use TypesafeAi\Testing\MockHttpClient;

final class ClientTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function validSystemOneBody(): array
    {
        return [
            'model' => 'jev-latest',
            'answers' => [
                'billing' => ['type' => 'noul', 'noul' => 0.9],
            ],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 2],
        ];
    }

    private static function aQuestionRequest(?string $model = null): SystemOneRequest
    {
        return new SystemOneRequest('hello', ['q' => new NoulQuestion('Is this spam?')], $model);
    }

    #[Test]
    public function constructorRejectsAnEmptyApiKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Client('', new MockHttpClient());
    }

    #[Test]
    public function constructorRejectsAWhitespaceOnlyApiKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Client('   ', new MockHttpClient());
    }

    #[Test]
    public function withGuzzleBuildsAClientWithoutMakingARequest(): void
    {
        self::assertInstanceOf(Client::class, Client::withGuzzle('test-key'));
    }

    #[Test]
    public function systemOneSendsBearerAuthorizationAndJsonHeadersOnFirstAttempt(): void
    {
        $mock = new MockHttpClient([JsonResponse::fromArray(200, self::validSystemOneBody())]);
        $client = new Client('test-key', $mock);

        $client->systemOne(self::aQuestionRequest());

        $request = $mock->lastRequest();
        self::assertNotNull($request);
        self::assertSame('POST', $request->getMethod());
        self::assertStringEndsWith('/v1/systemone', (string) $request->getUri());
        self::assertSame('Bearer test-key', $request->getHeaderLine('Authorization'));
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertStringContainsString(
            'typesafe-ai-php-client/' . Client::VERSION,
            $request->getHeaderLine('User-Agent'),
        );
        self::assertStringContainsString(
            'typesafe-ai-php-client/' . Client::VERSION,
            $request->getHeaderLine('X-TypeSafe-SDK'),
        );
        self::assertSame('php/' . PHP_VERSION, $request->getHeaderLine('X-TypeSafe-Runtime'));
        self::assertFalse($request->hasHeader('X-TypeSafe-Retry-Count'));
    }

    #[Test]
    public function modelsSendsBearerAndRuntimeHeadersWithoutContentType(): void
    {
        $mock = new MockHttpClient([JsonResponse::fromArray(200, ['models' => []])]);
        $client = new Client('test-key', $mock);

        $client->models();

        $request = $mock->lastRequest();
        self::assertNotNull($request);
        self::assertSame('GET', $request->getMethod());
        self::assertStringEndsWith('/v1/models', (string) $request->getUri());
        self::assertSame('Bearer test-key', $request->getHeaderLine('Authorization'));
        self::assertFalse($request->hasHeader('Content-Type'));
        self::assertFalse($request->hasHeader('X-TypeSafe-Retry-Count'));
    }

    #[Test]
    public function systemOneInjectsTheDefaultModelWhenTheRequestHasNone(): void
    {
        $mock = new MockHttpClient([JsonResponse::fromArray(200, self::validSystemOneBody())]);
        $client = new Client('test-key', $mock, options: new ClientOptions(defaultModel: 'jev-preview'));

        $client->systemOne(self::aQuestionRequest());

        $request = $mock->lastRequest();
        self::assertNotNull($request);
        $sentBody = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($sentBody);
        self::assertSame('jev-preview', $sentBody['model']);
    }

    #[Test]
    public function systemOneKeepsAnExplicitModelInTheRequest(): void
    {
        $mock = new MockHttpClient([JsonResponse::fromArray(200, self::validSystemOneBody())]);
        $client = new Client('test-key', $mock, options: new ClientOptions(defaultModel: 'jev-preview'));

        $client->systemOne(self::aQuestionRequest('jev-1.13.0'));

        $request = $mock->lastRequest();
        self::assertNotNull($request);
        $sentBody = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($sentBody);
        self::assertSame('jev-1.13.0', $sentBody['model']);
    }

    #[Test]
    public function systemOneHydratesTheResponseIncludingTheRequestIdHeader(): void
    {
        $mock = new MockHttpClient([
            JsonResponse::fromArray(200, self::validSystemOneBody(), ['x-typesafe-request-id' => 'req_abc123']),
        ]);
        $client = new Client('test-key', $mock);

        $response = $client->systemOne(self::aQuestionRequest());

        self::assertInstanceOf(SystemOneResponse::class, $response);
        self::assertSame('req_abc123', $response->requestId());
        self::assertSame('jev-latest', $response->model());
        self::assertSame(0.9, $response->answer('billing')->toArray()['noul']);
    }

    #[Test]
    public function evaluateSendsOneRequestWithPositionalQuestionKeysAndDecodesTypedAnswers(): void
    {
        $mock = new MockHttpClient([
            JsonResponse::fromArray(200, [
                'model' => 'jev-latest',
                'answers' => [
                    'question_0' => [
                        'type' => 'choice',
                        'choice' => 'billing',
                        'confidence' => 0.9,
                        'probabilities' => ['billing' => 0.9, 'technical' => 0.1],
                    ],
                    'question_1' => ['type' => 'noul', 'noul' => 0.7],
                    'question_2' => [
                        'type' => 'score',
                        'score' => 2.0,
                        'confidence' => 0.6,
                        'legend' => ['0' => 'minor', '1' => 'severe'],
                        'probabilities' => ['0' => 0.4, '1' => 0.6],
                    ],
                ],
                'usage' => ['input_tokens' => 12, 'output_tokens' => 4],
            ], ['x-typesafe-request-id' => 'req_eval123']),
        ]);
        $client = new Client('test-key', $mock, options: new ClientOptions(defaultModel: 'jev-preview'));

        $result = $client->evaluate('some ticket text', Questions::create()
            ->choice('What is this about?', ['billing' => null, 'technical' => null])
            ->noul('Is this urgent?')
            ->score('How severe?', ['minor', 'severe']));

        self::assertCount(1, $mock->requests());
        $request = $mock->lastRequest();
        self::assertNotNull($request);
        $sentBody = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($sentBody);
        self::assertIsArray($sentBody['questions']);
        self::assertSame(['question_0', 'question_1', 'question_2'], array_keys($sentBody['questions']));
        self::assertSame('jev-preview', $sentBody['model']);

        self::assertInstanceOf(TypedSystemOneResponse::class, $result);
        $answers = $result->answers();
        self::assertInstanceOf(ChoiceAnswer::class, $answers[0]);
        self::assertInstanceOf(NoulAnswer::class, $answers[1]);
        self::assertInstanceOf(ScoreAnswer::class, $answers[2]);
        self::assertSame('req_eval123', $result->requestId());
    }

    #[Test]
    public function modelsHydratesModelCards(): void
    {
        $mock = new MockHttpClient([JsonResponse::fromArray(200, [
            'models' => [
                ['name' => 'jev-latest', 'description' => 'General purpose', 'release_date' => '2026-09-15'],
            ],
        ])]);
        $client = new Client('test-key', $mock);

        $models = $client->models();

        self::assertCount(1, $models);
        self::assertSame('jev-latest', $models[0]->name());
    }

    /**
     * @return iterable<string, array{0: int, 1: class-string<ApiException>}>
     */
    public static function nonRetryableStatusProvider(): iterable
    {
        yield '401 is AuthenticationException' => [401, AuthenticationException::class];
        yield '403 is AuthenticationException' => [403, AuthenticationException::class];
        yield '404 is NotFoundException' => [404, NotFoundException::class];
        yield '422 is ValidationException' => [422, ValidationException::class];
        yield '429 is RateLimitException' => [429, RateLimitException::class];
        yield '500 is ServerException' => [500, ServerException::class];
        yield '529 is ServerException' => [529, ServerException::class];
    }

    /**
     * @param class-string<ApiException> $expectedClass
     */
    #[Test]
    #[DataProvider('nonRetryableStatusProvider')]
    public function everyErrorStatusMapsToTheExpectedExceptionAndCode(int $statusCode, string $expectedClass): void
    {
        // retryRateLimitedPost is disabled so that 429 also results in exactly one request here;
        // its retry behaviour has its own dedicated tests below.
        $mock = new MockHttpClient([JsonResponse::fromArray($statusCode, ['detail' => 'failure'])]);
        $client = new Client('test-key', $mock, options: new ClientOptions(retryRateLimitedPost: false));

        try {
            $client->systemOne(self::aQuestionRequest());
            self::fail('Expected an exception.');
        } catch (ApiException $exception) {
            self::assertInstanceOf($expectedClass, $exception);
            self::assertSame($statusCode, $exception->getCode());
        }

        self::assertCount(1, $mock->requests());
    }

    #[Test]
    public function post500WithDefaultOptionsIsNotRetried(): void
    {
        $mock = new MockHttpClient([JsonResponse::fromArray(500, ['detail' => 'boom'])]);
        $client = new Client('test-key', $mock);

        $this->expectException(ServerException::class);

        try {
            $client->systemOne(self::aQuestionRequest());
        } finally {
            self::assertCount(1, $mock->requests());
        }
    }

    #[Test]
    public function post429IsRetriedOnceByDefaultHonouringRetryAfterMs(): void
    {
        $mock = new MockHttpClient([
            JsonResponse::fromArray(429, ['detail' => 'slow down'], ['retry-after-ms' => '5']),
            JsonResponse::fromArray(200, self::validSystemOneBody()),
        ]);
        $client = new Client('test-key', $mock);

        $start = hrtime(true);
        $response = $client->systemOne(self::aQuestionRequest());
        $elapsedMs = (hrtime(true) - $start) / 1_000_000;

        self::assertInstanceOf(SystemOneResponse::class, $response);
        self::assertCount(2, $mock->requests());
        self::assertSame('1', $mock->requests()[1]->getHeaderLine('X-TypeSafe-Retry-Count'));
        // The queued retry-after-ms was 5ms. Falling back to the ~500ms default backoff instead
        // would still eventually pass, but comfortably exceed this bound, so this demonstrates the
        // header was honoured rather than ignored.
        self::assertLessThan(200.0, $elapsedMs);
    }

    #[Test]
    public function repeatedPost429DoesNotRetryMoreThanOnceByDefault(): void
    {
        $mock = new MockHttpClient([
            JsonResponse::fromArray(429, ['detail' => 'slow down'], ['retry-after-ms' => '1']),
            JsonResponse::fromArray(429, ['detail' => 'still slow'], ['retry-after-ms' => '1']),
        ]);
        $client = new Client('test-key', $mock);

        $this->expectException(RateLimitException::class);

        try {
            $client->systemOne(self::aQuestionRequest());
        } finally {
            self::assertCount(2, $mock->requests());
        }
    }

    #[Test]
    public function postTimeoutWithMaxRetriesOneRetriesOnceThenThrows(): void
    {
        $timeout = static fn (): ConnectException => new ConnectException(
            'cURL error 28: Operation timed out after 5000 milliseconds',
            new Request('POST', 'https://api.typesafe.ai/v1/systemone'),
        );
        $mock = new MockHttpClient([$timeout(), $timeout()]);
        $client = new Client('test-key', $mock, options: new ClientOptions(maxRetries: 1));

        $this->expectException(TimeoutException::class);

        try {
            $client->systemOne(self::aQuestionRequest());
        } finally {
            self::assertCount(2, $mock->requests());
        }
    }

    #[Test]
    public function postTimeoutWithDefaultOptionsIsNotRetried(): void
    {
        $mock = new MockHttpClient([
            new ConnectException(
                'cURL error 28: Operation timed out after 5000 milliseconds',
                new Request('POST', 'https://api.typesafe.ai/v1/systemone'),
            ),
        ]);
        $client = new Client('test-key', $mock);

        $this->expectException(TimeoutException::class);

        try {
            $client->systemOne(self::aQuestionRequest());
        } finally {
            self::assertCount(1, $mock->requests());
        }
    }

    #[Test]
    public function getModelsRetriesUpToThreeAttemptsOnServerErrors(): void
    {
        $mock = new MockHttpClient([
            JsonResponse::fromArray(503, ['detail' => 'a'], ['retry-after-ms' => '1']),
            JsonResponse::fromArray(503, ['detail' => 'b'], ['retry-after-ms' => '1']),
            JsonResponse::fromArray(503, ['detail' => 'c']),
        ]);
        $client = new Client('test-key', $mock);

        $this->expectException(ServerException::class);

        try {
            $client->models();
        } finally {
            self::assertCount(3, $mock->requests());
        }
    }

    #[Test]
    public function getModelsSucceedsAfterATransientServerError(): void
    {
        $mock = new MockHttpClient([
            JsonResponse::fromArray(503, ['detail' => 'a'], ['retry-after-ms' => '1']),
            JsonResponse::fromArray(200, ['models' => []]),
        ]);
        $client = new Client('test-key', $mock);

        $models = $client->models();

        self::assertSame([], $models);
        self::assertCount(2, $mock->requests());
    }

    #[Test]
    public function theApiKeyNeverAppearsInAnApiExceptionMessage(): void
    {
        $mock = new MockHttpClient([JsonResponse::fromArray(401, [
            'detail' => ['error_type' => 'authentication_error', 'message' => 'Invalid API key'],
        ])]);
        $client = new Client('sk-super-secret-value', $mock);

        try {
            $client->systemOne(self::aQuestionRequest());
            self::fail('Expected an exception.');
        } catch (AuthenticationException $exception) {
            self::assertStringNotContainsString('sk-super-secret-value', $exception->getMessage());
            self::assertStringNotContainsString('sk-super-secret-value', $exception->getRawBody());
        }
    }

    #[Test]
    public function theApiKeyNeverAppearsInATransportExceptionMessage(): void
    {
        $mock = new MockHttpClient([
            new \RuntimeException('curl said: header Authorization: Bearer sk-super-secret-value was rejected'),
        ]);
        $client = new Client('sk-super-secret-value', $mock, options: new ClientOptions(modelsMaxRetries: 0));

        try {
            $client->models();
            self::fail('Expected an exception.');
        } catch (TransportException $exception) {
            self::assertStringNotContainsString('sk-super-secret-value', $exception->getMessage());
        }
    }
}
