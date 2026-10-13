<?php

declare(strict_types=1);

namespace League\Glide\Tests\Responses;

use League\Flysystem\FilesystemOperator;
use League\Glide\Responses\SymfonyResponseFactory;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SymfonyResponseFactoryTest extends TestCase
{
    public function tearDown(): void
    {
        Mockery::close();
    }

    public function testCreateInstance(): void
    {
        self::assertInstanceOf(
            SymfonyResponseFactory::class,
            new SymfonyResponseFactory()
        );
    }

    public function testCreate(): void
    {
        $cache = Mockery::mock(FilesystemOperator::class, function (MockInterface $mock): void {
            $mock->shouldReceive('mimeType')->andReturn('image/jpeg')->once();
            $mock->shouldReceive('fileSize')->andReturn(0)->once();
            $mock->shouldReceive('readStream');
        });

        $factory = new SymfonyResponseFactory();
        $response = $factory->create($cache, '');

        self::assertInstanceOf(StreamedResponse::class, $response);
        self::assertEquals('image/jpeg', $response->headers->get('Content-Type'));
        self::assertEquals('0', $response->headers->get('Content-Length'));
        self::assertStringContainsString(gmdate('D, d M Y H:i', strtotime('+1 years')), $response->headers->get('Expires'));
        self::assertEquals('max-age=31536000, public', $response->headers->get('Cache-Control'));
    }

    public function testCreateWithRequest(): void
    {
        $cache = Mockery::mock(FilesystemOperator::class, function (MockInterface $mock): void {
            $mock->shouldReceive('mimeType')->andReturn('image/jpeg')->once();
            $mock->shouldReceive('fileSize')->andReturn(0)->once();
            $mock->shouldReceive('readStream');
            $mock->shouldReceive('lastModified')->andReturn(strtotime('2025-01-01'));
        });

        $factory = new SymfonyResponseFactory(new Request());
        $response = $factory->create($cache, '');

        self::assertInstanceOf(StreamedResponse::class, $response);
        self::assertEquals('image/jpeg', $response->headers->get('Content-Type'));
        self::assertEquals('0', $response->headers->get('Content-Length'));
        self::assertStringContainsString(gmdate('D, d M Y H:i', strtotime('+1 years')), $response->headers->get('Expires'));
        self::assertEquals('max-age=31536000, public', $response->headers->get('Cache-Control'));
        self::assertEquals('Wed, 01 Jan 2025 00:00:00 GMT', $response->headers->get('Last-Modified'));
    }
}
