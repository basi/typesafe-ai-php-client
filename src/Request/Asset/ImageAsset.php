<?php

declare(strict_types=1);

namespace TypesafeAi\Request\Asset;

/**
 * A PNG or JPEG image to send with a {@see \TypesafeAi\Request\SystemOneRequest}, as base64 text
 * inside the JSON request body.
 *
 * Images are an experimental feature of the API; see the README's "Images (experimental)" section
 * for how to reference an image from a request and what to prepare first. This class only checks
 * that the format name and the base64 text are well formed: it never decodes the image itself, so
 * the API stays the authority on whether the file is a valid image within the image limits in
 * {@see \TypesafeAi\Limits}.
 *
 * `var_dump()` and `print_r()` show the format and the decoded size instead of the base64 payload,
 * which can be megabytes long.
 */
final readonly class ImageAsset implements \JsonSerializable
{
    private const array FORMATS = ['png', 'jpg', 'jpeg'];

    private const string BASE64_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';

    private int $decodedBytes;

    /**
     * @param string $format `png`, `jpg`, or `jpeg`, in lowercase; `jpeg` is another name for `jpg`.
     * @param string $dataBase64 The image file, base64-encoded: non-empty, using the standard
     *     alphabet with `=` padding, with no line breaks or other whitespace and no `data:` prefix.
     *
     * @throws \InvalidArgumentException if $format or $dataBase64 is not one of the accepted forms.
     */
    public function __construct(
        private string $format,
        private string $dataBase64,
    ) {
        if (!in_array($format, self::FORMATS, true)) {
            throw new \InvalidArgumentException('format must be one of "png", "jpg", or "jpeg" (lowercase).');
        }

        $this->decodedBytes = self::decodedLength($dataBase64);
    }

    /**
     * Build an asset from the bytes of an image file.
     *
     * @param ?string $format `png`, `jpg`, or `jpeg`. When null, it is detected from the file
     *     signature: `png` for PNG data and `jpg` for JPEG data.
     *
     * @throws \InvalidArgumentException if $bytes is empty, or $format is null and $bytes is
     *     neither PNG nor JPEG data, or $format is not an accepted format name.
     */
    public static function fromBinary(string $bytes, ?string $format = null): self
    {
        if ($bytes === '') {
            throw new \InvalidArgumentException('image data must not be empty.');
        }

        return new self($format ?? self::detectFormat($bytes), base64_encode($bytes));
    }

    /**
     * Build an asset from an image file on the local filesystem. The whole file is read into
     * memory.
     *
     * @param ?string $format See {@see self::fromBinary()}.
     *
     * @throws \InvalidArgumentException if $path is not a readable file, or for any reason
     *     {@see self::fromBinary()} throws.
     */
    public static function fromFile(string $path, ?string $format = null): self
    {
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException(sprintf('image file "%s" does not exist or is not readable.', $path));
        }

        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new \InvalidArgumentException(sprintf('image file "%s" could not be read.', $path));
        }

        return self::fromBinary($bytes, $format);
    }

    public function format(): string
    {
        return $this->format;
    }

    public function dataBase64(): string
    {
        return $this->dataBase64;
    }

    /**
     * @return array{type: string, format: string, data_base64: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'type' => 'image',
            'format' => $this->format,
            'data_base64' => $this->dataBase64,
        ];
    }

    /**
     * @return array{format: string, decodedBytes: int}
     */
    public function __debugInfo(): array
    {
        return [
            'format' => $this->format,
            'decodedBytes' => $this->decodedBytes,
        ];
    }

    /**
     * Checks that $dataBase64 is well-formed base64 text and returns the length of what it decodes
     * to.
     *
     * @throws \InvalidArgumentException
     */
    private static function decodedLength(string $dataBase64): int
    {
        if ($dataBase64 === '') {
            throw new \InvalidArgumentException('dataBase64 must not be empty.');
        }

        if (str_starts_with($dataBase64, 'data:')) {
            throw new \InvalidArgumentException(
                'dataBase64 must be the base64 text alone, without a "data:" URI prefix.',
            );
        }

        // base64_decode() in strict mode still skips whitespace and accepts missing padding, so the
        // alphabet and the length are checked here first. strspn() examines the payload in place
        // rather than copying it, which matters for an image that can be megabytes long. At most
        // two trailing "=" characters are padding; one anywhere else is not in the alphabet.
        $length = strlen($dataBase64);
        $padding = 0;
        while ($padding < 2 && $padding < $length && $dataBase64[$length - 1 - $padding] === '=') {
            $padding++;
        }
        $unpaddedLength = $length - $padding;

        if (strspn($dataBase64, self::BASE64_ALPHABET, 0, $unpaddedLength) !== $unpaddedLength) {
            throw new \InvalidArgumentException(
                'dataBase64 must use only the standard base64 alphabet (A-Z, a-z, 0-9, "+" and "/"), '
                    . 'with "=" padding at the end and no whitespace.',
            );
        }

        if ($length % 4 !== 0) {
            throw new \InvalidArgumentException('dataBase64 length must be a multiple of 4; pad it with "=".');
        }

        $decoded = base64_decode($dataBase64, true);
        if ($decoded === false) {
            throw new \InvalidArgumentException('dataBase64 is not valid base64.');
        }

        return strlen($decoded);
    }

    /**
     * @throws \InvalidArgumentException if $bytes does not start with a PNG or JPEG signature.
     */
    private static function detectFormat(string $bytes): string
    {
        if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            return 'png';
        }

        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return 'jpg';
        }

        throw new \InvalidArgumentException(
            'image format could not be detected: expected PNG or JPEG data, or pass $format explicitly.',
        );
    }
}
