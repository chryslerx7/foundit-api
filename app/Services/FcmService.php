<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class FcmService
{
    /**
     * Send push notification to a user's registered device tokens using FCM HTTP v1 API.
     * Keeps payloads privacy-safe (generic summaries, no PII or sensitive text).
     * Handles invalid tokens (cleans up stale tokens) and missing credentials gracefully.
     */
    public static function sendToUser(User $user, string $title, string $body, array $data = []): void
    {
        $tokens = $user->deviceTokens()->pluck('token')->toArray();

        if (empty($tokens)) {
            return;
        }

        $projectId = env('FIREBASE_PROJECT_ID') ?: config('services.fcm.project_id');
        $clientEmail = env('FIREBASE_CLIENT_EMAIL') ?: config('services.fcm.client_email');
        $privateKey = env('FIREBASE_PRIVATE_KEY') ?: config('services.fcm.private_key');

        if (empty($projectId) || empty($clientEmail) || empty($privateKey)) {
            Log::warning('FCM HTTP v1 Push Notification skipped: Firebase credentials (project_id, client_email, private_key) are not fully configured.');
            return;
        }

        $accessToken = self::getOAuthAccessToken($clientEmail, $privateKey);
        if (!$accessToken) {
            Log::error('FCM HTTP v1 Push Notification failed: Could not obtain OAuth2 access token.');
            return;
        }

        $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

        foreach ($tokens as $token) {
            try {
                $payload = [
                    'message' => [
                        'token' => $token,
                        'notification' => [
                            'title' => $title,
                            'body' => $body,
                        ],
                        'data' => array_map('strval', $data),
                    ],
                ];

                $response = Http::withToken($accessToken)
                    ->post($url, $payload);

                if ($response->failed()) {
                    $errorData = $response->json();
                    $errorCode = $errorData['error']['details'][0]['errorCode'] ?? $errorData['error']['status'] ?? '';
                    $errorMessage = $errorData['error']['message'] ?? $response->body();

                    if (in_array($errorCode, ['UNREGISTERED', 'INVALID_ARGUMENT', 'NOT_FOUND', 'Unregistered']) || str_contains(strtolower($errorMessage), 'notregistered') || str_contains(strtolower($errorMessage), 'requested entity was not found')) {
                        \App\Models\DeviceToken::where('token', $token)->delete();
                        Log::info("Removed invalid FCM HTTP v1 token: {$token}");
                    } else {
                        Log::warning("FCM HTTP v1 send failed for token {$token}: " . $errorMessage);
                    }
                }
            } catch (\Exception $e) {
                Log::error("FCM HTTP v1 Exception for token {$token}: " . $e->getMessage());
            }
        }
    }

    private static function getOAuthAccessToken(string $clientEmail, string $privateKey): ?string
    {
        return Cache::remember('fcm_oauth_access_token', 3300, function () use ($clientEmail, $privateKey) {
            try {
                $now = time();
                $header = json_encode(['alg' => 'RS256', 'typ' => 'JWT']);
                $payload = json_encode([
                    'iss' => $clientEmail,
                    'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                    'aud' => 'https://oauth2.googleapis.com/token',
                    'iat' => $now,
                    'exp' => $now + 3600,
                ]);

                $base64Header = self::base64UrlEncode($header);
                $base64Payload = self::base64UrlEncode($payload);
                $unsignedJwt = $base64Header . '.' . $base64Payload;

                // Format private key correctly if stored with escaped newlines
                $formattedKey = str_replace('\\n', "\n", $privateKey);

                $signature = '';
                if (!openssl_sign($unsignedJwt, $signature, $formattedKey, OPENSSL_ALGO_SHA256)) {
                    Log::error('FCM JWT signing failed.');
                    return null;
                }

                $base64Signature = self::base64UrlEncode($signature);
                $jwt = $unsignedJwt . '.' . $base64Signature;

                $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $jwt,
                ]);

                if ($response->successful()) {
                    return $response->json('access_token');
                }

                Log::error('Failed to obtain FCM OAuth2 token: ' . $response->body());
                return null;
            } catch (\Exception $e) {
                Log::error('FCM OAuth2 Exception: ' . $e->getMessage());
                return null;
            }
        });
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
