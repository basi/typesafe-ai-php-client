<?php

declare(strict_types=1);

namespace TypesafeAi\Tests\Live;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TypesafeAi\Client;
use TypesafeAi\ClientOptions;
use TypesafeAi\Exception\ApiException;
use TypesafeAi\Request\Image;
use TypesafeAi\Request\Question\BoxQuestion;
use TypesafeAi\Request\Question\ChoiceQuestion;
use TypesafeAi\Request\Question\NoulQuestion;
use TypesafeAi\Request\Question\ScoreQuestion;
use TypesafeAi\Request\Questions;
use TypesafeAi\Request\SystemOneRequest;
use TypesafeAi\Response\Answer\BoxAnswer;
use TypesafeAi\Response\Answer\ChoiceAnswer;
use TypesafeAi\Response\Answer\NoulAnswer;
use TypesafeAi\Response\Answer\ScoreAnswer;
use TypesafeAi\Tests\Support\UsesTemporaryDirectory;

/**
 * Sends images to the real API. Not part of the test suites that CI runs: it costs money, needs a
 * key and depends on how a model answers. Run it by hand:
 *
 * ```bash
 * TYPESAFE_API_KEY=... TYPESAFE_LIVE_IMAGES=1 vendor/bin/phpunit tests/Live
 * ```
 *
 * Ported from the official SDK (0.7.5)'s `test_integration_images.py`. Only synthetic pictures are
 * used: plain squares that this class draws itself.
 */
final class ImagesLiveTest extends TestCase
{
    use UsesTemporaryDirectory;

    /**
     * The API rejects images below a minimum size, so the pictures are comfortably above it.
     */
    private const int SIZE = 512;

    /**
     * The API only accepts a request with an image from callers that opt in to the feature.
     */
    private const string IMAGES_HEADER = 'TypeSafe-Danger-Zone-Images';

    private const string BOX_MODEL = 'jev-1.14.0';

    private static function apiKey(): string
    {
        $apiKey = getenv('TYPESAFE_API_KEY');
        $enabled = getenv('TYPESAFE_LIVE_IMAGES');
        $switchedOn = is_string($enabled) && !in_array($enabled, ['', '0'], true);
        if (!is_string($apiKey) || trim($apiKey) === '' || !$switchedOn) {
            self::markTestSkipped('Set TYPESAFE_API_KEY and TYPESAFE_LIVE_IMAGES=1 to run the live image tests.');
        }

        return $apiKey;
    }

    /**
     * A client that has opted in to images, or, when $optedIn is false, one that has not.
     */
    private static function client(bool $optedIn = true): Client
    {
        return Client::withGuzzle(self::apiKey(), new ClientOptions(
            timeoutSeconds: 120,
            headers: $optedIn ? [self::IMAGES_HEADER => 'true'] : [],
        ));
    }

