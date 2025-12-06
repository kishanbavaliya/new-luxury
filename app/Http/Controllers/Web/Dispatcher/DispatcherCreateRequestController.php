<?php

namespace App\Http\Controllers\Web\Dispatcher;

use App\Jobs\NotifyViaMqtt;
use App\Models\Admin\Driver;
use App\Jobs\NotifyViaSocket;
use App\Models\Admin\ZoneType;
use App\Models\Admin\NotIcludeOwner;
use App\Models\Request\Request;
use App\Models\Request\RequestCycles;   
use Salman\Mqtt\MqttClass\Mqtt;
use Illuminate\Support\Facades\DB;
use App\Models\Request\RequestMeta;
use Illuminate\Support\Facades\Log;
use App\Base\Constants\Masters\PushEnums;
use App\Base\Constants\Masters\EtaConstants;
use App\Http\Controllers\Web\BaseController;
use App\Http\Requests\Request\CreateTripRequest;
use Illuminate\Http\Request as ValidatorRequest;
use App\Transformers\Requests\TripRequestTransformer;
use Carbon\Carbon;
use Illuminate\Support\Facades\Validator; 
use App\Helpers\Rides\FetchDriversFromFirebaseHelpers;
use App\Transformers\User\EtaTransformer;
use Kreait\Firebase\Contract\Database;
use App\Models\User;
use App\Models\Country;
use App\Models\Admin\Owner;
use App\Jobs\Notifications\SendPushNotification;
use App\Helpers\Rides\RidePriceCalculationHelpers;
use App\Base\Constants\Masters\PaymentType;
use App\Base\Constants\Masters\WalletRemarks;
use App\Base\Constants\Masters\UnitType;
use App\Models\Admin\ZoneTypePackagePrice;
use App\Models\Admin\Promo;
use App\Models\Admin\PromoUser;
use App\Models\Request\RequestCancellationFee;
use App\Models\Request\RequestBill;
use Illuminate\Support\Facades\Mail;

/**
 * @group Dispatcher-trips-apis
 *
 * APIs for Dispatcher-trips apis
 */
class DispatcherCreateRequestController extends BaseController
{
    protected $request;
    use FetchDriversFromFirebaseHelpers;
    use RidePriceCalculationHelpers;

    public function __construct(Request $request,Database $database,User $user)
    {
        $this->request = $request;
        $this->database = $database;
        $this->user = $user;
    }
    /**
    * Create Request
    * @bodyParam pick_lat double required pikup lat of the user
    * @bodyParam pick_lng double required pikup lng of the user
    * @bodyParam drop_lat double required drop lat of the user
    * @bodyParam drop_lng double required drop lng of the user
    * @bodyParam vehicle_type string required id of zone_type_id
    * @bodyParam payment_opt tinyInteger required type of ride whther cash or card, wallet('0 => card,1 => cash,2 => wallet)
    * @bodyParam pick_address string required pickup address of the trip request
    * @bodyParam drop_address string required drop address of the trip request
    * @bodyParam pickup_poc_name string required customer name for the request
    * @bodyParam pickup_poc_mobile string required customer name for the request
    * @responseFile responses/requests/create-request.json
    *
    */
    public function createRequest(ValidatorRequest $request)
    {
    //    dd($request->all());
        Log::debug('Received request data:', $request->all());
        $rules = [
            'pick_lat'  => 'required',
            'pick_lng'  => 'required',
            'drop_lat' => 'exclude_if:booking_type,book-hourly|sometimes|required',
            'drop_lng' => 'exclude_if:booking_type,book-hourly|sometimes|required',
            'vehicle_type'=>'sometimes|required|exists:zone_types,id',
            'payment_opt'=>'sometimes|required|in:0,1,2',
            'pick_address'=>'required',
            'drop_address'=>'exclude_if:booking_type,book-hourly|sometimes|required',
            'drivers'=>'sometimes|required',
            'is_later'=>'sometimes|required|in:1',
            'trip_start_time'=>'sometimes|required',
            'promocode_id'=>'sometimes|required|exists:promo,id'
            
        ];
        // Create a new validator instance
        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            // Validation failed
            $errors = $validator->errors()->all();
            return response()->json(['status'=>false,"message"=>$errors]);
            // Handle errors
        } 
        // dd($request->all());
        
        /**
        * Validate payment option is available.
        * if card payment choosen, then we need to check if the user has added thier card.
        * if the paymenr opt is wallet, need to check the if the wallet has enough money to make the trip request
        * Check if thge user created a trip and waiting for a driver to accept. if it is we need to cancel the exists trip and create new one
        * Find the zone using the pickup coordinates & get the nearest drivers
        * create request along with place details
        * assing driver to the trip depends the assignment method
        * send emails and sms & push notifications to the user& drivers as well.
        */
        // dd($request->all());
        // Validate payment option is available.
        if ($request->has('is_later') && $request->is_later) {
            return $this->createRideLater($request);
        }

        $country_data = Country::where('dial_code',$request->dial_code)->first();



        // @TODO
        // get type id
        $zone_type_detail = ZoneType::where('id', $request->vehicle_type)->first();
        $type_id = $zone_type_detail->type_id;

        // Get currency code of Request
        $service_location = $zone_type_detail->zone->serviceLocation;
        $currency_code = $service_location->currency_code;
        $currency_symbol = $service_location->currency_symbol;
        $eta_result = fractal($zone_type_detail, new EtaTransformer);

        $eta_result =json_decode($eta_result->toJson());

         // Calculate ETA
         $request_eta_params=[
            'base_price'=>$eta_result->data->base_price,
            'base_distance'=>$eta_result->data->base_distance,
            'total_distance'=>$eta_result->data->distance,
            'total_time'=>$eta_result->data->time,
            'price_per_distance'=>$eta_result->data->price_per_distance,
            'distance_price'=>$eta_result->data->distance_price,
            'price_per_time'=>$eta_result->data->price_per_time,
            'time_price'=>$eta_result->data->time_price,
            'service_tax'=>$eta_result->data->tax_amount,
            'service_tax_percentage'=>$eta_result->data->tax,
            'promo_discount'=>$eta_result->data->discount_amount,
            'admin_commision'=>$eta_result->data->without_discount_admin_commision,
            'admin_commision_with_tax'=>($eta_result->data->without_discount_admin_commision + $eta_result->data->tax_amount),
            'total_amount'=>$eta_result->data->total,
            'requested_currency_code'=>$currency_code
        ]; 
        // fetch unit from zone
        $unit = $zone_type_detail->zone->unit;
        // Fetch user detail
        $user_detail = auth()->user();
        // Get last request's request_number
        $request_number = $this->request->orderBy('created_at', 'DESC')->pluck('request_number')->first();
        if ($request_number) {
            $request_number = explode('_', $request_number);
            $request_number = $request_number[1]?:000000;
        } else {
            $request_number = 000000;
        }
        // Generate request number
        $request_number = 'REQ_'.sprintf("%06d", $request_number+1);
        // $request_number = 'REQ_'.time();

