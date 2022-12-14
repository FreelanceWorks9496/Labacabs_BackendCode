<?php 

namespace App\Services;

use Illuminate\Http\Request;
use Validator;
use Exception;
use DateTime;
use Auth;
use Lang;
use Setting;
use App\ServiceType;
use App\Promocode;
use App\Provider;
use App\ProviderService;
use App\Helpers\Helper;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use DB;
use Carbon\Carbon;


class ServiceTypes{

    public function __construct(){}

    /**
        * Get a validator for a tradepost.
        *
        * @param  array $data
        * @return \Illuminate\Contracts\Validation\Validator
    */
    protected function validator(array $data) {
        $rules = [
            'location'  => 'required',
        ];

        $messages = [
            'location.required' => 'Location Required!',
        ];

        return Validator::make($data,$rules,$messages);
    }

   
    /**
    * get the btc details.        
    * get the currency master data.        
    * get the payment methods master data.
    * @return response with data,system related errors
    */
    public function show() {
        
     
    }    

    /**
        * get all details.
        * @return response with data,system related errors
    */
    public function getAll() {
   
        
    }    

    /**
        * find tradepost.        
        * @param  $id
        * @return response with data,system related errors
    */

    public function find($id) {  
       
    }    
    
    /**
        * insert function
        * checking form field validations
        * @param  $postrequest
        * @return response with success,errors,system related errors
    */ 
    public function create($request) {
        
    } 

    /**
        * update function
        * checking form validations
        * @param  $postrequest
        * @return response with success,errors,system related errors
    */    
    public function update($request,$id) {
   

    } 

    /**
        * delete function.        
        * @param  $id
        * @return response with success,errors,system related errors
    */
    public function delete($id) {
           
    }

    public function calculateFare($request, $cflag=0){
        try{

            $total=$tax_price='';
            $location=$this->getLocationDistance($request);
        
            if(!empty($location['errors'])){
                throw new Exception($location['errors']);
            }
            else{

                if(Setting::get('distance','Kms')=='Kms')
                    $total_kilometer = round($location['meter']/1000,1); //TKM
                else
                    $total_kilometer = round($location['meter']/1609.344,1); //TMi
                
         

                $requestarr['meter']=$total_kilometer;
                $requestarr['time']=$location['time'];
                $requestarr['seconds']=$location['seconds'];
                $requestarr['kilometer']=0;
                $requestarr['minutes']=0;
                $requestarr['service_type']=$request['service_type'];

                $tax_percentage = Setting::get('tax_percentage');
                $commission_percentage = Setting::get('commission_percentage');
                $surge_trigger = Setting::get('surge_trigger');
                
                $price_response=$this->applyPriceLogic($requestarr);

                if($tax_percentage>0){
                    $tax_price = $this->applyPercentage($price_response['price'],$tax_percentage);
                    $total = $price_response['price'] + $tax_price;
                }
                else{
                    $total = $price_response['price'];
                }
                if($cflag!=0){
                    if($commission_percentage>0){
                        $commission_price = $this->applyPercentage($price_response['price'],$commission_percentage);
                        $commission_price = $price_response['price'] + $commission_price;
                    }
                   
                    $surge = 0;                

                    if($surge_trigger>0){

                        $ActiveProviders = ProviderService::AvailableServiceProvider($request['service_type'])->get()->pluck('provider_id');

                        $distance = Setting::get('provider_search_radius', '10');
                        $latitude = $request['s_latitude'];
                        $longitude = $request['s_longitude'];

                        $Providers = Provider::whereIn('id', $ActiveProviders)
                            ->where('status', 'approved')
                            ->whereRaw("(1.609344 * 3956 * acos( cos( radians('$latitude') ) * cos( radians(latitude) ) * cos( radians(longitude) - radians('$longitude') ) + sin( radians('$latitude') ) * sin( radians(latitude) ) ) ) <= $distance")
                            ->get();

                        $surge = 0;

                        if($Providers->count() <= Setting::get('surge_trigger') && $Providers->count() > 0){
                            $surge_price = $this->applyPercentage($total,Setting::get('surge_percentage'));
                            $total += $surge_price;
                            $surge = 1;
                        }

                    }    

                    $surge_percentage = 1+(Setting::get('surge_percentage')/100)."X";
                }    
                
                $return_data['estimated_fare']=$this->applyNumberFormat(floatval($total)); 
                $return_data['distance']=$total_kilometer;    
                $return_data['time']=$location['time'];
                $return_data['tax_price']=$this->applyNumberFormat(floatval($tax_price));    
                $return_data['base_price']=$this->applyNumberFormat(floatval($price_response['base_price']));    
                $return_data['service_type']=(int)$request['service_type'];   
                $return_data['commission_percentage']=$commission_percentage;   

                if(Auth::user()){
                    $return_data['surge']=$surge;    
                    $return_data['surge_value']=$surge_percentage;   
                    $return_data['wallet_balance']=$this->applyNumberFormat(floatval(Auth::user()->wallet_balance));  
                }

                $service_response["data"]=$return_data;                      
            }

        } catch(Exception $e) {
            $service_response["errors"]=$e->getMessage();
        }
    
        return $service_response;    
    } 

