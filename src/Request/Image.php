<?php

declare(strict_types=1);

namespace TypesafeAi\Request;

use TypesafeAi\Exception\AssetException;
use TypesafeAi\Uuid;

/**
 * An image to send with a {@see SystemOneRequest}, placed wherever text can go.
 *
 * Mirrors the official SDK (0.7.5)'s `Image`, with named constructors instead of its single
 * constructor, because a PHP string cannot say whether it holds image bytes or a path:
 * {@see self::fromBytes()}, {@see self::fromPath()} and {@see self::fromBase64()}.
 *
 * Put an `Image` anywhere in the request's content: as the whole `state`, inside a nested array or
 * object, or in a question's instructions or criteria. It is sent as a file part of a
 * `multipart/form-data` request, with the JSON body naming its part, instead of as base64 text
 * inside the JSON. A request with no image is still a plain JSON request, byte for byte.
 *
 * The API accepts images only from callers that opt in to this experimental feature with the
 * request header `TypeSafe-Danger-Zone-Images: true`. This client does not add that header: set it
 * with {@see \TypesafeAi\ClientOptions::$headers} or {@see SystemOneRequest::extraHeaders()}.
 *
 * Every image has a unique reference, its string form, which is also what it turns into inside the
 * JSON. Two images made from the same bytes are two images with two references, and `===` is the
 * only way two `Image` objects are the same image. A clone keeps the reference of its original.
 *
 * Memory: the bytes of an image are held once by the `Image`, and a request holds the whole
 * multipart body once more, while the PSR-7 stream a request is sent from usually copies it again.
 * Plan for about two to three times the size of the images being sent. `var_dump()` and `print_r()`
 * show where an image comes from and its size, never its bytes or its reference.
 *
 * Security: {@see self::fromPath()} reads a local file and the client sends its contents to the
 * API. Never pass it a path that came from an untrusted source.
 */
final class Image implements \JsonSerializable, \Stringable
{
    private const string BASE64_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';

    private readonly string $reference;

    private function __construct(
        private readonly ?string $path,
        private ?ResolvedImage $resolved,
    ) {
        $this->reference = '<image:' . Uuid::hex() . '>';
    }

    /**
     * An image from the bytes of an image file, such as an upload that is already in memory.
     *
     * The data is not decoded or checked: only PNG and JPEG are recognised, by their signatures, and
     * only to label the upload. The API validates the image.
     *
     * @throws \InvalidArgumentException if $bytes is empty.
     */
    public static function fromBytes(string $bytes): self
    {
        if ($bytes === '') {
            throw new \InvalidArgumentException('Image data is empty.');
        }

        return new self(null, new ResolvedImage($bytes));
    }

    /**
     * An image from a file on the local filesystem.
     *
     * The file is not read now. It is read the first time a request carrying the image is sent, so
     * it need not exist yet, and an unreadable file surfaces as an
     * {@see \TypesafeAi\Exception\AssetException} from that call, before any HTTP request. A
     * relative path is resolved at that moment too, against the working directory then. After a
     * successful read the contents are kept: later requests reuse them even if the file changes or
     * disappears. A failed read is not remembered, so the next request tries again.
     *
     * Only a plain path is accepted: a stream wrapper such as `php://`, `file://`, `http://` or
     * `data:` is rejected, since it could fetch something other than a local file. Never pass a
     * path that came from an untrusted source: the file's contents are sent to the API.
     *
     * @throws \InvalidArgumentException if the path is empty, contains a NUL byte, or is a stream
     *     wrapper URL.
     */
    public static function fromPath(string|\SplFileInfo $path): self
    {
        $path = $path instanceof \SplFileInfo ? $path->getPathname() : $path;

        if ($path === '') {
            throw new \InvalidArgumentException('Image path must not be empty.');
        }

        if (str_contains($path, "\0")) {
            throw new \InvalidArgumentException('Image path must not contain a NUL byte.');
        }

        // PHP hands a path to a stream wrapper when it starts with a scheme of two or more characters
        // from [A-Za-z0-9+.-] — a digit or a sign may come first — followed by "://" (php-src,
        // php_stream_locate_url_wrapper()); "data:" needs no slashes. A single letter is a drive.
        if (preg_match('~^[A-Za-z0-9+.-]{2,}://~', $path) === 1 || stripos($path, 'data:') === 0) {
            throw new \InvalidArgumentException(
                'Image path must be a path on the local filesystem, not a stream wrapper URL. Use '
                . 'Image::fromBytes() or Image::fromBase64() for data that is already in memory.',
            );
        }

        return new self($path, null);
    }

