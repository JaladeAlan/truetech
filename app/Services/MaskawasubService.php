<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Exception;

class MaskawasubService
{
    protected string $baseUrl;
    protected ?string $apiKey;

    public function __construct()
    {
        $this->baseUrl = "https://maskawasubapi.com/";
        $this->apiKey = env('MASKAWASUB_API_KEY');
    }

    /**
     * Fetch data from Maskawasub API (POST request)
     *
     * @param string $endpoint
     * @param array $payload
     * @return array
     * @throws Exception
     */
    public function fetchFromMaskawasubAPI(string $endpoint, array $payload): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => "Token {$this->apiKey}",
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ])->post($this->baseUrl . $endpoint, $payload)->throw();

            return $response->json();
        } catch (RequestException $e) {
            Log::error("Maskawasub API request failed: " . $e->getMessage(), [
                'endpoint' => $endpoint,
                'payload' => $payload,
            ]);
            throw new Exception("API request failed: " . $e->getMessage());
        }
    }

    /**
     * Fetch data from Maskawasub API (GET request)
     *
     * @param string $endpoint
     * @param array $queryParams
     * @return array
     * @throws Exception
     */
    public function fetchFromMaskawasubAPIGet(string $endpoint, array $queryParams = []): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => "Token {$this->apiKey}",
                'Accept'        => 'application/json',
            ])->get($this->baseUrl . $endpoint, $queryParams)->throw();

            return $response->json();
        } catch (RequestException $e) {
            Log::error("Maskawasub API GET request failed: " . $e->getMessage(), [
                'endpoint' => $endpoint,
                'queryParams' => $queryParams,
            ]);
            throw new Exception("API GET request failed: " . $e->getMessage());
        }
    }
}
