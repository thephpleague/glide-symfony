# Glide adapter for Symfony

[![Author](http://img.shields.io/badge/author-@reinink-blue.svg?style=flat-square)](https://twitter.com/reinink)
[![Latest Version](https://img.shields.io/github/release/thephpleague/glide-symfony.svg?style=flat-square)](https://github.com/thephpleague/glide-symfony/releases)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](https://github.com/thephpleague/glide-symfony/blob/master/LICENSE)
[![Build Status](https://img.shields.io/travis/thephpleague/glide-symfony/master.svg?style=flat-square)](https://travis-ci.org/thephpleague/glide-symfony)
[![HHVM Status](https://img.shields.io/hhvm/league/glide-symfony.svg?style=flat-square)](http://hhvm.h4cc.de/package/league/glide-symfony)
[![Total Downloads](https://img.shields.io/packagist/dt/league/glide-symfony.svg?style=flat-square)](https://packagist.org/packages/league/glide-symfony)

## Installation

```bash
composer require league/glide-symfony
```

## Usage

```php
use League\Glide\Responses\SymfonyResponseFactory;

$server->setResponseFactory(new SymfonyResponseFactory($request));
```

### Fewer calls to the cache

`SymfonyResponseFactory` asks the cache for the stream, the mime type, the size and the modification date of every image it serves. When the cache is an object store (S3, GCS, Azure...), each of these calls is an HTTP request. `MinimalSymfonyResponseFactory` builds the same response with fewer:

```php
use League\Glide\Responses\MinimalSymfonyResponseFactory;

$server->setResponseFactory(new MinimalSymfonyResponseFactory($request));
```

| Cache calls per image served (including Glide's `fileExists()`) | `SymfonyResponseFactory` | `MinimalSymfonyResponseFactory` |
| ---------------------------------------------------------------- | ------------------------ | ------------------------------- |
| Local cache                                                      | 5                        | 2                               |
| Remote cache (object store)                                      | 5                        | 3                               |
| `If-Modified-Since`, not modified (304)                          | 5, image opened          | 2, image never opened           |

- The content type is read from the image's first bytes (JPEG, PNG, GIF, WebP, AVIF, HEIC, BMP, TIFF); other formats are asked to the cache.
- The size and modification date come from the stream when it is a local file.
- Trade-off: with a remote cache, responses to unconditional requests carry no `Last-Modified` header, which would cost one more call. Glide URLs are signed and responses are cacheable for a year, so clients seldom revalidate them.

## Documentation

Full documentation can be found at [glide.thephpleague.com](http://glide.thephpleague.com).
