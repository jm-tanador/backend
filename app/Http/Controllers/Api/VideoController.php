<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Models\WatchHistory;

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
            'maxResults' => 100,
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

    public function personalizedFeed(Request $request)
    {
        $user = $request->user();

        // YouTube search costs 100 quota units per call, so cache each user's feed
        $items = Cache::remember("feed:{$user->id}", now()->addMinutes(30), function () use ($user) {
            $history = WatchHistory::where('user_id', $user->id)
                ->orderByDesc('watched_at')
                ->limit(30)
                ->get();

            if ($history->isEmpty()) {
                return null;
            }

            $rawVideos = [];

            // Latest videos from the 3 channels they watch most
            $topChannels = $history->whereNotNull('channel_id')
                ->groupBy('channel_id')
                ->sortByDesc(fn ($g) => $g->count())
                ->keys()
                ->take(3);

            foreach ($topChannels as $channelId) {
                $data = $this->executeRequestWithKeyRotation('search', [
                    'part'       => 'snippet',
                    'channelId'  => $channelId,
                    'order'      => 'date',
                    'maxResults' => 8,
                    'type'       => 'video',
                ]);
                if (!empty($data['items'])) {
                    $rawVideos = array_merge($rawVideos, $data['items']);
                }
            }

            // Videos similar to the most recently watched one
            $recentTitle = $history->first()->title;
            if ($recentTitle) {
                $data = $this->executeRequestWithKeyRotation('search', [
                    'part'       => 'snippet',
                    'q'          => $recentTitle,
                    'maxResults' => 10,
                    'type'       => 'video',
                ]);
                if (!empty($data['items'])) {
                    $rawVideos = array_merge($rawVideos, $data['items']);
                }
            }

            // Skip videos they've already watched
            $watched = $history->pluck('video_id')->all();
            $videoIds = collect($rawVideos)
                ->map(fn ($i) => is_array($i['id']) ? ($i['id']['videoId'] ?? null) : $i['id'])
                ->filter()
                ->unique()
                ->reject(fn ($id) => in_array($id, $watched))
                ->take(50)
                ->implode(',');

            if (!$videoIds) {
                return null;
            }

            $videos = $this->executeRequestWithKeyRotation('videos', [
                'part' => 'snippet,statistics,contentDetails,liveStreamingDetails',
                'id'   => $videoIds,
            ]);

            $feed = $videos['items'] ?? [];
            shuffle($feed);

            return $feed ?: null;
        });

        // New user with no history yet (or API failure): show trending
        if (empty($items)) {
            return $this->search($request);
        }

        return response()->json(['items' => $items]);
    }

    public function recordWatch(Request $request)
    {
        $data = $request->validate([
            'video_id'      => 'required|string|max:32',
            'channel_id'    => 'nullable|string|max:64',
            'channel_title' => 'nullable|string|max:255',
            'title'         => 'nullable|string|max:255',
        ]);

        $userId = $request->user()->id;

        WatchHistory::updateOrCreate(
            ['user_id' => $userId, 'video_id' => $data['video_id']],
            [
                'channel_id'    => $data['channel_id'] ?? null,
                'channel_title' => $data['channel_title'] ?? null,
                'title'         => $data['title'] ?? null,
                'watched_at'    => now(),
            ]
        );

        Cache::forget("feed:{$userId}"); // so the next feed reflects this watch

        return response()->json(['ok' => true]);
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