    public function applyPriceLogic($requestarr,$iflag=0){
    
        $fn_response=array();

        $service_type = ServiceType::findOrFail($requestarr['service_type']);

       
        if($iflag==0){
            //for estimated fare
            $total_kilometer = $requestarr['meter']; //TKM || TMi
            $total_minutes = round($requestarr['seconds']/60); //TM        
            $total_hours=($requestarr['seconds']/60)/60; //TH
        }
        else{
 
            $lastrequest    = DB::table('user_requests')->latest()->first();
            $request_id     = $lastrequest->id;
      
            $startdatetime  = DB::table('user_requests')
                                ->where('id','=',$request_id)
                                ->value('started_at');

            $enddatetime   = DB::table('user_requests')
                                ->where('id','=',$request_id)
                                ->value('finished_at');

            $starttime     =  date('H:i', strtotime($startdatetime));
            $endtime       =  date('H:i', strtotime($enddatetime));


                    
            $totalminute   =   $this->convtTimeToMinute($starttime , $endtime);
            $totalhour     =   $this->convertHoursToMinute($totalminute);

             //for invoice fare
            $total_kilometer = $requestarr['kilometer']; //TKM || TMi       
            $total_minutes = $totalminute; //TM        
            $total_hours= $totalhour; //TH
           
        }
        
        //$rental = ceil($requestarr['rental_hours']);
       
        $per_minute=$service_type->minute; //PM
        $per_hour=$service_type->hour; //PH
        $per_kilometer=$service_type->price; //PKM
        $base_distance=$service_type->distance; //BD       
        $base_price=$service_type->fixed; //BP

        if($service_type->calculator == 'MIN') {
            //BP+(TM*PM)
            $price = $base_price+($total_minutes * $per_minute);
          
        } else if($service_type->calculator == 'HOUR') {
            //BP+(TH*PH)
            $price = $base_price+($total_hours * $per_hour);

        } else if($service_type->calculator == 'DISTANCE') {
            //BP+((TKM-BD)*PKM)  
            if($base_distance>$total_kilometer){
                $price = $base_price;
            }else{
                $price = $base_price+(($total_kilometer - $base_distance)*$per_kilometer); 
                        
            }         
        } else if($service_type->calculator == 'DISTANCEMIN') {
            //BP+((TKM-BD)*PKM)+(TM*PM)
            if($base_distance>$total_kilometer){
                $price = $base_price+($total_minutes * $per_minute);
              
            }
            else{
                $price = $base_price+((($total_kilometer - $base_distance)*$per_kilometer)+($total_minutes * $per_minute));
               
            }    
        } else if($service_type->calculator == 'DISTANCEHOUR') {
            //BP+((TKM-BD)*PKM)+(TH*PH)
            if($base_distance>$total_kilometer){
                $price = $base_price+($total_hours * $per_hour);
            }
            else{
                $price = $base_price+((($total_kilometer - $base_distance)*$per_kilometer)+($total_hours * $per_hour));
            }    
        } else {
            //by default set Ditance price BP+((TKM-BD)*PKM) 
            $price = $base_price+(($total_kilometer - $base_distance)*$per_kilometer);

        }

        $fn_response['price']=$price;

        $fn_response['base_price']=$base_price;
        if($base_distance>$total_kilometer){
            $fn_response['distance_fare']=0;
        }
        else{
            $fn_response['distance_fare']=($total_kilometer - $base_distance)*$per_kilometer;
        }    
        $fn_response['minute_fare']=$total_minutes * $per_minute;
        $fn_response['hour_fare']=$total_hours * $per_hour;
        $fn_response['calculator']=$service_type->calculator;
        $fn_response['service_type']=$requestarr['service_type'];

        return $fn_response;
    }


    public function convtTimeToMinute($intime, $outtime)
    {
            $start = Carbon::parse($intime);
            $end = Carbon::parse($outtime);
            $minutes = $end->diffInMinutes($start); // 226
            return $minutes; 
    } 

    public function convertHoursToMinute($minutes)
    {
        return $minutes / 60;
    }

    public function applyPercentage($total,$percentage){
        return ($percentage/100)*$total;
    }

    public function applyNumberFormat($total){
        return round($total,Setting::get('round_decimal'));
    }
    
    public function getLocationDistance($locationarr){
        $fn_response=array('data'=>null,'errors'=>null);
        
        try{

            $apiurl = "https://maps.googleapis.com/maps/api/distancematrix/json?origins=".$locationarr['s_latitude'].",".$locationarr['s_longitude']."&destinations=".$locationarr['d_latitude'].",".$locationarr['d_longitude']."&mode=driving&sensor=false&units=imperial&key=".Setting::get('map_key');

            $client = new Client;
            $location = $client->get($apiurl);           
            $location = json_decode($location->getBody(),true);
           
            if(!empty($location['rows'][0]['elements'][0]['status']) && $location['rows'][0]['elements'][0]['status']=='ZERO_RESULTS'){
                throw new Exception("Out of service area", 1);
                
            }
            $fn_response["meter"]=$location['rows'][0]['elements'][0]['distance']['value'];
            $fn_response["time"]=$location['rows'][0]['elements'][0]['duration']['text'];
            $fn_response["seconds"]=$location['rows'][0]['elements'][0]['duration']['value'];

        }
        catch(Exception $e){
            $fn_response["errors"]=trans('user.maperror');
        }      

        return $fn_response;    
    }
}