        $request_params = [
            'request_number'=>$request_number,
            'zone_type_id'=>$request->vehicle_type,
            'if_dispatch'=>true,
            'dispatcher_id'=>$user_detail->admin->id,
            'payment_opt'=>$request->payment_opt,
            'unit'=>$unit,
            'transport_type'=>$request->transport_type,
            'requested_currency_code'=>$currency_code,
            'requested_currency_symbol'=>$currency_symbol,
            'total_distance'=>$eta_result->data->distance,
            'adults'=>$request->adults,
            'childrens'=>$request->childrens,
            'infants'=>$request->infants,
            'flight_number'=>$request->flight_number,
            'seats_accessories'=>$request->seats_accessories,
            'notes_for_chauffeuer'=>$request->notes_for_chauffeuer,
            'refrence_name'=>$request->refrence_name,
            'refrence_short_name'=>$request->refrence_short_name,

            'sign_board_name'=>$request->sign_board_name,
            'service_location_id'=>$service_location->id,
            'baby_bucket'=> $request->baby_bucket,
            'child_seat'=> $request->child_seat,
            'booster_seat'=> $request->booster_seat,
            'is_later'=>true,
            'ride_type' => $request->booking_type ?? ""
        ];
        $request_params['assign_method'] = $request->assign_method;
        $request_params['comission_percentage'] = $request->comission_percentage;
        $request_params['request_eta_amount'] = round($request->request_eta_amount, 2);
        if($request->has('rental_package_id') && $request->rental_package_id){

            $request_params['is_rental'] = true; 

            $request_params['rental_package_id'] = $request->rental_package_id;
        }
        if($request->has('goods_type_id') && $request->goods_type_id){
            $request_params['goods_type_id'] = $request->goods_type_id; 
            $request_params['goods_type_quantity'] = $request->goods_type_quantity;
        }
        if($request->trip_start_time){
            $trip_start_time = $request->trip_start_time;
            $secondcarbonDateTime = Carbon::parse($request->trip_start_time, $service_location->timezone)->setTimezone('UTC')->toDateTimeString();
            $request_params['trip_start_time'] = $secondcarbonDateTime;
        } else {
            $trip_start_time = Carbon::now();
            $secondcarbonDateTime = $trip_start_time->setTimezone('UTC')->toDateTimeString();
            $request_params['trip_start_time'] = $secondcarbonDateTime;
        }
        
          // store request place details
          $user = $this->user->belongsToRole('user')
          ->where('mobile', $request->pickup_poc_mobile)
          ->first();
          if(!$user)
          {
            $request_params1['name'] = $request->pickup_poc_name;
            $request_params1['surname'] = $request->surname;
            $request_params1['mobile'] = $request->pickup_poc_mobile;
            $request_params1['country'] = $country_data->id;
            
            $useremail = $this->user->belongsToRole('user')
            ->where('email', $request->email)
            ->first();
            if(!$useremail){
                $request_params1['email'] = $request->email;
            }
            
            $user = $this->user->create($request_params1);  
            $user->attachRole('user');
          } else {
            $user->surname = $user->surname != "" ? $user->surname : $request->surname;
            $user->save();
          }  
          $request_params['user_id'] = $user->id; 
          $request_params['booking_hour'] = $request->booking_hour ?? '';
        // store request details to db
        // DB::beginTransaction();
        // try {
            // Log::info("test1");
        $only_luxury_limoexpress = 1;
        $request_detail = $this->request->create($request_params);
        if($request->owner_include_option == 'not_include') {
            if(!empty($request->not_include_owners)){
                $only_luxury_limoexpress = 0;

                foreach($request->not_include_owners as $not_include_owner){
                    \App\Models\Admin\NotIcludeOwner::create([
                        "request_id" => $request_detail->id,
                        "user_id" => $not_include_owner,
                    ]);
                }
            }
        }
        if($request->owner_include_option == 'include') {
            if(!empty($request->include_owner)){
                $Owners = Owner::where("email", "!=", "multani@luxury-limoexpress.com")
                ->where("company_name", "!=", "Luxury Limoexpress")
                ->when(!empty($request->include_owner), function ($query) use ($request) {
                    // Exclude owners selected in the request
                    $query->whereNotIn('id', $request->include_owner);
                })
                ->get();
                $only_luxury_limoexpress = 0;
                foreach ($Owners as $owner) {
                    \App\Models\Admin\NotIcludeOwner::create([
                        "request_id" => $request_detail->id,
                        "user_id" => $owner->id,
                    ]);
                }
            }
        }
        Log::info("------------requestDetailss----------------");
        Log::info($request_detail);
        Log::info($request_params);
        // request place detail params
        $request_place_params = [
            'pick_lat'=>$request->pick_lat,
            'pick_lng'=>$request->pick_lng,
            'drop_lat'=>$request->drop_lat,
            'drop_lng'=>$request->drop_lng,
            'pick_address'=>$request->pick_address,
            'drop_address'=>$request->drop_address];
      
        $request_detail->requestPlace()->create($request_place_params);
        // $ad_hoc_user_params = $request->only(['name','phone_number']);
        $ad_hoc_user_params['name'] = $request->pickup_poc_name;
        $ad_hoc_user_params['mobile'] = $request->pickup_poc_mobile;

        // Store ad hoc user detail of this request
        // $request_detail->adHocuserDetail()->create($ad_hoc_user_params);
      

        // $request_detail->requestEtaDetail()->create($request_eta_params);

        // Add Request detail to firebase database
         $this->database->getReference('requests/'.$request_detail->id)->update(['request_id'=>$request_detail->id,'request_number'=>$request_detail->request_number,'service_location_id'=>$service_location->id,'user_id'=>$request_detail->user_id,'trnasport_type'=>$request->trnasport_type,'pick_address'=>$request->pick_address,'drop_address'=>$request->drop_address,'assign_method'=>1,'active'=>1,'is_accept'=>0,'date'=>$request_detail->converted_created_at,'updated_at'=> Database::SERVER_TIMESTAMP]); 

        $selected_drivers = [];
        $notification_android = [];
        $notification_ios = [];
        $i = 0; 
        $request_result =  fractal($request_detail, new TripRequestTransformer)->parseIncludes('userDetail');

        $mqtt_object = new \stdClass();
        $mqtt_object->success = true;
        $mqtt_object->success_message  = PushEnums::REQUEST_CREATED;
        $mqtt_object->result = $request_result; 
        DB::commit();
        
