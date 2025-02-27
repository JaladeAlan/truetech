<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\AutopilotService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Carbon\Carbon;

class TransactionController extends Controller
{
    /**
     * Get Networks
     */
    public function getNetworks()
    {
        try {
            $response = AutopilotService::fetchFromAutoPilotAPI("load/networks", ["networks" => "all"]);
            return response()->json($response, 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to fetch networks: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Get Data Types
     */
    public function getDataTypes(Request $request)
    {
        $validator = Validator::make($request->all(), ['networkId' => 'required|string']);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $response = AutopilotService::fetchFromAutoPilotAPI("load/data-types", $request->all());
            return response()->json($response, 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to fetch data types: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Get Data Plans
     */
    public function getDataPlans(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'networkId' => 'required|string',
            'dataType'  => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $response = AutopilotService::fetchFromAutoPilotAPI("load/data", $request->all());
            return response()->json($response, 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to fetch data plans: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Purchase Data
     */
    public function purchaseData(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'networkId'  => 'required|string',
            'dataType'   => 'required|string',
            'planId'     => 'required|string',
            'phone'      => 'required|string|min:10|max:15',
            'amount'     => 'required|numeric|min:50', // Ensure amount is provided
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = auth()->user(); // Get the authenticated user
        $amount = $request->amount;

        // Check if the user has enough balance
        if ($user->balance < $amount) {
            return response()->json(['error' => 'Insufficient balance'], 400);
        }

        // Deduct the amount from user's balance
        $user->balance -= $amount;
        $user->save();

        // Generate reference number
        $dateTime = Carbon::now('Africa/Lagos')->format('YmdHi');
        $randomString = Str::upper(Str::random(rand(13, 18)));
        $reference = $dateTime . $randomString;

        try {
            $requestData = $request->all();
            $requestData['reference'] = $reference;

            // Make API request to Autopilot
            $response = AutopilotService::fetchFromAutoPilotAPI("data", $requestData);

            // Save the transaction in the database
            Transaction::create([
                'user_id'   => $user->id,
                'reference' => $reference,
                'amount'    => $amount,
                'type'      => 'data', 
                'status'    => 'successful'
            ]);

            return response()->json([
                'message'   => 'Data purchase successful',
                'reference' => $reference,
                'data'      => $response
            ], 200);
        } catch (\Exception $e) {
            // Refund the balance if API request fails
            $user->balance += $amount;
            $user->save();

            return response()->json(['error' => 'Failed to purchase data: ' . $e->getMessage()], 500);
        }
    }


    /**
     * Get Airtime Types
     */
    public function getAirtimeTypes(Request $request)
    {
        $validator = Validator::make($request->all(), ['networkId' => 'required|string']);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $response = AutopilotService::fetchFromAutoPilotAPI("load/airtime-types", $request->all());
            return response()->json($response, 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to fetch airtime types: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Purchase Airtime
     */
    public function purchaseAirtime(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'networkId'   => 'required|string',
            'airtimeType' => 'required|string',
            'amount'      => 'required|numeric|min:50',
            'phone'       => 'required|string|min:10|max:15',
        ]);
    
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
    
        $user = auth()->user();
        $amount = $request->amount;
    
        // Check if user has enough balance
        if ($user->balance < $amount) {
            return response()->json(['error' => 'Insufficient balance'], 400);
        }
    
        // Deduct the amount from the user's balance
        $user->balance -= $amount;
        $user->save();
    
        // Generate unique reference
        $dateTime = Carbon::now('Africa/Lagos')->format('YmdHi');
        $randomString = Str::upper(Str::random(rand(13, 18)));
        $reference = $dateTime . $randomString;
    
        try {
            $requestData = $request->all();
            $requestData['reference'] = $reference;
    
            // Call Autopilot API
            $response = AutopilotService::fetchFromAutoPilotAPI("airtime", $requestData);
    
            return response()->json([
                'message'   => 'Airtime purchase successful',
                'reference' => $reference,
                'data'      => $response
            ], 200);
        } catch (\Exception $e) {
            // If transaction fails, refund the balance
            $user->balance += $amount;
            $user->save();
    
            return response()->json(['error' => 'Failed to purchase airtime: ' . $e->getMessage()], 500);
        }
    }
    

     /**
     * Get Cable Providers
     */
    public function getCableProviders()
    {
        try {
            $response = AutopilotService::fetchFromAutoPilotAPI("load/cable-types",["cables" => "all"]);
            return response()->json($response, 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to fetch cable providers: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Get Cable Plans
     */
    public function getCablePlans(Request $request)
    {
        $validator = Validator::make($request->all(), ['cableType' => 'required|string']);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $response = AutopilotService::fetchFromAutoPilotAPI("load/cable-packages", $request->all());
            return response()->json($response, 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to fetch cable plans: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Validate Smartcard Number
     */
    public function validateSmartCard(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'cableType'   => 'required|string',
            'smartCardNo' => 'required|string|min:6|max:20',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $response = AutopilotService::fetchFromAutoPilotAPI("validate/smartcard-no", $request->all());
            return response()->json($response, 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to validate smartcard number: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Purchase Cable Subscription
     */
    public function purchaseCable(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'cableType'    => 'required|string',
            'planId'       => 'required|string',
            'smartCardNo'  => 'required|string|min:6|max:20',
            'customerName' => 'required|string',
            'paymentTypes' => 'required|string|in:TOP_UP,FULL_PAYMENT',
            'amount'       => 'required_if:paymentType,TOP_UP|numeric|min:50',
        ]);
    
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
    
        $user = auth()->user();
        $amount = $request->amount;
    
        // Check if user has enough balance
        if ($user->balance < $amount) {
            return response()->json(['error' => 'Insufficient balance'], 400);
        }
    
        // Deduct the amount from the user's balance
        $user->balance -= $amount;
        $user->save();
    
        // Generate unique reference
        $dateTime = Carbon::now('Africa/Lagos')->format('YmdHi');
        $randomString = Str::upper(Str::random(rand(13, 18)));
        $reference = $dateTime . $randomString;
    
        try {
            $requestData = $request->all();
            $requestData['reference'] = $reference;
    
            // Call Autopilot API
            $response = AutopilotService::fetchFromAutoPilotAPI("cable", $requestData);
    
            return response()->json([
                'message'   => 'Cable subscription successful',
                'reference' => $reference,
                'data'      => $response
            ], 200);
        } catch (\Exception $e) {
            // If transaction fails, refund the balance
            $user->balance += $amount;
            $user->save();
    
            return response()->json(['error' => 'Failed to purchase cable subscription: ' . $e->getMessage()], 500);
        }
    } 
    
    /**
     * Get Billers
     */
    public function getBillers()
    {
        try {
            $response = AutopilotService::fetchFromAutoPilotAPI("load/billers", []);
            return response()->json($response, 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to fetch billers: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Get Biller Services
     */
    public function getBillerServices(Request $request)
    {
        $validator = Validator::make($request->all(), ['billerId' => 'required|string']);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $response = AutopilotService::fetchFromAutoPilotAPI("load/biller-services", $request->all());
            return response()->json($response, 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to fetch biller services: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Validate Customer Account for Bill Payment
     */
    public function validateBillerCustomer(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'billerId'   => 'required|string',
            'serviceId'  => 'required|string',
            'customerId' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $response = AutopilotService::fetchFromAutoPilotAPI("validate/biller-customer", $request->all());
            return response()->json($response, 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to validate customer: ' . $e->getMessage()], 500);
        } 
    }

    /**
     * Pay Bill
     */
    public function payBill(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'billerId'   => 'required|string',
            'serviceId'  => 'required|string',
            'customerId' => 'required|string',
            'amount'     => 'required|numeric|min:50',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = auth()->user();
        $amount = $request->amount;

        // Check if user has enough balance
        if ($user->balance < $amount) {
            return response()->json(['error' => 'Insufficient balance'], 400);
        }

        // Deduct the amount from the user's balance
        $user->balance -= $amount;
        $user->save();

        // Generate unique reference
        $dateTime = Carbon::now('Africa/Lagos')->format('YmdHi');
        $randomString = Str::upper(Str::random(rand(13, 18)));
        $reference = $dateTime . $randomString;

        try {
            $requestData = $request->all();
            $requestData['reference'] = $reference;

            // Call Autopilot API
            $response = AutopilotService::fetchFromAutoPilotAPI("bill-payment", $requestData);

            return response()->json([
                'message'   => 'Bill payment successful',
                'reference' => $reference,
                'data'      => $response
            ], 200);
        } catch (\Exception $e) {
            // If transaction fails, refund the balance
            $user->balance += $amount;
            $user->save();

            return response()->json(['error' => 'Failed to pay bill: ' . $e->getMessage()], 500);
        }
    }

}
