<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VideoController extends Controller
{
    private $baseUrl = 'https://www.googleapis.com/youtube/v3';

    /**
     * Get the array of API keys configured in services.php
     */
    private function getApiKeys(): array
    {
        $keys = config('services.youtube.keys', []);

        // Filter out any empty values
        return array_filter(array_map('trim', $keys));
    }

    /**
     * Executes a GET request against the YouTube API, rotating keys if quota is exceeded.
     */
    private function executeRequestWithKeyRotation(string $endpoint, array $params = [])
    {
        $apiKeys = $this->getApiKeys();

        if (empty($apiKeys)) {
            Log::warning('No YouTube API keys configured.');
            return null;
        }

        foreach ($apiKeys as $index => $apiKey) {
            try {
                $requestParams = array_merge($params, ['key' => $apiKey]);

                $response = Http::timeout(3)
                    ->get("{$this->baseUrl}/{$endpoint}", $requestParams);

                // If the request is successful, return the response data
                if ($response->successful()) {
                    return $response->json();
                }

                // YouTube returns HTTP 403 when quota is exceeded
                if ($response->status() === 403) {
                    Log::warning("YouTube API Key index [{$index}] quota exceeded or forbidden. Trying next key...");
                    continue; // Move to the next key
                }

                // If other client/server error occurs, log and try next key
                Log::warning("YouTube API returned status {$response->status()} for key index [{$index}].");
            } catch (\Exception $e) {
                Log::warning("YouTube API request failed on key index [{$index}]: " . $e->getMessage());
            }
        }

        // All keys failed or timed out
        return null;
    }

    public function search(Request $request)
    {
        $query = $request->query('q', 'trending');

        $data = $this->executeRequestWithKeyRotation('search', [
            'part' => 'snippet',
            'q' => $query,
            'maxResults' => 30,
            'type' => 'video'
        ]);

        if ($data !== null) {
            return response()->json($data);
        }

        // Fallback to offline mock data if all keys fail or network issues persist
        Log::warning('All YouTube API keys exhausted or network blocked. Falling back to offline mock data.');
        return response()->json($this->getMockSearchData($query));
    }

    public function show($id)
    {
        $data = $this->executeRequestWithKeyRotation('videos', [
            'part' => 'snippet,statistics',
            'id' => $id
        ]);

        if ($data !== null) {
            return response()->json($data);
        }

        Log::warning('YouTube API Details failed across all keys. Falling back to offline mock data.');
        return response()->json($this->getMockVideoDetails($id));
    }

    /**
     * Offline mock data for Search
     */
    private function getMockSearchData($query)
    {
        return [
            'items' => [
                [
                    'id' => ['videoId' => 'dQw4w9WgXcQ'],
                    'snippet' => [
                        'title' => "Offline Mode: Vue 3 Basics (Search: {$query})",
                        'channelTitle' => 'Frontend Academy',
                        'thumbnails' => [
                            'medium' => ['url' => 'https://picsum.photos/id/1/320/180']
                        ]
                    ]
                ],
                [
                    'id' => ['videoId' => 'bMknfKXIFA8'],
                    'snippet' => [
                        'title' => "Offline Mode: Laravel 9 Controllers (Search: {$query})",
                        'channelTitle' => 'Backend Casts',
                        'thumbnails' => [
                            'medium' => ['url' => 'https://picsum.photos/id/2/320/180']
                        ]
                    ]
                ],
                [
                    'id' => ['videoId' => '2g811Eo7K8U'],
                    'snippet' => [
                        'title' => "Offline Mode: Deploying to Netlify (Search: {$query})",
                        'channelTitle' => 'Deployment Tips',
                        'thumbnails' => [
                            'medium' => ['url' => 'https://picsum.photos/id/3/320/180']
                        ]
                    ]
                ]
            ]
        ];
    }

    /**
     * Offline mock data for Video Details
     */
    private function getMockVideoDetails($id)
    {
        return [
            'items' => [
                [
                    'id' => $id,
                    'snippet' => [
                        'title' => 'Offline Mode: Sample Video Details',
                        'channelTitle' => 'Local Development Server',
                        'description' => "This is a local simulation.\n\nYour network or API keys are unavailable. This automatic fallback lets you continue building and testing your user interface without interruption."
                    ]
                ]
            ]
        ];
    }
}