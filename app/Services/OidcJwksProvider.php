<?php

namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class OidcJwksProvider
{
    private int $cacheTtl;

    public function __construct(
        private readonly ?string $discoveryUrl = null,
        private readonly ?string $issuer = null,
        ?int $cacheTtl = null,
    ) {
        $this->cacheTtl = $cacheTtl ?? (int) Config::get('oidc.cache_ttl', 300);
    }

    /**
     * Retrieve the JWKS document, falling back to cache if the remote endpoint fails.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getJwks(): array
    {
        $cacheKey = $this->cacheKey('jwks');
        $cached = Cache::get($cacheKey);
        $stale = Cache::get($this->staleKey('jwks'));

        try {
            return Cache::remember($cacheKey, $this->cacheTtl, function () {
                $document = $this->getDiscoveryDocument();
                $jwksUri = Arr::get($document, 'jwks_uri');

                if (! $jwksUri) {
                    throw new RuntimeException('jwks_uri is missing from discovery document.');
                }

                $response = Http::timeout(10)
                    ->retry(2, 200)
                    ->get($jwksUri)
                    ->throw();

                $payload = $response->json();

                $keys = Arr::get($payload, 'keys');
                if (! is_array($keys) || $keys === []) {
                    throw new RuntimeException('JWKS payload is empty.');
                }

                Cache::forever($this->staleKey('jwks'), $keys);

                return $keys;
            });
        } catch (Throwable $exception) {
            if ($cached) {
                return $cached;
            }
            if ($stale) {
                return $stale;
            }

            throw $exception;
        }
    }

    /**
     * Retrieve the discovery document (cached).
     *
     * @return array<string, mixed>
     */
    public function getDiscoveryDocument(): array
    {
        $discoveryUrl = $this->discoveryUrl ?? Config::get('oidc.discovery');
        if (! $discoveryUrl) {
            throw new RuntimeException('OIDC discovery endpoint has not been configured.');
        }

        $cacheKey = $this->cacheKey('discovery');
        $cached = Cache::get($cacheKey);
        $stale = Cache::get($this->staleKey('discovery'));

        try {
            return Cache::remember($cacheKey, $this->cacheTtl, function () use ($discoveryUrl) {
                try {
                    $response = Http::timeout(10)
                        ->retry(2, 200)
                        ->get($discoveryUrl)
                        ->throw();
                } catch (RequestException $exception) {
                    throw new RuntimeException('Unable to reach OIDC discovery endpoint.', 0, $exception);
                }

                $payload = $response->json();
                if (! is_array($payload)) {
                    throw new RuntimeException('OIDC discovery document is invalid.');
                }

                $issuer = $this->issuer ?? Config::get('oidc.issuer');
                if ($issuer && Arr::get($payload, 'issuer') && Arr::get($payload, 'issuer') !== $issuer) {
                    throw new RuntimeException('Issuer mismatch between discovery and configuration.');
                }

                Cache::forever($this->staleKey('discovery'), $payload);

                return $payload;
            });
        } catch (Throwable $exception) {
            if ($cached) {
                return $cached;
            }
            if ($stale) {
                return $stale;
            }

            throw $exception;
        }
    }

    private function cacheKey(string $suffix): string
    {
        $issuer = $this->issuer ?? Config::get('oidc.issuer', 'oidc');

        return sprintf('oidc:%s:%s', sha1((string) $issuer), $suffix);
    }

    private function staleKey(string $suffix): string
    {
        return $this->cacheKey($suffix).':stale';
    }
}
