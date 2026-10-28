<?php

namespace App\Http\Controllers\Users;

use App\Http\Controllers\Controller;
use App\Models\Widget;
use App\Models\WidgetSetting;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WidgetListController extends Controller
{
    public function create_form(Request $request)
    {
        $response = [
            'status' => 0,
            'message' => 'Invalid request, please try again later',
        ];

        $userData = $this->authUser($request);

        $widgetTitle = $request->title;
        if (! $widgetTitle) {
            return response()->json([
                'status' => 0,
                'message' => 'Form title is required',
            ]);
        }

        $widget = new Widget;
        $widget->title = $widgetTitle;
        $widget->unique_id = Str::uuid();
        $widget->widget_status = 0;
        $widget->user_id = $userData->id;
        $widget->created_by = $userData->id;
        $widget->created_at = now();
        $widget->save();

        $response = [
            'status' => 1,
            'form_id' => $widget->unique_id,
            'message' => 'Form created successfully',
        ];

        return response()->json($response);
    }

    public function get_form_list(Request $request)
    {
        $response = [
            'status' => 0,
            'message' => 'Invalid request, please try again later',
        ];

        $userData = $this->authUser($request);

        $time = $request->filter ?? $request->time;
        if (! empty($time) && isset($time)) {
            if ($time == 'today') {
                $widgetData = Widget::leftJoin('widget_settings', function ($join) {
                    $join->on('widgets.unique_id', '=', 'widget_settings.widget_id');
                })
                    ->leftJoin('widget_views', function ($join) {
                        $join->on('widgets.id', '=', 'widget_views.widget_id')
                            ->whereDate('widget_views.created_date', Carbon::today());
                    })
                    ->where('widgets.user_id', $userData->id)
                    ->where('widgets.is_deleted', 0)
                    ->select(
                        'widgets.id',
                        'widgets.widget_status',
                        'widgets.title',
                        'widgets.unique_id',
                        'widgets.created_at',

                        DB::raw('COALESCE(SUM(widget_views.mobile_view),0) as mobile_view'),
                        DB::raw('COALESCE(SUM(widget_views.desktop_view),0) as desktop_view'),
                        DB::raw('COALESCE(SUM(widget_views.mobile_click),0) as mobile_click'),
                        DB::raw('COALESCE(SUM(widget_views.desktop_click),0) as desktop_click'),
                        DB::raw('(SELECT COUNT(*) FROM submissions WHERE submissions.widget_id = widgets.id AND DATE(submissions.created_at) = "'.Carbon::today()->toDateString().'") as total_submissions')
                    )
                    ->groupBy(
                        'widgets.id',
                        'widgets.widget_status',
                        'widgets.title',
                        'widgets.unique_id',
                        'widgets.created_at'
                    )
                    ->orderBy('widgets.created_at', 'desc')
                    ->paginate(config('global.pagination_limit'));
            } elseif ($time == 'yesterday') {
                $widgetData = Widget::leftJoin('widget_settings', function ($join) {
                    $join->on('widgets.unique_id', '=', 'widget_settings.widget_id');
                })
                    ->leftJoin('widget_views', function ($join) {
                        $join->on('widgets.id', '=', 'widget_views.widget_id')
                            ->whereDate('widget_views.created_date', Carbon::yesterday());
                    })
                    ->where('widgets.user_id', $userData->id)
                    ->where('widgets.is_deleted', 0)
                    ->select(
                        'widgets.id',
                        'widgets.widget_status',
                        'widgets.title',
                        'widgets.unique_id',
                        'widgets.created_at',

                        DB::raw('COALESCE(SUM(widget_views.mobile_view),0) as mobile_view'),
                        DB::raw('COALESCE(SUM(widget_views.desktop_view),0) as desktop_view'),
                        DB::raw('COALESCE(SUM(widget_views.mobile_click),0) as mobile_click'),
                        DB::raw('COALESCE(SUM(widget_views.desktop_click),0) as desktop_click'),
                        DB::raw('(SELECT COUNT(*) FROM submissions WHERE submissions.widget_id = widgets.id AND DATE(submissions.created_at) = "'.Carbon::yesterday()->toDateString().'") as total_submissions')
                    )
                    ->groupBy(
                        'widgets.id',
                        'widgets.widget_status',
                        'widgets.title',
                        'widgets.unique_id',
                        'widgets.created_at'
                    )
                    ->orderBy('widgets.created_at', 'desc')
                    ->paginate(config('global.pagination_limit'));
            } elseif ($time == 'this_week') {
                $endDate = date('Y-m-d 23:59:59');
                $startDate = date('Y-m-d 00:00:00', strtotime('this week'));

                $widgetData = Widget::leftJoin('widget_settings', function ($join) {
                    $join->on('widgets.unique_id', '=', 'widget_settings.widget_id');
                })
                    ->leftJoin('widget_views', function ($join) use ($startDate, $endDate) {
                        $join->on('widgets.id', '=', 'widget_views.widget_id')
                            ->whereBetween('widget_views.created_date', [$startDate, $endDate]);
                    })
                    ->where('widgets.user_id', $userData->id)
                    ->where('widgets.is_deleted', 0)
                    ->select(
                        'widgets.id',
                        'widgets.widget_status',
                        'widgets.title',
                        'widgets.unique_id',
                        'widgets.created_at',

                        DB::raw('COALESCE(SUM(widget_views.mobile_view),0) as mobile_view'),
                        DB::raw('COALESCE(SUM(widget_views.desktop_view),0) as desktop_view'),
                        DB::raw('COALESCE(SUM(widget_views.mobile_click),0) as mobile_click'),
                        DB::raw('COALESCE(SUM(widget_views.desktop_click),0) as desktop_click'),
                        DB::raw('(SELECT COUNT(*) FROM submissions WHERE submissions.widget_id = widgets.id AND submissions.created_at BETWEEN "'.$startDate.'" AND "'.$endDate.'") as total_submissions')
                    )
                    ->groupBy(
                        'widgets.id',
                        'widgets.widget_status',
                        'widgets.title',
                        'widgets.unique_id',
                        'widgets.created_at'
                    )
                    ->orderBy('widgets.created_at', 'desc')
                    ->paginate(config('global.pagination_limit'));
            } elseif ($time == 'last_7_days') {
                $endDate = date('Y-m-d 23:59:59');
                $startDate = date('Y-m-d 00:00:00', strtotime('-7 days'));

                $widgetData = Widget::leftJoin('widget_settings', function ($join) {
                    $join->on('widgets.unique_id', '=', 'widget_settings.widget_id');
                })
                    ->leftJoin('widget_views', function ($join) use ($startDate, $endDate) {
                        $join->on('widgets.id', '=', 'widget_views.widget_id')
                            ->whereBetween('widget_views.created_date', [$startDate, $endDate]);
                    })
                    ->where('widgets.user_id', $userData->id)
                    ->where('widgets.is_deleted', 0)
                    ->select(
                        'widgets.id',
                        'widgets.widget_status',
                        'widgets.title',
                        'widgets.unique_id',
                        'widgets.created_at',

                        DB::raw('COALESCE(SUM(widget_views.mobile_view),0) as mobile_view'),
                        DB::raw('COALESCE(SUM(widget_views.desktop_view),0) as desktop_view'),
                        DB::raw('COALESCE(SUM(widget_views.mobile_click),0) as mobile_click'),
                        DB::raw('COALESCE(SUM(widget_views.desktop_click),0) as desktop_click'),
                        DB::raw('(SELECT COUNT(*) FROM submissions WHERE submissions.widget_id = widgets.id AND submissions.created_at BETWEEN "'.$startDate.'" AND "'.$endDate.'") as total_submissions')
                    )
                    ->groupBy(
                        'widgets.id',
                        'widgets.widget_status',
                        'widgets.title',
                        'widgets.unique_id',
                        'widgets.created_at'
                    )
                    ->orderBy('widgets.created_at', 'desc')
                    ->paginate(config('global.pagination_limit'));
            } elseif ($time == 'last_30_days') {
                $endDate = date('Y-m-d 23:59:59');
                $startDate = date('Y-m-d 00:00:00', strtotime('-30 days'));

                $widgetData = Widget::leftJoin('widget_settings', function ($join) {
                    $join->on('widgets.unique_id', '=', 'widget_settings.widget_id');
                })
                    ->leftJoin('widget_views', function ($join) use ($startDate, $endDate) {
                        $join->on('widgets.id', '=', 'widget_views.widget_id')
                            ->whereBetween('widget_views.created_date', [$startDate, $endDate]);
                    })
                    ->where('widgets.user_id', $userData->id)
                    ->where('widgets.is_deleted', 0)
                    ->select(
                        'widgets.id',
                        'widgets.widget_status',
                        'widgets.title',
                        'widgets.unique_id',
                        'widgets.created_at',

                        DB::raw('COALESCE(SUM(widget_views.mobile_view),0) as mobile_view'),
                        DB::raw('COALESCE(SUM(widget_views.desktop_view),0) as desktop_view'),
                        DB::raw('COALESCE(SUM(widget_views.mobile_click),0) as mobile_click'),
                        DB::raw('COALESCE(SUM(widget_views.desktop_click),0) as desktop_click'),
                        DB::raw('(SELECT COUNT(*) FROM submissions WHERE submissions.widget_id = widgets.id AND submissions.created_at BETWEEN "'.$startDate.'" AND "'.$endDate.'") as total_submissions')
                    )
                    ->groupBy(
                        'widgets.id',
                        'widgets.widget_status',
                        'widgets.title',
                        'widgets.unique_id',
                        'widgets.created_at'
                    )
                    ->orderBy('widgets.created_at', 'desc')
                    ->paginate(config('global.pagination_limit'));
            } elseif ($time == 'this_month') {
                $endDate = date('Y-m-d 23:59:59');
                $startDate = date('Y-m-01 00:00:00');

                $widgetData = Widget::leftJoin('widget_settings', function ($join) {
                    $join->on('widgets.unique_id', '=', 'widget_settings.widget_id');
                })
                    ->leftJoin('widget_views', function ($join) use ($startDate, $endDate) {
                        $join->on('widgets.id', '=', 'widget_views.widget_id')
                            ->whereBetween('widget_views.created_date', [$startDate, $endDate]);
                    })
                    ->where('widgets.user_id', $userData->id)
                    ->where('widgets.is_deleted', 0)
                    ->select(
                        'widgets.id',
                        'widgets.widget_status',
                        'widgets.title',
                        'widgets.unique_id',
                        'widgets.created_at',

                        DB::raw('COALESCE(SUM(widget_views.mobile_view),0) as mobile_view'),
                        DB::raw('COALESCE(SUM(widget_views.desktop_view),0) as desktop_view'),
                        DB::raw('COALESCE(SUM(widget_views.mobile_click),0) as mobile_click'),
                        DB::raw('COALESCE(SUM(widget_views.desktop_click),0) as desktop_click'),
                        DB::raw('(SELECT COUNT(*) FROM submissions WHERE submissions.widget_id = widgets.id AND submissions.created_at BETWEEN "'.$startDate.'" AND "'.$endDate.'") as total_submissions')
                    )
                    ->groupBy(
                        'widgets.id',
                        'widgets.widget_status',
                        'widgets.title',
                        'widgets.unique_id',
                        'widgets.created_at'
                    )
                    ->orderBy('widgets.created_at', 'desc')
                    ->paginate(config('global.pagination_limit'));
            } elseif ($time == 'this_year') {
                $endDate = date('Y-12-31 23:59:59');
                $startDate = date('Y-01-01 00:00:00');

                $widgetData = Widget::leftJoin('widget_settings', function ($join) {
                    $join->on('widgets.unique_id', '=', 'widget_settings.widget_id');
                })
                    ->leftJoin('widget_views', function ($join) use ($startDate, $endDate) {
                        $join->on('widgets.id', '=', 'widget_views.widget_id')
                            ->whereBetween('widget_views.created_date', [$startDate, $endDate]);
                    })
                    ->where('widgets.user_id', $userData->id)
                    ->where('widgets.is_deleted', 0)
                    ->select(
                        'widgets.id',
                        'widgets.widget_status',
                        'widgets.title',
                        'widgets.unique_id',
                        'widgets.created_at',

                        DB::raw('COALESCE(SUM(widget_views.mobile_view),0) as mobile_view'),
                        DB::raw('COALESCE(SUM(widget_views.desktop_view),0) as desktop_view'),
                        DB::raw('COALESCE(SUM(widget_views.mobile_click),0) as mobile_click'),
                        DB::raw('COALESCE(SUM(widget_views.desktop_click),0) as desktop_click'),
                        DB::raw('(SELECT COUNT(*) FROM submissions WHERE submissions.widget_id = widgets.id AND submissions.created_at BETWEEN "'.$startDate.'" AND "'.$endDate.'") as total_submissions')
                    )
                    ->groupBy(
                        'widgets.id',
                        'widgets.widget_status',
                        'widgets.title',
                        'widgets.unique_id',
                        'widgets.created_at'
                    )
                    ->orderBy('widgets.created_at', 'desc')
                    ->paginate(config('global.pagination_limit'));
            } elseif ($time == 'custom') {
                $startDateStr = $request->start_date ?? date('Y-m-d');
                $endDateStr = $request->end_date ?? date('Y-m-d');
                $startDate = date('Y-m-d 00:00:00', strtotime($startDateStr));
                $endDate = date('Y-m-d 23:59:59', strtotime($endDateStr));

                $widgetData = Widget::leftJoin('widget_settings', function ($join) {
                    $join->on('widgets.unique_id', '=', 'widget_settings.widget_id');
                })
                    ->leftJoin('widget_views', function ($join) use ($startDate, $endDate) {
                        $join->on('widgets.id', '=', 'widget_views.widget_id')
                            ->whereBetween('widget_views.created_date', [$startDate, $endDate]);
                    })
                    ->where('widgets.user_id', $userData->id)
                    ->where('widgets.is_deleted', 0)
                    ->select(
                        'widgets.id',
                        'widgets.widget_status',
                        'widgets.title',
                        'widgets.unique_id',
                        'widgets.created_at',

                        DB::raw('COALESCE(SUM(widget_views.mobile_view),0) as mobile_view'),
                        DB::raw('COALESCE(SUM(widget_views.desktop_view),0) as desktop_view'),
                        DB::raw('COALESCE(SUM(widget_views.mobile_click),0) as mobile_click'),
                        DB::raw('COALESCE(SUM(widget_views.desktop_click),0) as desktop_click'),
                        DB::raw('(SELECT COUNT(*) FROM submissions WHERE submissions.widget_id = widgets.id AND submissions.created_at BETWEEN "'.$startDate.'" AND "'.$endDate.'") as total_submissions')
                    )
                    ->groupBy(
                        'widgets.id',
                        'widgets.widget_status',
                        'widgets.title',
                        'widgets.unique_id',
                        'widgets.created_at'
                    )
                    ->orderBy('widgets.created_at', 'desc')
                    ->paginate(config('global.pagination_limit'));

            } else {
                $widgetData = Widget::leftJoin('widget_settings', function ($join) {
                    $join->on('widgets.unique_id', '=', 'widget_settings.widget_id');
                })
                    ->leftJoin('widget_views', 'widgets.id', '=', 'widget_views.widget_id')
                    ->where('widgets.user_id', $userData->id)
                    ->where('widgets.is_deleted', 0)
                    ->select(
                        'widgets.id',
                        'widgets.widget_status',
                        'widgets.title',
                        'widgets.unique_id',
                        'widgets.created_at',

                        DB::raw('COALESCE(SUM(widget_views.mobile_view),0) as mobile_view'),
                        DB::raw('COALESCE(SUM(widget_views.desktop_view),0) as desktop_view'),
                        DB::raw('COALESCE(SUM(widget_views.mobile_click),0) as mobile_click'),
                        DB::raw('COALESCE(SUM(widget_views.desktop_click),0) as desktop_click'),
                        DB::raw('(SELECT COUNT(*) FROM submissions WHERE submissions.widget_id = widgets.id) as total_submissions')
                    )
                    ->groupBy(
                        'widgets.id',
                        'widgets.widget_status',
                        'widgets.title',
                        'widgets.unique_id',
                        'widgets.created_at'
                    )
                    ->orderBy('widgets.created_at', 'desc')
                    ->paginate(config('global.pagination_limit'));
            }
        } else {
            $endDate = date('Y-m-d 23:59:59');
            $startDate = date('Y-m-d 00:00:00', strtotime('-7 days'));

            $widgetData = Widget::leftJoin('widget_settings', function ($join) {
                $join->on('widgets.unique_id', '=', 'widget_settings.widget_id');
            })
                ->leftJoin('widget_views', function ($join) use ($startDate, $endDate) {
                    $join->on('widgets.id', '=', 'widget_views.widget_id')
                        ->whereBetween('widget_views.created_date', [$startDate, $endDate]);
                })
                ->where('widgets.user_id', $userData->id)
                ->where('widgets.is_deleted', 0)
                ->select(
                    'widgets.id',
                    'widgets.widget_status',
                    'widgets.title',
                    'widgets.unique_id',
                    'widgets.created_at',

                    DB::raw('COALESCE(SUM(widget_views.mobile_view),0) as mobile_view'),
                    DB::raw('COALESCE(SUM(widget_views.desktop_view),0) as desktop_view'),
                    DB::raw('COALESCE(SUM(widget_views.mobile_click),0) as mobile_click'),
                    DB::raw('COALESCE(SUM(widget_views.desktop_click),0) as desktop_click'),
                    DB::raw('(SELECT COUNT(*) FROM submissions WHERE submissions.widget_id = widgets.id AND submissions.created_at BETWEEN "'.$startDate.'" AND "'.$endDate.'") as total_submissions')
                )
                ->groupBy(
                    'widgets.id',
                    'widgets.widget_status',
                    'widgets.title',
                    'widgets.unique_id',
                    'widgets.created_at'
                )
                ->orderBy('widgets.created_at', 'desc')
                ->paginate(config('global.pagination_limit'));
        }

        if ($widgetData->isEmpty()) {
            return response()->json([
                'status' => 1,
                'widgetList' => [],
                'totalWidget' => 0,
                'activeWidget' => 0,
                'inActiveWidget' => 0,
                'message' => 'No form found',
            ]);
        }

        $totalWidget = Widget::where('user_id', $userData->id)
            ->where('is_deleted', 0)
            ->count();

        $activeWidget = Widget::where('user_id', $userData->id)
            ->where('widget_status', 1)
            ->where('is_deleted', 0)
            ->count();

        $inActiveWidget = Widget::where('user_id', $userData->id)
            ->where('widget_status', 0)
            ->where('is_deleted', 0)
            ->count();

        return response()->json([
            'status' => 1,
            'widgetList' => $widgetData,
            'totalWidget' => $totalWidget,
            'activeWidget' => $activeWidget,
            'inActiveWidget' => $inActiveWidget,
            'message' => 'Form list fetched successfully',
        ]);

    }

    public function change_widget_status(Request $request)
    {
        $response = [
            'status' => 0,
            'message' => 'Invalid request, please try again later',
        ];

        if (! $request->filled('widget_id') || ! $request->filled('widget_status')) {
            return response()->json([
                'status' => 0,
                'message' => 'Form ID or status missing',
            ], 422);
        }

        $userData = $this->authUser($request);

        $widget = Widget::where('unique_id', $request->widget_id)->where('user_id', $userData->id)->where('is_deleted', 0)->first();

        if ($widget) {
            $widget->widget_status = $request->widget_status;
            $widget->save();

            $totalWidget = Widget::where('user_id', $userData->id)
                ->where('is_deleted', 0)
                ->count();

            $activeWidget = Widget::where('user_id', $userData->id)
                ->where('widget_status', 1)
                ->where('is_deleted', 0)
                ->count();

            $inActiveWidget = Widget::where('user_id', $userData->id)
                ->where('widget_status', 0)
                ->where('is_deleted', 0)
                ->count();

            $response = [
                'status' => 1,
                'totalWidget' => $totalWidget,
                'activeWidget' => $activeWidget,
                'inActiveWidget' => $inActiveWidget,
                'message' => 'Form status updated successfully',
            ];
        } else {
            $response['message'] = 'Form not found';
        }

        return response()->json($response);
    }

    public function rename_widget_title(Request $request)
    {
        $response = [
            'status' => 0,
            'message' => 'Invalid request, please try again later',
        ];

        if (! $request->filled('widget_id') || ! $request->filled('title')) {
            return response()->json([
                'status' => 0,
                'message' => 'Form ID or title missing',
            ], 422);
        }

        $userData = $this->authUser($request);

        $widgetData = Widget::where('unique_id', $request->widget_id)->where('user_id', $userData->id)->where('is_deleted', 0)->first();

        if ($widgetData) {
            $widgetData->title = $request->title;
            $widgetData->update();

            $response = [
                'status' => 1,
                'message' => 'Form title renamed successfully',
            ];
        } else {
            $response['message'] = 'Form not found';
        }

        return response()->json($response);

    }

    public function remove_widget(Request $request)
    {
        $response = [
            'status' => 0,
            'message' => 'Invalid request, please try again later',
        ];

        if (! $request->filled('widget_id')) {
            return response()->json([
                'status' => 0,
                'message' => 'Form ID missing',
            ], 422);
        }

        $userData = $this->authUser($request);

        $widgetData = Widget::where('unique_id', $request->widget_id)->where('user_id', $userData->id)->where('is_deleted', 0)->first();
        if (! $widgetData) {
            return response()->json([
                'status' => 0,
                'message' => 'Form not found or access denied',
            ], 403);
        }

        $widgetData->is_deleted = 1;
        $widgetData->deleted_by = $userData->id;
        $widgetData->deleted_at = now();
        $widgetData->update();

        return response()->json([
            'status' => 1,
            'message' => 'Form removed successfully',
        ], 200);

    }

    public function clone_widget(Request $request)
    {

        if (! $request->filled('widget_id') || ! $request->filled('title')) {
            return response()->json([
                'status' => 0,
                'message' => 'Widget ID or title missing',
            ], 422);
        }

        $userData = $this->authUser($request);

        $widgetData = Widget::where('unique_id', $request->widget_id)->where('user_id', $userData->id)->where('is_deleted', 0)->first();
        if (! $widgetData) {
            return response()->json([
                'status' => 0,
                'message' => 'Form not found or access denied',
            ], 403);
        }

        $widget = new Widget;
        $widget->title = $request->title;
        $widget->unique_id = Str::uuid();
        $widget->widget_status = $widgetData->widget_status;
        $widget->user_id = $userData->id;
        $widget->created_by = $userData->id;
        $widget->created_at = now();
        $widget->save();

        $widgetSettingData = WidgetSetting::where('widget_id', $widgetData->unique_id)->first();

        if ($widgetSettingData) {
            $widgetSetting = new WidgetSetting;
            $widgetSetting->form_field_setting = $widgetSettingData->form_field_setting;
            $widgetSetting->form_style_setting = $widgetSettingData->form_style_setting;
            $widgetSetting->display_rule_setting = $widgetSettingData->display_rule_setting;
            $widgetSetting->submission_setting = $widgetSettingData->submission_setting;
            $widgetSetting->time_delay_setting = $widgetSettingData->time_delay_setting;
            $widgetSetting->scroll_based_setting = $widgetSettingData->scroll_based_setting;
            $widgetSetting->page_rule_setting = $widgetSettingData->page_rule_setting;
            $widgetSetting->date_time_setting = $widgetSettingData->date_time_setting;
            $widgetSetting->day_hour_setting = $widgetSettingData->day_hour_setting;
            $widgetSetting->country_rule_setting = $widgetSettingData->country_rule_setting;
            $widgetSetting->widget_id = $widget->unique_id;
            $widgetSetting->save();
        }

        return response()->json([
            'status' => 1,
            'message' => 'Form cloned successfully',
        ], 200);
    }
}