        // Handle owner driver assignment
        if($request->owner_include_option == 'include' && $request->owner_action && $request->owner_driver_id) {
            $driver_id = $request->owner_driver_id;
            $driver = Driver::find($driver_id);
            
            if($driver) {
                if($request->owner_action == 'assign') {
                    // Directly assign driver without notifications to other drivers
                    $selected_drivers = [];
                    $selected_drivers["user_id"] = $request_detail->user_id;
                    $selected_drivers["driver_id"] = $driver_id;
                    $selected_drivers["active"] = 1;
                    $selected_drivers["assign_method"] = 1;
                    $selected_drivers["created_at"] = date('Y-m-d H:i:s');
                    $selected_drivers["updated_at"] = date('Y-m-d H:i:s');
                    
                    // Create RequestMeta for direct assignment
                    RequestMeta::create([
                        'request_id' => $request_detail->id,
                        'driver_id' => $driver_id,
                        'user_id' => $request_detail->user_id,
                        'active' => 1,
                        'assign_method' => 1
                    ]);
                    
                    // Update Firebase
                    $this->database->getReference('request-meta/'.$request_detail->id)->set([
                        'driver_id' => $driver_id,
                        'request_id' => $request_detail->id,
                        'user_id' => $request_detail->user_id,
                        'active' => 1,
                        'updated_at' => Database::SERVER_TIMESTAMP
                    ]);
                    $this->database->getReference('requests/'.$request_detail->id)->update(['is_accept' => 1]);

                    $accepted_fare = $request_detail->request_eta_amount;
                    $offered_fare = $request_detail->request_eta_amount;

                    $updated_params = [
                        'driver_id' => $driver->id,
                        'accepted_at' => date('Y-m-d H:i:s'),
                        'is_driver_started' => true,
                        // 'accepted_ride_fare'=>$accepted_fare,
                        // 'offerred_ride_fare'=>$offered_fare,
                    ];

                    if($request_detail->is_out_station==0)
                    {
                        $updated_params['is_driver_started'] = true;
                    }
                    if($driver->owner_id){
                        $updated_params['owner_id'] = $driver->owner_id;
                        $updated_params['fleet_id'] = $driver->fleet_id;
                    }

                    $request_detail->update($updated_params);
                    $request_detail->fresh();

                    $driver->available = true;
                    $driver->save();

                    $notifable_driver = $driver->user;
                    $title = trans('push_notifications.ride_confirmed_by_user_title',[],$notifable_driver->lang);
                    $body = trans('push_notifications.ride_confirmed_by_user_body',[],$notifable_driver->lang);

                    dispatch(new SendPushNotification($notifable_driver,$title,$body));
                    
                    Log::info("Driver {$driver_id} directly assigned to request {$request_detail->id} without notifying other drivers");
                } elseif($request->owner_action == 'complete') {
                    // Complete the ride immediately with all end request functionalities
                    $this->completeRequestFromDispatcher($request_detail, $driver, $request);
                    
                    Log::info("Driver {$driver_id} completed ride for request {$request_detail->id} from dispatcher");
                }
            }
        } elseif($request->assign_method == 0) {
            Log::info("test data dispatcher");
            $nearest_drivers =  $this->fetchDriversFromFirebase($request_detail, $only_luxury_limoexpress);

            // Send Request to the nearest Drivers
             if ($nearest_drivers==null) {
                    goto no_drivers_available;
             } 
            no_drivers_available:
        }
        Log:info($request_detail->user_id);
        
        $request_datas['request_id'] = $request_detail->id;
        $request_datas['user_id'] = $request_detail->user_id; 
        $request_datas['orderby_status'] = 1; 
        $data[0]['status'] = 1;
        $data[0]['process_type'] = "create_request";
        $data[0]['if_dispatcher'] = true;
        $default_image_path = config('base.default.user.profile_picture');
        $data[0]['user_image'] = env('APP_URL').$default_image_path;
        $data[0]['orderby_status'] = 1;
        $data[0]['dricver_details'] = null; 
        $data[0]['created_at'] = date("Y-m-d H:i:s", time()); 
        $request_datas['request_data'] = base64_encode(json_encode($data)); 
        $insert_request_cycles = RequestCycles::create($request_datas);
        

