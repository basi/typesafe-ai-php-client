<?php

declare(strict_types=1);

namespace TypesafeAi\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TypesafeAi\Exception\ApiException;
use TypesafeAi\Exception\ApiExceptionFactory;
use TypesafeAi\Exception\AuthenticationException;
use TypesafeAi\Exception\NotFoundException;
use TypesafeAi\Exception\RateLimitException;
use TypesafeAi\Exception\ServerException;
use TypesafeAi\Exception\ValidationException;

final class ApiExceptionFactoryTest extends TestCase
{
    /**
     * @return iterable<string, array{0: int, 1: class-string<ApiException>}>
     */
    public static function statusCodeProvider(): iterable
    {
        yield '400 is a generic ApiException' => [400, ApiException::class];
        yield '401 is an AuthenticationException' => [401, AuthenticationException::class];
        yield '403 is an AuthenticationException' => [403, AuthenticationException::class];
        yield '404 is a NotFoundException' => [404, NotFoundException::class];
        yield '422 is a ValidationException' => [422, ValidationException::class];
        yield '429 is a RateLimitException' => [429, RateLimitException::class];
        yield '500 is a ServerException' => [500, ServerException::class];
        yield '529 is a ServerException' => [529, ServerException::class];
        yield '599 is a ServerException' => [599, ServerException::class];
    }

    /**
     * @param class-string<ApiException> $expectedClass
     */
    #[Test]
    #[DataProvider('statusCodeProvider')]
    public function mapsStatusCodeToExceptionClassAndCode(int $statusCode, string $expectedClass): void
    {
        $exception = ApiExceptionFactory::fromResponse($statusCode, '{}', [], null);

        self::assertInstanceOf($expectedClass, $exception);
        self::assertSame($expectedClass, get_class($exception));
        self::assertSame($statusCode, $exception->getCode());
        self::assertSame($statusCode, $exception->getStatusCode());
    }

    #[Test]
    public function parsesErrorTypeAndMessageFromAnObjectDetail(): void
    {
        $body = json_encode([
            'detail' => ['error_type' => 'authentication_error', 'message' => 'Invalid API key'],
        ], JSON_THROW_ON_ERROR);

        $exception = ApiExceptionFactory::fromResponse(401, $body, [], 'req_abc');

        self::assertSame('authentication_error', $exception->getErrorType());
        self::assertSame('req_abc', $exception->getRequestId());
        self::assertStringContainsString('Invalid API key', $exception->getMessage());
        self::assertStringContainsString('401', $exception->getMessage());
        self::assertSame($body, $exception->getRawBody());
    }

    #[Test]
    public function parsesAStringDetail(): void
    {
        $body = json_encode(['detail' => 'Not Found'], JSON_THROW_ON_ERROR);

        $exception = ApiExceptionFactory::fromResponse(404, $body, [], null);

        self::assertNull($exception->getErrorType());
        self::assertStringContainsString('Not Found', $exception->getMessage());
    }

    #[Test]
    public function parsesValidationErrors(): void
    {
        $body = json_encode([
            'detail' => [
                ['loc' => ['body', 'state'], 'msg' => 'Field required', 'type' => 'missing'],
                ['loc' => ['body', 'questions'], 'msg' => 'Field required', 'type' => 'missing'],
            ],
        ], JSON_THROW_ON_ERROR);

        $exception = ApiExceptionFactory::fromResponse(422, $body, [], null);

        self::assertInstanceOf(ValidationException::class, $exception);
        self::assertSame([
            ['loc' => ['body', 'state'], 'msg' => 'Field required', 'type' => 'missing'],
            ['loc' => ['body', 'questions'], 'msg' => 'Field required', 'type' => 'missing'],
        ], $exception->getValidationErrors());
        self::assertStringContainsString('Field required', $exception->getMessage());
    }

    #[Test]
    public function retryAfterMsHeaderTakesPrecedenceOverRetryAfter(): void
    {
        $exception = ApiExceptionFactory::fromResponse(429, '{}', [
            'retry-after-ms' => '1500',
            'retry-after' => '30',
        ], null);

        self::assertInstanceOf(RateLimitException::class, $exception);
        self::assertSame(1500, $exception->getRetryAfterMs());
    }

    #[Test]
    public function retryAfterInSecondsIsConvertedToMilliseconds(): void
    {
        $exception = ApiExceptionFactory::fromResponse(429, '{}', ['retry-after' => '3'], null);

        self::assertInstanceOf(RateLimitException::class, $exception);
        self::assertSame(3000, $exception->getRetryAfterMs());
    }

    #[Test]
    public function retryAfterAsAnHttpDateIsConvertedToMilliseconds(): void
    {
        $target = gmdate('D, d M Y H:i:s', time() + 5) . ' GMT';

        $exception = ApiExceptionFactory::fromResponse(503, '{}', ['retry-after' => $target], null);

        self::assertInstanceOf(ServerException::class, $exception);
        $retryAfterMs = $exception->getRetryAfterMs();
        self::assertNotNull($retryAfterMs);
        self::assertGreaterThan(0, $retryAfterMs);
        self::assertLessThanOrEqual(6000, $retryAfterMs);
    }

    #[Test]
    public function retryAfterMsIsNullWhenNoHeaderIsPresent(): void
    {
        $exception = ApiExceptionFactory::fromResponse(429, '{}', [], null);

        self::assertInstanceOf(RateLimitException::class, $exception);
        self::assertNull($exception->getRetryAfterMs());
    }

    #[Test]
    public function toleratesANonJsonBody(): void
    {
        $exception = ApiExceptionFactory::fromResponse(500, 'Internal Server Error', [], null);

        self::assertInstanceOf(ServerException::class, $exception);
        self::assertNull($exception->getErrorType());
        self::assertSame('Internal Server Error', $exception->getRawBody());
        self::assertStringContainsString('500', $exception->getMessage());
    }

    #[Test]
    public function messageNeverIncludesRawBodyVerbatim(): void
    {
        $secretLookingBody = json_encode([
            'detail' => ['error_type' => 'authentication_error', 'message' => 'Bad key'],
            'leaked_api_key' => 'sk-should-not-appear-in-message',
        ], JSON_THROW_ON_ERROR);

        $exception = ApiExceptionFactory::fromResponse(401, $secretLookingBody, [], null);

        self::assertStringNotContainsString('sk-should-not-appear-in-message', $exception->getMessage());
        self::assertStringContainsString('sk-should-not-appear-in-message', $exception->getRawBody());
    }
}
