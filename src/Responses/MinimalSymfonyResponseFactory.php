<?php

namespace League\Glide\Responses;

use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A SymfonyResponseFactory making as few calls to the cache file system as possible.
 *
 * SymfonyResponseFactory asks the cache for the stream, the mime type, the size
 * and the modification date of every image it serves: four calls, each an HTTP
 * request when the cache is an object store (S3, GCS...). This factory reads the
 * stream, takes the content type from the image's first bytes, and takes the size
 * and the date from the stream when it is a local file. Other streams cost one
 * more call, for the size.
 *
 * A conditional request (If-Modified-Since) costs the date only: an image that was
 * not modified is never opened.
 *
 * Trade-off: when the stream is not a local file, responses to unconditional
 * requests carry no Last-Modified header, which would cost one more call. Glide
 * URLs are signed and the responses are cacheable for a year, so clients seldom
 * revalidate them.
 */
class MinimalSymfonyResponseFactory implements ResponseFactoryInterface
{
    /**
     * Bytes read up front to tell the image format; enough for every format Glide encodes.
     */
    private const SNIFF_LENGTH = 16;

    /**
     * Request object to check "is not modified".
     */
    protected ?Request $request;

    /**
     * Create MinimalSymfonyResponseFactory instance.
     * @param Request|null $request Request object to check "is not modified".
     */
    public function __construct(?Request $request = null)
    {
        $this->request = $request;
    }

    /**
     * Create the response.
     * @param FilesystemOperator $cache The cache file system.
     * @param string             $path  The cached file path (untyped: league/glide 2 declares it so).
     */
    public function create(FilesystemOperator $cache, $path): StreamedResponse
    {
        $response = new StreamedResponse();
        $response->setPublic();
        $response->setMaxAge(31536000);
        $response->setExpires(date_create()->modify('+1 years'));

        if ($this->request && $this->request->headers->has('If-Modified-Since')) {
            $response->setLastModified(date_create()->setTimestamp($cache->lastModified($path)));

            if ($response->isNotModified($this->request)) {
                // Symfony 5 refuses to send a StreamedResponse without callback
                $response->setCallback(static function (): void {
                });

                return $response;
            }
        }

        $stream = $cache->readStream($path);
        $head = (string) stream_get_contents($stream, self::SNIFF_LENGTH);
        $size = null;

        // A local file states its size and date for free
        $meta = stream_get_meta_data($stream);
        if (isset($meta['wrapper_type']) && 'plainfile' === $meta['wrapper_type']) {
            $stat = fstat($stream);
            if (false !== $stat) {
                $size = $stat['size'];
                if (!$response->headers->has('Last-Modified')) {
                    $response->setLastModified(date_create()->setTimestamp($stat['mtime']));
                }
            }
        }

        $response->headers->set('Content-Type', self::sniffMimeType($head) ?? $this->storedMimeType($cache, $path));
        $response->headers->set('Content-Length', null !== $size ? $size : $cache->fileSize($path));

        $response->setCallback(static function () use ($stream, $head): void {
            echo $head;
            fpassthru($stream);
            fclose($stream);
        });

        return $response;
    }

    /**
     * The mime type the cache tells for a format Glide does not encode itself. A
     * cache unable to tell must not fail the request: the image is there.
     */
    protected function storedMimeType(FilesystemOperator $cache, string $path): string
    {
        try {
            return $cache->mimeType($path);
        } catch (FilesystemException $exception) {
            return 'application/octet-stream';
        }
    }

    /**
     * The mime type of the formats Glide encodes, from their magic bytes, or
     * null for any other format.
     */
    protected static function sniffMimeType(string $head): ?string
    {
        if (str_starts_with($head, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        if (str_starts_with($head, "\x89PNG\r\n\x1A\n")) {
            return 'image/png';
        }
        if (str_starts_with($head, 'GIF87a') || str_starts_with($head, 'GIF89a')) {
            return 'image/gif';
        }
        if (str_starts_with($head, 'RIFF') && 'WEBP' === substr($head, 8, 4)) {
            return 'image/webp';
        }
        if ('ftyp' === substr($head, 4, 4)) {
            $brand = substr($head, 8, 4);
            if ('avif' === $brand || 'avis' === $brand) {
                return 'image/avif';
            }
            if (in_array($brand, ['heic', 'heix', 'hevc', 'hevx'], true)) {
                return 'image/heic';
            }
        }
        if (str_starts_with($head, 'BM')) {
            return 'image/bmp';
        }
        if (str_starts_with($head, "II*\x00") || str_starts_with($head, "MM\x00*")) {
            return 'image/tiff';
        }

        return null;
    }
}
