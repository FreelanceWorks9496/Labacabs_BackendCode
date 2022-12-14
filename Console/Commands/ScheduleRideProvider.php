<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use DB;
use App\Http\Controllers\SendPushNotification;
use App\Http\Controllers\AdminController;
use Carbon\Carbon;

class ScheduleRideProvider extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cronjob:ScheduleRideProvider';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Updating the Schedule Rides Provider Timing';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
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



              DB::table('user_requests')
              ->where('id',$ride->id)
              ->update(['status' => 'STARTED', 'assigned_at' =>Carbon::now() , 'schedule_at' => null ]);

              $shedule_time_message=date("g:i A", strtotime($ride->schedule_at));
              $message="Your upcoming ride has been started";
          
            //scehule start request push to provider
             (new SendPushNotification)->sendPushToProvider($ride->provider_id,$message);

                      //scehule start request push to user
                      (new SendPushNotification)->user_schedule($ride->user_id);
                      //scehule start request push to provider
                      (new SendPushNotification)->provider_schedule($ride->provider_id);



             DB::table('provider_services')->where('provider_id',$ride->provider_id)->update(['status' =>'riding']);

              }
            
          }
         
      }


    }
}
