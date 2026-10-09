<?php

namespace App\Services\Microsoft;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\LazyCollection;
use RuntimeException;
use Throwable;

/**
 * Microsoft Graph with an application (app-only) token per tenant.
 *
 * Uses the multitenant app registration of `config('services.microsoft')`
 * (client credentials grant). Each tenant must grant admin consent to the
 * `User.Read.All` application permission.
 *
 * Tokens are cached per tenant (encrypted) until 60 seconds before they
 * expire. Requests time out after 30 s and are retried twice on 429/5xx or
 * connection errors, honouring `Retry-After` up to MAX_RETRY_AFTER_SECONDS.
 */
class GraphClient
{
    public const TOKEN_URL = 'https://login.microsoftonline.com/%s/oauth2/v2.0/token';

    public const GRAPH_URL = 'https://graph.microsoft.com/v1.0';

    public const SCOPE = 'https://graph.microsoft.com/.default';

    public const TIMEOUT_SECONDS = 30;

    /** Retries after the first attempt. */
    public const RETRIES = 2;

    public const MAX_RETRY_AFTER_SECONDS = 30;

    public const DEFAULT_BACKOFF_MILLISECONDS = 1000;

    public const USER_FIELDS = 'id,displayName,mail,userPrincipalName,jobTitle,department,accountEnabled,userType';

    public function __construct(
        private readonly Cache $cache,
    ) {}

    /**
     * The app registration (client id and secret) is set in the environment.
     */
    public static function isConfigured(): bool
    {
        return filled(config('services.microsoft.client_id')) && filled(config('services.microsoft.client_secret'));
    }

    /**
     * App-only access token of the tenant. `$fresh` skips the cached token
     * (Integraciones › "Probar conexión").
     *
     * @throws RuntimeException when the app credentials are not configured
     * @throws RequestException when Microsoft rejects the request
     */
    public function token(string $tenantId, bool $fresh = false): string
    {
        $tenantId = strtolower(trim($tenantId));

        if ($tenantId === '') {
            throw new RuntimeException('Microsoft tenant id is required.');
        }

        $cacheKey = 'microsoft-graph:token:'.$tenantId;
        $cached = $fresh ? null : $this->cache->get($cacheKey);

        if (is_string($cached)) {
            try {
                return Crypt::decryptString($cached);
            } catch (Throwable) {
                $this->cache->forget($cacheKey);
            }
        }

        $clientId = (string) config('services.microsoft.client_id');
        $clientSecret = (string) config('services.microsoft.client_secret');

        if (! self::isConfigured()) {
            throw new RuntimeException('Microsoft app credentials (MICROSOFT_CLIENT_ID / MICROSOFT_CLIENT_SECRET) are not configured.');
        }

        $response = $this->request()
            ->asForm()
            ->post(sprintf(self::TOKEN_URL, rawurlencode($tenantId)), [
                'grant_type' => 'client_credentials',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'scope' => self::SCOPE,
            ]);

        $token = (string) $response->json('access_token');

        if ($token === '') {
            throw new RuntimeException('Microsoft returned no access token for tenant '.$tenantId.'.');
        }

        $ttl = (int) $response->json('expires_in', 3600) - 60;

        if ($ttl > 0) {
            $this->cache->put($cacheKey, Crypt::encryptString($token), $ttl);
        }

        return $token;
    }

    /**
     * Every user of the tenant (members and guests; the caller filters),
     * with the manager id, following `@odata.nextLink`. Pages are fetched
     * lazily while the collection is iterated.
     *
     * @return LazyCollection<int, array<string, mixed>>
     */
    public function listUsers(string $tenantId): LazyCollection
    {
        return LazyCollection::make(function () use ($tenantId) {
            $url = self::GRAPH_URL.'/users?'.http_build_query([
                '$select' => self::USER_FIELDS,
                '$expand' => 'manager($select=id)',
            ], '', '&', PHP_QUERY_RFC3986);

            while ($url !== null) {
                $response = $this->request()
                    ->withToken($this->token($tenantId))
                    ->acceptJson()
                    ->get($url);

                foreach ((array) $response->json('value', []) as $user) {
                    if (is_array($user)) {
                        yield $user;
                    }
                }

                // Not json('@odata.nextLink'): the key contains a dot.
                $next = ((array) $response->json())['@odata.nextLink'] ?? null;
                $url = is_string($next) && $next !== '' ? $next : null;
            }
        });
    }

    private function request(): PendingRequest
    {
        return Http::timeout(self::TIMEOUT_SECONDS)
            ->connectTimeout(10)
            ->retry(
                self::RETRIES + 1,
                fn (int $attempt, Throwable $exception): int => $this->backoffMilliseconds($exception),
                fn (Throwable $exception): bool => $this->shouldRetry($exception),
            );
    }

    private function shouldRetry(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if (! $exception instanceof RequestException) {
            return false;
        }

        $status = $exception->response->status();

        return $status === 429 || $status >= 500;
    }

    private function backoffMilliseconds(Throwable $exception): int
    {
        $retryAfter = $exception instanceof RequestException
            ? $exception->response->header('Retry-After')
            : '';

        if (is_numeric($retryAfter) && (int) $retryAfter >= 0) {
            return min((int) $retryAfter, self::MAX_RETRY_AFTER_SECONDS) * 1000;
        }

        return self::DEFAULT_BACKOFF_MILLISECONDS;
    }
}