    /**
     * A PNG made of the bytes of its rows, one `pack`ed RGB triple per pixel.
     *
     * @param \Closure(int): string $row The bytes of the row at the given height: three per pixel.
     */
    private static function png(int $width, int $height, \Closure $row): string
    {
        $scanlines = '';
        for ($y = 0; $y < $height; $y++) {
            $scanlines .= "\x00" . $row($y);
        }

        $chunk = static fn (string $type, string $data): string
            => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));

        return "\x89PNG\r\n\x1A\n"
            . $chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            . $chunk('IDAT', gzcompress($scanlines) ?: throw new \RuntimeException('Could not compress the image.'))
            . $chunk('IEND', '');
    }

    /**
     * A picture of one colour.
     *
     * @param int<0, 255> $red
     * @param int<0, 255> $green
     * @param int<0, 255> $blue
     */
    private static function solid(int $red, int $green, int $blue): string
    {
        $pixel = chr($red) . chr($green) . chr($blue);
        $row = str_repeat($pixel, self::SIZE);

        return self::png(self::SIZE, self::SIZE, static fn (): string => $row);
    }

    /**
     * White with a red square in the middle, a quarter of each side from the edges.
     */
    private static function redSquareOnWhite(): string
    {
        $white = "\xFF\xFF\xFF";
        $red = "\xFF\x00\x00";
        $quarter = intdiv(self::SIZE, 4);
        $plain = str_repeat($white, self::SIZE);
        $striped = str_repeat($white, $quarter) . str_repeat($red, 2 * $quarter) . str_repeat($white, $quarter);

        return self::png(
            self::SIZE,
            self::SIZE,
            static fn (int $y): string => $y >= $quarter && $y < 3 * $quarter ? $striped : $plain,
        );
    }

    #[Test]
    public function anImagePlacedInTheState(): void
    {
        $answer = self::client()->evaluate(
            ['Here is a photo:', Image::fromBytes(self::solid(255, 0, 0))],
            Questions::create()->noul('Is the photo a solid red square?'),
        );

        self::assertGreaterThan(0.5, $answer->noul(0)->noul());
    }

    #[Test]
    #[RequiresPhpExtension('gd')]
    public function aJpegMadeFromAGdImage(): void
    {
        $gd = imagecreatetruecolor(self::SIZE, self::SIZE);
        self::assertInstanceOf(\GdImage::class, $gd);
        $red = imagecolorallocate($gd, 255, 0, 0);
        self::assertIsInt($red);
        imagefilledrectangle($gd, 0, 0, self::SIZE - 1, self::SIZE - 1, $red);

        $answer = self::client()->evaluate(
            ['Here is a photo:', Image::fromGd($gd)],
            Questions::create()->noul('Is the photo a solid red square?'),
        );

        self::assertGreaterThan(0.5, $answer->noul(0)->noul());
    }

    #[Test]
    public function anImageUnderANameAndUnderItsReference(): void
    {
        // Sent under two names, so each gets a part of its own.
        $photo = Image::fromBytes(self::solid(255, 0, 0));

        $answer = self::client()->evaluate(
            "Compare <photo> with {$photo}.",
            Questions::create()->noul('Are both photos the same?'),
            assets: ['<photo>' => $photo],
        );

        self::assertGreaterThan(0.5, $answer->noul(0)->noul());
    }

    #[Test]
    public function anImageInAQuestionMadeFromBase64Text(): void
    {
        $photo = Image::fromBase64(base64_encode(self::solid(255, 0, 0)));

        $answer = self::client()->evaluate(
            'Look at the image in the question.',
            Questions::create()->noul(['Is this image a solid red square?', $photo]),
        );

        self::assertGreaterThan(0.5, $answer->noul(0)->noul());
    }

    #[Test]
    public function imagesAsTheCriteriaOfAChoiceAndOfAScore(): void
    {
        $redOption = Image::fromBytes(self::solid(255, 0, 0));
        $blueOption = Image::fromBytes(self::solid(0, 0, 255));
        $blueLevel = Image::fromBytes(self::solid(0, 0, 255));
        $redLevel = Image::fromBytes(self::solid(255, 0, 0));

        $response = self::client()->systemOne(new SystemOneRequest(
            Image::fromBytes(self::solid(255, 0, 0)),
            [
                'color' => new ChoiceQuestion(
                    'Which sample has the same color as the state image?',
                    ['red' => $redOption, 'blue' => $blueOption],
                ),
                'similarity' => new ScoreQuestion(
                    'How similar is the state image to the examples?',
                    [$blueLevel, $redLevel],
                ),
            ],
        ));

        $color = $response->answer('color');
        self::assertInstanceOf(ChoiceAnswer::class, $color);
        self::assertSame('red', $color->choice());

        $similarity = $response->answer('similarity');
        self::assertInstanceOf(ScoreAnswer::class, $similarity);
        self::assertGreaterThan(0.5, $similarity->score());
        // The answer repeats the criteria, which were images: it holds what the API stored for them.
        self::assertCount(2, $similarity->legend());
        self::assertSame([(string) $blueLevel, (string) $redLevel], array_values($similarity->legend()));
    }

    #[Test]
    public function anAttachedImageThatIsReadFromAFile(): void
    {
        $path = $this->temporaryFile('red.png', self::solid(255, 0, 0));
        $photo = Image::fromPath($path);
        $client = self::client();

        $response = $client->attachAssets($photo, fn () => $client->systemOne(new SystemOneRequest(
            "Here is a photo: {$photo}",
            ['red' => new NoulQuestion('Is the photo a solid red square?')],
        )));

        $answer = $response->answer('red');
        self::assertInstanceOf(NoulAnswer::class, $answer);
        self::assertGreaterThan(0.5, $answer->noul());
    }

    #[Test]
    public function aBoxQuestionLocatesTheRedSquare(): void
    {
        $response = self::client()->systemOne(new SystemOneRequest(
            Image::fromBytes(self::redSquareOnWhite()),
            ['red_square' => new BoxQuestion('The red square in the center')],
            self::BOX_MODEL,
        ));

        $box = $response->answer('red_square');
        self::assertInstanceOf(BoxAnswer::class, $box);
        self::assertSame($box, $response->boxes()['red_square']);
        $edges = $box->box();
        foreach ([$edges->top(), $edges->bottom(), $edges->left(), $edges->right()] as $edge) {
            self::assertGreaterThanOrEqual(0.0, $edge);
            self::assertLessThanOrEqual(1.0, $edge);
        }
        self::assertLessThan($edges->right(), $edges->left());
        self::assertLessThan($edges->bottom(), $edges->top());
        self::assertTrue($box->confidence() === null || ($box->confidence() >= 0.0 && $box->confidence() <= 1.0));
    }

    #[Test]
    public function aRequestWithAnImageIsRejectedWithoutTheHeader(): void
    {
        $client = self::client(optedIn: false);

        try {
            $client->evaluate(
                Image::fromBytes(self::solid(255, 0, 0)),
                Questions::create()->noul('Is the photo a solid red square?'),
            );
            self::fail('Expected the API to reject the request.');
        } catch (ApiException $exception) {
            self::assertSame(400, $exception->getStatusCode());
            self::assertNotSame('', $exception->getRawBody());
        }
    }
}
