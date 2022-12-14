<?php

namespace App\Http\Controllers\ProviderResources;

use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;

use App\Http\Controllers\Controller;

use Auth;
use Setting;
use Storage;
use Exception;
use Carbon\Carbon;

use App\Provider;

use App\ProviderProfile;
use App\UserRequests;
use App\ProviderService;
use App\Fleet;
use App\RequestFilter;
use App\Document;
use App\Http\Controllers\SendPushNotification;
use App\Http\Controllers\ProviderResources\DocumentController;

class ProfileController extends Controller
{
    /**
     * Create a new user instance.
     *
     * @return void
     */

    public function __construct()
    {
        $this->middleware('provider.api', ['except' => ['show', 'store', 'available', 'location_edit', 'location_update','stripe']]);
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        try {

            Auth::user()->service = ProviderService::where('provider_id',Auth::user()->id)
                                            ->with('service_type')
                                            ->first();
            Auth::user()->fleet = Fleet::find(Auth::user()->fleet);
            Auth::user()->currency = Setting::get('currency', '$');
            Auth::user()->sos = Setting::get('sos_number', '911');
            Auth::user()->measurement = Setting::get('distance', 'Kms');
            Auth::user()->profile = ProviderProfile::where('provider_id',Auth::user()->id)
                                            ->first();
            Auth::user()->cash =(int)Setting::get('CASH', 1);
            Auth::user()->card =(int)Setting::get('CARD', 0);
            Auth::user()->stripe_secret_key = Setting::get('stripe_secret_key', '');
            Auth::user()->stripe_publishable_key = Setting::get('stripe_publishable_key', '');
            return Auth::user();

        } catch (Exception $e) {
            return $e->getMessage();
        }
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $this->validate($request, [
                'first_name' => 'required|max:255',
                'last_name' => 'required|max:255',
                'mobile' => 'required',
                'avatar' => 'mimes:jpeg,bmp,png',
                'language' => 'max:255',
                'address' => 'max:255',
                'address_secondary' => 'max:255',
                'city' => 'max:255',
                'country' => 'max:255',
                'postal_code' => 'max:255',
            ]);

        try {

            $Provider = Auth::user();

            if($request->has('first_name')) 
                $Provider->first_name = $request->first_name;

            if($request->has('last_name')) 
                $Provider->last_name = $request->last_name;

            if ($request->has('mobile'))
                $Provider->mobile = $request->mobile;

            if ($request->hasFile('avatar')) {
                Storage::delete($Provider->avatar);
                $Provider->avatar = $request->avatar->store('provider/profile');
            }

            if($request->has('service_type')) {
                if($Provider->service) {
                    if($Provider->service->service_type_id != $request->service_type) {
                        $Provider->status = 'banned';
                    }
                    //$ProviderService = ProviderService::where('provider_id',Auth::user()->id);
                    $Provider->service->service_type_id = $request->service_type;
                    $Provider->service->service_number = $request->service_number;
                    $Provider->service->service_model = $request->service_model;
                    $Provider->service->save();

                } else {
                    ProviderService::create([
                        'provider_id' => $Provider->id,
                        'service_type_id' => $request->service_type,
                        'service_number' => $request->service_number,
                        'service_model' => $request->service_model,
                    ]);
                    $Provider->status = 'banned';
                }
            }

            if($Provider->profile) {
                $Provider->profile->update([
                        'language' => $request->language ? : $Provider->profile->language,
                        'address' => $request->address ? : $Provider->profile->address,
                        'address_secondary' => $request->address_secondary ? : $Provider->profile->address_secondary,
                        'city' => $request->city ? : $Provider->profile->city,
                        'country' => $request->country ? : $Provider->profile->country,
                        'postal_code' => $request->postal_code ? : $Provider->profile->postal_code,
                    ]);
            } else {
                ProviderProfile::create([
                        'provider_id' => $Provider->id,
                        'language' => $request->language,
                        'address' => $request->address,
                        'address_secondary' => $request->address_secondary,
                        'city' => $request->city,
                        'country' => $request->country,
                        'postal_code' => $request->postal_code,
                    ]);
            }


            $Provider->save();

            return redirect(route('provider.profile.index'));
        }

        catch (ModelNotFoundException $e) {
            return response()->json(['error' => trans('api.provider.provider_not_found')], 404);
        }
    }

