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

    // Category filters for the home page chips.
    // 'videoCategoryId' values are YouTube's standard category IDs.
    private const CATEGORIES = [
        'live'          => ['eventType' => 'live'],
        // YouTube has no "Shorts" filter: search short videos with #shorts, then keep only clips <= 3 min
        'shorts'        => ['videoDuration' => 'short', 'suffix' => '#shorts', 'maxSeconds' => 180],
        'music'         => ['videoCategoryId' => '10'],
        'gaming'        => ['videoCategoryId' => '20'],
        'sports'        => ['videoCategoryId' => '17'],
        'news'          => ['videoCategoryId' => '25'],
        'entertainment' => ['videoCategoryId' => '24'],
        'education'     => ['videoCategoryId' => '27'],
        'tech'          => ['videoCategoryId' => '28'],
        'comedy'        => ['videoCategoryId' => '23'],
    ];

    public function search(Request $request)
    {
        $query    = trim((string) $request->query('q', ''));
        $category = strtolower((string) $request->query('category', 'all'));
        $config   = self::CATEGORIES[$category] ?? null;

        if ($config === null) {
            $category = 'all';
            if ($query === '') {
                $query = 'trending';
            }
        }

        // A search costs 100 YouTube quota units, so cache identical searches for a few minutes
        $ttl  = $category === 'live' ? now()->addMinutes(3) : now()->addMinutes(10);
        $data = Cache::remember('search:' . md5($category . '|' . $query), $ttl, function () use ($query, $config) {
            return $this->fetchSearch($query, $config);
        });

        // null means the YouTube API failed on every key: fall back to the offline sample data
        if ($data === null) {
            return response()->json($this->getMockSearchData($query !== '' ? $query : $category));
        }

        return response()->json($data);
    }

    private function fetchSearch(string $query, ?array $config): ?array
    {
        $params = [
            'part'       => 'snippet',
            'maxResults' => 50,
            'type'       => 'video',
        ];

        $q = $query;
        if ($config) {
            foreach (['eventType', 'videoDuration', 'videoCategoryId'] as $key) {
                if (isset($config[$key])) {
                    $params[$key] = $config[$key];
                }
            }
            if (!empty($config['suffix'])) {
                $q = trim($q . ' ' . $config['suffix']);
            }
        }
        if ($q !== '') {
            $params['q'] = $q;
        }

        // Step 1: search
        $searchData = $this->executeRequestWithKeyRotation('search', $params);
        if ($searchData === null) {
            return null;
        }
        if (empty($searchData['items'])) {
            return ['items' => []];
        }

        $videoIds = collect($searchData['items'])
            ->map(fn ($item) => is_array($item['id']) ? ($item['id']['videoId'] ?? null) : $item['id'])
            ->filter()
            ->implode(',');

        if ($videoIds === '') {
            return $searchData;
        }

        // Step 2: add statistics, duration and live details
        $videos = $this->executeRequestWithKeyRotation('videos', [
            'part' => 'snippet,statistics,contentDetails,liveStreamingDetails',
            'id'   => $videoIds,
        ]);

        if ($videos === null || empty($videos['items'])) {
            return $searchData;
        }

        // Shorts: keep only clips that are really short
        if (!empty($config['maxSeconds'])) {
            $videos['items'] = array_values(array_filter($videos['items'], function ($v) use ($config) {
                $seconds = $this->durationSeconds($v['contentDetails']['duration'] ?? '');
                return $seconds > 0 && $seconds <= $config['maxSeconds'];
            }));
        }

        return $videos;
    }

    // "PT1M5S" -> 65
    private function durationSeconds(string $iso): int
    {
        if (!preg_match('/^P(?:(\d+)D)?T?(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$/', $iso, $m)) {
            return 0;
        }

        return ((int) ($m[1] ?? 0)) * 86400
            + ((int) ($m[2] ?? 0)) * 3600
            + ((int) ($m[3] ?? 0)) * 60
            + (int) ($m[4] ?? 0);
    }

    // Channel info (name, avatar, banner, subscribers...). 1 quota unit, cached 1 hour.
    public function channel(string $id)
    {
        $data = Cache::remember("channel:{$id}", now()->addHour(), function () use ($id) {
            return $this->executeRequestWithKeyRotation('channels', [
                'part' => 'snippet,statistics,brandingSettings',
                'id'   => $id,
            ]);
        });

        if (empty($data['items'])) {
            return response()->json(['message' => 'Channel not found'], 404);
        }

        return response()->json($data['items'][0]);
    }

    // Latest uploads of a channel. Uses the channel's "uploads" playlist (UC... -> UU...),
    // which costs 1 quota unit instead of 100 for a search.
    public function channelVideos(string $id)
    {
        $items = Cache::remember("channel-videos:{$id}", now()->addMinutes(10), function () use ($id) {
            $playlist = $this->executeRequestWithKeyRotation('playlistItems', [
                'part'       => 'contentDetails',
                'playlistId' => 'UU' . substr($id, 2),
                'maxResults' => 50,
            ]);

            if ($playlist === null) {
                return null;
            }

            $ids = collect($playlist['items'] ?? [])
                ->map(fn ($i) => $i['contentDetails']['videoId'] ?? null)
                ->filter()
                ->implode(',');

            if ($ids === '') {
                return [];
            }

            $videos = $this->executeRequestWithKeyRotation('videos', [
                'part' => 'snippet,statistics,contentDetails,liveStreamingDetails',
                'id'   => $ids,
            ]);

            return $videos['items'] ?? null;
        });

        if ($items === null) {
            return response()->json(['items' => [], 'message' => 'Could not load videos'], 502);
        }

        return response()->json(['items' => $items]);
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