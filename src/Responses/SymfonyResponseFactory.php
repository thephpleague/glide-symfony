<?php

declare(strict_types=1);

namespace League\Glide\Responses;

use League\Flysystem\FilesystemOperator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SymfonyResponseFactory implements ResponseFactoryInterface
{
    /**
     * Create SymfonyResponseFactory instance.
     *
     * @param Request|null $request Request object to check "is not modified".
     */
    public function __construct(
        protected ?Request $request = null,
    ) {
    }

    /**
     * Create the response.
     *
     * $path is left untyped on purpose: league/glide 2.x declares it untyped in
     * ResponseFactoryInterface, and narrowing it to string would be a fatal error there.
     *
     * @param FilesystemOperator $cache The cache file system.
     * @param string             $path  The cached file path.
     *
     * @return StreamedResponse The response object.
     */
    public function create(FilesystemOperator $cache, $path): StreamedResponse
    {
        $stream = $cache->readStream($path);

        $response = new StreamedResponse();
        $response->headers->set('Content-Type', $cache->mimeType($path));
        $response->headers->set('Content-Length', (string) $cache->fileSize($path));
        $response->setPublic();
        $response->setMaxAge(31536000);
        $response->setExpires(date_create()->modify('+1 years'));

        if ($this->request) {
            $response->setLastModified(date_create()->setTimestamp($cache->lastModified($path)));
            $response->isNotModified($this->request);
        }

        $response->setCallback(function () use ($stream): void {
            if (0 !== ftell($stream)) {
                rewind($stream);
            }
            fpassthru($stream);
            fclose($stream);
        });

        return $response;
    }
}
