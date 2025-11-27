<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Exception;

class MaskawasubController extends Controller
{
    /**
     * Purchase Data
     */
    public function purchaseData(Request $request): JsonResponse
    {
        Log::info('purchaseData request received', $request->all());

        return $this->sendPostRequest('https://maskawasubapi.com/api/data/', [
            'network'       => (int) ($request->network ?? 0),
            'mobile_number' => (string) ($request->mobile_number ?? ''),
            'plan'          => (int) ($request->plan ?? 0),
            'Ported_number' => true,
            'payment_medium'=> 'MAIN WALLET',
        ]);
    }

    /**
     * Airtime Top-Up
     */
    public function topUp(Request $request): JsonResponse
    {
        Log::info('topUp request received', $request->all());

        return $this->sendPostRequest('https://maskawasubapi.com/api/topup/', [
            'network'       => (int) ($request->network ?? 0),
            'amount'        => (float) ($request->amount ?? 0),
            'mobile_number' => (string) ($request->mobile_number ?? ''),
            'Ported_number' => true,
            'airtime_type'  => (string) ($request->airtime_type ?? 'VTU'),
        ]);
    }

    /**
     * Bill Payment
     */
    public function payBill(Request $request): JsonResponse
    {
        Log::info('payBill request received', $request->all());

        return $this->sendPostRequest('https://maskawasubapi.com/api/billpayment/', [
            'disco_name'   => (string) ($request->disco_name ?? ''),
            'amount'       => (float) ($request->amount ?? 0),
            'meter_number' => (string) ($request->meter_number ?? ''),
            'MeterType'    => (string) ($request->meter_type ?? ''),
        ]);
    }

    /**
     * Cable Subscription Payment
     */
    public function cableSubscription(Request $request): JsonResponse
    {
        Log::info('cableSubscription request received', $request->all());

        return $this->sendPostRequest('https://maskawasubapi.com/api/cablesub/', [
            'cablename'         => (string) ($request->cablename ?? ''),
            'cableplan'         => (string) ($request->cableplan ?? ''),
            'smart_card_number' => (string) ($request->smart_card_number ?? ''),
        ]);
    }

    /**
     * Validate Smart Card for Cable TV
     */
    public function validateSmartCard(Request $request): JsonResponse
    {
        Log::info('smartcard validation request received', $request->all());
        $request->validate([
            'smart_card_number' => 'required|string',
            'cablename'         => 'required|string',
        ]);

        return $this->sendGetRequest('https://maskawasubapi.com/ajax/validate_iuc', [
            'smart_card_number' => $request->smart_card_number,
            'cablename'         => $request->cablename,
        ]);
    }

    /**
     * Validate Meter Number for Electricity Bill Payment
     */
    public function validateMeterNumber(Request $request): JsonResponse
    {
        Log::info('meter validation request received', $request->all());

        $request->validate([
            'meter_number' => 'required|string',
            'disco_name'   => 'required|string',
            'meter_type'   => 'required|integer',
        ]);

        return $this->sendGetRequest('https://maskawasubapi.com/ajax/validate_meter_number', [
            'meternumber' => $request->meter_number,
            'disconame'   => $request->disco_name,
            'mtype'       => $request->meter_type,
        ]);
    }

    /**
     * Get User Details
     */
    public function getUserDetails(): JsonResponse
    {
        return $this->sendGetRequest('https://maskawasubapi.com/api/user/');
    }

    /**
     * Send GET request
     */
    private function sendGetRequest(string $url, array $queryParams = []): JsonResponse
    {
        Log::info('Sending GET request', ['url' => $url, 'params' => $queryParams]);

        try {
            $response = Http::withHeaders($this->getHeaders())->get($url, $queryParams);

            Log::info('GET response received', ['status' => $response->status(), 'body' => $response->json()]);

            if ($response->failed()) {
                return response()->json(['error' => 'Request failed', 'details' => $response->json()], $response->status());
            }

            return response()->json($response->json());
        } catch (Exception $e) {
            Log::error("Maskawasub API GET request failed: " . $e->getMessage());
            return response()->json(['error' => 'Internal Server Error'], 500);
        }
    }

    /**
     * Send POST request
     */
    private function sendPostRequest(string $url, array $payload): JsonResponse
    {
        Log::info('Sending POST request', ['url' => $url, 'payload' => $payload]);

        try {
            $response = Http::withHeaders($this->getHeaders())->post($url, $payload);

            Log::info('POST response received', ['status' => $response->status(), 'body' => $response->json()]);

            if ($response->failed()) {
                return response()->json(['error' => 'Request failed', 'details' => $response->json()], $response->status());
            }

            return response()->json($response->json());
        } catch (Exception $e) {
            Log::error("Maskawasub API POST request failed: " . $e->getMessage());
            return response()->json(['error' => 'Internal Server Error'], 500);
        }
    }

    /**
     * Get headers for requests
     */
    private function getHeaders(): array
    {
        return [
            'Authorization' => "Token " . env('MASKAWASUB_API_KEY'),
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ];
    }
}
