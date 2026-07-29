<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VideoController extends Controller
{
    private $baseUrl = 'https://www.googleapis.com/youtube/v3';

    public function search(Request $request)
    {
        $query = $request->query('q', 'programming');
        $apiKey = env('YOUTUBE_API_KEY');

        try {
            // Try contacting Google, but limit waiting to 3 seconds
            $response = Http::withoutVerifying()
                ->timeout(3) 
                ->get("{$this->baseUrl}/search", [
                    'key' => $apiKey,
                    'part' => 'snippet',
                    'q' => $query,
                    'maxResults' => 12,
                    'type' => 'video'
                ]);

            if ($response->successful()) {
                return response()->json($response->json());
            }
        } catch (\Exception $e) {
            // Log the error silently in storage/logs/laravel.log
            Log::warning('YouTube API blocked. Falling back to offline mock data: ' . $e->getMessage());
        }

        // Return offline mock data if the API call fails or times out
        return response()->json($this->getMockSearchData($query));
    }

    public function show($id)
    {
        $apiKey = env('YOUTUBE_API_KEY');

        try {
            // Try contacting Google, but limit waiting to 3 seconds
            $response = Http::withoutVerifying()
                ->timeout(3)
                ->get("{$this->baseUrl}/videos", [
                    'key' => $apiKey,
                    'part' => 'snippet,statistics',
                    'id' => $id
                ]);

            if ($response->successful()) {
                return response()->json($response->json());
            }
        } catch (\Exception $e) {
            Log::warning('YouTube API Details blocked. Falling back to offline mock data: ' . $e->getMessage());
        }

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
                        'description' => "This is a local simulation.\n\nYour network is blocking connection requests to Google (timeout error 28). This automatic fallback lets you continue building and testing your user interface without interruption."
                    ]
                ]
            ]
        ];
    }
}