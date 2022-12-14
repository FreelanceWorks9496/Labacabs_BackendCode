<?php

namespace App\Http\Controllers\ProviderAuth;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\RegistersUsers;
use Illuminate\Support\Facades\Auth;

use Setting;
use Validator;

use App\Helpers\Helper;
use App\Provider;
use App\ProviderService;
use Illuminate\Http\Request;
use App\Http\Controllers\TwilioController;
use Storage;

class RegisterController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Register Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles the registration of new users as well as their
    | validation and creation. By default this controller uses a trait to
    | provide this functionality without requiring any additional code.
    |
    */

    use RegistersUsers;

    /**
     * Where to redirect users after login / registration.
     *
     * @var string
     */
    protected $redirectTo = '/provider/';

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('provider.guest');
    }

    /**
     * Get a validator for an incoming registration request.
     *
     * @param  array  $data
     * @return \Illuminate\Contracts\Validation\Validator
     */
    protected function validator(array $data)
    {
        return Validator::make($data, [
            'first_name' => 'required|max:255',
            'last_name' => 'required|max:255',
            'phone_number' => 'required',
            'country_code' => 'required',
            'email' => 'required|email|max:255|unique:providers',
            'password' => 'required|min:6|confirmed',
            'service_type' => 'required',
            'service_number' => 'required',
            'service_model' => 'required',
        ]);
    }

    /**
     * Create a new user instance after a valid registration.
     *
     * @param  array  $data
     * @return Provider
     */
    protected function create(array $data)
    {   
        if(!empty($data['gender']))
            $gender=$data['gender'];
        else
            $gender='MALE';

        $Provider = Provider::create([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'gender' => $gender,
            'mobile' => $data['phone_number'],
            'country_code' =>  $data['country_code'],
            'password' => bcrypt($data['password'])            
        ]);

        $provider_service = ProviderService::create([
            'provider_id' => $Provider->id,
            'service_type_id' => $data['service_type'],
            'service_number' => $data['service_number'],
            'service_model' => $data['service_model'],
        ]);

        if(Setting::get('demo_mode', 0) == 1) {
            //$Provider->update(['status' => 'approved']);
            $provider_service->update([
                'status' => 'active',
            ]);
        }

        if(Setting::get('send_email', 0) == 1) {
            // send welcome email here
            Helper::site_registermail($Provider);
        }    
        
        return $Provider;
    }

    /**
     * Show the application registration form.
     *
     * @return \Illuminate\Http\Response
     */
    public function showRegistrationForm()
    {
        return view('provider.auth.register');
    }

    /**
     * Get the guard to be used during registration.
     *
     * @return \Illuminate\Contracts\Auth\StatefulGuard
     */
    protected function guard()
    {
        return Auth::guard('provider');
    }

    public function OTP(Request $request)
    {   
        
   
        $messages = [                    
                    'mobile.unique' => 'You are already Registered',
                ];
        if($request->has('login_by'))
        {
            $this->validate($request, [
                'mobile' => 'required|unique:providers|min:6',
                'login_by' => 'required',
                'accessToken' => 'required'
            ],$messages);  
        }
        else
        {

            $this->validate($request, [
                'mobile' => 'required|unique:providers|min:6'
            ],$messages); 

        } 



        try {

            $data = $request->all();
            if($request->has('login_by')){                
               $social_data =Provider::where('mobile',$data['mobile'])->where('login_by','!=','manual')->first(); 
                //dd($social_data);
                if($social_data){
                    return response()->json([
                    'error' => trans('form.socialuser_exist'),
                ], 422); 
                }
            }

            elseif(Provider::where('mobile',$data['mobile'])->first()){

                return response()->json([
                    'error' => trans('form.mobile_exist'),
                ], 422); 
            }

            $newotp = rand(1000,9999);
            $data['otp'] = $newotp;
            $data['phone'] = $data['mobile'];     
            $data['message'] = 'Your Otp is '.$newotp;     
            $check =Provider::wheremobile($data['phoneonly'])->first();           

            if(count($check)>0) 
            {
                 return response()->json(['error' => 'Mobile Number Already Exist'], 200); 
            }   
            else
            {
               $sms=$this->sms($data['mobile'],$newotp);           
               // (new TwilioController)->sendSms($data);
                return response()->json([
                    'message' => 'OTP Sent',
                    'otp' => $newotp
                ]);
       
            }
        } catch (Exception $e) {
            return response()->json(['error' => trans('form.whoops')], 500);
        }
    }
   public function sms($mobile,$otp){

          $curl = curl_init();
          curl_setopt_array($curl, array(
          CURLOPT_URL => "https://www.fast2sms.com/dev/bulkV2?authorization=yxkIUFZfpmNWYsQ76TPbDXO0oHv1Ad5Mize9uSqnVEGCcLw3hg6WMTgSqr1BN4X05mhluOA2eDpaYZR3&route=v3&sender_id=TXTIND&message=".$otp."&language=english&flash=0&numbers=".$mobile."",
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_ENCODING => "",
          CURLOPT_MAXREDIRS => 10,
          CURLOPT_TIMEOUT => 30,
          CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
          CURLOPT_CUSTOMREQUEST => "GET",
          CURLOPT_POSTFIELDS => "",
          CURLOPT_HTTPHEADER => array(
            "authorization: Basic NzY4NjgwMDgwMDpMQUJBVEVBTTY0NDNzJA==",
            "cache-control: no-cache",
            "content-type: multipart/form-data; boundary=----WebKitFormBoundary7MA4YWxkTrZu0gW",
            "postman-token: 48745995-8d8c-22cd-5fbf-519a724ffd80"
          ),
        ));

        $response = curl_exec($curl);
        $err = curl_error($curl);

        curl_close($curl);
        $res =  json_decode($response,TRUE);
        
        return $res;
    }

}
