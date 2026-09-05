<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VideoController extends Controller
{
    private $baseUrl = 'https://www.googleapis.com/youtube/v3';

    private function getApiKeys(): array
    {
        $keys = config('services.youtube.keys', []);
        return array_filter(array_map('trim', $keys));
    }

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

                if ($response->successful()) {
                    return $response->json();
                }

                if ($response->status() === 403) {
                    Log::warning("YouTube API Key index [{$index}] quota exceeded. Rotating...");
                    continue;
                }

                Log::warning("YouTube API returned status {$response->status()} for key index [{$index}].");
            } catch (\Exception $e) {
                Log::warning("YouTube API request failed on key index [{$index}]: " . $e->getMessage());
            }
        }

        return null;
    }

    public function search(Request $request)
    {
        $query = $request->query('q', 'trending');

        // Step 1: Search videos (this already queries both regular videos and live broadcasts)
        $searchData = $this->executeRequestWithKeyRotation('search', [
            'part' => 'snippet',
            'q' => $query,
            'maxResults' => 30,
            'type' => 'video'
        ]);

        if ($searchData !== null && !empty($searchData['items'])) {
            $videoIds = collect($searchData['items'])
                ->map(function ($item) {
                    return is_array($item['id']) ? ($item['id']['videoId'] ?? null) : $item['id'];
                })
                ->filter()
                ->implode(',');

            // Step 2: Include 'liveStreamingDetails' alongside snippet, statistics, contentDetails
            if (!empty($videoIds)) {
                $videosWithStats = $this->executeRequestWithKeyRotation('videos', [
                    'part' => 'snippet,statistics,contentDetails,liveStreamingDetails',
                    'id' => $videoIds
                ]);

                if ($videosWithStats !== null && !empty($videosWithStats['items'])) {
                    return response()->json($videosWithStats);
                }
            }

            return response()->json($searchData);
        }

        return response()->json($this->getMockSearchData($query));
    }

    public function show($id)
    {
        $data = $this->executeRequestWithKeyRotation('videos', [
            'part' => 'snippet,statistics,contentDetails',
            'id' => $id
        ]);

        if ($data !== null) {
            return response()->json($data);
        }

        return response()->json($this->getMockVideoDetails($id));
    }

    private function getMockSearchData($query)
    {
        return [
            'items' => [
                [
                    'id' => 'dQw4w9WgXcQ',
                    'snippet' => [
                        'title' => "Offline Mode: Vue 3 Basics (Search: {$query})",
                        'channelTitle' => 'Frontend Academy',
                        'publishedAt' => '2023-11-10T12:00:00Z',
                        'thumbnails' => [
                            'medium' => ['url' => 'https://picsum.photos/id/1/320/180']
                        ]
                    ],
                    'statistics' => [
                        'viewCount' => '1420500'
                    ]
                ],
                [
                    'id' => 'bMknfKXIFA8',
                    'snippet' => [
                        'title' => "Offline Mode: Laravel 10 Controllers (Search: {$query})",
                        'channelTitle' => 'Backend Casts',
                        'publishedAt' => '2024-01-15T09:30:00Z',
                        'thumbnails' => [
                            'medium' => ['url' => 'https://picsum.photos/id/2/320/180']
                        ]
                    ],
                    'statistics' => [
                        'viewCount' => '85320'
                    ]
                ],
                [
                    'id' => '2g811Eo7K8U',
                    'snippet' => [
                        'title' => "Offline Mode: Deploying Applications (Search: {$query})",
                        'channelTitle' => 'Deployment Tips',
                        'publishedAt' => '2024-02-20T18:00:00Z',
                        'thumbnails' => [
                            'medium' => ['url' => 'https://picsum.photos/id/3/320/180']
                        ]
                    ],
                    'statistics' => [
                        'viewCount' => '12400'
                    ]
                ]
            ]
        ];
    }

    private function getMockVideoDetails($id)
    {
        return [
            'items' => [
                [
                    'id' => $id,
                    'snippet' => [
                        'title' => 'Offline Mode: Sample Video Details',
                        'channelTitle' => 'Local Development Server',
                        'publishedAt' => '2024-01-01T00:00:00Z',
                        'description' => "This is a local simulation fallback."
                    ],
                    'statistics' => [
                        'viewCount' => '2500000',
                        'likeCount' => '120000'
                    ]
                ]
            ]
        ];
    }
}