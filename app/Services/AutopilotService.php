<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class AutopilotService
{
    /**
     * Fetch data from Autopilot API
     *
     * @param string $endpoint
     * @param array $payload
     * @return array
     * @throws \Exception
     */
    public static function fetchFromAutoPilotAPI($endpoint, $payload)
    {
        $baseUrl = "https://autopilotng.com/api/live/v1/";
        $apiKey = env('AUTOPILOT_API_KEY');

        $response = Http::withHeaders([
            'Authorization' => "Bearer $apiKey",
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ])->post($baseUrl . $endpoint, $payload);

        if ($response->failed()) {
            throw new \Exception("API request failed: " . $response->body());
        }

        return $response->json();
    }
}
