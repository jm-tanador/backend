<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\User;

class GoogleAuthController extends Controller
{
    // Step 1: Redirect user to Google consent screen
    public function redirectToGoogle()
    {
        $query = http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => config('services.google.redirect_uri'),
            'response_type' => 'code',
            'scope' => 'openid profile email https://www.googleapis.com/auth/youtube.readonly',
            'access_type' => 'offline',
            'prompt' => 'consent',
        ]);

        return redirect("https://accounts.google.com/o/oauth2/v2/auth?{$query}");
    }

    // Step 2: Handle callback from Google
   // Step 2: Handle callback from Google
public function handleGoogleCallback(Request $request)
{
    $frontendUrl = env('FRONTEND_URL', 'http://localhost:5173');

    $code = $request->query('code');
    if (!$code) {
        return redirect("{$frontendUrl}?error=no_code");
    }

    // Exchange authorization code for tokens
    $tokenResponse = Http::asForm()->post('https://oauth2.googleapis.com/token', [
        'client_id' => config('services.google.client_id'),
        'client_secret' => config('services.google.client_secret'),
        'code' => $code,
        'grant_type' => 'authorization_code',
        'redirect_uri' => config('services.google.redirect_uri'),
    ]);

    if (!$tokenResponse->successful()) {
        return redirect("{$frontendUrl}?error=token_failed");
    }

    $tokens = $tokenResponse->json();
    $accessToken = $tokens['access_token'];

    // Get Google user info
    $userResponse = Http::withToken($accessToken)
        ->get('https://www.googleapis.com/oauth2/v3/userinfo');

    $googleUser = $userResponse->json();

    // Save or update user (find by email)
    $user = User::updateOrCreate([
        'email' => $googleUser['email'], // <-- Search by email to prevent duplicate constraint errors
    ], [
        'name' => $googleUser['name'] ?? '',
        'google_id' => $googleUser['sub'] ?? null,
        'avatar' => $googleUser['picture'] ?? '',
        'google_token' => $accessToken,
        'google_refresh_token' => $tokens['refresh_token'] ?? null,
    ]);

    // Generate Sanctum token for Vue
    $token = $user->createToken('auth-token')->plainTextToken;

    return redirect("{$frontendUrl}?token={$token}");
}
}