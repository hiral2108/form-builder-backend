<?php

namespace App\Http\Controllers\Users;

use App\Http\Controllers\Controller;
use App\Mail\FirstWidgetMailTemplate;
use App\Models\CtaImage;
use App\Models\EmailTemplate;
use App\Models\Widget;
use App\Models\WidgetSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class WidgetSettingController extends Controller
{
    public function save_widget_setting(Request $request)
    {

        if (! $request->filled('widget_id')) {
            return response()->json([
                'status' => 0,
                'message' => 'Form ID is required',
            ], 400);
        }

        $userData = $this->authUser($request);

        $widget = Widget::where('unique_id', $request->widget_id)
            ->where('user_id', $userData->id)
            ->where('is_deleted', 0)
            ->first();

        if (! $widget) {
            return response()->json([
                'status' => 0,
                'message' => 'Form not found or access denied',
            ], 200);
        }

        $formFieldSettings = $request->input('form_field_setting', []);
        if (! isset($formFieldSettings['fields']) || empty($formFieldSettings['fields'])) {
            return response()->json([
                'status' => 0,
                'message' => 'Please add at least one field',
            ], 200);
        }

        if (count($formFieldSettings['fields']) === 1 && isset($formFieldSettings['fields'][0]['type']) && $formFieldSettings['fields'][0]['type'] === 'hidden') {
            return response()->json([
                'status' => 0,
                'message' => 'Please add at least one field',
            ], 200);
        }

        // Update widget status and updated details
        $widget->widget_status = 1;
        $widget->updated_by = $userData->id;
        $widget->updated_at = now();
        $widget->update();

        // Encode settings to JSON
        $formFieldSettingsEncoded = json_encode($formFieldSettings);

        $formStyleSettings = json_encode($request->input('form_style_setting', []));

        $displayRuleSettings = $request->input('display_rule_setting', []);
        if (isset($displayRuleSettings['custom_cta_file']) && ! empty($displayRuleSettings['custom_cta_file'])) {
            $existingImage = CtaImage::where('img_name', $displayRuleSettings['custom_cta_file'])->where('shop_id', $userData->id)->first();

            if (empty($existingImage)) {
                $ctaImage = new CtaImage;
                $ctaImage->img_name = $displayRuleSettings['custom_cta_file'];
                $ctaImage->shop_id = $userData->id;
                $ctaImage->is_used = 1;
                $ctaImage->created_at = now();
                $ctaImage->updated_at = now();
                $ctaImage->save();
            } else {
                $ctaImage = CtaImage::find($existingImage->id);
                $ctaImage->is_used = 1;
                $ctaImage->updated_at = now();
                $ctaImage->update();
            }
        }
        $displayRuleSettings = json_encode($displayRuleSettings);

        $submissionSettings = json_encode($request->input('submission_setting', []));
        $timeDelaySettings = json_encode($request->input('time_delay_setting', []));
        $scrollBasedSettings = json_encode($request->input('scroll_based_setting', []));

        $data = [
            'form_field_setting' => $formFieldSettingsEncoded,
            'form_style_setting' => $formStyleSettings,
            'display_rule_setting' => $displayRuleSettings,
            'submission_setting' => $submissionSettings,
            'time_delay_setting' => $timeDelaySettings,
            'scroll_based_setting' => $scrollBasedSettings,
        ];

        //        if ($userData->plan_id > 1) {
        $data['page_rule_setting'] = json_encode($request->input('page_rule_setting', []));
        $data['date_time_setting'] = json_encode($request->input('date_time_setting', []));
        $data['day_hour_setting'] = json_encode($request->input('day_hour_setting', []));
        $data['country_rule_setting'] = json_encode($request->input('country_rule_setting', []));
        //        } else {
        //            $data['page_rule_setting'] = json_encode([]);
        //            $data['date_time_setting'] = json_encode([]);
        //            $data['day_hour_setting'] = json_encode([]);
        //            $data['country_rule_setting'] = json_encode([]);
        //        }

        $status = WidgetSetting::updateOrCreate(
            ['widget_id' => $widget->unique_id],
            $data
        );

        if ($status) {
            $totalWidgets = Widget::where('user_id', $userData->id)
                ->where('is_deleted', 0)
                ->count();

            if ($totalWidgets == 1 && $userData->widget_mail_status == 0) {
                $userData->widget_mail_status = 1;
                $userData->update();

                $email_templates = EmailTemplate::where('key', 'WIDGET_MAIL')->first();
                if (! empty($email_templates) && env('TEST_MAIL') && ! empty($userData->email) && filter_var($userData->email, FILTER_VALIDATE_EMAIL)) {
                    Mail::to($userData->email)->send(new FirstWidgetMailTemplate($email_templates, $userData->email, $userData->identifier));
                }
            }

            return response()->json([
                'status' => 1,
                'message' => 'Form settings saved successfully',
            ], 200);
        }

        return response()->json([
            'status' => 0,
            'message' => 'Invalid request, please try again later',
        ], 200);

    }

    public function get_widget_setting(Request $request)
    {
        if (! $request->filled('widget_id')) {
            return response()->json([
                'status' => 0,
                'message' => 'Form ID is required',
            ], 400);
        }

        $response = [
            'status' => 0,
            'message' => 'Invalid request, please try again later',
        ];

        $userData = $this->authUser($request);

        $widget = Widget::where('unique_id', $request->widget_id)
            ->where('user_id', $userData->id)
            ->where('is_deleted', 0)
            ->first();

        if (! $widget) {
            return response()->json([
                'status' => 0,
                'message' => 'Form not found or access denied',
            ], 403);
        }

        $widgetSetting = WidgetSetting::where('widget_id', $widget->unique_id)->first();

        if (! $widgetSetting) {
            return response()->json([
                'status' => 0,
                'message' => 'Form setting not found',
            ]);
        }

        $response = [
            'data' => [
                'form_field_setting' => json_decode($widgetSetting->form_field_setting, true),
                'form_style_setting' => json_decode($widgetSetting->form_style_setting, true),
                'display_rule_setting' => json_decode($widgetSetting->display_rule_setting, true),
                'submission_setting' => json_decode($widgetSetting->submission_setting, true),
                'time_delay_setting' => json_decode($widgetSetting->time_delay_setting, true),
                'scroll_based_setting' => json_decode($widgetSetting->scroll_based_setting, true),
                'page_rule_setting' => json_decode($widgetSetting->page_rule_setting, true),
                'date_time_setting' => json_decode($widgetSetting->date_time_setting, true),
                'day_hour_setting' => json_decode($widgetSetting->day_hour_setting, true),
                'country_rule_setting' => json_decode($widgetSetting->country_rule_setting, true),
            ],
            'status' => 1,
            'message' => 'Form data fetched successfully',
        ];

        return response()->json($response);
    }

    public function upload_image(Request $request)
    {
        $request->validate([
            'image' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:1024',
        ]);

        $userData = $this->authUser($request);

        $year = date('Y');
        $month = date('m');
        // Define the dynamic folder path
        $folderPath = public_path("uploads/{$year}/{$month}");

        // Check if the folder exists, if not, create it
        if (! file_exists($folderPath)) {
            mkdir($folderPath, 0755, true);
        }

        // Handle image file
        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $filename = Str::random(8).'-'.$file->getClientOriginalName();
            $file->move($folderPath, $filename);

            $filePath = env('APP_IMAGE_URL')."uploads/{$year}/{$month}/{$filename}";

            $ctaImage = new CtaImage;
            $ctaImage->img_name = $filename;
            $ctaImage->shop_id = $userData->id;
            $ctaImage->is_used = 0;
            $ctaImage->created_at = now();
            $ctaImage->updated_at = now();
            $ctaImage->save();

            return response()->json(['message' => 'Image uploaded successfully', 'status' => 1, 'image' => $filename, 'fullPath' => $filePath]);
        }

        return response()->json(['message' => 'No file uploaded'], 400);
    }

    public function remove_image(Request $request)
    {
        $userData = $this->authUser($request);

        $existingImage = CtaImage::where('img_name', $request->image_name)->where('shop_id', $userData->id)->first();
        if (! $existingImage) {
            return response()->json([
                'status' => 0,
                'message' => 'Image not found',
            ], 404);
        }

        $existingImage->is_used = 0;
        $existingImage->updated_at = now();
        $existingImage->update();

        return response()->json([
            'status' => 1,
            'message' => 'Image removed successfully',
        ]);
    }
}