    /**
     * An image from base64 text, such as the field of a JSON upload.
     *
     * The text is decoded now. Spaces and line breaks, such as the line breaks of wrapped (MIME)
     * base64, are ignored. Everything else must be the standard base64 alphabet, with at most two
     * `=` of padding at the end and a length that is a multiple of four: unpadded text is
     * rejected, and so is a `data:` URI prefix.
     *
     * @throws \InvalidArgumentException if the text is empty, not ASCII, or not valid base64.
     */
    public static function fromBase64(string $data): self
    {
        if (preg_match('/[^\x00-\x7F]/', $data) === 1) {
            throw self::notAsciiText();
        }

        $text = strcspn($data, " \t\n\r\v\f") === strlen($data)
            ? $data
            : str_replace([' ', "\t", "\n", "\r", "\v", "\f"], '', $data);

        if ($text === '') {
            throw self::notAsciiText();
        }

        // base64_decode() in strict mode still accepts text whose padding is missing, so the shape
        // is checked first. strspn() looks at the text in place, which matters for an image that
        // can be megabytes long. At most two trailing "=" are padding; one anywhere else fails the
        // alphabet check.
        $length = strlen($text);
        $padding = 0;
        while ($padding < 2 && $padding < $length && $text[$length - 1 - $padding] === '=') {
            $padding++;
        }
        $unpadded = $length - $padding;

        if ($length % 4 !== 0 || strspn($text, self::BASE64_ALPHABET, 0, $unpadded) !== $unpadded) {
            throw self::invalidBase64();
        }

        $bytes = base64_decode($text, true);
        if ($bytes === false) {
            throw self::invalidBase64();
        }

        return self::fromBytes($bytes);
    }

    /**
     * The unique reference to this image, for putting it inside text, such as an interpolated
     * string: `"Compare {$image} with the listing."`. It looks like
     * `<image:0c0ea0b06f1641dd8e4b64f2cbbd4c25>` and is the same string the image becomes in the
     * request's JSON.
     */
    public function __toString(): string
    {
        return $this->reference;
    }

    /**
     * What the image becomes when a request is encoded as JSON: its reference. While a request is
     * being encoded for sending, this also records the image, so that it is uploaded.
     */
    public function jsonSerialize(): string
    {
        return References::write($this);
    }

    /**
     * The image with its bytes read and its content type found. Reads a path on the first call and
     * keeps the result, but only when the read succeeds.
     *
     * @internal
     *
     * @throws AssetException if a path could not be read, or holds no data. The message does not
     *     name the asset: the caller adds that.
     */
    public function resolve(): ResolvedImage
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        // Only an image made from a path is ever unresolved: every other constructor resolves it.
        $path = $this->path ?? throw new \LogicException('An image without a path is always resolved.');

        return $this->resolved = self::read($path);
    }

    /**
     * What var_dump() and print_r() show: where the image comes from and how big it is, never the
     * image itself or its reference. The size is null until a path has been read.
     *
     * @return array{source: string, path: ?string, bytes: ?int}
     */
    public function __debugInfo(): array
    {
        return [
            'source' => $this->path === null ? 'bytes' : 'path',
            'path' => $this->path,
            'bytes' => $this->resolved === null ? null : strlen($this->resolved->bytes),
        ];
    }

    /**
     * @throws AssetException
     */
    private static function read(string $path): ResolvedImage
    {
        // Every warning of the calls below is kept for the message instead of reaching the error
        // handler of the application, which could turn it into an exception of its own: is_dir()
        // warns when open_basedir does not allow the path, like file_get_contents().
        $warning = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });
        try {
            // file_get_contents() does not fail on a directory: it warns and returns an empty string.
            $isDirectory = is_dir($path);
            $bytes = $isDirectory ? false : file_get_contents($path);
        } finally {
            restore_error_handler();
        }

        if ($isDirectory) {
            throw self::unreadable($path, 'Is a directory');
        }

        if ($bytes === false) {
            throw self::unreadable($path, self::reasonFrom($warning));
        }

        if ($bytes === '') {
            throw new AssetException('Image data is empty.');
        }

        return new ResolvedImage($bytes);
    }

    /**
     * The warning of a failed read ends with the reason:
     * `file_get_contents(<path>): Failed to open stream: <reason>`.
     */
    private static function reasonFrom(mixed $warning): string
    {
        if (!is_string($warning)) {
            return 'unknown error';
        }

        $separator = strrpos($warning, ': ');

        return $separator === false ? $warning : substr($warning, $separator + 2);
    }

    private static function unreadable(string $path, string $reason): AssetException
    {
        return new AssetException(sprintf("Image file '%s' could not be read: %s.", $path, $reason));
    }

    private static function notAsciiText(): \InvalidArgumentException
    {
        return new \InvalidArgumentException('Image data must contain non-empty ASCII base64 text.');
    }

    private static function invalidBase64(): \InvalidArgumentException
    {
        return new \InvalidArgumentException('Image data must contain valid base64 text.');
    }
}
