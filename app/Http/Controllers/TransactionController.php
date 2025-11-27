<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\AutopilotService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Carbon\Carbon;
use App\Models\Transaction;

class TransactionController extends Controller
{
    // ======== Helper Functions ========
    private function generateReference(): string
    {
        $dateTime = Carbon::now('Africa/Lagos')->format('YmdHi');
        $randomString = Str::upper(Str::random(rand(13, 18)));
        return $dateTime . $randomString;
    }

    private function processTransaction(Request $request, array $validationRules, string $apiEndpoint, string $type, bool $deductBalance = true)
    {
        $validator = Validator::make($request->all(), $validationRules);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = auth()->user();
        $amount = $request->input('amount', 0);

        if ($deductBalance && $user->balance < $amount) {
            return response()->json(['error' => 'Insufficient balance'], 400);
        }

        if ($deductBalance) {
            $user->balance -= $amount;
            $user->save();
        }

        $reference = $this->generateReference();
        $requestData = $request->all();
        $requestData['reference'] = $reference;

        try {
            $response = AutopilotService::fetchFromAutoPilotAPI($apiEndpoint, $requestData);

            if ($deductBalance) {
                Transaction::create([
                    'user_id'   => $user->id,
                    'reference' => $reference,
                    'amount'    => $amount,
                    'type'      => $type,
                    'status'    => 'successful',
                ]);
            }

            return response()->json([
                'message'   => ucfirst($type) . ' transaction successful',
                'reference' => $reference,
                'data'      => $response
            ], 200);

        } catch (\Exception $e) {
            if ($deductBalance) {
                $user->balance += $amount;
                $user->save();
            }

            return response()->json(['error' => 'Failed to process ' . $type . ': ' . $e->getMessage()], 500);
        }
    }

    // ======== Transaction Endpoints ========

    public function purchaseData(Request $request)
    {
        return $this->processTransaction($request, [
            'networkId' => 'required|string',
            'dataType'  => 'required|string',
            'planId'    => 'required|string',
            'phone'     => 'required|string|min:10|max:15',
            'amount'    => 'required|numeric|min:50'
        ], 'data', 'data');
    }

    public function purchaseAirtime(Request $request)
    {
        return $this->processTransaction($request, [
            'networkId'   => 'required|string',
            'airtimeType' => 'required|string',
            'amount'      => 'required|numeric|min:50',
            'phone'       => 'required|string|min:10|max:15'
        ], 'airtime', 'airtime');
    }

    public function purchaseCable(Request $request)
    {
        return $this->processTransaction($request, [
            'cableType'    => 'required|string',
            'planId'       => 'required|string',
            'smartCardNo'  => 'required|string|min:6|max:20',
            'customerName' => 'required|string',
            'paymentTypes' => 'required|string|in:TOP_UP,FULL_PAYMENT',
            'amount'       => 'required_if:paymentTypes,TOP_UP|numeric|min:50'
        ], 'cable', 'cable');
    }

    public function payBill(Request $request)
    {
        return $this->processTransaction($request, [
            'billerId'   => 'required|string',
            'serviceId'  => 'required|string',
            'customerId' => 'required|string',
            'amount'     => 'required|numeric|min:50'
        ], 'bill-payment', 'bill');
    }

    // ======== Endpoints ========
    public function getNetworks() { return $this->fetchFromAutopilot('load/networks', ['networks' => 'all']); }
    public function getDataTypes(Request $request) { return $this->fetchFromAutopilot('load/data-types', $request->all()); }
    public function getDataPlans(Request $request) { return $this->fetchFromAutopilot('load/data', $request->all()); }
    public function getAirtimeTypes(Request $request) { return $this->fetchFromAutopilot('load/airtime-types', $request->all()); }
    public function getCableProviders() { return $this->fetchFromAutopilot('load/cable-types', ['cables' => 'all']); }
    public function getCablePlans(Request $request) { return $this->fetchFromAutopilot('load/cable-packages', $request->all()); }
    public function getBillers() { return $this->fetchFromAutopilot('load/billers', []); }
    public function getBillerServices(Request $request) { return $this->fetchFromAutopilot('load/biller-services', $request->all()); }

    private function fetchFromAutopilot(string $endpoint, array $params)
    {
        try {
            $response = AutopilotService::fetchFromAutoPilotAPI($endpoint, $params);
            return response()->json($response, 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to fetch from Autopilot: ' . $e->getMessage()], 500);
        }
    }
}
