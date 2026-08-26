<?php

namespace App\Http\Controllers\Users;

use App\Http\Controllers\Controller;
use App\Libraries\Shopifyapi;
use App\Libraries\ShopifyRest;
use App\Mail\DowngradedPlanMailTemplate;
use App\Mail\SubscriptionMailTemplate;
use App\Mail\UpgradedPlanMailTemplate;
use App\Models\AdminUser;
use App\Models\Plan;
use App\Models\RecurringPlanCharge;
use App\Models\UserToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class ShopifyPlanController extends Controller
{
    public function __construct()
    {
        $this->plan_secret_key = isset($_GET['plan_secret_key']) ? $_GET['plan_secret_key'] : (isset($_POST['plan_secret_key']) ? $_POST['plan_secret_key'] : '');
    }

    public function get_plan_list()
    {
        $planList = Plan::where('is_deleted', 0)->get();

        if (! $planList) {
            return response()->json([
                'planData' => [],
                'message' => 'No plans found',
            ], 404);
        }

        return response()->json([
            'planData' => $planList,
            'message' => 'Plan list fetched successfully',
        ], 200);
    }

    public function change_plan(Request $request)
    {
        $response = [
            'status' => 0,
            'message' => 'Invalid request, please try again later',
        ];

        $token = preg_replace('/^Bearer\s+/i', '', $request->header('Authorization'));
        if (! $token) {
            return response()->json(['error' => 'Invalid token'], 401);
        }

        $userData = AdminUser::select(DB::raw('admin_users.*,user_tokens.user_token AS unique_key'))
            ->leftJoin('user_tokens', 'user_tokens.shop_id', '=', 'admin_users.id')
            ->where('user_tokens.user_token', $token)
            ->first();

        $plan_id = $request->post('plan_id');
        $plan_typed = $request->post('plan_type');
        $plan_type = ($plan_typed == 'yearly') ? 'Yearly' : 'Monthly';
        $plan_name = $request->post('plan_name');

        $plan_details = Plan::where('id', $plan_id)->first();

        $shop = $userData->shop_url;
        $token = $userData->token;
        $params = [
            'shop_domain' => $shop,
            'token' => $token,
            'api_key' => config('services.shopify.key'),
            'secret' => config('services.shopify.secret'),
        ];
        $shopifyapi = new Shopifyapi($params);
        $current_plan = $userData->plan_id;

        if ($plan_id == 1) {
            $shop_id = $userData->id;
            if ($userData->charge_id != '0') {
                try {
                    $result = $shopifyapi->call('DELETE', '/admin/api/'.config('services.shopify.version').'/recurring_application_charges/'.$userData->charge_id.'.json');
                    $planData = RecurringPlanCharge::where('store', $shop_id)->where('charge_id', $userData->charge_id)->first();
                    $id = $planData->id;
                    $planDetail = RecurringPlanCharge::find($id);
                    $planDetail->is_deleted = 1;
                    $planDetail->update();
                } catch (ShopifyApiException $e) {
                    echo '<pre>';
                    print_r($e->getResponse());
                    echo '</pre>';
                    exit;
                }
            }

            $names = $userData->shop_owner_name;
            $new_plan = $plan_id;
            if ($current_plan != 0) {
                $old_plan_details = Plan::where('id', $current_plan)->first();
                $price = '';
                if ($plan_type == 'Monthly') {
                    $price = $old_plan_details->month_price;
                } else {
                    $price = $old_plan_details->year_price;
                }
                if ($current_plan != 0 && $new_plan == 1) {
                    $downgradeMessage = ':sob: Shopify: '.config('services.shopify.name').', Plan is downgraded to '.$plan_name.' ('.$plan_type.')';
                    $title = $names.' has downgraded his plan to '.$plan_name;
                    $text = "\nNew Plan: ".$plan_name."\n";
                    $text .= 'Old Plan: '.$old_plan_details['name'].' ($'.$price.")\n";
                    $text .= 'Shop: '.$shop."\n";
                    $text .= 'Email: '.$userData['email']."\n";
                    $text .= 'Installed on: '.date('d M, Y', strtotime($userData->created_at))."\n";
                    $color = '#e83845';
                    get_slack_message($downgradeMessage, $title, $text, $color);

                    if (isset($userData['id']) && ! empty($userData['email']) && env('TEST_MAIL')) {
                        Mail::to($userData->email)->send(new DowngradedPlanMailTemplate($plan_details, $old_plan_details, $userData->email, $userData->identifier));
                    }
                } else {
                    $upgrageMessage = ':tada: Shopify: '.config('services.shopify.name').', Plan is upgraded to '.$plan_name.' ('.$plan_type.')';
                    $title = $names.' has upgraded his plan to '.$plan_name;
                    $text = "\nNew Plan: ".$plan_name."\n";
                    $text .= 'Old Plan: '.$old_plan_details['name'].' ($'.$price.")\n";
                    $text .= 'Shop: '.$shop."\n";
                    $text .= 'Email: '.$userData['email']."\n";
                    $text .= 'Installed on: '.date('d M, Y', strtotime($userData->created_at))."\n";
                    $color = '#ffce30';
                    get_slack_message($upgrageMessage, $title, $text, $color);

                    if (isset($userData['id']) && ! empty($userData['email']) && env('TEST_MAIL')) {
                        Mail::to($userData->email)->send(new UpgradedPlanMailTemplate($plan_details, $old_plan_details, $userData->email, $userData->identifier));
                    }
                }
            } else {
                $old_plan_details = '';
                $upgrageMessage = ':tada: Shopify: '.config('services.shopify.name').', Plan is upgraded to '.$plan_name.' ('.$plan_type.')';
                $title = $names.' has upgraded his plan to '.$plan_name;
                $text = "\nNew Plan: ".$plan_name."\n";
                $text .= 'Shop: '.$shop."\n";
                $text .= 'Email: '.$userData['email']."\n";
                $text .= 'Installed on: '.date('d M, Y', strtotime($userData->created_at))."\n";
                $color = '#ffce30';
                get_slack_message($upgrageMessage, $title, $text, $color);

                if (isset($userData['id']) && ! empty($userData['email']) && env('TEST_MAIL')) {
                    Mail::to($userData->email)->send(new SubscriptionMailTemplate($plan_details, $userData->email, $userData->identifier));
                }
            }

            date_default_timezone_set('UTC');
            $id = $shop_id;
            $userDetail = AdminUser::find($id);
            $userDetail->charge_id = 0;
            $userDetail->plan_type = $plan_type;
            $userDetail->plan_id = 1;
            $userDetail->plan_created_at = now();
            $userDetail->current_charges = 0;
            $userDetail->visitors = 0;
            if ($plan_type == 'Monthly') {
                $userDetail->next_reset_date = date('Y-m-d H:i:s', strtotime('+30 days'));
            } else {
                $userDetail->next_reset_date = date('Y-m-d H:i:s', strtotime('+1 month'));
            }
            $userDetail->update();

        } else {
            $shopify_plan = $userData->shopify_plan;
            $shopify_plan_name = ['affiliate', 'partner_test', 'plus_partner_sandbox', 'staff', 'staff_business'];

            $shop_id = $userData->id;
            $test = env('TEST_MODE');

            if (in_array($shopify_plan, $shopify_plan_name) || $shop == 'hiru123456.myshopify.com') {
                $test = env('TEST_MODE');
            }

            $planSecretKey = (string) Str::uuid();
            $userData->plan_secret_key = $planSecretKey;
            $userData->update();

            $url_shop = '?plan_secret_key='.$planSecretKey;

            $trialDays = env('TRIAL_DAYS');

            if ($plan_type == 'Yearly') {

                $returnUrl = env('FRONTEND_URL').
                    "/plan/annual?plan_secret_key={$planSecretKey}";

                $payload = [
                    'recurring_application_charge' => [
                        'name' => $plan_name,
                        'price' => (float) $plan_details->year_price,
                        'return_url' => $returnUrl,
                        'trial_days' => (int) $trialDays,
                        'test' => $test === true,
                    ],
                ];

                $response = ShopifyRest::post(
                    $shop,
                    $token,
                    'recurring_application_charges.json',
                    $payload
                );

                // ❌ API-level error
                if ($response['status'] !== 201) {
                    return response()->json([
                        'status' => 0,
                        'error' => $response,
                    ], 422);
                }

                $charge = $response['body']['recurring_application_charge'];

                $response['confirmationUrl'] = $charge['confirmation_url'];
            } else {
                $shopifyPlan = Plan::where('id', $plan_id)->first();
                $data = ['recurring_application_charge' => [
                    'name' => $shopifyPlan->name,
                    'price' => $plan_details->month_price,
                    'return_url' => env('FRONTEND_URL').'/plan/month?plan_secret_key='.$planSecretKey,
                    'test' => $test,
                    'trial_days' => env('TRIAL_DAYS'),
                ],
                ];
                try {
                    $result = $shopifyapi->call('POST', '/admin/api/'.config('services.shopify.version').'/recurring_application_charges.json', $data);
                    date_default_timezone_set('UTC');
                    $data = new RecurringPlanCharge;
                    $data->store = $shop_id;
                    $data->charge_id = $result['id'];
                    $data->plan_id = $shopifyPlan->id;
                    $data->api_client_id = $result['api_client_id'];
                    $data->plan_type = 'Monthly';
                    $data->price = $result['price'];
                    $data->status = $result['status'];
                    $data->return_url = $result['return_url'];
                    $data->billing_on = now();
                    $data->created_at = now();
                    $data->updated_at = now();
                    $data->test = $result['test'];
                    $data->activated_on = $result['activated_on'];
                    $data->trial_ends_on = $result['trial_ends_on'];
                    $data->cancelled_on = $result['cancelled_on'];
                    $data->trial_days = $result['trial_days'];
                    $data->decorated_return_url = $result['decorated_return_url'];
                    $data->confirmation_url = isset($result['confirmation_url']) ? $result['confirmation_url'] : '';
                    $data->created_date = date('Y-m-d H:i:s');
                    $data->updated_date = date('Y-m-d H:i:s');
                    $data->save();

                    $response['confirmationUrl'] = $result['confirmation_url'];

                } catch (ShopifyApiException $e) {
                    print_r($e);
                    print_r($e->getResponse());
                    exit;
                }
            }
        }
        $response['status'] = 1;
        $response['message'] = 'Plan updated successfully';

        return response()->json($response);
    }

    public function annual(Request $request)
    {
        $response = [
            'status' => 0,
            'message' => 'Invalid request, please try again later',
        ];

        $plan_secret_key = $request->post('planSecretKey');
        $is_new_user = false;
        $userData = AdminUser::where('plan_secret_key', $plan_secret_key)->first();
        $shop = $userData->shop_url;
        $token = $userData->token;

        $charge_id = $request->post('chargeId');

        if ($charge_id) {
            $params = [
                'shop_domain' => $shop,
                'token' => $token,
                'api_key' => config('services.shopify.key'),
                'secret' => config('services.shopify.secret'),
            ];
            $shopifyapi = new Shopifyapi($params);
            try {

                $charges = $shopifyapi->call('GET', '/admin/api/'.config('services.shopify.version').'/recurring_application_charges/'.$charge_id.'.json');
                // pre($charges);
                $charge_plan = Plan::where('name', $charges['name'])->first();
                $charge_plan_id = $charge_plan->id;

                if ($charges['status'] == 'active') {
                    $resource_feedback['resource_feedback'] = [
                        'state' => 'success',
                        'feedback_generated_at' => date('Y-m-d\TH:i:s.u'),
                    ];
                    $resource_result = $shopifyapi->call('POST', '/admin/api/'.config('services.shopify.version').'/resource_feedback.json', $resource_feedback);

                    $plan_id = $charge_plan_id;
                    $shop_details = AdminUser::where('plan_secret_key', $plan_secret_key)->first();
                    $current_plan = $shop_details->plan_id;
                    $is_new_user = ($current_plan == 0) ? true : false;

                    $names = $shop_details->shop_owner_name;
                    $new_plan_details = Plan::where('id', $plan_id)->first();
                    if ($shop_details->plan_id != 0) {
                        $old_plan_details = Plan::where('id', $shop_details->plan_id)->first();
                        $new_plan_details = Plan::where('id', $plan_id)->first();
                        if ($current_plan < $plan_id) {
                            $upgrageMessage = ':tada: Shopify: '.config('services.shopify.name').', Plan is upgraded to '.$new_plan_details['name'].' (Yearly)';
                            $title = $names.' has upgraded his plan to '.$new_plan_details['name'];
                            $text = "\nNew Plan: ".$new_plan_details['name'].' ($'.$new_plan_details['year_price'].")\n";
                            $text .= 'Old Plan: '.$old_plan_details['name'].' ($'.$old_plan_details['year_price'].")\n";
                            $text .= 'Shop: '.$shop."\n";
                            $text .= 'Email: '.$shop_details['email']."\n";
                            $text .= 'Installed on: '.date('d M, Y', strtotime($shop_details->created_at))."\n";
                            $color = '#ffce30';

                            get_slack_message($upgrageMessage, $title, $text, $color);

                            if (env('TEST_MAIL')) {
                                Mail::to($userData->email)->send(new UpgradedPlanMailTemplate($new_plan_details, $old_plan_details, $userData->email, $userData->identifier));
                            }
                        } elseif ($current_plan == $plan_id) {
                            $upgrageMessage = ':tada: Shopify: '.config('services.shopify.name').', Plan is upgraded to '.$new_plan_details['name'].' (Yearly)';
                            $title = $names.' has upgraded his plan to '.$new_plan_details['name'];
                            $text = "\nNew Plan: ".$new_plan_details['name'].' ($'.$new_plan_details['year_price'].")\n";
                            $text .= 'Old Plan: '.$old_plan_details['name'].' ($'.$old_plan_details['month_price'].")\n";
                            $text .= 'Shop: '.$shop."\n";
                            $text .= 'Email: '.$shop_details['email']."\n";
                            $text .= 'Installed on: '.date('d M, Y', strtotime($shop_details->created_at))."\n";
                            $color = '#ffce30';

                            get_slack_message($upgrageMessage, $title, $text, $color);

                            if (env('TEST_MAIL')) {
                                Mail::to($userData->email)->send(new UpgradedPlanMailTemplate($new_plan_details, $old_plan_details, $userData->email, $userData->identifier));
                            }
                        } else {
                            $downgradeMessage = ':sob: Shopify: '.config('services.shopify.name').', Plan is downgraded to '.$new_plan_details['name'].' (Yearly)';
                            $title = $names.' has downgraded his plan to '.$new_plan_details['name'];
                            $text = "\nNew Plan: ".$new_plan_details['name'].' ($'.$new_plan_details['year_price'].")\n";
                            $text .= 'Old Plan: '.$old_plan_details['name'].' ($'.$old_plan_details['year_price'].")\n";
                            $text .= 'Shop: '.$shop."\n";
                            $text .= 'Email: '.$shop_details['email']."\n";
                            $text .= 'Installed on: '.date('d M, Y', strtotime($shop_details->created_at))."\n";
                            $color = '#e83845';
                            get_slack_message($downgradeMessage, $title, $text, $color);

                            if (env('TEST_MAIL')) {
                                Mail::to($userData->email)->send(new DowngradedPlanMailTemplate($new_plan_details, $old_plan_details, $userData->email, $userData->identifier));
                            }
                        }
                    } else {
                        $old_plan_details = '';
                        $upgrageMessage = ':tada: Shopify: '.config('services.shopify.name').', Plan is upgraded to '.$new_plan_details['name'].' (Yearly)';
                        $title = $names.' has upgraded his plan to '.$new_plan_details['name'];
                        $text = "\nNew Plan: ".$new_plan_details['name'].' ($'.$new_plan_details['year_price'].")\n";
                        $text .= 'Shop: '.$shop."\n";
                        $text .= 'Email: '.$shop_details['email']."\n";
                        $text .= 'Installed on: '.date('d M, Y', strtotime($shop_details->created_at))."\n";
                        $color = '#ffce30';

                        get_slack_message($upgrageMessage, $title, $text, $color);

                        if (env('TEST_MAIL')) {
                            Mail::to($userData->email)->send(new SubscriptionMailTemplate($new_plan_details, $userData->email, $userData->identifier));
                        }
                    }

                    $recurreData = RecurringPlanCharge::where('store', $shop_details->id)->where('charge_id', $shop_details->charge_id)->first();
                    if (! empty($recurreData)) {
                        $id = $recurreData->id;
                        $recurringData = RecurringPlanCharge::find($id);
                        $recurringData->is_deleted = 1;
                        $recurringData->update();
                    }

                    date_default_timezone_set('UTC');
                    $data = new RecurringPlanCharge;
                    $data->store = $shop_details->id;
                    $data->charge_id = $charges['id'];
                    $data->plan_id = $charge_plan_id;
                    $data->api_client_id = $charges['api_client_id'];
                    $data->plan_type = 'Yearly';
                    $data->price = $charges['price'];
                    $data->status = $charges['status'];
                    $data->return_url = $charges['return_url'];
                    $data->billing_on = now();
                    $data->created_at = now();
                    $data->updated_at = now();
                    $data->test = $charges['test'];
                    $data->activated_on = $charges['activated_on'];
                    $data->trial_ends_on = $charges['trial_ends_on'];
                    $data->cancelled_on = $charges['cancelled_on'];
                    $data->trial_days = $charges['trial_days'];
                    $data->decorated_return_url = $charges['decorated_return_url'];
                    $data->confirmation_url = isset($charges['confirmation_url']) ? $charges['confirmation_url'] : '';
                    $data->created_date = date('Y-m-d H:i:s');
                    $data->updated_date = date('Y-m-d H:i:s');
                    $data->save();

                    $resetAt = date('Y-m-d H:i:s', strtotime($charges['trial_ends_on'].' +1 month'));

                    $id = $shop_details->id;
                    $userData = AdminUser::find($id);
                    $userData->charge_id = $charge_id;
                    $userData->plan_type = 'Yearly';
                    $userData->plan_id = $plan_id;
                    $userData->visitors = 0;
                    $userData->plan_created_at = now();
                    $userData->next_reset_date = $resetAt;
                    $userData->max_visitors_at = null;
                    $userData->current_charges = $charges['price'];
                    $userData->update();

                    $currentPlan = $shop_details->plan_id;
                    $is_new_user = ($currentPlan == 0) ? true : false;
                    $currentPlan = ($is_new_user == true) ? '1' : $currentPlan;

                }

            } catch (ShopifyApiException $e) {
                echo '<pre>';
                print_r($e->getResponse());
                echo '</pre>';
                exit;
            }
            $response['status'] = 1;
            $response['message'] = 'Plan updated successfully';

        }

        return response()->json($response);
    }

    public function month(Request $request)
    {
        $response = [
            'status' => 0,
            'message' => 'Invalid request, please try again later',
        ];

        $plan_secret_key = $request->post('planSecretKey');
        $is_new_user = false;
        $userData = $userData = AdminUser::where('plan_secret_key', $plan_secret_key)->first();
        $shop = $userData->shop_url;
        $token = $userData->token;

        $charge_id = $request->post('chargeId');

        if ($charge_id) {
            $shop_details = AdminUser::where('shop_url', $shop)->first();
            try {

                $params = [
                    'shop_domain' => $shop,
                    'token' => $token,
                    'api_key' => config('services.shopify.key'),
                    'secret' => config('services.shopify.secret'),
                ];
                $shopifyapi = new Shopifyapi($params);

                $charges = $shopifyapi->call('GET', '/admin/api/'.config('services.shopify.version').'/recurring_application_charges/'.$charge_id.'.json');

                $charge_plan = Plan::where('name', $charges['name'])->first();
                $charge_plan_id = $charge_plan->id;
                if ($charges['status'] == 'declined') {
                    $urlshop = isset($shop) ? '?shop='.$shop.'&shop_hash='.md5(base64_encode(md5($shop))) : '';
                    $data = RecurringPlanCharge::where('store', $shop_details->id)->first();
                    $id = $data->id;
                    $chargesData = RecurringPlanCharge::find($id);
                    $chargesData->is_deleted = 1;
                    $chargesData->update();

                    $usertoken = '';
                    $latestToken = UserToken::where('shop_id', $shop_details->id)
                        ->orderByDesc('id')   // latest by auto-increment
                        ->first();
                    if ($latestToken) {
                        $usertoken = $latestToken->user_token;
                    }
                    $tempId = Str::random(40);
                    Cache::put("tmp_token:{$tempId}", $usertoken, now()->addMinutes(5));
                    header('Location:'.route('user.plan').'?t='.$tempId.'&shop='.$shop);
                    exit;

                } elseif ($charges['status'] == 'accepted') {

                    $resource_feedback['resource_feedback'] = [
                        'state' => 'success',
                        'feedback_generated_at' => date('Y-m-d\TH:i:s.u'),
                    ];
                    $resource_result = $shopifyapi->call('POST', '/admin/api/'.config('services.shopify.version').'/resource_feedback.json', $resource_feedback);
                    date_default_timezone_set('UTC');
                    $data = RecurringPlanCharge::where('charge_id', $charges['id'])->first();
                    $id = $data->id;
                    $charges_data = RecurringPlanCharge::find($id);
                    $charges_data->store = $shop_details->id;
                    $charges_data->charge_id = $charges['id'];
                    $charges_data->plan_id = $charge_plan_id;
                    $charges_data->api_client_id = $charges['api_client_id'];
                    $charges_data->plan_type = 'Monthly';
                    $charges_data->price = $charges['price'];
                    $charges_data->status = $charges['status'];
                    $charges_data->return_url = $charges['return_url'];
                    $charges_data->billing_on = now();
                    $charges_data->created_at = now();
                    $charges_data->updated_at = now();
                    $charges_data->test = $charges['test'];
                    $charges_data->activated_on = $charges['activated_on'];
                    $charges_data->trial_ends_on = $charges['trial_ends_on'];
                    $charges_data->cancelled_on = $charges['cancelled_on'];
                    $charges_data->trial_days = $charges['trial_days'];
                    $charges_data->decorated_return_url = $charges['decorated_return_url'];
                    $charges_data->confirmation_url = isset($charges['confirmation_url']) ? $charges['confirmation_url'] : '';
                    $charges_data->created_date = date('Y-m-d H:i:s');
                    $charges_data->updated_date = date('Y-m-d H:i:s');
                    $charges_data->update();

                    $data = ['recurring_application_charge' => $charges];

                    $activate = $shopifyapi->call('POST', '/admin/api/'.config('services.shopify.version').'/recurring_application_charges/'.$charge_id.'/activate.json', $data);
                    $data = RecurringPlanCharge::where('charge_id', $activate['id'])->first();
                    $id = $data->id;
                    $activate_data = RecurringPlanCharge::find($id);
                    $activate_data->store = $shop_details->id;
                    $activate_data->charge_id = $activate['id'];
                    $activate_data->plan_id = $charge_plan_id;
                    $activate_data->api_client_id = $activate['api_client_id'];
                    $activate_data->plan_type = 'Monthly';
                    $activate_data->price = $activate['price'];
                    $activate_data->status = $activate['status'];
                    $activate_data->return_url = $activate['return_url'];
                    $activate_data->billing_on = now();
                    $activate_data->created_at = now();
                    $activate_data->updated_at = now();
                    $activate_data->test = $activate['test'];
                    $activate_data->activated_on = $activate['activated_on'];
                    $activate_data->trial_ends_on = $activate['trial_ends_on'];
                    $activate_data->cancelled_on = $activate['cancelled_on'];
                    $activate_data->trial_days = $activate['trial_days'];
                    $activate_data->decorated_return_url = $activate['decorated_return_url'];
                    $activate_data->confirmation_url = isset($activate['confirmation_url']) ? $activate['confirmation_url'] : '';
                    $activate_data->created_date = date('Y-m-d H:i:s');
                    $activate_data->updated_date = date('Y-m-d H:i:s');
                    $activate_data->update();

                    $price = $charges['price'];
                    $shop_id = $shop_details->id;
                    if ($shop_details->charge_id != '0') {
                        try {
                            $result = $shopifyapi->call('DELETE', '/admin/api/'.config('services.shopify.version').'/recurring_application_charges/'.$shop_details->charge_id.'.json');
                            $data = RecurringPlanCharge::where('store', $shop_id)->where('charge_id', $shop_details->charge_id)->first();
                            $id = $data->id;
                            $chargesData = RecurringPlanCharge::find($id);
                            $chargesData->is_deleted = 1;
                            $chargesData->update();
                        } catch (ShopifyApiException $e) {
                            echo '<pre>';
                            print_r($e->getResponse());
                            echo '</pre>';
                            exit;
                        }
                    }

                    $plan_id = $charge_plan_id;
                    $names = $shop_details->shop_owner_name;
                    $currentPlan = $shop_details->plan_id;
                    $is_new_user = ($currentPlan == 0) ? true : false;
                    $current_Plan = ($is_new_user == true) ? '1' : $currentPlan;
                    $newPlan = $plan_id;
                    $new_plan_details = Plan::where('id', $plan_id)->first();
                    if ($shop_details->plan_id != 0) {
                        $old_plan_details = Plan::where('id', $shop_details->plan_id)->first();

                        $old_plan_details = Plan::where('id', $shop_details->plan_id)->first();
                        $new_plan_details = Plan::where('id', $plan_id)->first();
                        if ($currentPlan < $plan_id || $currentPlan == 0) {
                            $upgrageMessage = ':tada: Shopify: '.config('services.shopify.name').', Plan is upgraded to '.$new_plan_details['name'].' (Monthly)';
                            $title = $names.' has upgraded his plan to '.$new_plan_details['name'];
                            $text = "\nNew Plan: ".$new_plan_details['name'].' ($'.$new_plan_details['month_price'].")\n";
                            $text .= 'Old Plan: '.$old_plan_details['name'].' ($'.$old_plan_details['month_price'].")\n";
                            $text .= 'Shop: '.$shop."\n";
                            $text .= 'Email: '.$shop_details['email']."\n";
                            $text .= 'Installed on: '.date('d M, Y', strtotime($shop_details->created_at))."\n";
                            $color = '#ffce30';

                            get_slack_message($upgrageMessage, $title, $text, $color);

                            if (env('TEST_MAIL')) {
                                Mail::to($userData->email)->send(new UpgradedPlanMailTemplate($new_plan_details, $old_plan_details, $userData->email, $userData->identifier));
                            }
                        } elseif ($currentPlan == $plan_id) {
                            $upgrageMessage = ':sob: Shopify: '.config('services.shopify.name').', Plan is downgraded to '.$new_plan_details['name'].' (Monthly)';
                            $title = $names.' has downgraded his plan to '.$new_plan_details['name'];
                            $text = "\nNew Plan: ".$new_plan_details['name'].' ($'.$new_plan_details['month_price'].")\n";
                            $text .= 'Old Plan: '.$old_plan_details['name'].' ($'.$old_plan_details['year_price'].")\n";
                            $text .= 'Shop: '.$shop."\n";
                            $text .= 'Email: '.$shop_details['email']."\n";
                            $text .= 'Installed on: '.date('d M, Y', strtotime($shop_details->created_at))."\n";
                            $color = '#e83845';

                            get_slack_message($upgrageMessage, $title, $text, $color);

                            if (env('TEST_MAIL')) {
                                Mail::to($userData->email)->send(new DowngradedPlanMailTemplate($new_plan_details, $old_plan_details, $userData->email, $userData->identifier));
                            }
                        } else {
                            $downgradeMessage = ':sob: Shopify: '.config('services.shopify.name').', Plan is downgraded to '.$new_plan_details['name'].' (Monthly)';
                            $title = $names.' has downgraded his plan to '.$new_plan_details['name'];
                            $text = "\nNew Plan: ".$new_plan_details['name'].' ($'.$new_plan_details['month_price'].")\n";
                            $text .= 'Old Plan: '.$old_plan_details['name'].' ($'.$old_plan_details['month_price'].")\n";
                            $text .= 'Shop: '.$shop."\n";
                            $text .= 'Email: '.$shop_details['email']."\n";
                            $text .= 'Installed on: '.date('d M, Y', strtotime($shop_details->created_at))."\n";
                            $color = '#e83845';
                            get_slack_message($downgradeMessage, $title, $text, $color);

                            if (env('TEST_MAIL')) {
                                Mail::to($userData->email)->send(new DowngradedPlanMailTemplate($new_plan_details, $old_plan_details, $userData->email, $userData->identifier));
                            }
                        }
                    } else {
                        $old_plan_details = '';
                        $upgrageMessage = ':tada: Shopify: '.config('services.shopify.name').', Plan is upgraded to '.$new_plan_details['name'].' (Monthly)';
                        $title = $names.' has upgraded his plan to '.$new_plan_details['name'];
                        $text = "\nNew Plan: ".$new_plan_details['name'].' ($'.$new_plan_details['month_price'].")\n";
                        $text .= 'Shop: '.$shop."\n";
                        $text .= 'Email: '.$shop_details['email']."\n";
                        $text .= 'Installed on: '.date('d M, Y', strtotime($shop_details->created_at))."\n";
                        $color = '#ffce30';

                        get_slack_message($upgrageMessage, $title, $text, $color);

                        if (env('TEST_MAIL')) {
                            Mail::to($userData->email)->send(new SubscriptionMailTemplate($new_plan_details, $userData->email, $userData->identifier));
                        }
                    }

                    $resetAt = date('Y-m-d H:i:s', strtotime($activate['trial_ends_on'].' +30 days'));

                    $userData = AdminUser::find($shop_id);
                    $userData->charge_id = $charge_id;
                    $userData->plan_type = 'Monthly';
                    $userData->plan_id = $plan_id;
                    $userData->visitors = 0;
                    $userData->plan_created_at = now();
                    $userData->next_reset_date = $resetAt;
                    $userData->max_visitors_at = null;
                    $userData->current_charges = $price;
                    $userData->update();

                } elseif ($charges['status'] == 'active') {

                    $resource_feedback['resource_feedback'] = [
                        'state' => 'success',
                        'feedback_generated_at' => date('Y-m-d\TH:i:s.u'),
                    ];
                    $resource_result = $shopifyapi->call('POST', '/admin/api/'.config('services.shopify.version').'/resource_feedback.json', $resource_feedback);
                    date_default_timezone_set('UTC');
                    $data = RecurringPlanCharge::where('charge_id', $charges['id'])->first();
                    $id = $data->id;
                    $charges_data = RecurringPlanCharge::find($id);
                    $charges_data->store = $shop_details->id;
                    $charges_data->charge_id = $charges['id'];
                    $charges_data->plan_id = $charge_plan_id;
                    $charges_data->api_client_id = $charges['api_client_id'];
                    $charges_data->plan_type = 'Monthly';
                    $charges_data->price = $charges['price'];
                    $charges_data->status = $charges['status'];
                    $charges_data->return_url = $charges['return_url'];
                    $charges_data->billing_on = now();
                    $charges_data->created_at = now();
                    $charges_data->updated_at = now();
                    $charges_data->test = $charges['test'];
                    $charges_data->activated_on = $charges['activated_on'];
                    $charges_data->trial_ends_on = $charges['trial_ends_on'];
                    $charges_data->cancelled_on = $charges['cancelled_on'];
                    $charges_data->trial_days = $charges['trial_days'];
                    $charges_data->decorated_return_url = $charges['decorated_return_url'];
                    $charges_data->confirmation_url = isset($charges['confirmation_url']) ? $charges['confirmation_url'] : '';
                    $charges_data->created_date = date('Y-m-d H:i:s');
                    $charges_data->updated_date = date('Y-m-d H:i:s');
                    $charges_data->update();

                    $data = ['recurring_application_charge' => $charges];
                    $activate = $shopifyapi->call('POST', '/admin/api/'.config('services.shopify.version').'/recurring_application_charges/'.$charge_id.'/activate.json', $data);
                    $data = RecurringPlanCharge::where('charge_id', $activate['id'])->first();
                    $id = $data->id;
                    $activate_data = RecurringPlanCharge::find($id);
                    $activate_data->store = $shop_details->id;
                    $activate_data->charge_id = $activate['id'];
                    $activate_data->plan_id = $charge_plan_id;
                    $activate_data->api_client_id = $activate['api_client_id'];
                    $activate_data->plan_type = 'Monthly';
                    $activate_data->price = $activate['price'];
                    $activate_data->status = $activate['status'];
                    $activate_data->return_url = $activate['return_url'];
                    $activate_data->billing_on = now();
                    $activate_data->created_at = now();
                    $activate_data->updated_at = now();
                    $activate_data->test = $activate['test'];
                    $activate_data->activated_on = $activate['activated_on'];
                    $activate_data->trial_ends_on = $activate['trial_ends_on'];
                    $activate_data->cancelled_on = $activate['cancelled_on'];
                    $activate_data->trial_days = $activate['trial_days'];
                    $activate_data->decorated_return_url = $activate['decorated_return_url'];
                    $activate_data->confirmation_url = isset($activate['confirmation_url']) ? $activate['confirmation_url'] : '';
                    $activate_data->created_date = date('Y-m-d H:i:s');
                    $activate_data->updated_date = date('Y-m-d H:i:s');
                    $activate_data->update();

                    $price = $charges['price'];
                    $shop_id = $shop_details->id;
                    if ($shop_details->charge_id != '0') {
                        try {
                            $result = $shopifyapi->call('DELETE', '/admin/api/'.config('services.shopify.version').'/recurring_application_charges/'.$shop_details->charge_id.'.json');
                            $data = RecurringPlanCharge::where('store', $shop_id)->where('charge_id', $shop_details->charge_id)->first();
                            $id = $data->id;
                            $chargesData = RecurringPlanCharge::find($id);
                            $chargesData->is_deleted = 1;
                            $chargesData->update();
                        } catch (ShopifyApiException $e) {
                            echo '<pre>';
                            print_r($e->getResponse());
                            echo '<pre>';
                            exit;
                        }
                    }

                    $plan_id = $charge_plan_id;
                    $names = $shop_details->shop_owner_name;
                    $currentPlan = $shop_details->plan_id;
                    $is_new_user = ($currentPlan == 0) ? true : false;
                    $current_Plan = ($is_new_user == true) ? '1' : $currentPlan;
                    $newPlan = $plan_id;
                    $new_plan_details = Plan::where('id', $plan_id)->first();

                    if ($shop_details->plan_id != 0) {
                        $old_plan_details = Plan::where('id', $shop_details->plan_id)->first();
                        if ($currentPlan < $plan_id || $currentPlan == 0) {
                            $upgrageMessage = ':tada: Shopify: '.config('services.shopify.name').', Plan is upgraded to '.$new_plan_details['name'].' (Monthly)';
                            $title = $names.' has upgraded his plan to '.$new_plan_details['name'];
                            $text = "\nNew Plan: ".$new_plan_details['name'].' ($'.$new_plan_details['month_price'].")\n";
                            $text .= 'Old Plan: '.$old_plan_details['name'].' ($'.$old_plan_details['month_price'].")\n";
                            $text .= 'Shop: '.$shop."\n";
                            $text .= 'Email: '.$shop_details['email']."\n";
                            $text .= 'Installed on: '.date('d M, Y', strtotime($shop_details->created_at))."\n";
                            $color = '#ffce30';

                            get_slack_message($upgrageMessage, $title, $text, $color);

                            if (env('TEST_MAIL')) {
                                Mail::to($userData->email)->send(new UpgradedPlanMailTemplate($new_plan_details, $old_plan_details, $userData->email, $userData->identifier));
                            }
                        } elseif ($currentPlan == $plan_id) {
                            $upgrageMessage = ':sob: Shopify: '.config('services.shopify.name').', Plan is downgraded to '.$new_plan_details['name'].' (Monthly)';
                            $title = $names.' has downgraded his plan to '.$new_plan_details['name'];
                            $text = "\nNew Plan: ".$new_plan_details['name'].' ($'.$new_plan_details['month_price'].")\n";
                            $text .= 'Old Plan: '.$old_plan_details['name'].' ($'.$old_plan_details['year_price'].")\n";
                            $text .= 'Shop: '.$shop."\n";
                            $text .= 'Email: '.$shop_details['email']."\n";
                            $text .= 'Installed on: '.date('d M, Y', strtotime($shop_details->created_at))."\n";
                            $color = '#e83845';

                            get_slack_message($upgrageMessage, $title, $text, $color);

                            if (env('TEST_MAIL')) {
                                Mail::to($userData->email)->send(new DowngradedPlanMailTemplate($new_plan_details, $old_plan_details, $userData->email, $userData->identifier));
                            }
                        } else {
                            $downgradeMessage = ':sob: Shopify: '.config('services.shopify.name').', Plan is downgraded to '.$new_plan_details['name'].' (Monthly)';
                            $title = $names.' has downgraded his plan to '.$new_plan_details['name'];
                            $text = "\nNew Plan: ".$new_plan_details['name'].' ($'.$new_plan_details['month_price'].")\n";
                            $text .= 'Old Plan: '.$old_plan_details['name'].' ($'.$old_plan_details['month_price'].")\n";
                            $text .= 'Shop: '.$shop."\n";
                            $text .= 'Email: '.$shop_details['email']."\n";
                            $text .= 'Installed on: '.date('d M, Y', strtotime($shop_details->created_at))."\n";
                            $color = '#e83845';
                            get_slack_message($downgradeMessage, $title, $text, $color);

                            if (env('TEST_MAIL')) {
                                Mail::to($userData->email)->send(new DowngradedPlanMailTemplate($new_plan_details, $old_plan_details, $userData->email, $userData->identifier));
                            }
                        }
                    } else {
                        $old_plan_details = '';
                        $upgrageMessage = ':tada: Shopify: '.config('services.shopify.name').', Plan is upgraded to '.$new_plan_details['name'].' (Monthly)';
                        $title = $names.' has upgraded his plan to '.$new_plan_details['name'];
                        $text = "\nNew Plan: ".$new_plan_details['name'].' ($'.$new_plan_details['month_price'].")\n";
                        $text .= 'Shop: '.$shop."\n";
                        $text .= 'Email: '.$shop_details['email']."\n";
                        $text .= 'Installed on: '.date('d M, Y', strtotime($shop_details->created_at))."\n";
                        $color = '#ffce30';

                        get_slack_message($upgrageMessage, $title, $text, $color);

                        if (env('TEST_MAIL')) {
                            Mail::to($userData->email)->send(new SubscriptionMailTemplate($new_plan_details, $userData->email, $userData->identifier));
                        }
                    }

                    $resetAt = date('Y-m-d H:i:s', strtotime($activate['trial_ends_on'].'+30 days'));

                    $userData = AdminUser::find($shop_id);
                    $userData->charge_id = $charge_id;
                    $userData->plan_type = 'Monthly';
                    $userData->plan_id = $plan_id;
                    $userData->visitors = 0;
                    $userData->plan_created_at = now();
                    $userData->next_reset_date = $resetAt;
                    $userData->max_visitors_at = null;
                    $userData->current_charges = $price;
                    $userData->update();

                }

            } catch (ShopifyApiException $e) {
                echo '<pre>';
                print_r($e->getResponse());
                echo '</pre>';
                exit;
            }

            $response['status'] = 1;
            $response['message'] = 'Plan updated successfully';

        }

        return response()->json($response);
    }

}