    /**
     * Display the specified resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function show()
    {
        return view('provider.profile.index');
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request)
    {
        $this->validate($request, [
                'first_name' => 'required|max:255',
                'last_name' => 'required|max:255',
                'mobile' => 'required',
                'avatar' => 'mimes:jpeg,bmp,png',
                'language' => 'max:255',
                'address' => 'max:255',
                'address_secondary' => 'max:255',
                'city' => 'max:255',
                'country' => 'max:255',
                'postal_code' => 'max:255',
            ]);

        try {

            $Provider = Auth::user();

            if($request->has('first_name')) 
                $Provider->first_name = $request->first_name;

            if($request->has('last_name')) 
                $Provider->last_name = $request->last_name;

            if ($request->has('mobile'))
                $Provider->mobile = $request->mobile;

            if ($request->hasFile('avatar')) {
                Storage::delete($Provider->avatar);
                $Provider->avatar = $request->avatar->store('provider/profile');
            }

            if($Provider->profile) {
                $Provider->profile->update([
                        'language' => $request->language ? : $Provider->profile->language,
                        'address' => $request->address ? : $Provider->profile->address,
                        'address_secondary' => $request->address_secondary ? : $Provider->profile->address_secondary,
                        'city' => $request->city ? : $Provider->profile->city,
                        'country' => $request->country ? : $Provider->profile->country,
                        'postal_code' => $request->postal_code ? : $Provider->profile->postal_code,
                    ]);
            } else {
                ProviderProfile::create([
                        'provider_id' => $Provider->id,
                        'language' => $request->language,
                        'address' => $request->address,
                        'address_secondary' => $request->address_secondary,
                        'city' => $request->city,
                        'country' => $request->country,
                        'postal_code' => $request->postal_code,
                    ]);
            }


            $Provider->save();

            return $Provider;
        }

        catch (ModelNotFoundException $e) {
            return response()->json(['error' => trans('api.provider.provider_not_found')], 404);
        }
    }

    /**
     * Update latitude and longitude of the user.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function location(Request $request)
    {
        $this->validate($request, [
                'latitude' => 'required|numeric',
                'longitude' => 'required|numeric',
            ]);

        if($Provider = Auth::user()){

            $Provider->latitude = $request->latitude;
            $Provider->longitude = $request->longitude;
            $Provider->save();

            return response()->json(['message' => trans('api.provider.location_updated')]);

        } else {
            return response()->json(['error' => trans('api.provider.provider_not_found')]);
        }
    }

    public function update_language(Request $request)
    {
        $this->validate($request, [
               'language' => 'required',
            ]);

        try {

            $Provider = Auth::user();

            if($Provider->profile) {
                $Provider->profile->update([
                        'language' => $request->language ? : $Provider->profile->language
                    ]);
            } else {
                ProviderProfile::create([
                        'provider_id' => $Provider->id,
                        'language' => $request->language,
                    ]);
            }

            return response()->json(['message' => trans('api.provider.language_updated'),'language'=>$request->language]);
        }

        catch (ModelNotFoundException $e) {
            return response()->json(['error' => trans('api.provider.provider_not_found')], 404);
        }
    }

    /**
     * Toggle service availability of the provider.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function available(Request $request)
    {
        $this->validate($request, [
                'service_status' => 'required|in:active,offline',
            ]);

        $Provider = Auth::user();
        
        if($Provider->service) {
            
            $provider = $Provider->id;
            $OfflineOpenRequest = RequestFilter::with(['request.provider','request'])
                ->where('provider_id', $provider)
                ->whereHas('request', function($query) use ($provider){
                    $query->where('status','SEARCHING');
                    $query->where('current_provider_id','<>',$provider);
                    $query->orWhereNull('current_provider_id');
                    })->pluck('id');

            if(count($OfflineOpenRequest)>0) {
                RequestFilter::whereIn('id',$OfflineOpenRequest)->delete();
            }   
           
            $Provider->service->update(['status' => $request->service_status]);
        } else {
            return response()->json(['error' => trans('api.provider.not_approved')]);
        }

        return $Provider;
    }

    /**
     * Update password of the provider.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function password(Request $request)
    {
        $this->validate($request, [
                'password' => 'required|confirmed',
                'password_old' => 'required',
            ]);

        $Provider = Auth::user();

        if(password_verify($request->password_old, $Provider->password))
        {
            $Provider->password = bcrypt($request->password);
            $Provider->save();

            return response()->json(['message' => trans('api.provider.password_updated')]);
        } else {
            return response()->json(['error' => trans('api.provider.change_password')], 422);
        }
    }

    /**
     * Show providers daily target.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function target(Request $request)
    {
        try {
            
            $Rides = UserRequests::where('provider_id', Auth::user()->id)
                    ->where('status', 'COMPLETED')
                    ->where('created_at', '>=', Carbon::today())
                    ->with('payment', 'service_type')
                    ->get();

            return response()->json([
                    'rides' => $Rides,
                    'rides_count' => $Rides->count(),
                    'target' => Setting::get('daily_target','0')
                ]);

        } catch(Exception $e) {
            return response()->json(['error' => trans('api.something_went_wrong')]);
        }
    }

    public function chatPush(Request $request){

        $this->validate($request,[
                'user_id' => 'required|numeric',
                'message' => 'required',
            ]);       

        try{

            $user_id=$request->user_id;
            $message=$request->message;
           
         //   $message = \PushNotification::Message($message,array(
         //   'badge' => 1,
         //   'sound' => 'default',
          //  'custom' => array('type' => 'chat')
          //  ));

           
            (new SendPushNotification)->sendPushToUser($user_id, $message);          

            return response()->json(['success' => 'true']);

        } catch(Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }

    }

    //provider document list
    public function documents(Request $request)
    {
        try {

            $provider_id=Auth::user()->id;



            $Documents=Document::select('id','name','type','doc_required')
                        ->with(['providerdocuments' => function ($query) use ($provider_id) {
                        $query->where('provider_id', $provider_id);
                        }])->get();

            return response()->json(['documents' => $Documents]);

        } catch(Exception $e) {
            return response()->json(['error' => trans('api.something_went_wrong')]);
        }
    }


       //provider document expire status
       public function documents_status(Request $request)
       {
           try {
               $i = 0;
               $resultset = array();
               $provider_id=Auth::user()->id;

               

                  $Documents_details=Document::join('provider_documents','provider_documents.document_id','=','documents.id')
                    ->where('provider_documents.provider_id', $provider_id)
                    ->get(['documents.id','documents.name','documents.type','provider_documents.url','documents.doc_required','provider_documents.status','provider_documents.expires_at']);
                
   
         if(count($Documents_details) == 0){


            $Documents=Document::select('id','name','type','doc_required')->get();

             return response()->json(['documents' => $Documents]);

         }else{
             foreach ($Documents_details as $Index) {
                 if ($Index->doc_required == "YES") {
                     $from_dt= date('Y-m-d', strtotime($Index->expires_at));

                     $duration2='-7 day';
                     $diffdatedt2 = date('Y-m-d', strtotime($duration2, strtotime($from_dt)));

                     if ($diffdatedt2 < date('Y-m-d')) {
                         $exp_status ="true";
                     } else {
                         $exp_status="false";
                     }

                          




                     if (!empty($Index->expires_at)) {
                         if ($Index->expires_at < date('Y-m-d')) {
                             $Document_status="Expired";
                         } else {
                             $Document_status=$Index->status;
                         }
                     } else {
                         $exp_status="false";
                         $Document_status=$Index->status;
                     }
                 } else {
                     $exp_status="false";
                     $Document_status=$Index->status;
                 }

                 $resultset[$i]['id'] = $Index->id;
                 $resultset[$i]['name'] = $Index->name;
                 $resultset[$i]['type']=$Index->type;
                 $resultset[$i]['doc_required']=$Index->doc_required;
                 $resultset[$i]['url']=$Index->url;
                 $resultset[$i]['status']= $Document_status;
                 $resultset[$i]['document_id']= $Index->id;
                 $resultset[$i]['expires_at']=$Index->expires_at;
                 $resultset[$i]['expired_status']=$exp_status;
                 $i++;
             }


                   
                 


             return response()->json(['documents' => $resultset]);
         }
           } catch(Exception $e) {
               return response()->json(['error' => trans('api.something_went_wrong')]);
           }
       }

    //provider document list

    public function documentstore(Request $request)
    {
        $this->validate($request, [
            'document' => 'required',
            'document.*' => 'mimes:jpg,jpeg,png|max:2048'
        ]);
        try {           

            if ($request->hasFile('document')) {

                foreach($request->file('document') as $ikey=> $image)
                {
                    $ids=$request->input('id');
                    $doc_id=$ids[$ikey];
                    $provider_id=Auth::user()->id;
                    (new DocumentController)->documentupdate($image, $doc_id,$provider_id);                    
                }                
                
                if(Setting::get('CARD', 0) == 1) {
                    Provider::where('id', Auth::user()->id)->where('status','document')->update(['status'=>'card']);
                }
                else{
                    if(Setting::get('demo_mode', 0) == 1) {
                        Provider::where('id', Auth::user()->id)->where('status','document')->update(['status'=>'approved']);
                    }
                    else{                        
                        Provider::where('id', Auth::user()->id)->where('status','document')->update(['status'=>'onboarding']);
                    }    
                }    

                return $this->documents($request); 
            }

        } catch(Exception $e) {
            return response()->json(['error' => trans('api.something_went_wrong')], 422);
        }
    }
   public function documentupdate(Request $request)
    {
        $this->validate($request, [
            'document' => 'required',
            'document.*' => 'mimes:jpg,jpeg,png|max:2048'
        ]);
        try {           

            if ($request->hasFile('document')) {

                    $ids=$request->input('id');
                    $doc_id=$ids;
                    $provider_id=Auth::user()->id;
                    $image=$request->file('document');
                    (new DocumentController)->documentupdate($image, $doc_id,$provider_id);                    
                              
                
                if(Setting::get('CARD', 0) == 1) {
                    Provider::where('id', Auth::user()->id)->where('status','document')->update(['status'=>'card']);
                }
                else{
                    if(Setting::get('demo_mode', 0) == 1) {
                        Provider::where('id', Auth::user()->id)->where('status','document')->update(['status'=>'approved']);
                    }
                    else{                        
                        Provider::where('id', Auth::user()->id)->where('status','document')->update(['status'=>'onboarding']);
                    }    
                }    

                return $this->documents($request); 
            }

        } catch(Exception $e) {
            return response()->json(['error' => trans('api.something_went_wrong')], 422);
        }
    }
    public function stripe(Request $request)
    {
        if(isset($request->code)){
            $post = [
                'client_secret' => Setting::get('stripe_secret_key'),
                'code' => $request->code,
                'grant_type' => 'authorization_code'
            ];
            $curl = curl_init("https://connect.stripe.com/oauth/token");
            curl_setopt($curl, CURLOPT_HEADER, 0); 
            curl_setopt($curl, CURLOPT_POST, 1); 
            curl_setopt($curl, CURLOPT_POSTFIELDS, $post);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1); 
            $result = curl_exec($curl); 
            $curl_error = curl_error($curl);
            curl_close($curl);
            $stripe = json_decode($result);

            if($stripe->stripe_user_id){
                $provider = Provider::where('id', Auth::user()->id)->first();
                $provider->stripe_acc_id = $stripe->stripe_user_id;
                $provider->save();

                if($request->ajax()){
                    return response()->json(['message' => 'Your stripe account connected successfully']);
                }else{
                    return redirect('/provider')->with('flash_success', 'Your stripe account connected successfully');
                }
            }else{
                if($request->ajax()){
                    return response()->json(['message' => $curl_error]);
                }else{
                    return redirect('/provider')->with('flash_error', $curl_error);
                }
            }

        }else{
            if($request->ajax()){
                return response()->json(['message' => $request->error_description]);
            }else{
                return redirect('/provider')->with('flash_error', $request->error_description);
            }
        }
    }
}
