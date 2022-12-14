<?php

namespace App\Http\Controllers\ProviderResources;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Auth;
use Log;
use Setting;
use Carbon\Carbon;
use App\Helpers\Helper;
use App\Http\Controllers\SendPushNotification;
use App\Document;
use App\ProviderDocument;
use App\User;
use App\Provider;
use App\Fleet;
use App\Admin;
use App\Promocode;
use App\UserRequests;
use App\RequestFilter;
use App\PromocodeUsage;
use App\PromocodePassbook;
use App\ProviderService;
use App\UserRequestRating;
use App\UserRequestPayment;
use App\ServiceType;
use App\WalletPassbook;
use Location\Coordinate;
use Location\Distance\Vincenty;
use App\Services\ServiceTypes;
use App\Services\TransactionsLog;
use App\Transactions;
use App\TransactionType;
use App\AdminTransactions;
use App\AdminWallet;
use App\UserWallet;
use App\ProviderWallet;
use App\FleetWallet;
use App\WalletRequests;
use DB;
use DateTime;

class TripController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        try{
            if($request->ajax()) {
                $Provider = Auth::user();
            } else {
                $Provider = Auth::guard('provider')->user();
            }

            $provider = $Provider->id;

            $document = Document::where('doc_required','=','Yes')->pluck('id');
            $provider_document = ProviderDocument::where('provider_id',Auth::user()->id)->whereIn('document_id', $document)->where('expires_at','<',date('Y-m-d'))->count();  
            $wallet_limit = Setting::get('wallet_limit');
            $Provider = Auth::user();
            $provider_wallet=$Provider->wallet_balance;



        
            $AfterAssignProvider = RequestFilter::with(['request.user', 'request.payment', 'request'])
                ->where('provider_id', $provider)
                ->whereHas('request', function($query) use ($provider) {
                        $query->where('status','<>', 'CANCELLED');
                        $query->where('status','<>', 'SCHEDULED');
                        $query->where('provider_id', $provider );
                        $query->where('current_provider_id', $provider);
                    });


            $BeforeAssignProvider = RequestFilter::with(['request.user', 'request.payment', 'request'])
                    ->where('provider_id', $provider)
                    ->whereHas('request', function($query) use ($provider){
                        $query->where('status','<>', 'CANCELLED');
                        $query->where('status','<>', 'SCHEDULED');
                        $query->when(Setting::get('broadcast_request') == 1, function ($q) {
                            $q->where('current_provider_id',0);
                        });
                        $query->when(Setting::get('broadcast_request') == 0, function ($q) use ($provider){
                            $q->where('current_provider_id',$provider);
                        });
                        
                    });
                
            $IncomingRequests = $BeforeAssignProvider->union($AfterAssignProvider)->get();
            
            if(!empty($request->latitude)) {
                $Provider->update([
                        'latitude' => $request->latitude,
                        'longitude' => $request->longitude,
                ]);
            }

            if(Setting::get('manual_request',0) == 0){

                $Timeout = Setting::get('provider_select_timeout', 180);
                    if(!empty($IncomingRequests)){
                        for ($i=0; $i < sizeof($IncomingRequests); $i++) {
                            $IncomingRequests[$i]->time_left_to_respond = $Timeout - (time() - strtotime($IncomingRequests[$i]->request->assigned_at));
                            if($IncomingRequests[$i]->request->status == 'SEARCHING' && $IncomingRequests[$i]->time_left_to_respond < 0) {
                                if(Setting::get('broadcast_request',0) == 1){
                                    $this->assign_destroy($IncomingRequests[$i]->request->id);
                                }else{
                                    $this->assign_next_provider($IncomingRequests[$i]->request->id);
                                }
                            }
                        }
                    }

            }

        
        if(($Provider->service ? Auth::user()->service->status : 'offline')  ==  "riding"){
          
            $account_status="approved";
           
         }else{
           
            if (($provider_document > 0)) {
                $account_status="document";
              } else {
                if (($wallet_limit > $provider_wallet)) {
                    $account_status="wallet";
                } else {
                    $account_status=$Provider->status;
                }
            }
          
        }  

            $Response = [
                    'account_status' => $account_status,
                    'service_status' => $Provider->service ? Auth::user()->service->status : 'offline',
                    'requests' => $IncomingRequests,
                    'provider_details' => $Provider,
                ];

