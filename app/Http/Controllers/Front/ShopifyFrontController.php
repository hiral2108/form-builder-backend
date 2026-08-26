<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Mail\LeadMailTemplate;
use App\Mail\LimitMailTemplate;
use App\Models\AdminUser;
use App\Models\Plan;
use App\Models\Submission;
use App\Models\Widget;
use App\Models\WidgetSetting;
use App\Models\WidgetView;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class ShopifyFrontController extends Controller
{
    public function get_forms_data(Request $request)
    {
        $response = [
            'status' => 0,
            'message' => 'Invalid Request, Please try again',
        ];

        $shopName = $request->shop_name;
        $shopUrl = $request->shop_url;
        $currentUrl = $request->current_url;
        $allWidgets = [];
        if (! empty($shopName)) {
            $userData = AdminUser::where('identifier', $shopName)->orWhere('shop_url', $shopName)->first();

            $planData = Plan::where('id', $userData->plan_id)->first();

            if (! empty($planData)) {
                if ($userData->visitors < $planData->visitors) {
                    $widgets = Widget::where('created_by', $userData->id)->where('is_deleted', 0)->where('widget_status', 1)->get();
                    foreach ($widgets as $key => $widget) {
                        $checkPageRules = $this->checkForPageRules($widget['unique_id'], $currentUrl, $shopUrl);
                        $widgetSetting = WidgetSetting::where('widget_id', $widget['unique_id'])->first();

                        if ($checkPageRules && isset($widgetSetting)) {
                            $widgetData = $this->set_defaults($widgetSetting);

                            $widgetData['id'] = $widget['unique_id'];
                            $widgetData['updated_at'] = strtotime($widget['updated_at']);

                            $allWidgets[] = $widgetData;
                        }
                    }
                }
            }

            $currentPlanDetail = Plan::where('id', $userData->plan_id)->first();
            if ($userData['is_sent_visitor_limit_mail'] == 0) {
                if (! empty($currentPlanDetail) && env('TEST_MAIL') == true) {
                    if ($userData->visitors == $planData->visitors) {
                        Mail::to($userData->email)->send(new LimitMailTemplate($userData, $currentPlanDetail, $userData->email, $userData->identifier));
                        $userData->is_sent_visitor_limit_mail = 1;
                        $userData->update();
                    }
                }
            }

        }

        $response['status'] = 1;
        $response['widget_data'] = $allWidgets;
        $response['message'] = 'widgets are loaded successfully';

        return response()->json($response, 200);
    }

    public function set_defaults($widget)
    {
        $defaultFormFieldSetting = get_default_form_field_setting();
        $defaultFormStyleSetting = get_default_form_style_setting();
        $defaultDisplayRuleSetting = get_default_display_rule_setting();
        $defaultSubmissionSetting = get_default_submission_setting();
        $defaultTimeDelaySetting = get_default_time_delay_setting();
        $defaultScrollBasedSetting = get_default_scroll_based_setting();
        $defaultPageRuleSetting = get_default_page_rule_setting();
        $defaultDateTimeSetting = get_default_date_time_setting();
        $defaultDayHourSetting = get_default_day_hour_setting();
        $defaultCountryRuleSetting = get_default_country_rule_setting();

        $formFieldSetting = json_decode($widget->form_field_setting, true);
        $formStyleSetting = json_decode($widget->form_style_setting, true);
        $displayRuleSetting = json_decode($widget->display_rule_setting, true);
        $submissionSetting = json_decode($widget->submission_setting, true);
        $timeDelaySetting = json_decode($widget->time_delay_setting, true);
        $scrollBasedSetting = json_decode($widget->scroll_based_setting, true);
        $pageRuleSetting = json_decode($widget->page_rule_setting, true);
        $dateTimeSetting = json_decode($widget->date_time_setting, true);
        $dayHourSetting = json_decode($widget->day_hour_setting, true);
        $countryRuleSetting = json_decode($widget->country_rule_setting, true);

        $widgetDataSetting = [];
        $widgetDataSetting['form_field_setting'] = shortcode_atts($defaultFormFieldSetting, $formFieldSetting);
        $widgetDataSetting['form_style_setting'] = shortcode_atts($defaultFormStyleSetting, $formStyleSetting);
        $widgetDataSetting['display_rule_setting'] = shortcode_atts($defaultDisplayRuleSetting, $displayRuleSetting);
        $widgetDataSetting['submission_setting'] = shortcode_atts($defaultSubmissionSetting, $submissionSetting);
        $widgetDataSetting['time_delay_setting'] = shortcode_atts($defaultTimeDelaySetting, $timeDelaySetting);
        $widgetDataSetting['scroll_based_setting'] = shortcode_atts($defaultScrollBasedSetting, $scrollBasedSetting);
        $widgetDataSetting['page_rule_setting'] = shortcode_atts($defaultPageRuleSetting, $pageRuleSetting);
        $widgetDataSetting['date_time_setting'] = shortcode_atts($defaultDateTimeSetting, $dateTimeSetting);
        $widgetDataSetting['day_hour_setting'] = shortcode_atts($defaultDayHourSetting, $dayHourSetting);
        $widgetDataSetting['country_rule_setting'] = shortcode_atts($defaultCountryRuleSetting, $countryRuleSetting);

        return $widgetDataSetting;
    }

    public function checkForPageRules($widgetId, $currentUrl, $shopUrl)
    {
        $widgetSettings = WidgetSetting::where('widget_id', $widgetId)->first();
        if (! empty($widgetSettings)) {
            $pageRuleSettings = json_decode($widgetSettings->page_rule_setting, true);
            if (! empty($pageRuleSettings) && $pageRuleSettings['has_page_rule'] == 1) {
                if (isset($pageRuleSettings['rule_setting']) && ! empty($pageRuleSettings['rule_setting'])) {
                    $pageRules = $pageRuleSettings['rule_setting'];
                    $url = $currentUrl;
                    $site_url = $shopUrl.'/';
                    $url = substr($url, strlen($site_url));
                    $url = trim($url, '/');

                    if (! empty($pageRules) && is_array($pageRules)) {
                        $is_shown_status = 1;
                        $is_hidden_status = 1;

                        foreach ($pageRules as $key => $rule) {
                            $rule_show_hide = $rule['rule_show_hide'];
                            if ($rule_show_hide == 'show_on') {
                                $is_shown_status = 0;
                                $link = trim(strtolower($rule['rule_value']));
                                $link = trim($link, '/');
                                $rule_type = $rule['url_rule'];
                                if ($link == '' && $url == '') {
                                    $is_shown_status = 1;
                                }

                                if (! empty($link) && ! empty($rule_type)) {
                                    switch ($rule_type) {
                                        /* checking for link is exists or not in URL */
                                        case 'contains':
                                            $index = strpos($url, $link);
                                            if ($index !== false) {
                                                $is_shown_status = 1;
                                            }
                                            break;
                                            /* checking for link is not exists in URL */
                                        case 'equal':
                                            if ($url === $link) {
                                                $is_shown_status = 1;
                                            }
                                            break;
                                            /* checking for link is exists in start or not */
                                        case 'begin':
                                            $length = strlen($link);
                                            $result = substr($url, 0, $length);
                                            if ($result == $link) {
                                                $is_shown_status = 1;
                                            }
                                            break;
                                            /* checking for link is exists in end or not */
                                        case 'end':
                                            $length = strlen($link);
                                            $result = substr($url, (-1) * $length);
                                            if ($result == $link) {
                                                $is_shown_status = 1;
                                            }
                                            break;
                                    }
                                }
                            }

                            if ($rule_show_hide == 'hide_on') {
                                $link = trim(strtolower($rule['rule_value']));
                                $link = trim($link, '/');
                                $rule_type = $rule['url_rule'];
                                if ($link == '' && $url == '') {

                                    $is_hidden_status = 0;
                                }

                                if (! empty($link) && ! empty($rule_type)) {
                                    switch ($rule_type) {
                                        /* checking for link is exists or not in URL */
                                        case 'contains':
                                            $index = strpos($url, $link);
                                            if ($index !== false) {
                                                $is_hidden_status = 0;
                                            }
                                            break;
                                            /* checking for link is not exists in URL */
                                        case 'equal':
                                            if ($url === $link) {
                                                $is_hidden_status = 0;
                                            }
                                            break;
                                            /* checking for link is exists in start or not */
                                        case 'begin':
                                            $length = strlen($link);
                                            $result = substr($url, 0, $length);
                                            if ($result == $link) {
                                                $is_hidden_status = 0;
                                            }
                                            break;
                                            /* checking for link is exists in end or not */
                                        case 'end':
                                            $length = strlen($link);
                                            $result = substr($url, (-1) * $length);
                                            if ($result == $link) {
                                                $is_hidden_status = 0;
                                            }
                                            break;
                                    }
                                }
                            }
                        }

                        if ($is_shown_status == 1 && $is_hidden_status == 1) {
                            return true;
                        } else {
                            return false;
                        }

                    }
                }
            } else {
                return true;
            }
        }

        return true;
    }

    /**
     * Submit form data and save to submissions table.
     */
    public function submit_form(Request $request): JsonResponse
    {
        $request->validate([
            'widget_id' => 'required|string',
            'form_fields' => 'present|array',
            'identifier' => 'nullable|string',
            'page_url' => 'nullable|string',
            'device' => 'nullable|string',
            'email_fields' => 'nullable|array',
        ]);

        $widget = Widget::where('unique_id', $request->widget_id)->first();

        if (! $widget) {
            return response()->json([
                'status' => 0,
                'message' => 'Widget not found',
            ], 404);
        }

        $device = $request->input('device');
        if ($device !== null) {
            $isMobile = $device === 'mobile';
        } else {
            $userAgent = $request->header('User-Agent', '');
            $isMobile = false;
            if (preg_match('/(android|bb\d+|meego).+mobile|avantgo|bada\/|blackberry|blazer|compal|elaine|fennec|hiptop|iemobile|ip(hone|od)|iris|kindle|lge |maemo|midp|mmp|mobile.+firefox|netfront|opera m(ob|in)i|palm( os)?|phone|p(ixi|re)\/|plucker|pocket|psp|series(4|6)0|symbian|treo|up\.(browser|link)|vodafone|wap|windows ce|xda|xiino/i', $userAgent)
                || preg_match('/1207|6310|6590|3gso|4thp|50[1-6]i|770s|802s|a wa|abac|ac(er|oo|s\-)|ai(ko|rn)|al(av|ca|co)|amoi|an(ex|ny|yw)|aptu|ar(ch|go)|as(te|us)|attw|au(di|\-m|r |s )|avan|be(ck|ll|nq)|bi(lb|rd)|bl(ac|az)|br(e|v)w|bumb|bw\-(n|u)|c55\/|capi|ccwa|cdm\-|cell|chtm|cldc|cmd\-|co(mp|nd)|craw|da(it|ll|ng)|dbte|dc\-s|devi|dica|dmob|do(c|p)o|ds(12|\-d)|el(49|ai)|em(l2|ul)|er(ic|k0)|esl8|ez([4-7]0|os|wa|ze)|fetc|fly(\-|_)|g1 u|g560|gene|gf\-5|g\-mo|go(\.w|od)|gr(ad|un)|haie|hcit|hd\-(m|p|t)|hei\-|hi(pt|ta)|hp( i|ip)|hs\-c|ht(c(\-| |_|a|g|p|s|t)|tp)|hu(aw|tc)|i\-(20|go|ma)|i230|iac( |\-|\/)|ibro|idea|ig01|ikom|im1k|inno|ipaq|iris|ja(t|v)a|jbro|jemu|jigs|kddi|keji|kgt( |\/)|klon|kpt |kwc\-|kyo(c|k)|le(no|xi)|lg( g|\/(k|l|u)|50|54|\-[a-w])|libw|lynx|m1\-w|m3ga|m50\/|ma(te|ui|xo)|mc(01|21|ca)|m\-cr|me(rc|ri)|mi(o8|oa|ts)|mmef|mo(01|02|bi|de|do|t(\-| |o|v)|zz)|mt(50|p1|v )|mwbp|mywa|n10[0-2]|n20[2-3]|n30(0|2)|n50(0|2|5)|n7(0(0|1)|10)|ne((c|m)\-|on|tf|wf|wg|wt)|nok(6|i)|nzph|o2im|op(ti|wv)|oran|owg1|p800|pan(a|d|t)|pdxg|pg(13|\-([1-8]|c))|phil|pire|pl(ay|uc)|pn\-2|po(ck|rt|se)|prox|psio|pt\-g|qa\-a|qc(07|12|21|32|60|\-[2-7]|i\-)|qtek|r380|r600|raks|rim9|ro(ve|zo)|s55\/|sa(ge|ma|mm|ms|ny|va)|sc(01|h\-|oo|p\-)|sdk\/|se(c(\-|0|1)|47|mc|nd|ri)|sgh\-|shar|sie(\-|m)|sk\-0|sl(id|b\-) |smart|sn(er|components)|so(ft|ny)|sp(01|h\-|v\-|v )|sy(01|mb)|t2(18|50)|t6(00|10|18)|ta(gt|lk)|tcl\-|tdg\-|tel(i|m)|tim\-|t\-mo|to(pl|sh)|ts(70|m\-|m3|m5)|tx\-9|up(\.b|g1|si)|utst|v400|v750|veri|vi(rg|te)|vk(40|5[0-3]|\-v)|vm40|voda|vulc|vx(52|53|60|61|70|80|81|83|85|98)|w3c(\-| )|webc|whit|wi(g |nc|nw)|wmlb|wonu|x700|yas\-|your|zeto|zte\-/i', substr($userAgent, 0, 4))) {
                $isMobile = true;
            }
        }

        // Fetch widget setting to get submission settings
        $widgetSetting = WidgetSetting::where('widget_id', $widget->unique_id)->first();
        $submissionSetting = [];
        if ($widgetSetting) {
            $submissionSetting = json_decode($widgetSetting->submission_setting, true) ?: [];
        }

        // Merge defaults
        $defaultSubmissionSetting = get_default_submission_setting();
        $submissionSetting = shortcode_atts($defaultSubmissionSetting, $submissionSetting);

        $saveToDatabase = $submissionSetting['saveToDatabase'] ?? true;
        $sendEmail = $submissionSetting['sendEmail'] ?? false;

        $submission = null;
        if ($saveToDatabase) {
            $submission = Submission::create([
                'fields' => $request->form_fields,
                'page_url' => $request->page_url,
                'widget_id' => $widget->id,
                'ip_address' => $request->ip(),
                'is_from_mobile' => (bool) $isMobile,
            ]);
        }

        if ($sendEmail) {
            $userData = AdminUser::find($widget->created_by);
            $storeIdentifier = $userData ? $userData->identifier : null;

            $sendTo = ! empty($submissionSetting['emailSettings']['sendToEmail'])
                ? $submissionSetting['emailSettings']['sendToEmail']
                : ($userData->email ?? null);

            if ($sendTo) {
                $emails = array_filter(array_map('trim', explode(',', $sendTo)));
                if (! empty($emails)) {
                    Mail::to($emails)->send(new LeadMailTemplate(
                        $submissionSetting['emailSettings'],
                        $request->input('email_fields', []),
                        $request->page_url,
                        $storeIdentifier
                    ));
                }
            }
        }

        return response()->json([
            'status' => 1,
            'message' => $saveToDatabase ? 'Submission saved successfully' : 'Submission processed successfully',
            'data' => $submission,
        ], 200);
    }

    public function update_visitors(Request $request)
    {
        $response = array(
            'status' => 0,
            'message' => '',
        );

        $shopData = AdminUser::where('identifier', $request->shop_url)->orWhere('shop_url', $request->shop_url)->first();

        $planData = Plan::where('id', $shopData->plan_id)->first();
        if ($shopData->visitors > $planData->visitors) {
            $updateVisitor = AdminUser::find($shopData->id);
            $updateVisitor->max_visitors_at = date('Y-m-d H:i:s');
            $updateVisitor->updated_at = date('Y-m-d H:i:s');
            $updateVisitor->update();
            $response['status'] = 1;
            $response['message'] = "You have to upgrade your plan";
        } else {
            $updateVisitor = AdminUser::find($shopData->id);
            $updateVisitor->visitors = $shopData->visitors + $request->visitor_count;
            $updateVisitor->updated_at = date('Y-m-d H:i:s');
            $updateVisitor->update();
            $response['status'] = 1;
            $response['message'] = "Visitors updated successfully";
        }

        return response()->json($response);
    }

    public function view_widget(Request $request)
    {
        $response = array(
            'status' => 0,
            'message' => ''
        );

        $shopData = AdminUser::where('identifier', $request->shop_url)->orWhere('shop_url', $request->shop_url)->first();
        $widgets = Widget::where('unique_id',$request->widget_id)->first();
        $widgetData = WidgetView::where('widget_id',$widgets->id)->whereDate('created_date', Carbon::today())->first();
        if(!empty($shopData)) {
            if(empty($widgetData)) {
                $newVisitor = new WidgetView;
                if($request->view == 1) {
                    $newVisitor->mobile_view = $newVisitor->mobile_view + $request->views;
                }else{
                    $newVisitor->desktop_view = $newVisitor->desktop_view + $request->views;
                }
                $newVisitor->created_date = date('Y-m-d H:i:s');
                $newVisitor->widget_id = $widgets->id;
                $newVisitor->save();
                $response['status'] = 1;
                $response['message'] = "Views created successfully";
            } else {
                $updateVisitor = WidgetView::find($widgetData->id);
                if($request->view == 1) {
                    $updateVisitor->mobile_view = $updateVisitor->mobile_view + $request->views;
                }else{
                    $updateVisitor->desktop_view = $updateVisitor->desktop_view + $request->views;
                }
                $updateVisitor->created_date = date('Y-m-d H:i:s');
                $updateVisitor->widget_id = $widgets->id;
                $updateVisitor->update();
                $response['status'] = 1;
                $response['message'] = "Views updated successfully";
            }
        }
        return response()->json($response);
    }

    public function click_widget(Request $request)
    {
        $response = array(
            'status' => 0,
            'message' => '',
        );
        // $shopData = AdminUser::where('shop_url',$request->shop_url)->first();
        $shopData = AdminUser::where('identifier', $request->shop_url)->orWhere('shop_url', $request->shop_url)->first();
        $widgets = Widget::where('unique_id',$request->widget_id)->first();
        $widgetData = WidgetView::where('widget_id',$widgets->id)->whereDate('created_date', Carbon::today())->first();

        if(!empty($shopData)) {
            if(empty($widgetData)) {
                $newVisitor = new WidgetView;
                if($request->view == 1) {
                    $newVisitor->mobile_click = $newVisitor->mobile_click + $request->clicks;
                }else{
                    $newVisitor->desktop_click = $newVisitor->desktop_click + $request->clicks;
                }
                $newVisitor->created_date = date('Y-m-d H:i:s');
                $newVisitor->widget_id = $widgets->id;
                $newVisitor->save();
                $response['status'] = 1;
                $response['message'] = "Clicks created successfully";
            }else {
                $updateVisitor = WidgetView::find($widgetData->id);
                if($request->view == 1) {
                    $updateVisitor->mobile_click = $updateVisitor->mobile_click + $request->clicks;
                }else{
                    $updateVisitor->desktop_click = $updateVisitor->desktop_click + $request->clicks;
                }
                $updateVisitor->created_date = date('Y-m-d H:i:s');
                $updateVisitor->widget_id = $widgets->id;
                $updateVisitor->update();
                $response['status'] = 1;
                $response['message'] = "Clicks updated successfully";
            }
        }
        return response()->json($response);
    }

}
