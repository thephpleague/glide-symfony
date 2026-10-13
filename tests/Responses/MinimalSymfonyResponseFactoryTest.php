<?php

namespace Responses;

use League\Flysystem\UnableToRetrieveMetadata;
use League\Glide\Responses\MinimalSymfonyResponseFactory;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class MinimalSymfonyResponseFactoryTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    public function tearDown(): void
    {
        Mockery::close();

        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    public function testCreateInstance(): void
    {
        self::assertInstanceOf(
            'League\Glide\Responses\ResponseFactoryInterface',
            new MinimalSymfonyResponseFactory()
        );
    }

    public function testALocalImageCostsOneCall(): void
    {
        $file = $this->file("\xFF\xD8\xFF\xE0".str_repeat('x', 100), strtotime('2025-01-01'));
        $cache = Mockery::mock('League\Flysystem\FilesystemOperator', function ($mock) use ($file) {
            $mock->shouldReceive('readStream')->andReturn(fopen($file, 'r'))->once();
            $mock->shouldNotReceive('mimeType', 'fileSize', 'lastModified');
        });

        $response = (new MinimalSymfonyResponseFactory(new Request()))->create($cache, 'image.jpg');

        self::assertInstanceOf('Symfony\Component\HttpFoundation\StreamedResponse', $response);
        self::assertEquals('image/jpeg', $response->headers->get('Content-Type'));
        self::assertEquals('104', $response->headers->get('Content-Length'));
        self::assertEquals('Wed, 01 Jan 2025 00:00:00 GMT', $response->headers->get('Last-Modified'));
        self::assertStringContainsString(gmdate('D, d M Y H:i', strtotime('+1 years')), $response->headers->get('Expires'));
        self::assertEquals('max-age=31536000, public', $response->headers->get('Cache-Control'));
        self::assertSame("\xFF\xD8\xFF\xE0".str_repeat('x', 100), $this->body($response));
    }

    public function testARemoteImageCostsTwoCalls(): void
    {
        $cache = Mockery::mock('League\Flysystem\FilesystemOperator', function ($mock) {
            $mock->shouldReceive('readStream')->andReturn($this->memoryStream("\x89PNG\r\n\x1A\n".str_repeat('x', 92)))->once();
            $mock->shouldReceive('fileSize')->andReturn(100)->once();
            $mock->shouldNotReceive('mimeType', 'lastModified');
        });

        $response = (new MinimalSymfonyResponseFactory(new Request()))->create($cache, 'image.png');

        self::assertEquals('image/png', $response->headers->get('Content-Type'));
        self::assertEquals('100', $response->headers->get('Content-Length'));
        self::assertFalse($response->headers->has('Last-Modified'), 'The date would cost one more call.');
        self::assertSame("\x89PNG\r\n\x1A\n".str_repeat('x', 92), $this->body($response));
    }

    public function testANotModifiedImageIsNeverOpened(): void
    {
        $cache = Mockery::mock('League\Flysystem\FilesystemOperator', function ($mock) {
            $mock->shouldReceive('lastModified')->andReturn(strtotime('2025-01-01'))->once();
            $mock->shouldNotReceive('readStream', 'mimeType', 'fileSize');
        });
        $request = new Request();
        $request->headers->set('If-Modified-Since', 'Wed, 01 Jan 2025 00:00:00 GMT');

        $response = (new MinimalSymfonyResponseFactory($request))->create($cache, 'image.jpg');

        self::assertEquals(Response::HTTP_NOT_MODIFIED, $response->getStatusCode());
        self::assertSame('', $this->body($response));
    }

    public function testAModifiedImageIsSentInFull(): void
    {
        $cache = Mockery::mock('League\Flysystem\FilesystemOperator', function ($mock) {
            $mock->shouldReceive('lastModified')->andReturn(strtotime('2025-01-01'))->once();
            $mock->shouldReceive('readStream')->andReturn($this->memoryStream('RIFF'."\x00\x00\x00\x00".'WEBPVP8 '))->once();
            $mock->shouldReceive('fileSize')->andReturn(16)->once();
            $mock->shouldNotReceive('mimeType');
        });
        $request = new Request();
        $request->headers->set('If-Modified-Since', 'Tue, 31 Dec 2024 00:00:00 GMT');

        $response = (new MinimalSymfonyResponseFactory($request))->create($cache, 'image.webp');

        self::assertEquals(Response::HTTP_OK, $response->getStatusCode());
        self::assertEquals('image/webp', $response->headers->get('Content-Type'));
        self::assertEquals('Wed, 01 Jan 2025 00:00:00 GMT', $response->headers->get('Last-Modified'));
        self::assertSame('RIFF'."\x00\x00\x00\x00".'WEBPVP8 ', $this->body($response));
    }

    public function testWithoutRequestConditionalHeadersAreIgnored(): void
    {
        $cache = Mockery::mock('League\Flysystem\FilesystemOperator', function ($mock) {
            $mock->shouldReceive('readStream')->andReturn($this->memoryStream('GIF89a'))->once();
            $mock->shouldReceive('fileSize')->andReturn(6)->once();
            $mock->shouldNotReceive('mimeType', 'lastModified');
        });

        $response = (new MinimalSymfonyResponseFactory())->create($cache, 'image.gif');

        self::assertEquals(Response::HTTP_OK, $response->getStatusCode());
        self::assertEquals('image/gif', $response->headers->get('Content-Type'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function imageProvider(): iterable
    {
        yield 'jpg' => ["\xFF\xD8\xFF\xE0\x00\x10JFIF\x00", 'image/jpeg'];
        yield 'png' => ["\x89PNG\r\n\x1A\n\x00\x00\x00\x0DIHDR", 'image/png'];
        yield 'gif' => ['GIF87a'."\x01\x00\x01\x00", 'image/gif'];
        yield 'webp' => ['RIFF'."\x24\x00\x00\x00".'WEBPVP8 ', 'image/webp'];
        yield 'avif' => ["\x00\x00\x00\x1C".'ftypavif'."\x00\x00\x00\x00", 'image/avif'];
        yield 'heic' => ["\x00\x00\x00\x18".'ftypheic'."\x00\x00\x00\x00", 'image/heic'];
        yield 'bmp' => ['BM'."\x3A\x00\x00\x00", 'image/bmp'];
        yield 'tiff' => ["II*\x00\x08\x00\x00\x00", 'image/tiff'];
    }

    #[DataProvider('imageProvider')]
    public function testTheContentTypeIsReadFromTheImage(string $bytes, string $mimeType): void
    {
        $cache = Mockery::mock('League\Flysystem\FilesystemOperator', function ($mock) use ($bytes) {
            $mock->shouldReceive('readStream')->andReturn($this->memoryStream($bytes));
            $mock->shouldReceive('fileSize')->andReturn(strlen($bytes));
            $mock->shouldNotReceive('mimeType');
        });

        $response = (new MinimalSymfonyResponseFactory())->create($cache, 'image');

        self::assertEquals($mimeType, $response->headers->get('Content-Type'));
    }

    public function testAnUnknownFormatIsAskedToTheCache(): void
    {
        $cache = Mockery::mock('League\Flysystem\FilesystemOperator', function ($mock) {
            $mock->shouldReceive('readStream')->andReturn($this->memoryStream('<svg/>'));
            $mock->shouldReceive('fileSize')->andReturn(6);
            $mock->shouldReceive('mimeType')->andReturn('image/svg+xml')->once();
        });

        $response = (new MinimalSymfonyResponseFactory())->create($cache, 'image.svg');

        self::assertEquals('image/svg+xml', $response->headers->get('Content-Type'));
    }

    public function testAFormatNobodyCanTellIsStillServed(): void
    {
        $cache = Mockery::mock('League\Flysystem\FilesystemOperator', function ($mock) {
            $mock->shouldReceive('readStream')->andReturn($this->memoryStream('unknown'));
            $mock->shouldReceive('fileSize')->andReturn(7);
            $mock->shouldReceive('mimeType')->andThrow(UnableToRetrieveMetadata::mimeType('image'));
        });

        $response = (new MinimalSymfonyResponseFactory())->create($cache, 'image');

        self::assertEquals('application/octet-stream', $response->headers->get('Content-Type'));
        self::assertSame('unknown', $this->body($response));
    }

    private function file(string $contents, int $mtime): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'glide');
        file_put_contents($file, $contents);
        touch($file, $mtime);
        $this->files[] = $file;

        return $file;
    }

    /**
     * @return resource
     */
    private function memoryStream(string $contents)
    {
        $stream = fopen('php://memory', 'w+');
        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }

    private function body(Response $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }
}