            return $Response;
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Something went wrong']);
        }
    }

    /**
     * Calculate distance between two coordinates.
     * 
     * @return \Illuminate\Http\Response
     */

    public function calculate_distance(Request $request, $id){
        $this->validate($request, [
                'latitude' => 'required|numeric',
                'longitude' => 'required|numeric'
            ]);
        try{

            if($request->ajax()) {
                $Provider = Auth::user();
            } else {
                $Provider = Auth::guard('provider')->user();
            }

            $UserRequest = UserRequests::where('status','PICKEDUP')
                            ->where('provider_id',$Provider->id)
                            ->find($id);

            if($UserRequest && ($request->latitude && $request->longitude)){

                Log::info("REQUEST ID:".$UserRequest->id."==SOURCE LATITUDE:".$UserRequest->track_latitude."==SOURCE LONGITUDE:".$UserRequest->track_longitude);
            
                if($UserRequest->track_latitude && $UserRequest->track_longitude){

                    $coordinate1 = new Coordinate($UserRequest->track_latitude, $UserRequest->track_longitude); /** Set Distance Calculation Source Coordinates ****/
                    $coordinate2 = new Coordinate($request->latitude, $request->longitude); /** Set Distance calculation Destination Coordinates ****/

                    $calculator = new Vincenty();

                    /***Distance between two coordinates using spherical algorithm (library as mjaschen/phpgeo) ***/ 

                    $mydistance = $calculator->getDistance($coordinate1, $coordinate2); 

                    $meters = $mydistance;

                    Log::info("REQUEST ID:".$UserRequest->id."==BETWEEN TWO COORDINATES DISTANCE:".$meters." (m)");

                    if($meters >= 100){
                        /*** If traveled distance riched houndred meters means to be the source coordinates ***/
                        $traveldistance = round(($meters/1000),8);

                        $calulatedistance = $UserRequest->track_distance + $traveldistance;

                        $UserRequest->track_distance  = $calulatedistance;
                        $UserRequest->distance        = $calulatedistance;
                        $UserRequest->track_latitude  = $request->latitude;
                        $UserRequest->track_longitude = $request->longitude;
                        $UserRequest->save();
                    }
                }else if(!$UserRequest->track_latitude && !$UserRequest->track_longitude) {
                    $UserRequest->distance             = 0;
                    $UserRequest->track_latitude      = $request->latitude;
                    $UserRequest->track_longitude     = $request->longitude;
                    $UserRequest->save();
                }
            }
            return $UserRequest;
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => trans('api.something_went_wrong')]);
        }
    }

    /**
     * Cancel given request.
     *
     * @return \Illuminate\Http\Response
     */
    public function cancel(Request $request)
    {
        $this->validate($request, [
            'cancel_reason'=> 'max:255',
        ]);
        
        try{

            $UserRequest = UserRequests::findOrFail($request->id);
            $Cancellable = ['SEARCHING', 'ACCEPTED', 'ARRIVED', 'STARTED', 'CREATED','SCHEDULED'];

            if(!in_array($UserRequest->status, $Cancellable)) {
                return back()->with(['flash_error' => 'Cannot cancel request at this stage!']);
            }

            $UserRequest->status = "CANCELLED";
            $UserRequest->cancel_reason = $request->cancel_reason;
            $UserRequest->cancelled_by = "PROVIDER";
            $UserRequest->save();

             RequestFilter::where('request_id', $UserRequest->id)->delete();

             ProviderService::where('provider_id',$UserRequest->provider_id)->update(['status' =>'active']);

             // Send Push Notification to User
            (new SendPushNotification)->ProviderCancellRide($UserRequest);

            return $UserRequest;

        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => trans('api.something_went_wrong')]);
        }


    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function rate(Request $request, $id)
    {

        $this->validate($request, [
                'rating' => 'required|integer|in:0,1,2,3,4,5',
                'comment' => 'max:255',
            ]);
    
        try {

            $UserRequest = UserRequests::where('id', $id)
                ->where('status', 'COMPLETED')
                ->firstOrFail();

            if($UserRequest->rating == null) {
                UserRequestRating::create([
                        'provider_id' => $UserRequest->provider_id,
                        'user_id' => $UserRequest->user_id,
                        'request_id' => $UserRequest->id,
                        'provider_rating' => $request->rating,
                        'provider_comment' => $request->comment,
                    ]);
            } else {
                $UserRequest->rating->update([
                        'provider_rating' => $request->rating,
                        'provider_comment' => $request->comment,
                    ]);
            }

            $UserRequest->update(['provider_rated' => 1]);

            // Delete from filter so that it doesn't show up in status checks.
            RequestFilter::where('request_id', $id)->delete();

            ProviderService::where('provider_id',$UserRequest->provider_id)->update(['status' =>'active']);

            // Send Push Notification to Provider 
            $average = UserRequestRating::where('provider_id', $UserRequest->provider_id)->avg('provider_rating');

            $UserRequest->user->update(['rating' => $average]);
             (new SendPushNotification)->Rate($UserRequest);

            return response()->json(['message' => trans('api.ride.request_completed')]);

        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => trans('api.ride.request_not_completed')], 500);
        }
    }
    /**
     * Get the trip history of the provider
     *
     * @return \Illuminate\Http\Response
     */
    public function request_rides(Request $request)
    {
        $req = $request->request_id;
        $provider =Auth::user()->id;


        try {
            if($request->ajax()) {

                 $query = UserRequests::query();
                 $query->when(request('type') == 'past' , function ($q) use ($req){
                      $q->when(request('request_id') != null  , function ($p) use ($req) {
                        $p->where('id' , $req);
                      });
                      $q->where('status', 'COMPLETED');
                      $q->where('provider_id', Auth::user()->id);
                 });
                 $query->when(request('type') == 'upcoming' , function ($q) use ($req){
                      $q->when(request('request_id') != null  , function ($p) use ($req) {
                        $p->where('id' , $req);
                      });
                      $q->where('is_scheduled', 'YES');
                      $q->where('provider_id', Auth::user()->id);
                 });
                 $Jobs = $query->orderBy('created_at','desc')
                               ->with('payment','service_type','user','rating')
                               ->get();


               if(!empty($Jobs)){
                $map_icon_start = asset('asset/img/marker-car.png');
                $map_icon_end = asset('asset/img/map-marker-red.png');
                foreach ($Jobs as $key => $value) {
                    $Jobs[$key]->static_map = "https://maps.googleapis.com/maps/api/staticmap?".
                            "autoscale=1".
                            "&size=600x300".
                            "&maptype=terrian".
                            "&format=png".
                            "&visual_refresh=true".
                            "&markers=icon:".$map_icon_start."%7C".$value->s_latitude.",".$value->s_longitude.
                            "&markers=icon:".$map_icon_end."%7C".$value->d_latitude.",".$value->d_longitude.
                            "&path=color:0x000000|weight:3|enc:".$value->route_key.
                            "&key=".Setting::get('map_key');
                }
            }
            return $Jobs;
        }
        
        } catch (Exception $e) {
            
        }
    }

    /**
     * Get the trip history of the provider
     *
     * @return \Illuminate\Http\Response
     */
    public function scheduled(Request $request)
    {
        
        try{

            $Jobs = UserRequests::where('provider_id', Auth::user()->id)
                    ->where('status' , 'SCHEDULED')
                    ->where('is_scheduled', 'YES')
                    ->with('payment','service_type')
                    ->get();

            if(!empty($Jobs)){
                $map_icon_start = asset('asset/img/marker-start.png');
                $map_icon_end = asset('asset/img/marker-end.png');
                foreach ($Jobs as $key => $value) {
                    $Jobs[$key]->static_map = "https://maps.googleapis.com/maps/api/staticmap?".
                            "autoscale=1".
                            "&size=600x300".
                            "&maptype=terrian".
                            "&format=png".
                            "&visual_refresh=true".
                            "&markers=icon:".$map_icon_start."%7C".$value->s_latitude.",".$value->s_longitude.
                            "&markers=icon:".$map_icon_end."%7C".$value->d_latitude.",".$value->d_longitude.
                            "&path=color:0x000000|weight:3|enc:".$value->route_key.
                            "&key=".Setting::get('map_key');
                }
            }

            return $Jobs;
            
        } catch(Exception $e) {
            return response()->json(['error' => trans('api.something_went_wrong')]);
        }
    }

    /**
     * Get the trip history of the provider
     *
     * @return \Illuminate\Http\Response
     */
    public function history(Request $request)
    {
        if($request->ajax()) {

            $Jobs = UserRequests::where('provider_id', Auth::user()->id)
                    ->where('status', 'COMPLETED')
                    ->orderBy('created_at','desc')
                    ->with('payment','service_type')
                    ->get();

            if(!empty($Jobs)){
                $map_icon_start = asset('asset/img/marker-start.png');
                $map_icon_end = asset('asset/img/marker-end.png');
                foreach ($Jobs as $key => $value) {
                    $Jobs[$key]->static_map = "https://maps.googleapis.com/maps/api/staticmap?".
                            "autoscale=1".
                            "&size=600x300".
                            "&maptype=terrian".
                            "&format=png".
                            "&visual_refresh=true".
                            "&markers=icon:".$map_icon_start."%7C".$value->s_latitude.",".$value->s_longitude.
                            "&markers=icon:".$map_icon_end."%7C".$value->d_latitude.",".$value->d_longitude.
                            "&path=color:0x000000|weight:3|enc:".$value->route_key.
                            "&key=".Setting::get('map_key');
                }
            }
            return $Jobs;
        }
        $Jobs = UserRequests::where('provider_id', Auth::guard('provider')->user()->id)->with('user', 'service_type', 'payment', 'rating')->get();
        return view('provider.trip.index', compact('Jobs'));
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function accept(Request $request, $id)
    {
        try {

            $UserRequest = UserRequests::with('user')->findOrFail($id);

            if($UserRequest->status != "SEARCHING") {
                return response()->json(['error' => trans('api.ride.request_inprogress')]);
            }
            
            $UserRequest->provider_id = Auth::user()->id;

            if(Setting::get('broadcast_request',0) == 1){
               $UserRequest->current_provider_id = Auth::user()->id; 
            }

            if($UserRequest->schedule_at != ""){

                $beforeschedule_time = strtotime($UserRequest->schedule_at."- 1 hour");
                $afterschedule_time = strtotime($UserRequest->schedule_at."+ 1 hour");

                $CheckScheduling = UserRequests::where('status','SCHEDULED')
                            ->where('provider_id', Auth::user()->id)
                            ->whereBetween('schedule_at',[$beforeschedule_time,$afterschedule_time])
                            ->count();

                if($CheckScheduling > 0 ){
                    if($request->ajax()) {
                        return response()->json(['error' => trans('api.ride.request_already_scheduled')]);
                    }else{
                        return redirect('dashboard')->with('flash_error', trans('api.ride.request_already_scheduled'));
                    }
                }

                RequestFilter::where('request_id',$UserRequest->id)->where('provider_id',Auth::user()->id)->update(['status' => 2]);

                $UserRequest->status = "SCHEDULED";
                $UserRequest->save();
 // Send Push Notification to User
                (new SendPushNotification)->ScheduleRideAcceptedUser($UserRequest);
// Send Push Notification to provider
                (new SendPushNotification)->ScheduleRideAcceptedProvider($UserRequest);

            }else{


                $UserRequest->status = "STARTED";
                $UserRequest->save();

                // Send Push Notification to User
                (new SendPushNotification)->RideAccepted($UserRequest);


                ProviderService::where('provider_id',$UserRequest->provider_id)->update(['status' =>'riding']);

                $Filters = RequestFilter::where('request_id', $UserRequest->id)->where('provider_id', '!=', Auth::user()->id)->get();
                // dd($Filters->toArray());
                foreach ($Filters as $Filter) {
                    $Filter->delete();
                }
            }

            $UnwantedRequest = RequestFilter::where('request_id','!=' ,$UserRequest->id)
                                ->where('provider_id',Auth::user()->id )
                                ->whereHas('request', function($query){
                                    $query->where('status','<>','SCHEDULED');
                                });

            if($UnwantedRequest->count() > 0){
                $UnwantedRequest->delete();
            }  

           

            return $UserRequest;

        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => trans('api.unable_accept')]);
        } catch (Exception $e) {
            return response()->json(['error' => trans('api.connection_err')]);
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $this->validate($request, [
              'status' => 'required|in:ACCEPTED,STARTED,ARRIVED,PICKEDUP,DROPPED,PAYMENT,COMPLETED',
           ]);

        try{


            //$this->callTransaction($id);

            $UserRequest = UserRequests::with('user')->findOrFail($id);


            if($request->status == 'DROPPED' && ($UserRequest->payment_mode != 'CASH' && $UserRequest->payment_mode != 'Korttimaksu' && $UserRequest->payment_mode != 'Lasku'  && $UserRequest->payment_mode != 'MobilePay')) {

                $UserRequest->status = 'COMPLETED';
                $UserRequest->paid = 0;

                (new SendPushNotification)->Complete($UserRequest);
            } else if ($request->status == 'COMPLETED' && ($UserRequest->payment_mode == 'CASH' || $UserRequest->payment_mode == 'Korttimaksu' || $UserRequest->payment_mode == 'Lasku' || $UserRequest->payment_mode == 'MobilePay')) {
                
                if($UserRequest->status=='COMPLETED'){
                    //for off cross clicking on change payment issue on mobile
                    return true;
                }
                
                $UserRequest->status = $request->status;
                $UserRequest->paid = 1;                
                
                (new SendPushNotification)->Complete($UserRequest);

                //for completed payments
                $RequestPayment = UserRequestPayment::where('request_id',$id)->first();
                $RequestPayment->payment_mode = $UserRequest->payment_mode;
                $RequestPayment->cash = $RequestPayment->payable;             
                $RequestPayment->save();               

            } else {
                $UserRequest->status = $request->status;

                if($request->status == 'ARRIVED'){
                    (new SendPushNotification)->Arrived($UserRequest);
                }
            }

            if($request->status == 'PICKEDUP'){
                if($UserRequest->is_track == "YES"){
                   $UserRequest->distance  = 0; 
                }
                $UserRequest->started_at = Carbon::now();
                (new SendPushNotification)->Pickedup($UserRequest);
            }

            $UserRequest->save();

            if($request->status == 'DROPPED') {

                if($UserRequest->is_track == "YES"){
                    $UserRequest->d_latitude = $request->latitude?:$UserRequest->d_latitude;
                    $UserRequest->d_longitude = $request->longitude?:$UserRequest->d_longitude;
                    $UserRequest->d_address =  $request->address?:$UserRequest->d_address;
                }
                $UserRequest->finished_at = Carbon::now();
                $StartedDate  = date_create($UserRequest->started_at);
                $FinisedDate  = Carbon::now();
                $TimeInterval = date_diff($StartedDate,$FinisedDate);
                $MintuesTime  = $TimeInterval->i;
                $UserRequest->travel_time = $MintuesTime;
                $UserRequest->save();
                $UserRequest->with('user')->findOrFail($id);
                $UserRequest->invoice = $this->invoice($id);
               
                (new SendPushNotification)->Dropped($UserRequest);
                
            }

            //for completed payments
         $this->callTransaction($id);

            // Send Push Notification to User
       
            return $UserRequest;

        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => trans('api.unable_accept')]);
        } catch (Exception $e) {
            return response()->json(['error' => trans('api.connection_err')]);
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
   public function destroy($id)
  {
     $UserRequest = UserRequests::find($id);

     $requestdelete = RequestFilter::where('request_id' , $id)
                                     ->where('provider_id' , Auth::user()->id)
                                     ->delete();

     try {
         if(Setting::get('broadcast_request') == 1){
             return response()->json(['message' => trans('api.ride.request_rejected')]);
         }else{
             $this->assign_next_provider($UserRequest->id);
             return $UserRequest->with('user')->get();
         }

     } catch (ModelNotFoundException $e) {
         return response()->json(['error' => trans('api.unable_accept')]);
     } catch (Exception $e) {
         return response()->json(['error' => trans('api.connection_err')]);
     }
  }

  public function test(Request $request)
    {
        $UserRequest = DB::table('user_requests')->where('status','SCHEDULED')
          ->get();
          $cur_date=date("Y-m-d");
          $duration='+20 minutes';
          if(!empty($UserRequest)){
            foreach($UserRequest as $ride){
               
               $shedule_dat=  date("Y-m-d", strtotime($ride->schedule_at));
               $shedule_time=date("H:i", strtotime($ride->schedule_at));
              if($cur_date == $shedule_dat && $shedule_time  ==  date('H:i', strtotime($duration, strtotime(date('H:i'))))){


                $shedule_time_message=date("g:i A", strtotime($ride->schedule_at));
                $message="Your upcoming ride has been started";
              //scehule start request push to user
              // (new SendPushNotification)->user_schedule_timer($ride->user_id,$message);
              //scehule start request push to provider
               (new SendPushNotification)->sendPushToProvider($ride->provider_id,$message);
               return response()->json(['error' => $ride->provider_id]);


                }
              
            }
           
        }




    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function assign_destroy($id)
    {
        $UserRequest = UserRequests::find($id);
        try {
            UserRequests::where('id', $UserRequest->id)->update(['status' => 'CANCELLED']);
            // No longer need request specific rows from RequestMeta
            RequestFilter::where('request_id', $UserRequest->id)->delete();
            //  request push to user provider not available
            (new SendPushNotification)->ProviderNotAvailable($UserRequest->user_id);

        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => trans('api.unable_accept')]);
        } catch (Exception $e) {
            return response()->json(['error' => trans('api.connection_err')]);
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */

    public function assign_next_provider($request_id) {

        try {
            $UserRequest = UserRequests::findOrFail($request_id);
        } catch (ModelNotFoundException $e) {
            // Cancelled between update.
            return false;
        }

        $RequestFilter = RequestFilter::where('provider_id', $UserRequest->current_provider_id)
            ->where('request_id', $UserRequest->id)
            ->delete();

        try {

            $next_provider = RequestFilter::where('request_id', $UserRequest->id)
                            ->orderBy('id')
                            ->firstOrFail();

            $UserRequest->current_provider_id = $next_provider->provider_id;
            $UserRequest->assigned_at = Carbon::now();
            $UserRequest->save();

            // incoming request push to provider
            (new SendPushNotification)->IncomingRequest($next_provider->provider_id);
            
        } catch (ModelNotFoundException $e) {

            UserRequests::where('id', $UserRequest->id)->update(['status' => 'CANCELLED']);

            // No longer need request specific rows from RequestMeta
            RequestFilter::where('request_id', $UserRequest->id)->delete();

            //  request push to user provider not available
            (new SendPushNotification)->ProviderNotAvailable($UserRequest->user_id);
        }
    }

    public function invoice($request_id)
    {
        try {
            $UserRequest = UserRequests::with('provider')->findOrFail($request_id);
           
            $tax_percentage = Setting::get('tax_percentage');
            $commission_percentage = Setting::get('commission_percentage');
            $provider_commission_percentage = Setting::get('provider_commission_percentage');
             
            $Fixed = 0;
            $Distance = 0;
            $Discount = 0; // Promo Code discounts should be added here.
            $Wallet = 0;
            $Surge = 0;
            $ProviderCommission = 0;
            $ProviderPay = 0;
            $Distance_fare =0;
            $Minute_fare =0;
            $calculator ='DISTANCE';
            $discount_per =0;

            //added the common function for calculate the price
            $requestarr['kilometer']=$UserRequest->distance;
            $requestarr['time']=0;
            $requestarr['seconds']=0;
            $requestarr['minutes']=$UserRequest->travel_time;
            $requestarr['service_type']=$UserRequest->service_type_id;
            
            $response = new ServiceTypes();
            $pricedata=$response->applyPriceLogic($requestarr,1);

            if(!empty($pricedata)){
                $Distance =$pricedata['price'];
                $Fixed = $pricedata['base_price'];
                $Distance_fare = $pricedata['distance_fare'];
                $Minute_fare = $pricedata['minute_fare'];
                $Hour_fare = $pricedata['hour_fare'];
                $calculator = $pricedata['calculator'];
              
            }
             
            $Distance=$Distance;
            
            $total_fare = $Fixed + $Minute_fare + $Distance_fare;
           
           
            if($UserRequest->surge){
                $Surge = (Setting::get('surge_percentage')/100) * $total_fare;
            }

            $Tax = ($total_fare + $Surge) * ( $tax_percentage/100 );
            $Commision = ($total_fare + $Surge) * ( $commission_percentage/100 );

            $Total = $Distance + $Tax + $Surge;
            $payable_amount = $Distance + $Tax + $Surge - $Discount;

       
            if($UserRequest->promocode_id>0){
                if($Promocode = Promocode::find($UserRequest->promocode_id)){
                    $max_amount = $Promocode->max_amount;
                    $discount_per = $Promocode->percentage;

                    $discount_amount = (($Distance + $Tax) * ($discount_per/100));

                    if($discount_amount>$Promocode->max_amount){
                        $Discount = $Promocode->max_amount;
                    }
                    else{
                        $Discount = $discount_amount;
                    }

                    $PromocodeUsage = new PromocodeUsage;
                    $PromocodeUsage->user_id =$UserRequest->user_id;
                    $PromocodeUsage->promocode_id =$UserRequest->promocode_id;
                    $PromocodeUsage->status ='USED';
                    $PromocodeUsage->save();

                    $Total = $Distance + $Tax + $Surge;
                    $payable_amount = $Distance + $Tax + $Surge - $Discount;

                }                
            }
           
           

            if($Total < 0){
                $Total = 0.00; // prevent from negative value
                $payable_amount = 0.00;
            }



            $ProviderCommission = 0;

            $ProviderPay = ($Total - $Commision)-$Tax;

            $Payment = new UserRequestPayment;
            $Payment->request_id = $UserRequest->id;

            $Payment->user_id=$UserRequest->user_id;
            $Payment->provider_id=$UserRequest->provider_id;
            $Payment->fleet_id=$UserRequest->provider->fleet;

            /*
            * Reported by Jeya, We are adding the surge price with Base price of Service Type.
            */ 
            $Payment->fixed = $Fixed + $Surge;
            $Payment->distance = $Distance_fare;
            $Payment->minute  = $Minute_fare;
            $Payment->hour  = $Hour_fare;
            $Payment->commision = $Commision;
            $Payment->commision_per = $commission_percentage;           
            $Payment->surge = $Surge;
            $Payment->total = $Total;
            $Payment->provider_commission = $ProviderCommission;
            $Payment->provider_pay = $ProviderPay;
            if($UserRequest->promocode_id>0){
                $Payment->promocode_id = $UserRequest->promocode_id;
            }
            $Payment->discount = $Discount;
            $Payment->discount_per = $discount_per;

            if($Discount  == ($Distance + $Tax)){
                $UserRequest->paid = 1;
            }

            if($UserRequest->use_wallet == 1 && $payable_amount > 0){

                $User = User::find($UserRequest->user_id);

                $Wallet = $User->wallet_balance;

                if($Wallet != 0){

                    if($payable_amount > $Wallet) {

                        $Payment->wallet = $Wallet;
                        $Payment->is_partial=1;
                        $Payable = $payable_amount - $Wallet;
                        
                        $Payment->payable = abs($Payable);

                        $wallet_det=$Wallet;                      

                    } else {

                        $Payment->payable = 0;
                        $WalletBalance = $Wallet - $payable_amount;
                        
                        $Payment->wallet = $payable_amount;
                        
                        $Payment->payment_id = 'WALLET';
                        $Payment->payment_mode = $UserRequest->payment_mode;

                        $UserRequest->paid = 1;
                        $UserRequest->status = 'COMPLETED';
                        $UserRequest->save();

                        $wallet_det=$payable_amount;
                       
                    }

                    // charged wallet money push 
                    (new SendPushNotification)->ChargedWalletMoney($UserRequest->user_id,currency($wallet_det));

                    //for create the user wallet transaction
                    $this->userCreditDebit($wallet_det,$UserRequest,0);

                }

            } else {
                $Payment->total = abs($Total);
                $Payment->payable = abs($payable_amount);
                
            }

            $Payment->tax = $Tax;
            $Payment->tax_per = $tax_percentage;
            $Payment->save();  

            if($Payment)
            { 

                $Payment_pdf = UserRequestPayment::with('request.provider','request.provider_service','request.service_type')->findOrFail($Payment->id);

                $pdf = \PDF::loadView('invoice_pdf', compact('Payment_pdf'));
               
                $booking_id =$Payment_pdf->request->booking_id.'.pdf';
                $pdf->save('storage/invoice/invoice'.$booking_id);  
                $Payment_pdf->pdf_link = 'storage/invoice/invoice'.$booking_id;
                $Payment_pdf->save();  
                $Payment = $Payment_pdf;
            }



            return $Payment;

        } catch (ModelNotFoundException $e) {
            return false;
        }
    }



    /**
     * Get the trip history details of the provider
     *
     * @return \Illuminate\Http\Response
     */
    public function history_details(Request $request)
    {
        $this->validate($request, [
                'request_id' => 'required|integer|exists:user_requests,id',
            ]);

        if($request->ajax()) {
            
            $Jobs = UserRequests::where('id',$request->request_id)
                                ->where('provider_id', Auth::user()->id)
                                ->with('payment','service_type','user','rating')
                                ->get();
            if(!empty($Jobs)){
                $map_icon_start = asset('asset/img/marker-start.png');
                $map_icon_end = asset('asset/img/marker-end.png');
                foreach ($Jobs as $key => $value) {
                    $Jobs[$key]->static_map = "https://maps.googleapis.com/maps/api/staticmap?".
                            "autoscale=1".
                            "&size=600x300".
                            "&maptype=terrian".
                            "&format=png".
                            "&visual_refresh=true".
                            "&markers=icon:".$map_icon_start."%7C".$value->s_latitude.",".$value->s_longitude.
                            "&markers=icon:".$map_icon_end."%7C".$value->d_latitude.",".$value->d_longitude.
                            "&path=color:0x000000|weight:3|enc:".$value->route_key.
                            "&key=".Setting::get('map_key');
                }
            }

            return $Jobs[0];
        }

    }

    /**
     * Show the application dashboard.
     *
     * @return \Illuminate\Http\Response
     */

    public function upcoming_trips() {
    
        try{
            $UserRequests = UserRequests::ProviderUpcomingRequest(Auth::user()->id)->get();
            if(!empty($UserRequests)){
                $map_icon = asset('asset/marker.png');
                foreach ($UserRequests as $key => $value) {
                    $UserRequests[$key]->static_map = "https://maps.googleapis.com/maps/api/staticmap?".
                                    "autoscale=1".
                                    "&size=320x130".
                                    "&maptype=terrian".
                                    "&format=png".
                                    "&visual_refresh=true".
                                    "&markers=icon:".$map_icon."%7C".$value->s_latitude.",".$value->s_longitude.
                                    "&markers=icon:".$map_icon."%7C".$value->d_latitude.",".$value->d_longitude.
                                    "&path=color:0x000000|weight:3|enc:".$value->route_key.
                                    "&key=".Setting::get('map_key');
                }
            }
            return $UserRequests;
        }

        catch (Exception $e) {
            return response()->json(['error' => trans('api.something_went_wrong')]);
        }
    }

    /**
     * Get the trip history details of the provider
     *
     * @return \Illuminate\Http\Response
     */
    public function upcoming_details(Request $request)
    {
        $this->validate($request, [
                'request_id' => 'required|integer|exists:user_requests,id',
            ]);

        if($request->ajax()) {
            
            $Jobs = UserRequests::where('id',$request->request_id)
                                ->where('provider_id', Auth::user()->id)
                                ->with('service_type','user','payment')
                                ->get();
            if(!empty($Jobs)){
                $map_icon_start = asset('asset/img/marker-start.png');
                $map_icon_end = asset('asset/img/marker-end.png');
                foreach ($Jobs as $key => $value) {
                    $Jobs[$key]->static_map = "https://maps.googleapis.com/maps/api/staticmap?".
                            "autoscale=1".
                            "&size=600x300".
                            "&maptype=terrian".
                            "&format=png".
                            "&visual_refresh=true".
                            "&markers=icon:".$map_icon_start."%7C".$value->s_latitude.",".$value->s_longitude.
                            "&markers=icon:".$map_icon_end."%7C".$value->d_latitude.",".$value->d_longitude.
                            "&path=color:0x000000|weight:3|enc:".$value->route_key.
                            "&key=".Setting::get('map_key');
                }
            }

            return $Jobs[0];
        }

    }

    /**
     * Get the trip history details of the provider
     *
     * @return \Illuminate\Http\Response
     */
    public function summary(Request $request)
    {
        try{
            if($request->ajax()) {
                
                $rides = UserRequests::where('provider_id', Auth::user()->id)->count();

                /*$revenue_total = UserRequestPayment::whereHas('request', function($query) use ($request) {
                                $query->where('provider_id', Auth::user()->id);
                            })
                        ->sum('total');
                 $revenue_commission = UserRequestPayment::whereHas('request', function($query) use ($request) {
                                $query->where('provider_id', Auth::user()->id);
                            })
                        ->sum('provider_commission');  

                 $revenue =  $revenue_total - $revenue_commission;*/

                $revenue = UserRequestPayment::where('provider_id', Auth::user()->id)->sum('provider_pay');

                $cancel_rides = UserRequests::where('status','CANCELLED')->where('provider_id', Auth::user()->id)->count();
                $scheduled_rides = UserRequests::where('status','SCHEDULED')->where('provider_id', Auth::user()->id)->count();

                return response()->json([
                    'rides' => $rides, 
                    'revenue' => $revenue,
                    'cancel_rides' => $cancel_rides,
                    'scheduled_rides' => $scheduled_rides,
                ]);
            }

        } catch (Exception $e) {
            return response()->json(['error' => trans('api.something_went_wrong')]);
        }

    }


    /**
     * help Details.
     *
     * @return \Illuminate\Http\Response
     */

    public function help_details(Request $request){

        try{

            if($request->ajax()) {
                return response()->json([
                    'contact_number' => Setting::get('contact_number',''), 
                    'contact_email' => Setting::get('contact_email','')
                     ]);
            }

        }catch (Exception $e) {
            if($request->ajax()) {
                return response()->json(['error' => trans('api.something_went_wrong')]);
            }
        }
    }


    /*
        check the payment status is completed or not
        if its completed check the below logics
        Check the request table if user have any commission
        check the request table if provider have any fleet
        check the user, applied any discount
        check the payment mode is cash, card, wallet, partial
        check whether provider have any negative balance 
    */ 
    public function callTransaction($request_id){  

        $UserRequest = UserRequests::with('provider')->with('payment')->findOrFail($request_id);

        if($UserRequest->paid==1){

            if(Setting::get('send_email', 0) == 1) {
                Helper::site_sendmail($UserRequest);
            }    

            $paymentsRequest = UserRequestPayment::where('request_id',$request_id)->first();

            $provider = Provider::where('id',$paymentsRequest->provider_id)->first();

            $fleet_amount=$discount=$admin_commision=$credit_amount=$balance_provider_credit=$provider_credit=0;                

            if($paymentsRequest->is_partial==1){
                //partial payment
                if($paymentsRequest->payment_mode=="CASH"){
                    $credit_amount=$paymentsRequest->wallet + $paymentsRequest->tips;
                }elseif($paymentsRequest->payment_mode=="Korttimaksu"){
                    $credit_amount=$paymentsRequest->wallet + $paymentsRequest->tips;
                }elseif($paymentsRequest->payment_mode=="Lasku"){
                    $credit_amount=$paymentsRequest->wallet + $paymentsRequest->tips;
                }elseif($paymentsRequest->payment_mode=="MobilePay"){
                    $credit_amount=$paymentsRequest->wallet + $paymentsRequest->tips;
                }
                else{
                    $credit_amount=$paymentsRequest->total + $paymentsRequest->tips;
                }
            }
            else{
		\Log::info($paymentsRequest->payment_id);
		\Log::info($paymentsRequest->payment_mode);
                if($paymentsRequest->payment_mode=="CARD" || $paymentsRequest->payment_id=="WALLET"){
                    $credit_amount=$paymentsRequest->total + $paymentsRequest->tips;
		    \Log::info("call Transcation wallet: ".$credit_amount." request_id ".$request_id);
                }
                else{
		    \Log::info("Else call Transcation".$request_id);
                    $credit_amount=0;
                }    
            }                
            

            //admin,fleet,provider calculations
            if(!empty($paymentsRequest->commision_per)){

                $admin_commision=$paymentsRequest->commision;

                if(!empty($paymentsRequest->fleet_id)){
                    //get the percentage of fleet owners
                    $fleet = Fleet::where('id',$paymentsRequest->fleet_id)->first();
                    $fleet_per=$fleet->commission;
                    $fleet_amount=($admin_commision) * ( $fleet_per/100 );
                    $admin_commision=$admin_commision;

                }
                
                //check the user applied discount
                if(!empty($paymentsRequest->discount)){
                    $balance_provider_credit=$paymentsRequest->discount;
                }  

            }
            else{

                if(!empty($paymentsRequest->fleet_id)){
                    $fleet_per=(int)Setting::get('fleet_commission_percentage');
                    $fleet_amount=($paymentsRequest->total) * ( $fleet_per/100 );
                    $admin_commision=$fleet_amount;
                }
                if(!empty($paymentsRequest->discount)){
                    $balance_provider_credit=$paymentsRequest->discount;
                }    
            }                

            if(!empty($admin_commision)){
                //add the commission amount to admin wallet and debit amount to provider wallet, update the provider wallet amount to provider table
               $this->adminCommission($admin_commision,$paymentsRequest,$UserRequest);
            }

            if(!empty($paymentsRequest->fleet_id) && !empty($fleet_amount)){
                $paymentsRequest->fleet=$fleet_amount;
                $paymentsRequest->fleet_per=$fleet_per;
                $paymentsRequest->save();
                //create the amount to fleet account and deduct the amount to admin wallet, update the fleet wallet amount to fleet table
                $this->fleetCommission($fleet_amount,$paymentsRequest,$UserRequest);                        
            }
            if(!empty($balance_provider_credit)){
                //debit the amount to admin wallet and add the amount to provider wallet, update the provider wallet amount to provider table
                $this->providerDiscountCredit($balance_provider_credit,$paymentsRequest,$UserRequest);
            }

            if(!empty($paymentsRequest->tax)){
                //debit the amount to admin wallet and add the amount to provider wallet, update the provider wallet amount to provider table
                $this->taxCredit($paymentsRequest->tax,$paymentsRequest,$UserRequest);
            }  
            
            if($credit_amount>0){               
                //provider ride amount
                //check whether provider have any negative wallet balance if its deduct the amount from its credit.
                //if its negative wallet balance grater of its credit amount then deduct credit-wallet balance and update the negative amount to admin wallet
                if($provider->wallet_balance>0){
                    $admin_amount=$credit_amount-$admin_commision-$paymentsRequest->tax;
                }
                else{
                    $admin_amount=$credit_amount-$admin_commision+($provider->wallet_balance)-$paymentsRequest->tax;
                }

                $this->providerRideCredit($credit_amount,$admin_amount,$paymentsRequest,$UserRequest);
            }
            
            return true;
        }
        else{
            return true;
        }
        
    }
    
    public function createAdminWallet($request){

        $admin_data=AdminWallet::orderBy('id', 'DESC')->first();

        $adminwallet=new AdminWallet;
        $adminwallet->transaction_id=$request['transaction_id'];        
        $adminwallet->transaction_alias=$request['transaction_alias'];
        $adminwallet->transaction_desc=$request['transaction_desc'];
        $adminwallet->transaction_type=$request['transaction_type'];
        $adminwallet->type=$request['type'];
        $adminwallet->amount=$request['amount'];

        if(empty($admin_data->close_balance))
            $adminwallet->open_balance=0;
        else
            $adminwallet->open_balance=$admin_data->close_balance;

        if(empty($admin_data->close_balance))
            $adminwallet->close_balance=$request['amount'];
        else            
            $adminwallet->close_balance=$admin_data->close_balance+($request['amount']);        

        $adminwallet->save();

        return true;
    }

    protected function createUserWallet($request){
        
        $user=User::findOrFail($request['id']);

        $userWallet=new UserWallet;
        $userWallet->user_id=$request['id']; 
        $userWallet->transaction_id=$request['transaction_id'];        
        $userWallet->transaction_alias=$request['transaction_alias'];
        $userWallet->transaction_desc=$request['transaction_desc'];
        $userWallet->type=$request['type'];
        $userWallet->amount=$request['amount'];        

        if(empty($user->wallet_balance))
            $userWallet->open_balance=0;
        else
            $userWallet->open_balance=$user->wallet_balance;

        if(empty($user->wallet_balance))
            $userWallet->close_balance=$request['amount'];
        else            
            $userWallet->close_balance=$user->wallet_balance+($request['amount']);

        $userWallet->save();

        //update the user wallet amount to user table        
        $user->wallet_balance=$user->wallet_balance+($request['amount']);
        $user->save();

        return true;
    }

    public function createProviderWallet($request){
        
        $provider=Provider::findOrFail($request['id']);

        $providerWallet=new ProviderWallet;        
        $providerWallet->provider_id=$request['id'];        
        $providerWallet->transaction_id=$request['transaction_id'];        
        $providerWallet->transaction_alias=$request['transaction_alias'];
        $providerWallet->transaction_desc=$request['transaction_desc'];
        $providerWallet->type=$request['type'];
        $providerWallet->amount=$request['amount'];

        if(empty($provider->wallet_balance))
            $providerWallet->open_balance=0;
        else
            $providerWallet->open_balance=$provider->wallet_balance;

        if(empty($provider->wallet_balance))
            $providerWallet->close_balance=$request['amount'];
        else            
            $providerWallet->close_balance=$provider->wallet_balance+($request['amount']);        

        $providerWallet->save();

        //update the provider wallet amount to provider table        
        $provider->wallet_balance=$provider->wallet_balance+($request['amount']);
        $provider->save();

        return true;

    }

    protected function createFleetWallet($request){

        $fleet=Fleet::findOrFail($request['id']);

        $fleetWallet=new FleetWallet;
        $fleetWallet->fleet_id=$request['id'];
        $fleetWallet->transaction_id=$request['transaction_id'];        
        $fleetWallet->transaction_alias=$request['transaction_alias'];
        $fleetWallet->transaction_desc=$request['transaction_desc'];
        $fleetWallet->type=$request['type'];
        $fleetWallet->amount=$request['amount'];        

        if(empty($fleet->wallet_balance))
            $fleetWallet->open_balance=0;
        else
            $fleetWallet->open_balance=$fleet->wallet_balance;

        if(empty($fleet->wallet_balance))
            $fleetWallet->close_balance=$request['amount'];
        else            
            $fleetWallet->close_balance=$fleet->wallet_balance+($request['amount']);       

        $fleetWallet->save();

        //update the fleet wallet amount to fleet table        
        $fleet->wallet_balance=$fleet->wallet_balance+($request['amount']);
        $fleet->save();

        return true;
    }

    protected function adminCommission($amount,$paymentsRequest,$UserRequest){
        $ipdata=array();    
        $ipdata['transaction_id']=$UserRequest->id;
        $ipdata['transaction_alias']=$UserRequest->booking_id;                        
        $ipdata['transaction_desc']=trans('api.transaction.admin_commission');
        $ipdata['transaction_type']=1;
        $ipdata['type']='C';
        $ipdata['amount']=$amount;
        $this->createAdminWallet($ipdata);

        $provider_det_amt= -1 * abs($amount);
        $ipdata=array();
        $ipdata['transaction_id']=$UserRequest->id;
        $ipdata['transaction_alias']=$UserRequest->booking_id;                        
        $ipdata['transaction_desc']=trans('api.transaction.admin_commission');
        $ipdata['id']=$paymentsRequest->provider_id;
        $ipdata['type']='D';
        $ipdata['amount']=$provider_det_amt;
        $this->createProviderWallet($ipdata);
    }

    protected function fleetCommission($amount,$paymentsRequest,$UserRequest){

        $ipdata=array();
        $admin_det_amt= -1 * abs($amount);     
        $ipdata['transaction_id']=$UserRequest->id;
        $ipdata['transaction_alias']=$UserRequest->booking_id;                        
        $ipdata['transaction_desc']=trans('api.transaction.fleet_debit');
        $ipdata['transaction_type']=7;
        $ipdata['type']='D';
        $ipdata['amount']=$admin_det_amt;
        $this->createAdminWallet($ipdata);

        $ipdata=array();        
        $ipdata['transaction_id']=$UserRequest->id;
        $ipdata['transaction_alias']=$UserRequest->booking_id;                        
        $ipdata['transaction_desc']=trans('api.transaction.fleet_add');
        $ipdata['id']=$paymentsRequest->fleet_id;        
        $ipdata['type']='C';
        $ipdata['amount']=$amount;
        $this->createFleetWallet($ipdata);

        $ipdata=array();        
        $ipdata['transaction_id']=$UserRequest->id;
        $ipdata['transaction_alias']=$UserRequest->booking_id;                        
        $ipdata['transaction_desc']=trans('api.transaction.fleet_recharge');
        $ipdata['transaction_type']=6;
        $ipdata['type']='C';
        $ipdata['amount']=$amount;
        $this->createAdminWallet($ipdata);       

        return true;
    }

    protected function providerDiscountCredit($amount,$paymentsRequest,$UserRequest){
        $ipdata=array();
        $ad_det_amt= -1 * abs($amount);
        $ipdata['transaction_id']=$UserRequest->id;
        $ipdata['transaction_alias']=$UserRequest->booking_id;                        
        $ipdata['transaction_desc']=trans('api.transaction.discount_apply');
        $ipdata['transaction_type']=10;       
        $ipdata['type']='D';
        $ipdata['amount']=$ad_det_amt;
        $this->createAdminWallet($ipdata);

        $ipdata=array();
        $ipdata['transaction_id']=$UserRequest->id;
        $ipdata['transaction_alias']=$UserRequest->booking_id;                        
        $ipdata['transaction_desc']=trans('api.transaction.discount_refund');
        $ipdata['id']=$paymentsRequest->provider_id;
        $ipdata['type']='C';
        $ipdata['amount']=$amount;
        $this->createProviderWallet($ipdata);

        $ipdata=array();        
        $ipdata['transaction_id']=$UserRequest->id;
        $ipdata['transaction_alias']=$UserRequest->booking_id;                        
        $ipdata['transaction_desc']=trans('api.transaction.discount_recharge');
        $ipdata['transaction_type']=11;
        $ipdata['type']='C';
        $ipdata['amount']=$amount;
        $this->createAdminWallet($ipdata);

        return true;
    }

    protected function taxCredit($amount,$paymentsRequest,$UserRequest){        

        $ipdata=array();
        $ad_det_amt= -1 * abs($amount);
        $ipdata['transaction_id']=$UserRequest->id;
        $ipdata['transaction_alias']=$UserRequest->booking_id;                        
        $ipdata['transaction_desc']=trans('api.transaction.tax_credit');
        $ipdata['id']=$paymentsRequest->provider_id;
        $ipdata['type']='D';
        $ipdata['amount']=$ad_det_amt;
        $this->createProviderWallet($ipdata);

        $ipdata=array();        
        $ipdata['transaction_id']=$UserRequest->id;
        $ipdata['transaction_alias']=$UserRequest->booking_id;                        
        $ipdata['transaction_desc']=trans('api.transaction.tax_debit');
        $ipdata['transaction_type']=9;
        $ipdata['type']='C';
        $ipdata['amount']=$amount;
        $this->createAdminWallet($ipdata);

        return true;
    }    

    protected function providerRideCredit($amount,$admin_amount,$paymentsRequest,$UserRequest){

        $ipdata=array();
        $ipdata['transaction_id']=$UserRequest->id;
        $ipdata['transaction_alias']=$UserRequest->booking_id;  
        $ipdata['transaction_desc']=trans('api.transaction.provider_credit');        
        $ipdata['id']=$paymentsRequest->provider_id;
        $ipdata['type']='C';
        $ipdata['amount']=$amount;
        $this->createProviderWallet($ipdata);

        if($admin_amount>0){
            $ipdata=array();
            $ipdata['transaction_id']=$UserRequest->id;
            $ipdata['transaction_alias']=$UserRequest->booking_id;                        
            $ipdata['transaction_desc']=trans('api.transaction.provider_recharge');
            $ipdata['transaction_type']=4;                     
            $ipdata['type']='C';
            $ipdata['amount']=$admin_amount;
            $this->createAdminWallet($ipdata);
        }    

        return true;
    }

    public function userCreditDebit($amount,$UserRequest,$type=1){

        if($type==1){
            $msg=trans('api.transaction.user_recharge');           
            $ttype='C';
            $user_data=UserWallet::orderBy('id', 'DESC')->first();
            if(!empty($user_data))
            $transaction_id=$user_data->id+1;
            else
               $transaction_id=1;

            $transaction_alias='URC'.str_pad($transaction_id, 6, 0, STR_PAD_LEFT);

            $user_id=$UserRequest;
            $transaction_type=2;
        }
        else{
            $msg=trans('api.transaction.user_trip');            
            $ttype='D';
            $amount= -1 * abs($amount);
            $transaction_id=$UserRequest->id;
            $transaction_alias=$UserRequest->booking_id;
            $user_id=$UserRequest->user_id;
            $transaction_type=3;
        }           
        
        $ipdata=array();
        $ipdata['transaction_id']=$transaction_id;
        $ipdata['transaction_alias']=$transaction_alias;
        $ipdata['transaction_desc']=$msg;
        $ipdata['id']=$user_id;        
        $ipdata['type']=$ttype;
        $ipdata['amount']=$amount;
        $this->createUserWallet($ipdata);
        
        $ipdata=array();
        $ipdata['transaction_id']=$transaction_id;
        $ipdata['transaction_alias']=$transaction_alias;
        $ipdata['transaction_desc']=$msg;
        $ipdata['transaction_type']=$transaction_type;        
        $ipdata['type']=$ttype;
        $ipdata['amount']=$amount;
        $this->createAdminWallet($ipdata);          

        return true;
    }

    public function wallet_transation(Request $request){        
        try{

           $start_node= $request->start_node;
           $limit= $request->limit;
            
           $wallet_transation = ProviderWallet::where('provider_id',Auth::user()->id);
            if(!empty($limit)){
                $wallet_transation =$wallet_transation->offset($start_node);
                $wallet_transation =$wallet_transation->limit($limit);
            }
            $wallet_transation =$wallet_transation->orderBy('id','desc')->get();

            return response()->json(['wallet_transation' => $wallet_transation,'wallet_balance'=>Auth::user()->wallet_balance]);

        }catch(Exception $e){
            return response()->json(['error' => trans('api.something_went_wrong')]);
        }
        
    }

    public function requestamount(Request $request){

        $premat=WalletRequests::where('from_id',Auth::user()->id)->where('request_from',$request->type)->where('status',0)->sum('amount');

        $available=Auth::user()->wallet_balance-$premat;

        $messsages = array(
            'amount.max'=>trans('api.amount_max').Setting::get('currency','$').$available,
        );
        $this->validate($request, [
                'amount' => 'required|numeric|min:1|max:'.$available,               
            ],$messsages);        
        try{

            $nextid=(new Helper)->generate_request_id($request->type);            
            $amountRequest=new WalletRequests;
            $amountRequest->alias_id=$nextid;
            $amountRequest->request_from=$request->type;          
            $amountRequest->from_id=Auth::user()->id;
            $amountRequest->type='D';
            if(Setting::get('CARD', 0) == 1)
                $amountRequest->send_by='online';
            else
                $amountRequest->send_by='offline';
            $amountRequest->amount=$request->amount;
            $amountRequest->save();
            $fn_response["success"]=trans('api.amount_success');

        }catch(\Illuminate\Database\QueryException $e){
            $fn_response["error"]=$e->getMessage();
             
        }catch(Exception $e){            
            $fn_response["error"]=$e->getMessage();
        }
        
        return response()->json($fn_response);
    }

    public function requestcancel(Request $request){
        
        $this->validate($request, [
                'id' => 'required|numeric',               
            ]);        
        try{
            
            $amountRequest=WalletRequests::find($request->id);
            $amountRequest->status=2;           
            $amountRequest->save();
            $fn_response["success"]=trans('api.amount_cancel');

        }catch(\Illuminate\Database\QueryException $e){
            $fn_response["error"]=$e->getMessage();
             
        }catch(Exception $e){            
            $fn_response["error"]=$e->getMessage();
        }
        
        return response()->json($fn_response);
    }

    public function transferlist(Request $request){

       $start_node= $request->start_node;
       $limit= $request->limit;
        
        $pendinglist = WalletRequests::where('from_id',Auth::user()->id)->where('request_from','provider')->where('status',0);
        if(!empty($limit)){
            $pendinglist =$pendinglist->offset($start_node);
            $pendinglist =$pendinglist->limit($limit);
        }
        $pendinglist =$pendinglist->orderBy('id','desc')->get();

        return response()->json(['pendinglist' => $pendinglist,'wallet_balance'=>Auth::user()->wallet_balance]);
    }

    

    public function settlements($id){

        $request_data = WalletRequests::where('id',$id)->first();

        if($request_data->type=='D'){
            $settle_amt=-1 * $request_data->amount;
            $admin_amt=-1 * abs($request_data->amount);
            $settle_msg='settlement debit';
            $ad_msg='settlement debit';
            $settle_type=$request_data->type;
            $ad_type=$request_data->type;
        }
        else{
            $settle_amt=$request_data->amount;
            $admin_amt=$request_data->amount;
            $settle_msg='settlement credit';
            $ad_msg='settlement credit';
            $settle_type=$request_data->type;
            $ad_type=$request_data->type;
        }

        if($request_data->request_from=='provider'){
            $ipdata=array();
            $ipdata['transaction_id']=$request_data->id;
            $ipdata['transaction_alias']=$request_data->alias_id;
            $ipdata['transaction_desc']=$settle_msg;
            $ipdata['id']=$request_data->from_id;        
            $ipdata['type']=$settle_type;
            $ipdata['amount']=$settle_amt;
            $this->createProviderWallet($ipdata);
            $transaction_type=5;
        }
        else{
            $ipdata=array();        
            $ipdata['transaction_id']=$request_data->id;
            $ipdata['transaction_alias']=$request_data->alias_id;                        
            $ipdata['transaction_desc']=$settle_msg;
            $ipdata['id']=$request_data->from_id;        
            $ipdata['type']=$settle_type;
            $ipdata['amount']=$settle_amt;
            $this->createFleetWallet($ipdata);
            $transaction_type=8;
        }
        
        $ipdata=array();
        $ipdata['transaction_id']=$request_data->id;
        $ipdata['transaction_alias']=$request_data->alias_id;
        $ipdata['transaction_desc']=$ad_msg;
        $ipdata['transaction_type']=$transaction_type;        
        $ipdata['type']=$ad_type;
        $ipdata['amount']=$admin_amt;
        $this->createAdminWallet($ipdata);          

        $request_data->status=1;
        $request_data->save();

        return true;
    }
    


    public function wallet_passbook(Request $request)
    {
        try{

            $start_node= $request->start_node;
            $limit= $request->limit;
            
            $wallet_transation = ProviderWallet::where('provider_id',Auth::user()->id);
            if(!empty($limit)){
                $wallet_transation =$wallet_transation->offset($start_node);
                $wallet_transation =$wallet_transation->limit($limit);
            }

            $wallet_transation =$wallet_transation->orderBy('id','desc')->get();

            return response()->json(['wallet_transation' => $wallet_transation,'wallet_balance'=>Auth::user()->wallet_balance]);

        } catch (Exception $e) {
             return response()->json(['error' => trans('api.something_went_wrong')], 500);
        }
    }

    public function active_check(Request $request)
    {
        try{

            $document = Document::where('doc_required','=','Yes')->pluck('id');
            $provider_document = ProviderDocument::where('provider_id',Auth::user()->id)->whereIn('document_id', $document)->where('expires_at','<',date('Y-m-d'))->count();  

            if(($provider_document > 0)){

                $document_status="disable";

            }else{

                $document_status="active";
                    
            }
       

            $wallet_limit = Setting::get('wallet_limit');
            $Provider = Auth::user();
            $provider_wallet=$Provider->wallet_balance;


            if(($wallet_limit > $provider_wallet)){

                $wallet_status="disable";

            }else{

                $wallet_status="active";
                    
            }


           

            $duration2='+7 day';
            $diffdatedt2 = date('Y-m-d', strtotime($duration2));

            $sevendays_status = ProviderDocument::where('provider_id',Auth::user()->id)->whereIn('document_id', $document)->where('expires_at','<',$diffdatedt2)->where('expires_at','>',date('Y-m-d'))->count();  

            if(($sevendays_status > 0)){

                $sevendays="disable";
            }else{
              
                $sevendays="active";
 
                    
            }

            $Provider = Auth::user();
            $provider = $Provider->status;


            if(($provider == "approved")){

                $provider_status="true";    

            }else if(($provider == "document")){

                $provider_status="true";    

            }else if(($provider == "banned")){

                $provider_status="true";    

            }
            else{

                $provider_status="false"; 
               
            }


            $document_count = Document::count();
            $document_dt = Document::pluck('id');

            $provider_document_count = ProviderDocument::where('provider_id',Auth::user()->id)->whereIn('document_id', $document_dt)->count();  

            if(($document_count == $provider_document_count)){


                $document_check="true";

            }else{

                $document_check="false";
                    
            }


            $Provider_banned = Auth::user();
            $Provider_banned = $Provider_banned->status;


            if(($Provider_banned == "banned")){

                $provider_disable_status="disable";    

            }else{

                $provider_disable_status="enable"; 
               
            }



        
            
            return response()->json(['document_status' => $document_status,'wallet_status' => $wallet_status,'sevendays' => $sevendays,'provider_status' => $provider_status,'document_check' => $document_check,'provider_enable_status' => $provider_disable_status]);

        } catch (Exception $e) {
             return response()->json(['error' => trans('api.something_went_wrong')], 500);
        }
    }

}
