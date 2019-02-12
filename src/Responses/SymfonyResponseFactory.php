<?php

namespace League\Glide\Responses;

use League\Flysystem\FilesystemInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SymfonyResponseFactory implements ResponseFactoryInterface
{
    /**
     * Request object to check "is not modified".
     * @var Request|null
     */
    protected $request;

    /**
     * Create SymfonyResponseFactory instance.
     * @param Request|null $request Request object to check "is not modified".
     */
    public function __construct(Request $request = null)
    {
        $this->request = $request;
    }

    /**
     * Create the response.
     * @param  FilesystemInterface $cache The cache file system.
     * @param  string              $path  The cached file path.
     * @return StreamedResponse    The response object.
     */
    public function create(FilesystemInterface $cache, $path)
    {
        $stream = $cache->readStream($path);

        $response = new StreamedResponse();
        $response->headers->set('Content-Type', $cache->getMimetype($path));
        $response->headers->set('Content-Length', $cache->getSize($path));
        $response->setPublic();
        $response->setMaxAge(31536000);
        $response->setExpires(date_create()->modify('+1 years'));

        if ($this->request) {
            $metadata = $cache->getMetadata($path);
            if (isset($metadata['etag'])) {
                $response->setEtag($metadata['etag']);
                $request_etags = $this->request->getETags();
                if ($request_etags && $request_etags[0] == $metadata['etag']) {
                    $response->setNotModified();
                }
            }
            $response->setLastModified(date_create()->setTimestamp($cache->getTimestamp($path)));
            $response->isNotModified($this->request);
        }

        $response->setCallback(function () use ($stream) {
            if (ftell($stream) !== 0) {
                rewind($stream);
            }
            fpassthru($stream);
            fclose($stream);
        });

        return $response;
    }
}
