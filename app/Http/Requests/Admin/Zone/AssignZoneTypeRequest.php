<?php

namespace App\Http\Requests\Admin\Zone;

use App\Http\Requests\BaseRequest;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

class AssignZoneTypeRequest extends BaseRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        $route = Route::currentRouteName();
        
        $rules = [
            'type' => $route == 'updateTypePrice' ? '' :'required|exists:vehicle_types,id',
            'payment_type' => 'required',
            // 'bill_status' => 'required|in:0,1',
            'ride_now_base_price' => 'required|regex:/^\d*(\.\d{1,2})?$/',
            'ride_now_price_per_distance' => 'required|regex:/^\d*(\.\d{1,2})?$/',
            // 'ride_now_waiting_charge'=>'required|regex:/^\d*(\.\d{1,2})?$/',
            'ride_now_cancellation_fee' => 'required|regex:/^\d*(\.\d{1,2})?$/',
            'ride_now_base_distance' => 'required',
            'ride_now_price_per_time'=>'required|regex:/^\d*(\.\d{1,2})?$/',
        ];

        // Add validation for booking_hour_hourly_base_prices (1-12 hours)
        for ($hour = 1; $hour <= 12; $hour++) {
            $rules["booking_hour_hourly_base_prices.{$hour}"] = 'nullable|regex:/^\d*(\.\d{1,2})?$/';
        }

        return $rules;
    }
}
