<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TokenService
{
    private const CACHE_KEY = 'external_api_token';
    private const TOKEN_TTL = 3500; // Cache for ~58 minutes (tokens usually expire in 1 hour)

    private string $tokenUrl;
    private string $user;
    private string $password;
    private string $rtd;

    public function __construct()
    {
        $baseUrl = env('EXTERNAL_API_BASE_URL', 'http://14.203.153.238:58200/rest');
        $this->tokenUrl = $baseUrl . '/GetSecurityToken/JSON';
        $this->user = env('EXTERNAL_API_USER', 'web');
        $this->password = env('EXTERNAL_API_PASSWORD', 'web@2025');
        $this->rtd = env('EXTERNAL_API_RTD', 'CITY FARMERS MALAGA');
    }

    /**
     * Get a valid token, refreshing if necessary
     */
    public function getToken(): string
    {
        // Try to get from cache first
        $token = Cache::get(self::CACHE_KEY);

        if ($token) {
            return $token;
        }

        // Token not in cache or expired, fetch a new one
        return $this->refreshToken();
    }

    /**
     * Force refresh the token
     */
    public function refreshToken(): string
    {
        Log::info('Refreshing external API token...');

        $response = Http::timeout(30)->get($this->tokenUrl, [
            'user' => $this->user,
            'pwd' => $this->password,
            'rtd' => $this->rtd
        ]);

        if (!$response->successful()) {
            Log::error('Failed to refresh token', ['response' => $response->body()]);
            throw new \Exception('Failed to refresh API token: ' . $response->body());
        }

        $data = $response->json();

        // The token might be in different formats depending on API response
        $token = $data['Token'] ?? $data['token'] ?? $data['SecurityToken'] ?? $data;

        if (is_array($token)) {
            // If token is still an array, try to extract string value
            $token = $token['Token'] ?? $token['token'] ?? json_encode($token);
        }

        if (empty($token) || !is_string($token)) {
            Log::error('Invalid token response', ['data' => $data]);
            throw new \Exception('Invalid token received from API');
        }

        // Cache the token
        Cache::put(self::CACHE_KEY, $token, self::TOKEN_TTL);

        Log::info('Token refreshed successfully');

        return $token;
    }

    /**
     * Clear the cached token (useful when token is known to be invalid)
     */
    public function clearToken(): void
    {
        Cache::forget(self::CACHE_KEY);
        Log::info('Token cache cleared');
    }

    /**
     * Execute a callback with automatic token refresh on failure
     * If the callback fails with token error, refresh token and retry once
     */
    public function withAutoRefresh(callable $callback)
    {
        try {
            return $callback($this->getToken());
        } catch (\Exception $e) {
            // Check if it's a token-related error
            $message = strtolower($e->getMessage());
            if (str_contains($message, 'token') && (str_contains($message, 'invalid') || str_contains($message, 'expired'))) {
                Log::warning('Token expired, refreshing and retrying...');
                $this->clearToken();
                return $callback($this->refreshToken());
            }
            throw $e;
        }
    }
}
