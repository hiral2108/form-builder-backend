<?php

namespace App\Http\Controllers\Users;

use App\Http\Controllers\Controller;
use App\Libraries\Shopifyapi;
use App\Libraries\ShopifyApiException;
use App\Libraries\ShopifyRest;
use App\Mail\DowngradedPlanMailTemplate;
use App\Mail\SubscriptionMailTemplate;
use App\Mail\UpgradedPlanMailTemplate;
use App\Models\AdminUser;
use App\Models\Plan;
use App\Models\RecurringPlanCharge;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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

        $userData = $this->authUser($request);

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
                    // Best-effort cleanup of the old recurring charge — Shopify often
                    // returns an error if it was already cancelled/expired on their
                    // side, which shouldn't block the merchant from downgrading.
                    Log::warning('Failed to cancel previous Shopify recurring charge during downgrade', [
                        'shop' => $shop,
                        'charge_id' => $userData->charge_id,
                        'response' => $e->getResponse(),
                    ]);
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

                    if (isset($userData['id']) && ! empty($userData['email']) && config('services.slack.test_mail', env('TEST_MAIL'))) {
                        try {
                            Mail::to($userData->email)->send(new DowngradedPlanMailTemplate($plan_details, $plan_name, $old_plan_details->name ?? null, $userData->email, $userData->identifier));
                        } catch (\Throwable $e) {
                            \Illuminate\Support\Facades\Log::error('Mail sending error in change_plan downgrade: '.$e->getMessage());
                        }
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

                    if (isset($userData['id']) && ! empty($userData['email']) && config('services.slack.test_mail', env('TEST_MAIL'))) {
                        try {
                            Mail::to($userData->email)->send(new UpgradedPlanMailTemplate($plan_details, $plan_name, $old_plan_details->name ?? null, $userData->email, $userData->identifier));
                        } catch (\Throwable $e) {
                            \Illuminate\Support\Facades\Log::error('Mail sending error in change_plan upgrade: '.$e->getMessage());
                        }
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

                if (isset($userData['id']) && ! empty($userData['email']) && config('services.slack.test_mail', env('TEST_MAIL'))) {
                    try {
                        Mail::to($userData->email)->send(new SubscriptionMailTemplate($plan_details, $plan_name, null, $userData->email, $userData->identifier));
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::error('Mail sending error in change_plan subscription: '.$e->getMessage());
                    }
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
                    Log::error('Failed to create Shopify recurring charge', [
                        'shop' => $shop,
                        'plan_id' => $plan_id,
                        'response' => $e->getResponse(),
                    ]);

                    return response()->json([
                        'status' => 0,
                        'message' => 'Unable to start checkout with Shopify. Please try again.',
                    ], 422);
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
        $charge_id = $request->post('chargeId');

        if (!$plan_secret_key || !$charge_id) {
            $response['message'] = 'Missing charge details or secret key';
            return response()->json($response);
        }

        $userData = AdminUser::where('plan_secret_key', $plan_secret_key)->first();
        if (!$userData) {
            $response['message'] = 'User not found for this plan request';
            return response()->json($response);
        }

        // The plan_secret_key alone isn't enough to trust this request — it's
        // embedded in Shopify's billing redirect URL and could leak via
        // referrer headers or logs. Require it to also match the account of
        // whoever is actually authenticated on this request.
        if ($this->authUser($request)->id !== $userData->id) {
            return response()->json(['status' => 0, 'message' => 'Unauthorized'], 401);
        }

        $shop = $userData->shop_url;
        $token = $userData->token;

        try {
            $params = [
                'shop_domain' => $shop,
                'token' => $token,
                'api_key' => config('services.shopify.key'),
                'secret' => config('services.shopify.secret'),
            ];
            $shopifyapi = new Shopifyapi($params);

            $charges = $shopifyapi->call('GET', '/admin/api/'.config('services.shopify.version').'/recurring_application_charges/'.$charge_id.'.json');
            if (!$charges || !isset($charges['status'])) {
                $response['message'] = 'Unable to retrieve charge details from Shopify';
                return response()->json($response);
            }

            $charge_plan = Plan::where('name', $charges['name'])->first();
            $charge_plan_id = $charge_plan ? $charge_plan->id : 2;

            if (in_array($charges['status'], ['declined', 'cancelled'])) {
                RecurringPlanCharge::where('store', $userData->id)->where('charge_id', $charge_id)->update(['is_deleted' => 1]);
                $response['status'] = 0;
                $response['message'] = 'Charge was declined or cancelled';
                return response()->json($response);
            }

            $activated = $charges;
            if ($charges['status'] == 'accepted') {
                $data = ['recurring_application_charge' => $charges];
                $activated = $shopifyapi->call('POST', '/admin/api/'.config('services.shopify.version').'/recurring_application_charges/'.$charge_id.'/activate.json', $data);
            }

            $finalStatus = $activated['status'] ?? $charges['status'];
            if (in_array($finalStatus, ['active', 'accepted'])) {
                try {
                    $resource_feedback['resource_feedback'] = [
                        'state' => 'success',
                        'feedback_generated_at' => date('Y-m-d\TH:i:s.u'),
                    ];
                    $shopifyapi->call('POST', '/admin/api/'.config('services.shopify.version').'/resource_feedback.json', $resource_feedback);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::info('Resource feedback skipped: '.$e->getMessage());
                }

                $shop_details = $userData;
                $current_plan = $shop_details->plan_id;
                $names = $shop_details->shop_owner_name;
                $plan_id = $charge_plan_id;
                $new_plan_details = Plan::where('id', $plan_id)->first() ?? (object)['name' => $charges['name'], 'year_price' => $charges['price']];

                if ($shop_details->plan_id != 0) {
                    $old_plan_details = Plan::where('id', $shop_details->plan_id)->first();
                    if ($current_plan < $plan_id) {
                        $upgrageMessage = ':tada: Shopify: '.config('services.shopify.name').', Plan is upgraded to '.$new_plan_details->name.' (Yearly)';
                        $title = $names.' has upgraded his plan to '.$new_plan_details->name;
                        $text = "\nNew Plan: ".$new_plan_details->name.' ($'.$new_plan_details->year_price.")\n";
                        $text .= 'Old Plan: '.($old_plan_details['name'] ?? '').' ($'.($old_plan_details['year_price'] ?? '').")\n";
                        $text .= 'Shop: '.$shop."\n";
                        $text .= 'Email: '.$shop_details['email']."\n";
                        $text .= 'Installed on: '.date('d M, Y', strtotime($shop_details->created_at))."\n";
                        $color = '#ffce30';
                        get_slack_message($upgrageMessage, $title, $text, $color);

                        if (config('services.slack.test_mail', env('TEST_MAIL')) && !empty($userData->email)) {
                            try {
                                Mail::to($userData->email)->send(new UpgradedPlanMailTemplate($new_plan_details, $new_plan_details->name, $old_plan_details->name ?? null, $userData->email, $userData->identifier));
                            } catch (\Throwable $e) {
                                \Illuminate\Support\Facades\Log::error('Mail sending error in annual upgrade: '.$e->getMessage());
                            }
                        }
                    } elseif ($current_plan == $plan_id) {
                        $upgrageMessage = ':tada: Shopify: '.config('services.shopify.name').', Plan is upgraded to '.$new_plan_details->name.' (Yearly)';
                        $title = $names.' has upgraded his plan to '.$new_plan_details->name;
                        $text = "\nNew Plan: ".$new_plan_details->name.' ($'.$new_plan_details->year_price.")\n";
                        $text .= 'Old Plan: '.($old_plan_details['name'] ?? '').' ($'.($old_plan_details['month_price'] ?? '').")\n";
                        $text .= 'Shop: '.$shop."\n";
                        $text .= 'Email: '.$shop_details['email']."\n";
                        $text .= 'Installed on: '.date('d M, Y', strtotime($shop_details->created_at))."\n";
                        $color = '#ffce30';
                        get_slack_message($upgrageMessage, $title, $text, $color);

                        if (config('services.slack.test_mail', env('TEST_MAIL')) && !empty($userData->email)) {
                            try {
                                Mail::to($userData->email)->send(new UpgradedPlanMailTemplate($new_plan_details, $new_plan_details->name, $old_plan_details->name ?? null, $userData->email, $userData->identifier));
                            } catch (\Throwable $e) {
                                \Illuminate\Support\Facades\Log::error('Mail sending error in annual switch: '.$e->getMessage());
                            }
                        }
                    } else {
                        $downgradeMessage = ':sob: Shopify: '.config('services.shopify.name').', Plan is downgraded to '.$new_plan_details->name.' (Yearly)';
                        $title = $names.' has downgraded his plan to '.$new_plan_details->name;
                        $text = "\nNew Plan: ".$new_plan_details->name.' ($'.$new_plan_details->year_price.")\n";
                        $text .= 'Old Plan: '.($old_plan_details['name'] ?? '').' ($'.($old_plan_details['year_price'] ?? '').")\n";
                        $text .= 'Shop: '.$shop."\n";
                        $text .= 'Email: '.$shop_details['email']."\n";
                        $text .= 'Installed on: '.date('d M, Y', strtotime($shop_details->created_at))."\n";
                        $color = '#e83845';
                        get_slack_message($downgradeMessage, $title, $text, $color);

                        if (config('services.slack.test_mail', env('TEST_MAIL')) && !empty($userData->email)) {
                            try {
                                Mail::to($userData->email)->send(new DowngradedPlanMailTemplate($new_plan_details, $new_plan_details->name, $old_plan_details->name ?? null, $userData->email, $userData->identifier));
                            } catch (\Throwable $e) {
                                \Illuminate\Support\Facades\Log::error('Mail sending error in annual downgrade: '.$e->getMessage());
                            }
                        }
                    }
                } else {
                    $upgrageMessage = ':tada: Shopify: '.config('services.shopify.name').', Plan is upgraded to '.$new_plan_details->name.' (Yearly)';
                    $title = $names.' has upgraded his plan to '.$new_plan_details->name;
                    $text = "\nNew Plan: ".$new_plan_details->name.' ($'.$new_plan_details->year_price.")\n";
                    $text .= 'Shop: '.$shop."\n";
                    $text .= 'Email: '.$shop_details['email']."\n";
                    $text .= 'Installed on: '.date('d M, Y', strtotime($shop_details->created_at))."\n";
                    $color = '#ffce30';
                    get_slack_message($upgrageMessage, $title, $text, $color);

                    if (config('services.slack.test_mail', env('TEST_MAIL')) && !empty($userData->email)) {
                        try {
                            Mail::to($userData->email)->send(new SubscriptionMailTemplate($new_plan_details, $new_plan_details->name, null, $userData->email, $userData->identifier));
                        } catch (\Throwable $e) {
                            \Illuminate\Support\Facades\Log::error('Mail sending error in annual new subscription: '.$e->getMessage());
                        }
                    }
                }

                if (!empty($shop_details->charge_id) && $shop_details->charge_id != '0' && $shop_details->charge_id != $charge_id) {
                    try {
                        $shopifyapi->call('DELETE', '/admin/api/'.config('services.shopify.version').'/recurring_application_charges/'.$shop_details->charge_id.'.json');
                        RecurringPlanCharge::where('store', $shop_details->id)->where('charge_id', $shop_details->charge_id)->update(['is_deleted' => 1]);
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::warning('Old charge delete error: '.$e->getMessage());
                    }
                }

                date_default_timezone_set('UTC');
                $recCharge = RecurringPlanCharge::firstOrNew(['charge_id' => $charges['id']]);
                $recCharge->store = $shop_details->id;
                $recCharge->charge_id = $charges['id'];
                $recCharge->plan_id = $charge_plan_id;
                $recCharge->api_client_id = $charges['api_client_id'] ?? null;
                $recCharge->plan_type = 'Yearly';
                $recCharge->price = $charges['price'];
                $recCharge->status = $activated['status'] ?? 'active';
                $recCharge->return_url = $charges['return_url'] ?? '';
                $recCharge->billing_on = now();
                $recCharge->test = $charges['test'] ?? 0;
                $recCharge->activated_on = $activated['activated_on'] ?? now();
                $recCharge->trial_ends_on = $activated['trial_ends_on'] ?? null;
                $recCharge->cancelled_on = $activated['cancelled_on'] ?? null;
                $recCharge->trial_days = $charges['trial_days'] ?? 0;
                $recCharge->decorated_return_url = $charges['decorated_return_url'] ?? null;
                $recCharge->confirmation_url = $charges['confirmation_url'] ?? '';
                $recCharge->created_date = date('Y-m-d H:i:s');
                $recCharge->updated_date = date('Y-m-d H:i:s');
                $recCharge->save();

                $trialEnds = !empty($activated['trial_ends_on']) ? $activated['trial_ends_on'] : now();
                $resetAt = date('Y-m-d H:i:s', strtotime($trialEnds.' +1 year'));

                $userData->charge_id = $charge_id;
                $userData->plan_type = 'Yearly';
                $userData->plan_id = $plan_id;
                $userData->visitors = 0;
                $userData->plan_created_at = now();
                $userData->next_reset_date = $resetAt;
                $userData->max_visitors_at = null;
                $userData->current_charges = $charges['price'];
                $userData->save();

                $response['status'] = 1;
                $response['message'] = 'Plan updated successfully';
            } else {
                $response['status'] = 0;
                $response['message'] = 'Charge was declined or cancelled';
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Annual charge error: '.$e->getMessage());
            $response['status'] = 0;
            $response['message'] = $e->getMessage();
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
        $charge_id = $request->post('chargeId');

        if (!$plan_secret_key || !$charge_id) {
            $response['message'] = 'Missing charge details or secret key';
            return response()->json($response);
        }

        $userData = AdminUser::where('plan_secret_key', $plan_secret_key)->first();
        if (!$userData) {
            $response['message'] = 'User not found for this plan request';
            return response()->json($response);
        }

        // The plan_secret_key alone isn't enough to trust this request — it's
        // embedded in Shopify's billing redirect URL and could leak via
        // referrer headers or logs. Require it to also match the account of
        // whoever is actually authenticated on this request.
        if ($this->authUser($request)->id !== $userData->id) {
            return response()->json(['status' => 0, 'message' => 'Unauthorized'], 401);
        }

        $shop = $userData->shop_url;
        $token = $userData->token;

        try {
            $params = [
                'shop_domain' => $shop,
                'token' => $token,
                'api_key' => config('services.shopify.key'),
                'secret' => config('services.shopify.secret'),
            ];
            $shopifyapi = new Shopifyapi($params);

            $charges = $shopifyapi->call('GET', '/admin/api/'.config('services.shopify.version').'/recurring_application_charges/'.$charge_id.'.json');
            if (!$charges || !isset($charges['status'])) {
                $response['message'] = 'Unable to retrieve charge details from Shopify';
                return response()->json($response);
            }

            $charge_plan = Plan::where('name', $charges['name'])->first();
            $charge_plan_id = $charge_plan ? $charge_plan->id : 2;

            if (in_array($charges['status'], ['declined', 'cancelled'])) {
                RecurringPlanCharge::where('store', $userData->id)->where('charge_id', $charge_id)->update(['is_deleted' => 1]);
                $response['status'] = 0;
                $response['message'] = 'Charge was declined or cancelled';
                return response()->json($response);
            }

            $activated = $charges;
            if ($charges['status'] == 'accepted') {
                $data = ['recurring_application_charge' => $charges];
                $activated = $shopifyapi->call('POST', '/admin/api/'.config('services.shopify.version').'/recurring_application_charges/'.$charge_id.'/activate.json', $data);
            }

            $finalStatus = $activated['status'] ?? $charges['status'];
            if (in_array($finalStatus, ['active', 'accepted'])) {
                try {
                    $resource_feedback['resource_feedback'] = [
                        'state' => 'success',
                        'feedback_generated_at' => date('Y-m-d\TH:i:s.u'),
                    ];
                    $shopifyapi->call('POST', '/admin/api/'.config('services.shopify.version').'/resource_feedback.json', $resource_feedback);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::info('Resource feedback skipped: '.$e->getMessage());
                }

                $shop_details = $userData;
                $current_plan = $shop_details->plan_id;
                $names = $shop_details->shop_owner_name;
                $plan_id = $charge_plan_id;
                $new_plan_details = Plan::where('id', $plan_id)->first() ?? (object)['name' => $charges['name'], 'month_price' => $charges['price']];

                if ($shop_details->plan_id != 0) {
                    $old_plan_details = Plan::where('id', $shop_details->plan_id)->first();
                    if ($current_plan < $plan_id || $current_plan == 0) {
                        $upgrageMessage = ':tada: Shopify: '.config('services.shopify.name').', Plan is upgraded to '.$new_plan_details->name.' (Monthly)';
                        $title = $names.' has upgraded his plan to '.$new_plan_details->name;
                        $text = "\nNew Plan: ".$new_plan_details->name.' ($'.$new_plan_details->month_price.")\n";
                        $text .= 'Old Plan: '.($old_plan_details['name'] ?? '').' ($'.($old_plan_details['month_price'] ?? '').")\n";
                        $text .= 'Shop: '.$shop."\n";
                        $text .= 'Email: '.$shop_details['email']."\n";
                        $text .= 'Installed on: '.date('d M, Y', strtotime($shop_details->created_at))."\n";
                        $color = '#ffce30';
                        get_slack_message($upgrageMessage, $title, $text, $color);

                        if (config('services.slack.test_mail', env('TEST_MAIL')) && !empty($userData->email)) {
                            try {
                                Mail::to($userData->email)->send(new UpgradedPlanMailTemplate($new_plan_details, $new_plan_details->name, $old_plan_details->name ?? null, $userData->email, $userData->identifier));
                            } catch (\Throwable $e) {
                                \Illuminate\Support\Facades\Log::error('Mail sending error in month upgrade: '.$e->getMessage());
                            }
                        }
                    } elseif ($current_plan == $plan_id) {
                        $downgradeMessage = ':sob: Shopify: '.config('services.shopify.name').', Plan is downgraded to '.$new_plan_details->name.' (Monthly)';
                        $title = $names.' has downgraded his plan to '.$new_plan_details->name;
                        $text = "\nNew Plan: ".$new_plan_details->name.' ($'.$new_plan_details->month_price.")\n";
                        $text .= 'Old Plan: '.($old_plan_details['name'] ?? '').' ($'.($old_plan_details['year_price'] ?? '').")\n";
                        $text .= 'Shop: '.$shop."\n";
                        $text .= 'Email: '.$shop_details['email']."\n";
                        $text .= 'Installed on: '.date('d M, Y', strtotime($shop_details->created_at))."\n";
                        $color = '#e83845';
                        get_slack_message($downgradeMessage, $title, $text, $color);

                        if (config('services.slack.test_mail', env('TEST_MAIL')) && !empty($userData->email)) {
                            try {
                                Mail::to($userData->email)->send(new DowngradedPlanMailTemplate($new_plan_details, $new_plan_details->name, $old_plan_details->name ?? null, $userData->email, $userData->identifier));
                            } catch (\Throwable $e) {
                                \Illuminate\Support\Facades\Log::error('Mail sending error in month switch: '.$e->getMessage());
                            }
                        }
                    } else {
                        $downgradeMessage = ':sob: Shopify: '.config('services.shopify.name').', Plan is downgraded to '.$new_plan_details->name.' (Monthly)';
                        $title = $names.' has downgraded his plan to '.$new_plan_details->name;
                        $text = "\nNew Plan: ".$new_plan_details->name.' ($'.$new_plan_details->month_price.")\n";
                        $text .= 'Old Plan: '.($old_plan_details['name'] ?? '').' ($'.($old_plan_details['month_price'] ?? '').")\n";
                        $text .= 'Shop: '.$shop."\n";
                        $text .= 'Email: '.$shop_details['email']."\n";
                        $text .= 'Installed on: '.date('d M, Y', strtotime($shop_details->created_at))."\n";
                        $color = '#e83845';
                        get_slack_message($downgradeMessage, $title, $text, $color);

                        if (config('services.slack.test_mail', env('TEST_MAIL')) && !empty($userData->email)) {
                            try {
                                Mail::to($userData->email)->send(new DowngradedPlanMailTemplate($new_plan_details, $new_plan_details->name, $old_plan_details->name ?? null, $userData->email, $userData->identifier));
                            } catch (\Throwable $e) {
                                \Illuminate\Support\Facades\Log::error('Mail sending error in month downgrade: '.$e->getMessage());
                            }
                        }
                    }
                } else {
                    $upgrageMessage = ':tada: Shopify: '.config('services.shopify.name').', Plan is upgraded to '.$new_plan_details->name.' (Monthly)';
                    $title = $names.' has upgraded his plan to '.$new_plan_details->name;
                    $text = "\nNew Plan: ".$new_plan_details->name.' ($'.$new_plan_details->month_price.")\n";
                    $text .= 'Shop: '.$shop."\n";
                    $text .= 'Email: '.$shop_details['email']."\n";
                    $text .= 'Installed on: '.date('d M, Y', strtotime($shop_details->created_at))."\n";
                    $color = '#ffce30';
                    get_slack_message($upgrageMessage, $title, $text, $color);

                    if (config('services.slack.test_mail', env('TEST_MAIL')) && !empty($userData->email)) {
                        try {
                            Mail::to($userData->email)->send(new SubscriptionMailTemplate($new_plan_details, $new_plan_details->name, null, $userData->email, $userData->identifier));
                        } catch (\Throwable $e) {
                            \Illuminate\Support\Facades\Log::error('Mail sending error in month new subscription: '.$e->getMessage());
                        }
                    }
                }

                if (!empty($shop_details->charge_id) && $shop_details->charge_id != '0' && $shop_details->charge_id != $charge_id) {
                    try {
                        $shopifyapi->call('DELETE', '/admin/api/'.config('services.shopify.version').'/recurring_application_charges/'.$shop_details->charge_id.'.json');
                        RecurringPlanCharge::where('store', $shop_details->id)->where('charge_id', $shop_details->charge_id)->update(['is_deleted' => 1]);
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::warning('Old charge delete error: '.$e->getMessage());
                    }
                }

                date_default_timezone_set('UTC');
                $recCharge = RecurringPlanCharge::firstOrNew(['charge_id' => $charges['id']]);
                $recCharge->store = $shop_details->id;
                $recCharge->charge_id = $charges['id'];
                $recCharge->plan_id = $charge_plan_id;
                $recCharge->api_client_id = $charges['api_client_id'] ?? null;
                $recCharge->plan_type = 'Monthly';
                $recCharge->price = $charges['price'];
                $recCharge->status = $activated['status'] ?? 'active';
                $recCharge->return_url = $charges['return_url'] ?? '';
                $recCharge->billing_on = now();
                $recCharge->test = $charges['test'] ?? 0;
                $recCharge->activated_on = $activated['activated_on'] ?? now();
                $recCharge->trial_ends_on = $activated['trial_ends_on'] ?? null;
                $recCharge->cancelled_on = $activated['cancelled_on'] ?? null;
                $recCharge->trial_days = $charges['trial_days'] ?? 0;
                $recCharge->decorated_return_url = $charges['decorated_return_url'] ?? null;
                $recCharge->confirmation_url = $charges['confirmation_url'] ?? '';
                $recCharge->created_date = date('Y-m-d H:i:s');
                $recCharge->updated_date = date('Y-m-d H:i:s');
                $recCharge->save();

                $trialEnds = !empty($activated['trial_ends_on']) ? $activated['trial_ends_on'] : now();
                $resetAt = date('Y-m-d H:i:s', strtotime($trialEnds.' +30 days'));

                $userData->charge_id = $charge_id;
                $userData->plan_type = 'Monthly';
                $userData->plan_id = $plan_id;
                $userData->visitors = 0;
                $userData->plan_created_at = now();
                $userData->next_reset_date = $resetAt;
                $userData->max_visitors_at = null;
                $userData->current_charges = $charges['price'];
                $userData->save();

                $response['status'] = 1;
                $response['message'] = 'Plan updated successfully';
            } else {
                $response['status'] = 0;
                $response['message'] = 'Charge was declined or cancelled';
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Monthly charge error: '.$e->getMessage());
            $response['status'] = 0;
            $response['message'] = $e->getMessage();
        }

        return response()->json($response);
    }

}