        return $this->respondSuccess($request_result, 'Request Created Successfully');
    }


    /**
    * Get nearest Drivers using requested co-ordinates
    *  @param request
    */
    public function getDrivers($request, $type_id)
    {
        $driver_detail = [];
        $driver_ids = [];


        $pick_lat = $request->pick_lat;
        $pick_lng = $request->pick_lng;
        $driver_search_radius = get_settings('driver_search_radius')?:30;

        $haversine = "(6371 * acos(cos(radians($pick_lat)) * cos(radians(pick_lat)) * cos(radians(pick_lng) - radians($pick_lng)) + sin(radians($pick_lat)) * sin(radians(pick_lat))))";

        // Get Drivers who are all going to accept or reject the some request that nears the user's current location.

        $driver_ids = Driver::whereHas('requestDetail.requestPlace', function ($query) use ($haversine,$driver_search_radius) {
            $query->select('request_places.*')->selectRaw("{$haversine} AS distance")
                ->whereRaw("{$haversine} < ?", [$driver_search_radius]);
        })->pluck('id')->toArray();

        $meta_drivers = RequestMeta::whereIn('driver_id', $driver_ids)->pluck('driver_id')->toArray();

        $driver_haversine = "(6371 * acos(cos(radians($pick_lat)) * cos(radians(latitude)) * cos(radians(longitude) - radians($pick_lng)) + sin(radians($pick_lat)) * sin(radians(latitude))))";
        // get nearest driver exclude who are all struck with request meta
        $drivers = Driver::whereHas('driverDetail', function ($query) use ($driver_haversine,$driver_search_radius,$type_id) {
            $query->select('driver_details.*')->selectRaw("{$driver_haversine} AS distance")
                ->whereRaw("{$driver_haversine} < ?", [$driver_search_radius]);
        })->whereNotIn('id', $meta_drivers)->limit(10)->get();

        if ($drivers->isEmpty()) {
            return $this->respondFailed('all drivers are busy');
        }
        return $drivers;
    }

    /**
    * Get Drivers from firebase
    */
    public function getFirebaseDrivers($request, $type_id)
    {
        $pick_lat = $request->pick_lat;
        $pick_lng = $request->pick_lng;

        // NEW flow
        $client = new \GuzzleHttp\Client();
        $url = env('NODE_APP_URL').':'.env('NODE_APP_PORT').'/'.$pick_lat.'/'.$pick_lng.'/'.$type_id;

        $res = $client->request('GET', "$url");
        if ($res->getStatusCode() == 200) {
            $fire_drivers = \GuzzleHttp\json_decode($res->getBody()->getContents());
            if (empty($fire_drivers->data)) {
                return $this->respondFailed('no drivers available');
            } else {
                $nearest_driver_ids = [];
                foreach ($fire_drivers->data as $key => $fire_driver) {
                    $nearest_driver_ids[] = $fire_driver->id;
                }

                $driver_search_radius = get_settings('driver_search_radius')?:30;

                $haversine = "(6371 * acos(cos(radians($pick_lat)) * cos(radians(pick_lat)) * cos(radians(pick_lng) - radians($pick_lng)) + sin(radians($pick_lat)) * sin(radians(pick_lat))))";
                // Get Drivers who are all going to accept or reject the some request that nears the user's current location.
                $meta_drivers = RequestMeta::whereHas('request.requestPlace', function ($query) use ($haversine,$driver_search_radius) {
                    $query->select('request_places.*')->selectRaw("{$haversine} AS distance")
                ->whereRaw("{$haversine} < ?", [$driver_search_radius]);
                })->pluck('driver_id')->toArray();

                $nearest_drivers = Driver::where('active', 1)->where('approve', 1)->where('available', 1)->where('vehicle_type', $type_id)->whereIn('id', $nearest_driver_ids)->whereNotIn('id', $meta_drivers)->limit(10)->get();

                if ($nearest_drivers->isEmpty()) {
                    return $this->respondFailed('all drivers are busy');
                }

                return $this->respondSuccess($nearest_drivers, 'drivers_list');
            }
        } else {
            return $this->respondFailed('there is an error-getting-drivers');
        }
    }
    /**
    * Create Ride later trip
    */
    public function createRideLater($request)
    {
        Log::info("Web createRideLater");
        /**
        * @TODO validate if the user has any trip with same time period
        *
        */
        // get type id
        $zone_type_detail = ZoneType::where('id', $request->vehicle_type)->first();
        $type_id = $zone_type_detail->type_id;

        // Get currency code of Request
        $service_location = $zone_type_detail->zone->serviceLocation;
        $currency_code = $service_location->currency_code;
        $currency_symbol = $service_location->currency_symbol;
        $trip_start_time = $request->trip_start_time;
        $secondcarbonDateTime = Carbon::parse($request->trip_start_time, $service_location->timezone)->setTimezone('UTC')->toDateTimeString();
        // $carbonDateTime = Carbon::createFromFormat('d M, Y H:i:s', $trip_start_time, $service_location->timezone);  
        $now = Carbon::now($service_location->timezone)->addHour(); 
        // if (!$carbonDateTime->greaterThanOrEqualTo($now)) { 
        // return response()->json(['status'=>false,"type"=>"date_format","message"=>"The provided time is less than one hour"]);
        // } 
        // fetch unit from zone
        $unit = $zone_type_detail->zone->unit;
        $eta_result = fractal($zone_type_detail, new EtaTransformer);

        $eta_result =json_decode($eta_result->toJson());

         // Calculate ETA
        //  $request_eta_params=[
        //     'base_price'=>$eta_result->data->base_price,
        //     'base_distance'=>$eta_result->data->base_distance,
        //     'total_distance'=>$eta_result->data->distance,
        //     'total_time'=>$eta_result->data->time,
        //     'price_per_distance'=>$eta_result->data->price_per_distance,
        //     'distance_price'=>$eta_result->data->distance_price,
        //     'price_per_time'=>$eta_result->data->price_per_time,
        //     'time_price'=>$eta_result->data->time_price,
        //     'service_tax'=>$eta_result->data->tax_amount,
        //     'service_tax_percentage'=>$eta_result->data->tax,
        //     'promo_discount'=>$eta_result->data->discount_amount,
        //     'admin_commision'=>$eta_result->data->without_discount_admin_commision,
        //     'admin_commision_with_tax'=>($eta_result->data->without_discount_admin_commision + $eta_result->data->tax_amount),
        //     'total_amount'=>$eta_result->data->total,
        //     'requested_currency_code'=>$currency_code
        // ];

        // Fetch user detail
        $user_detail = auth()->user();
        // Get last request's request_number
        $request_number = $this->request->orderBy('created_at', 'DESC')->pluck('request_number')->first();
        if ($request_number) {
            $request_number = explode('_', $request_number);
            $request_number = $request_number[1]?:000000;
        } else {
            $request_number = 000000;
        }
        // Generate request number
        $request_number = 'REQ_'.time();

        // Convert trip start time as utc format
        $timezone = auth()->user()->timezone?:env('SYSTEM_DEFAULT_TIMEZONE');
        
        $trip_start_time = $secondcarbonDateTime; 
        $request_params = [
            'request_number'=>$request_number,
            'is_later'=>true,
            'zone_type_id'=>$request->vehicle_type,
            'trip_start_time'=>$trip_start_time,
            'if_dispatch'=>true,
            'dispatcher_id'=>$user_detail->admin->id,
            'payment_opt'=>$request->payment_opt,
            'unit'=>$unit,
            'requested_currency_code'=>$currency_code,
            'requested_currency_symbol'=>$currency_symbol,
            'total_distance'=>$eta_result->data->distance,
            'adults'=>$request->adults,
            'childrens'=>$request->childrens,
            'infants'=>$request->infants,
            'flight_number'=>$request->flight_number,
            'seats_accessories'=>$request->seats_accessories,
            'notes_for_chauffeuer'=>$request->notes_for_chauffeuer,
            'sign_board_name'=>$request->sign_board_name,
            'service_location_id'=>$service_location->id,
            'baby_bucket'=> $request->baby_bucket,
            'child_seat'=> $request->child_seat,
            'booster_seat'=> $request->booster_seat,
            'ride_type' => $request->booking_type ?? "",
            'refrence_name'=>$request->refrence_name,
            'refrence_short_name'=>$request->refrence_short_name,
        ];

            if($request->has('request_eta_amount') && $request->request_eta_amount){
 
                $request_params['request_eta_amount'] = round($request->request_eta_amount, 2);
     
             }    
     
             if($request->has('rental_package_id') && $request->rental_package_id){
     
                 $request_params['is_rental'] = true; 
     
                 $request_params['rental_package_id'] = $request->rental_package_id;
             }
             if($request->has('goods_type_id') && $request->goods_type_id){
                 $request_params['goods_type_id'] = $request->goods_type_id; 
                 $request_params['goods_type_quantity'] = $request->goods_type_quantity;
             }

            $request_params['assign_method'] = $request->assign_method;
            $request_params['comission_percentage'] = $request->comission_percentage;
            $request_params['request_eta_amount'] = round($request->request_eta_amount, 2);
            $user = $this->user->belongsToRole('user')
            ->where('mobile', $request->pickup_poc_mobile)
            ->first();
            if(!$user)
            {
              $country_data = Country::where('dial_code',$request->dial_code)->first();
              $request_params1['name'] = $request->pickup_poc_name;
              $request_params1['mobile'] = $request->pickup_poc_mobile;
              $request_params1['surname'] = $request->surname;
              $request_params1['country'] = $country_data->id;

              $useremail = $this->user->belongsToRole('user')
                ->where('email', $request->email)
                ->first();
                if(!$useremail){
                    $request_params1['email'] = $request->email;
                }
                
              $user = $this->user->create($request_params1);  
              $user->attachRole('user');
            } else {
                $user->surname = $user->surname != "" ? $user->surname : $request->surname;
                $user->save();
            }  

            $request_params['user_id'] = $user->id; 

        // store request details to db
        DB::beginTransaction();
        try {
            $request_detail = $this->request->create($request_params);
            
            if(!empty($request->not_include_owners)){
                foreach($request->not_include_owners as $not_include_owner){
                    \App\Models\Admin\NotIcludeOwner::create([
                        "request_id" => $request_detail->id,
                        "user_id" => $not_include_owner,
                    ]);
                }
            }
            if($request->owner_include_option == 'include') {
                if(!empty($request->include_owner)){
                    $Owners = Owner::where("email", "!=", "multani@luxury-limoexpress.com")
                    ->where("company_name", "!=", "Luxury Limoexpress")
                    ->when(!empty($request->include_owner), function ($query) use ($request) {
                        // Exclude owners selected in the request
                        $query->whereNotIn('id', $request->include_owner);
                    })
                    ->get();
                    $only_luxury_limoexpress = 0;
                    foreach ($Owners as $owner) {
                        \App\Models\Admin\NotIcludeOwner::create([
                            "request_id" => $request_detail->id,
                            "user_id" => $owner->id,
                        ]);
                    }
                }
            }
            // request place detail params
            $request_place_params = [
            'pick_lat'=>$request->pick_lat,
            'pick_lng'=>$request->pick_lng,
            'drop_lat'=>$request->drop_lat,
            'drop_lng'=>$request->drop_lng,
            'pick_address'=>$request->pick_address,
            'drop_address'=>$request->drop_address];
            // store request place details
            $request_detail->requestPlace()->create($request_place_params);

            // $ad_hoc_user_params = $request->only(['name','phone_number']);
            $ad_hoc_user_params['name'] = $request->pickup_poc_name;
            $ad_hoc_user_params['mobile'] = $request->pickup_poc_mobile;

            // Store ad hoc user detail of this request
            // $request_detail->adHocuserDetail()->create($ad_hoc_user_params);
            // Handle owner driver assignment
            if($request->owner_include_option == 'include' && $request->owner_action && $request->owner_driver_id) {
                $driver_id = $request->owner_driver_id;
                $driver = Driver::find($driver_id);
                
                if($driver) {
                    if($request->owner_action == 'assign') {
                        // Directly assign driver without notifications to other drivers
                        $selected_drivers = [];
                        $selected_drivers["user_id"] = $request_detail->user_id;
                        $selected_drivers["driver_id"] = $driver_id;
                        $selected_drivers["active"] = 1;
                        $selected_drivers["assign_method"] = 1;
                        $selected_drivers["created_at"] = date('Y-m-d H:i:s');
                        $selected_drivers["updated_at"] = date('Y-m-d H:i:s');
                        
                        // Create RequestMeta for direct assignment
                        RequestMeta::create([
                            'request_id' => $request_detail->id,
                            'driver_id' => $driver_id,
                            'user_id' => $request_detail->user_id,
                            'active' => 1,
                            'assign_method' => 1
                        ]);
                        
                        // Update Firebase
                        $this->database->getReference('request-meta/'.$request_detail->id)->set([
                            'driver_id' => $driver_id,
                            'request_id' => $request_detail->id,
                            'user_id' => $request_detail->user_id,
                            'active' => 1,
                            'updated_at' => Database::SERVER_TIMESTAMP
                        ]);
                        $this->database->getReference('requests/'.$request_detail->id)->update(['is_accept' => 1]);

                        $accepted_fare = $request_detail->request_eta_amount;
                        $offered_fare = $request_detail->request_eta_amount;

                        $updated_params = [
                            'driver_id' => $driver->id,
                            'accepted_at' => date('Y-m-d H:i:s'),
                            'is_driver_started' => true,
                            // 'accepted_ride_fare'=>$accepted_fare,
                            // 'offerred_ride_fare'=>$offered_fare,
                        ];

                        if($request_detail->is_out_station==0)
                        {
                            $updated_params['is_driver_started'] = true;
                        }
                        if($driver->owner_id){
                            $updated_params['owner_id'] = $driver->owner_id;
                            $updated_params['fleet_id'] = $driver->fleet_id;
                        }

                        $request_detail->update($updated_params);
                        $request_detail->fresh();

                        $driver->available = true;
                        $driver->save();

                        $notifable_driver = $driver->user;
                        $title = trans('push_notifications.ride_confirmed_by_user_title',[],$notifable_driver->lang);
                        $body = trans('push_notifications.ride_confirmed_by_user_body',[],$notifable_driver->lang);

                        dispatch(new SendPushNotification($notifable_driver,$title,$body));
                        
                        Log::info("Driver {$driver_id} directly assigned to request {$request_detail->id} without notifying other drivers");
                    } elseif($request->owner_action == 'complete') {
                        // Complete the ride immediately with all end request functionalities
                        $this->completeRequestFromDispatcher($request_detail, $driver, $request);
                        
                        Log::info("Driver {$driver_id} completed ride for request {$request_detail->id} from dispatcher");
                    }
                }
            } 
            $request_result =  fractal($request_detail, new TripRequestTransformer)->parseIncludes('userDetail');
            // @TODO send sms & email to the user
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e);
            Log::error('Error while Create new schedule request. Input params : ' . json_encode($request->all()));
            return $this->respondBadRequest('Unknown error occurred. Please try again later or contact us if it continues.');
        }
        DB::commit();

        return $this->respondSuccess($request_result, 'Request Scheduled Successfully');
    }

    /**
     * Complete request from dispatcher with all end request functionalities
     */
    protected function completeRequestFromDispatcher($request_detail, $driver, $request)
    {
        DB::beginTransaction();
        try {
            // Create RequestMeta for the driver
            RequestMeta::create([
                'request_id' => $request_detail->id,
                'driver_id' => $driver->id,
                'user_id' => $request_detail->user_id,
                'active' => 1,
                'assign_method' => 1
            ]);

            // Update driver state as Available
            if ($driver->driverDetail) {
                $driver->driverDetail->update(['available' => true]);
            }

            // Get zone type and pricing
            $zone_type = $request_detail->zoneType;
            $ride_type = 1;
            if($request->booking_type == 'book-hourly') {
                $ride_type = 3;
            }
            $zone_type_price = $zone_type->zoneTypePrice()->where('price_type', $ride_type)->first();

            // Calculate distance - use estimated distance from request or calculate from coordinates
            $distance = $request_detail->total_distance ?? 0;
            $request_place = $request_detail->requestPlace;
            if ($distance == 0 && $request_place) {
                // Calculate distance from coordinates if not available
                $pick_lat = $request_place->pick_lat;
                $pick_lng = $request_place->pick_lng;
                $drop_lat = $request_place->drop_lat ?? $request_place->pick_lat;
                $drop_lng = $request_place->drop_lng ?? $request_place->pick_lng;

                if (env('APP_FOR') != 'demo') {
                    if (get_settings('map_type') == 'open_street') {
                        $distance_and_duration = getDistanceMatrixByOpenstreetMap($pick_lat, $pick_lng, $drop_lat, $drop_lng);
                        $distance_in_meters = $distance_and_duration['distance_in_meters'];
                        $distance = $distance_in_meters / 1000;
                    } else {
                        $distance_matrix = get_distance_matrix($pick_lat, $pick_lng, $drop_lat, $drop_lng, true);
                        if ($distance_matrix->status == "OK" && $distance_matrix->rows[0]->elements[0]->status != "ZERO_RESULTS") {
                            $distance_in_meters = get_distance_value_from_distance_matrix($distance_matrix);
                            $distance = ceil($distance_in_meters / 1000);
                        }
                    }
                }

                if ($request_detail->unit == UnitType::MILES) {
                    $distance = ceil(kilometer_to_miles($distance));
                }
            }

            // Calculate duration - use trip_start_time if available, otherwise use current time
            $trip_start_time = $request_detail->trip_start_time ?? $request_detail->created_at;
            $duration = $this->calculateDurationOfTrip($trip_start_time);

            // Get waiting times - default to 0 or use free waiting time
            $before_trip_start_waiting_time = $request->input('before_trip_start_waiting_time', 0);
            $after_trip_start_waiting_time = $request->input('after_trip_start_waiting_time', 0);

            $subtract_with_free_waiting_before_trip_start = ($before_trip_start_waiting_time - ($zone_type_price->free_waiting_time_in_mins_before_trip_start ?? 0));
            $subtract_with_free_waiting_after_trip_start = ($after_trip_start_waiting_time - ($zone_type_price->free_waiting_time_in_mins_after_trip_start ?? 0));

            $waiting_time = ($subtract_with_free_waiting_before_trip_start + $subtract_with_free_waiting_after_trip_start);
            if ($waiting_time < 0) {
                $waiting_time = 0;
            }

            // Get promo detail if exists
            $promo_detail = null;
            if ($request_detail->promo_id) {
                $user_id = $request_detail->userDetail->id;
                $promo_detail = $this->validateAndGetPromoDetail($request_detail->promo_id, $user_id);
            }

            // Get service location for timezone
            $service_location = $request_detail->zoneType->zone->serviceLocation;
            $timezone = $service_location->timezone;

            // Calculate bill
            if (!$request_place) {
                throw new \Exception('Request place details not found');
            }
            $pick_lat = $request_place->pick_lat;
            $pick_lng = $request_place->pick_lng;
            $drop_lat = $request_place->drop_lat ?? $request_place->pick_lat;
            $drop_lng = $request_place->drop_lng ?? $request_place->pick_lng;
            if($request_detail->ride_type && $request_detail->ride_type == "book-hourly") {
                $base_price = 0;

                if(!empty($zone_type_price->hourly_base_prices) && is_array($zone_type_price->hourly_base_prices)) {
                    
                    if(request()->has('booking_hour') && request()->booking_hour) {
                        foreach($zone_type_price->hourly_base_prices as $hour => $price) {
                            if($hour == request()->booking_hour) {
                                $base_price = $price;
                                break;
                            }
                        }
                    }
                }
                $calculated_bill = [
                'base_price'=>$base_price,
                'base_distance'=>$zone_type_price->base_distance ?? 0,
                'price_per_distance'=>$zone_type_price->price_per_distance ?? 0,
                'distance_price'=>0,
                'price_per_time'=>$zone_type_price->price_per_time ?? 0,
                'time_price'=>0,
                'promo_discount'=>0,
                'waiting_charge'=>0,
                'service_tax'=>0,
                'service_tax_percentage'=>0,
                'admin_commision'=>0,
                'admin_commision_with_tax'=>0,
                'driver_commision'=>0,
                'admin_commision_from_driver'=>0,
                'total_amount'=> $base_price,
                'total_distance'=>0,
                'total_time'=>0,
                'airport_surge_fee'=>0,
                'cancellation_fee'=>0,
                ];
            } else {
                $calculated_bill = $this->calculateBillForARide(
                    $pick_lat,
                    $pick_lng,
                    $drop_lat,
                    $drop_lng,
                    $distance,
                    $duration,
                    $zone_type,
                    $zone_type_price,
                    $promo_detail,
                    $timezone,
                    null,
                    $waiting_time,
                    $request_detail,
                    $driver
                );
            }

            // Handle rental package if exists
            // if ($request_detail->is_rental && $request_detail->rental_package_id) {
            //     $chosen_package_price = ZoneTypePackagePrice::where('zone_type_id', $request_detail->zone_type_id)
            //         ->where('package_type_id', $request_detail->rental_package_id)
            //         ->first();

            //     if ($chosen_package_price) {
            //         $calculated_bill = $this->calculateRentalRideFares(
            //             $chosen_package_price,
            //             $distance,
            //             $duration,
            //             $waiting_time,
            //             $promo_detail,
            //             $request_detail,
            //             $driver
            //         );
            //     }
            // }

            // Add waiting time details to bill
            $calculated_bill['before_trip_start_waiting_time'] = $before_trip_start_waiting_time;
            $calculated_bill['after_trip_start_waiting_time'] = $after_trip_start_waiting_time;
            $calculated_bill['calculated_waiting_time'] = $waiting_time;
            $calculated_bill['waiting_charge_per_min'] = $zone_type_price->waiting_charge ?? 0;
            $calculated_bill['requested_currency_code'] = $service_location->currency_code;
            $calculated_bill['requested_currency_symbol'] = $service_location->currency_symbol;

            // Update request as completed
            $request_detail->update([
                'driver_id' => $driver->id,
                'is_completed' => true,
                'completed_at' => date('Y-m-d H:i:s'),
                'total_distance' => $distance,
                'total_time' => $duration,
            ]);

            // Handle payment based on payment option
            if ($request_detail->payment_opt == PaymentType::CASH) {
                // Deduct admin commission + tax from driver/owner wallet
                $admin_commision_with_tax = $calculated_bill['admin_commision_with_tax'];
                
                if ($driver->owner()->exists()) {
                    $owner_wallet = $driver->owner->ownerWalletDetail;
                    $owner_wallet->amount_spent += $admin_commision_with_tax;
                    $owner_wallet->amount_balance -= $admin_commision_with_tax;
                    $owner_wallet->save();

                    $driver->owner->ownerWalletHistoryDetail()->create([
                        'amount' => $admin_commision_with_tax,
                        'transaction_id' => str_random(6),
                        'remarks' => WalletRemarks::ADMIN_COMMISSION_FOR_REQUEST,
                        'is_credit' => false
                    ]);
                } else {
                    $driver_wallet = $driver->driverWallet;
                    $driver_wallet->amount_spent += $admin_commision_with_tax;
                    $driver_wallet->amount_balance -= $admin_commision_with_tax;
                    $driver_wallet->save();

                    $driver->driverWalletHistory()->create([
                        'amount' => $admin_commision_with_tax,
                        'transaction_id' => str_random(6),
                        'remarks' => WalletRemarks::ADMIN_COMMISSION_FOR_REQUEST,
                        'is_credit' => false
                    ]);
                }
                $request_detail['is_paid'] = false;
            } elseif ($request_detail->payment_opt == PaymentType::CARD) {
                $request_detail['is_paid'] = false;
                $request_detail->save();
            } else { // PaymentType::WALLET
                $request_detail['is_paid'] = true;
                // Deduct amount from user's wallet
                $chargable_amount = $calculated_bill['total_amount'];
                $user_wallet = $request_detail->userDetail->userWallet;

                if ($chargable_amount <= $user_wallet->amount_balance) {
                    $user_wallet->amount_balance -= $chargable_amount;
                    $user_wallet->amount_spent += $chargable_amount;
                    $user_wallet->save();

                    $request_detail->userDetail->userWalletHistory()->create([
                        'amount' => $chargable_amount,
                        'transaction_id' => $request_detail->id,
                        'request_id' => $request_detail->id,
                        'remarks' => WalletRemarks::SPENT_FOR_TRIP_REQUEST,
                        'is_credit' => false
                    ]);

                    // Add driver commission if payment type is wallet
                    $driver_commision = $calculated_bill['driver_commision'];
                    
                    if ($driver->owner()->exists()) {
                        $owner_wallet = $driver->owner->ownerWalletDetail;
                        $owner_wallet->amount_added += $driver_commision;
                        $owner_wallet->amount_balance += $driver_commision;
                        $owner_wallet->save();

                        $driver->owner->ownerWalletHistoryDetail()->create([
                            'amount' => $driver_commision,
                            'transaction_id' => $request_detail->id,
                            'remarks' => WalletRemarks::TRIP_COMMISSION_FOR_DRIVER,
                            'is_credit' => true
                        ]);
                    } else {
                        $driver_wallet = $driver->driverWallet;
                        $driver_wallet->amount_added += $driver_commision;
                        $driver_wallet->amount_balance += $driver_commision;
                        $driver_wallet->save();

                        $driver->driverWalletHistory()->create([
                            'amount' => $driver_commision,
                            'transaction_id' => $request_detail->id,
                            'remarks' => WalletRemarks::TRIP_COMMISSION_FOR_DRIVER,
                            'is_credit' => true
                        ]);
                    }
                } else {
                    // Insufficient wallet balance, switch to cash
                    $request_detail->payment_opt = PaymentType::CASH;
                    $request_detail->save();
                    $admin_commision_with_tax = $calculated_bill['admin_commision_with_tax'];

                    if ($driver->owner()->exists()) {
                        $owner_wallet = $driver->owner->ownerWalletDetail;
                        $owner_wallet->amount_spent += $admin_commision_with_tax;
                        $owner_wallet->amount_balance -= $admin_commision_with_tax;
                        $owner_wallet->save();

                        $driver->owner->ownerWalletHistoryDetail()->create([
                            'amount' => $admin_commision_with_tax,
                            'transaction_id' => str_random(6),
                            'remarks' => WalletRemarks::ADMIN_COMMISSION_FOR_REQUEST,
                            'is_credit' => false
                        ]);
                    } else {
                        $driver_wallet = $driver->driverWallet;
                        $driver_wallet->amount_spent += $admin_commision_with_tax;
                        $driver_wallet->amount_balance -= $admin_commision_with_tax;
                        $driver_wallet->save();

                        $driver->driverWalletHistory()->create([
                            'amount' => $admin_commision_with_tax,
                            'transaction_id' => str_random(6),
                            'remarks' => WalletRemarks::ADMIN_COMMISSION_FOR_REQUEST,
                            'is_credit' => false
                        ]);
                    }
                }
            }

            // Store request bill
            $bill = $request_detail->requestBill()->create($calculated_bill);

            // Update RequestCycles
            $get_request_datas = RequestCycles::where('request_id', $request_detail->id)->first();
            if ($get_request_datas) {
                $user_data = $driver->user ?? null;
                $request_data = json_decode(base64_decode($get_request_datas->request_data), true);
                $request_datas['request_id'] = $request_detail->id;
                $request_datas['user_id'] = $request_detail->user_id ?? ($request_detail->adHocuserDetail->id ?? null);
                $request_datas['driver_id'] = $driver->id;
                $driver_details['name'] = $driver->name;
                $driver_details['mobile'] = $driver->mobile;
                $driver_details['image'] = $user_data->profile_picture ?? null;
                $rating = number_format($user_data->rating ?? 0, 2);
                $data[0]['rating'] = $rating;
                $data[0]['status'] = 5;
                $data[0]['process_type'] = "trip_completed";
                $data[0]['orderby_status'] = intval($get_request_datas->orderby_status) + 1;
                $request_datas['orderby_status'] = $data[0]['orderby_status'];
                $data[0]['dricver_details'] = $driver_details;
                $data[0]['created_at'] = date("Y-m-d H:i:s", time());
                $request_data1 = array_merge($request_data, $data);
                $request_datas['request_data'] = base64_encode(json_encode($request_data1));

                RequestCycles::where('id', $get_request_datas->id)->update($request_datas);
            }

            // Send push notification to user if not dispatch request
            if (!$request_detail->if_dispatch && $request_detail->user_id) {
                $user = $request_detail->userDetail;
                if ($user) {
                    $title = trans('push_notifications.trip_completed_title', [], $user->lang ?? 'en');
                    $body = trans('push_notifications.trip_completed_body', [], $user->lang ?? 'en');
                    dispatch(new SendPushNotification($user, $title, $body));
                }
            }

            // Send emails
            if ($request_detail->user_id) {
                $User = User::where("id", $request_detail->user_id)->first();
                if ($User) {
                    $details = [
                        "customer_name" => $User->name,
                        "customer_phone_number" => $User->mobile,
                        "partner_name" => $driver->name,
                        "pickup_time" => $request_detail->trip_start_time != "" ? date("d-m-Y H:i:s", strtotime($request_detail->trip_start_time)) : "",
                        "pickup_location" => $request_detail->pick_address ?? '',
                        "destination" => $request_detail->drop_address ?? '',
                        "pickup_poc_instruction" => $request_detail->pickup_poc_instruction ?? '',
                    ];
                    Mail::to($User->email)->send(new \App\Mail\RideEndMailForCustomer($details));
                    Mail::to($driver->email)->send(new \App\Mail\RideEndMailForOwnerDriver($details));
                    
                    if (!empty($driver->owner)) {
                        $details["partner_name"] = $driver->owner->owner_name;
                        Mail::to($driver->owner->email)->send(new \App\Mail\RideEndMailForOwnerDriver($details));
                    }
                }
            }

            // Update Firebase
            $this->database->getReference('request-meta/' . $request_detail->id)->set([
                'driver_id' => $driver->id,
                'request_id' => $request_detail->id,
                'user_id' => $request_detail->user_id,
                'active' => 1,
                'updated_at' => Database::SERVER_TIMESTAMP
            ]);

            $this->database->getReference('requests/' . $request_detail->id)->update([
                'driver_id' => $driver->id,
                'is_accept' => 1,
                'is_completed' => true,
                'updated_at' => Database::SERVER_TIMESTAMP
            ]);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error completing request from dispatcher: ' . $e->getMessage());
            Log::error($e);
            throw $e;
        }
    }

    /**
     * Calculate duration of trip
     */
    protected function calculateDurationOfTrip($start_time)
    {
        $current_time = date('Y-m-d H:i:s');
        $start_time = Carbon::parse($start_time);
        $end_time = Carbon::parse($current_time);
        $total_duration = $end_time->diffInMinutes($start_time);
        return $total_duration;
    }

    /**
     * Validate & Get Promo Detail
     */
    protected function validateAndGetPromoDetail($promo_code_id, $user_id)
    {
        $current_date = Carbon::today()->toDateTimeString();
        $expired = Promo::where('id', $promo_code_id)->where('to', '>', $current_date)->first();
        
        if ($expired) {
            $exceed_usage = PromoUser::where('promo_code_id', $expired->id)->where('user_id', $user_id)->count();
            if ($exceed_usage >= $expired->uses_per_user) {
                return null;
            } else {
                return $expired;
            }
        } else {
            return null;
        }
    }

    /**
     * Calculate Rental Ride Fares
     */
    protected function calculateRentalRideFares($zone_type_price, $distance, $duration, $waiting_time, $coupon_detail, $request_detail, $driver)
    {
        $request_place = $request_detail->requestPlace;

        $airport_surge = find_airport($request_place->pick_lat, $request_place->pick_lng);
        if ($airport_surge == null) {
            $airport_surge = find_airport($request_place->drop_lat, $request_place->drop_lng);
        }

        $airport_surge_fee = 0;
        if ($airport_surge) {
            $airport_surge_fee = $airport_surge->airport_surge_fee ?: 0;
        }

        // Distance Price
        $calculatable_distance = $distance - $zone_type_price->free_distance;
        $calculatable_distance = $calculatable_distance < 0 ? 0 : $calculatable_distance;

        $price_per_distance = $zone_type_price->distance_price_per_km;

        // Validate if the current time in surge timings
        $timezone = $request_detail->serviceLocationDetail->timezone;
        $current_time = Carbon::now()->setTimezone($timezone);
        $day = $current_time->dayName;
        $current_time = $current_time->toTimeString();

        $zone_surge_price = $request_detail->zoneType->zone->zoneSurge()
            ->where('day', $day)
            ->whereTime('start_time', '<=', $current_time)
            ->whereTime('end_time', '>=', $current_time)
            ->first();

        if ($zone_surge_price) {
            $surge_percent = $zone_surge_price->value;
            $surge_price_additional_cost = ($price_per_distance * ($surge_percent / 100));
            $price_per_distance += $surge_price_additional_cost;
            $request_detail->is_surge_applied = true;
            $request_detail->save();
        }

        $distance_price = $calculatable_distance * $price_per_distance;
        
        // Time Price
        $ride_duration = $duration > $zone_type_price->free_min ? $duration - $zone_type_price->free_min : 0;
        $time_price = ($ride_duration) * $zone_type_price->time_price_per_min;
        
        // Waiting charge
        $waiting_charge = $waiting_time * $zone_type_price->waiting_charge;
        
        // Base Price
        $base_price = $zone_type_price->base_price;
        if(request()->booking_type == 'book-hourly') {
             $base_price = 0;
            
            foreach($zone_type_price->hourly_base_prices as $hour => $price) {
                if($hour == request()->booking_hour) {
                    $base_price = $price;
                    break;
                }
            }
        }

        // Sub Total
        if ($request_detail->zoneType->vehicleType->is_support_multiple_seat_price && $request_detail->passenger_count > 0) {
            if ($request_detail->passenger_count == 1) {
                $seat_discount = $request_detail->zoneType->vehicleType->one_seat_price_discount;
            }
            if ($request_detail->passenger_count == 2) {
                $seat_discount = $request_detail->zoneType->vehicleType->two_seat_price_discount;
            }
            if ($request_detail->passenger_count == 3) {
                $seat_discount = $request_detail->zoneType->vehicleType->three_seat_price_discount;
            }
            if ($request_detail->passenger_count == 4) {
                $seat_discount = $request_detail->zoneType->vehicleType->four_seat_price_discount;
            }

            $base_price -= ($base_price * ($seat_discount / 100));
            $distance_price -= ($distance_price * ($seat_discount / 100));
            $time_price -= ($time_price * ($seat_discount / 100));
            $airport_surge_fee -= ($airport_surge_fee * ($seat_discount / 100));
        }

        $sub_total = $base_price + $distance_price + $time_price + $waiting_charge + $airport_surge_fee;

        $discount_amount = 0;
        if ($coupon_detail) {
            if ($coupon_detail->minimum_trip_amount < $sub_total) {
                $discount_amount = $sub_total * ($coupon_detail->discount_percent / 100);
                if ($discount_amount > $coupon_detail->maximum_discount_amount) {
                    $discount_amount = $coupon_detail->maximum_discount_amount;
                }
                $sub_total = $sub_total - $discount_amount;
            }
        }
        
        $zone_type = $request_detail->zoneType;

        // Get service tax percentage from settings
        $tax_percent = $zone_type->service_tax;
        $tax_amount = ($sub_total * ($tax_percent / 100));

        // Get Admin Commission
        $admin_commision_type = $zone_type_price->zoneType->admin_commision_type;
        $service_fee = $zone_type_price->zoneType->admin_commision;
        $tax_percent = $zone_type_price->zoneType->service_tax;
        $tax_amount = ($sub_total * ($tax_percent / 100));
        
        $admin_commission_type_for_driver = get_settings('admin_commission_type_for_driver');
        $service_fee_for_driver = get_settings('admin_commission_for_driver');

        if ($driver->owner_id != NULL) {
            $admin_commission_type_for_driver = get_settings('admin_commission_type_for_owner');
            $service_fee_for_driver = get_settings('admin_commission_for_owner');
        }

        if ($admin_commision_type == 1) {
            $admin_commision = ($sub_total * ($service_fee / 100));
        } else {
            $admin_commision = $service_fee;
        }
        
        // Admin commission with tax amount
        $admin_commision_with_tax = $tax_amount + $admin_commision;
        $driver_commision = $sub_total + $discount_amount;
        
        // Driver Commission
        if ($coupon_detail && $coupon_detail->deduct_from == 2) {
            $driver_commision = $sub_total;
        }

        if ($admin_commission_type_for_driver == 1) {
            $admin_commision_from_driver = ($driver_commision * ($service_fee_for_driver / 100));
        } else {
            $admin_commision_from_driver = $service_fee_for_driver;
        }

        $driver_commision -= $admin_commision_from_driver;

        // Total Amount
        $total_amount = $sub_total + $admin_commision_with_tax;
        $admin_commision_with_tax += $admin_commision_from_driver;

        return [
            'base_price' => $base_price,
            'base_distance' => $zone_type_price->free_distance,
            'price_per_distance' => $zone_type_price->distance_price_per_km,
            'distance_price' => $distance_price,
            'price_per_time' => $zone_type_price->time_price_per_min,
            'time_price' => $time_price,
            'promo_discount' => $discount_amount,
            'waiting_charge' => $waiting_charge,
            'service_tax' => $tax_amount,
            'service_tax_percentage' => $tax_percent,
            'admin_commision' => $admin_commision,
            'admin_commision_with_tax' => $admin_commision_with_tax,
            'driver_commision' => $driver_commision,
            'admin_commision_from_driver' => $admin_commision_from_driver,
            'total_amount' => $total_amount,
            'total_distance' => $distance,
            'total_time' => $duration,
            'airport_surge_fee' => $airport_surge_fee
        ];
    }
}
