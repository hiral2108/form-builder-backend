<?php

namespace App\Http\Controllers\Users;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\Widget;
use App\Models\WidgetView;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Get dashboard analytics data for views, clicks, and click rate.
     */
    public function get_dashboard_data(Request $request): JsonResponse
    {
        $response = [
            'status' => 0,
            'message' => 'Invalid request, please try again later',
        ];

        $bearer = $request->header('Authorization', '');
        if (! $bearer) {
            return response()->json(['error' => 'Authorization header missing'], 401);
        }

        $token = preg_replace('/^Bearer\s+/i', '', $bearer);
        if (! $token) {
            return response()->json(['error' => 'Invalid token'], 401);
        }

        $userData = AdminUser::select(DB::raw('admin_users.*,user_tokens.user_token AS unique_key'))
            ->leftJoin('user_tokens', 'user_tokens.shop_id', '=', 'admin_users.id')
            ->where('user_tokens.user_token', $token)
            ->first();

        if (! $userData) {
            return response()->json(['error' => 'User not found or token is invalid'], 401);
        }

        $dateList = [];
        $clickData = [];
        $viewData = [];
        $totalViews = [];
        $records = [];

        $time = $request->filter ?? $request->time;

        if (! empty($time)) {
            if ($time == 'today' || $time == 'yesterday') {
                $totalViews = WidgetView::select(DB::raw('SUM(widget_views.mobile_view) As mobileView, SUM(widget_views.desktop_view) As desktopView, SUM(widget_views.mobile_click) As mobileClick, SUM(widget_views.desktop_click) As desktopClick'))
                    ->leftJoin('widgets', 'widget_views.widget_id', '=', 'widgets.id')
                    ->whereDate('widget_views.created_date', ($time == 'today') ? (Carbon::today()) : (Carbon::yesterday()))
                    ->where('widgets.user_id', $userData->id)
                    ->where('widgets.is_deleted', 0)
                    ->get();
                if (count($totalViews)) {
                    $totalViews = $totalViews[0];
                    $viewData[] = ($totalViews->mobileView ?? 0) + ($totalViews->desktopView ?? 0);
                    $clickData[] = ($totalViews->desktopClick ?? 0) + ($totalViews->mobileClick ?? 0);
                }
                if ($time == 'yesterday') {
                    $dateList[] = date('Y-m-d', strtotime('-1 days'));
                } else {
                    $dateList[] = date('Y-m-d');
                }
            } elseif ($time == 'this_week' || $time == 'last_7_days' || $time == 'last_30_days' || $time == 'this_month') {
                $endDate = date('Y-m-d 23:59:59');
                $startDate = date('Y-m-d 00:00:00');
                if ($time == 'this_week') {
                    $startDate = date('Y-m-d 00:00:00', strtotime('this week'));
                } elseif ($time == 'last_7_days') {
                    $startDate = date('Y-m-d 00:00:00', strtotime('-7 days'));
                } elseif ($time == 'last_30_days') {
                    $startDate = date('Y-m-d 00:00:00', strtotime('-30 days'));
                } elseif ($time == 'this_month') {
                    $startDate = date('Y-m-01 00:00:00');
                }
                $records = WidgetView::select(DB::raw('widget_views.mobile_view, widget_views.desktop_view, widget_views.mobile_click, widget_views.desktop_click, widget_views.created_date'))
                    ->leftJoin('widgets', 'widget_views.widget_id', '=', 'widgets.id')
                    ->whereBetween('widget_views.created_date', [$startDate, $endDate])
                    ->where('widgets.user_id', $userData->id)
                    ->where('widgets.is_deleted', 0)
                    ->get();

                $dataSet = [];
                $startDateTs = strtotime($startDate);
                $endDateTs = strtotime($endDate);
                $stepVal = '+1 day';
                while ($startDateTs <= $endDateTs) {
                    $dataSet[date('Y-m-d', $startDateTs)] = [
                        'date' => date('Y-m-d', $startDateTs),
                        'clicks' => 0,
                        'views' => 0,
                    ];
                    $startDateTs = strtotime($stepVal, $startDateTs);
                }
                foreach ($records as $record) {
                    $date = date('Y-m-d', strtotime($record->created_date));
                    if (isset($dataSet[$date])) {
                        $dataSet[$date]['clicks'] += ($record->mobile_click ?? 0) + ($record->desktop_click ?? 0);
                        $dataSet[$date]['views'] += ($record->mobile_view ?? 0) + ($record->desktop_view ?? 0);
                    } else {
                        $dataSet[$date] = [
                            'date' => $date,
                            'clicks' => ($record->mobile_click ?? 0) + ($record->desktop_click ?? 0),
                            'views' => ($record->mobile_view ?? 0) + ($record->desktop_view ?? 0),
                        ];
                    }
                }
                foreach ($dataSet as $data) {
                    $dateList[] = date('d, M', strtotime($data['date']));
                    $clickData[] = $data['clicks'];
                    $viewData[] = $data['views'];
                }
            } elseif ($time == 'this_year') {
                $dateList = ['Jan', 'Feb', 'March', 'April', 'May', 'Jun', 'July', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                $endDate = date('Y-12-31 23:59:59');
                $startDate = date('Y-01-01 00:00:00');

                $records = WidgetView::select(DB::raw('widget_views.mobile_view, widget_views.desktop_view, widget_views.mobile_click, widget_views.desktop_click, widget_views.created_date'))
                    ->leftJoin('widgets', 'widget_views.widget_id', '=', 'widgets.id')
                    ->whereBetween('widget_views.created_date', [$startDate, $endDate])
                    ->where('widgets.user_id', $userData->id)
                    ->where('widgets.is_deleted', 0)
                    ->get();

                $dataSet = [];
                $startDateTs = strtotime($startDate);
                $endDateTs = strtotime($endDate);
                $stepVal = '+1 month';
                while ($startDateTs <= $endDateTs) {
                    $dataSet[date('m', $startDateTs)] = [
                        'clicks' => 0,
                        'views' => 0,
                    ];
                    $startDateTs = strtotime($stepVal, $startDateTs);
                }
                foreach ($records as $record) {
                    $date = date('m', strtotime($record->created_date));
                    if (isset($dataSet[$date])) {
                        $dataSet[$date]['clicks'] += ($record->mobile_click ?? 0) + ($record->desktop_click ?? 0);
                        $dataSet[$date]['views'] += ($record->mobile_view ?? 0) + ($record->desktop_view ?? 0);
                    } else {
                        $dataSet[$date] = [
                            'clicks' => ($record->mobile_click ?? 0) + ($record->desktop_click ?? 0),
                            'views' => ($record->mobile_view ?? 0) + ($record->desktop_view ?? 0),
                        ];
                    }
                }
                foreach ($dataSet as $data) {
                    $clickData[] = $data['clicks'];
                    $viewData[] = $data['views'];
                }
            } elseif ($time == 'all_time') {
                $year = date('Y', strtotime($userData->created_at ?? now()->toDateString()));
                if ($year < date('Y')) {
                    $startDate = date("$year-m-d 00:00:00");
                    $endDate = date('Y-12-31 23:59:59');
                } else {
                    $startDate = date('Y-01-01 00:00:00');
                    $endDate = date('Y-12-31 23:59:59');
                }
                $records = WidgetView::select(DB::raw('widget_views.mobile_view, widget_views.desktop_view, widget_views.mobile_click, widget_views.desktop_click, widget_views.created_date'))
                    ->leftJoin('widgets', 'widget_views.widget_id', '=', 'widgets.id')
                    ->whereBetween('widget_views.created_date', [$startDate, $endDate])
                    ->where('widgets.user_id', $userData->id)
                    ->where('widgets.is_deleted', 0)
                    ->get();
                if (date('Y', strtotime($startDate)) < date('Y', strtotime($endDate))) {
                    $dataSet = [];
                    $startDateTs = strtotime($startDate);
                    $endDateTs = strtotime($endDate);
                    $stepVal = '+1 year';
                    while ($startDateTs <= $endDateTs) {
                        $dateList[] = date('Y', $startDateTs);
                        $dataSet[date('Y', $startDateTs)] = [
                            'clicks' => 0,
                            'views' => 0,
                        ];
                        $startDateTs = strtotime($stepVal, $startDateTs);
                    }
                    foreach ($records as $record) {
                        $date = date('Y', strtotime($record->created_date));
                        if (isset($dataSet[$date])) {
                            $dataSet[$date]['clicks'] += ($record->mobile_click ?? 0) + ($record->desktop_click ?? 0);
                            $dataSet[$date]['views'] += ($record->mobile_view ?? 0) + ($record->desktop_view ?? 0);
                        } else {
                            $dataSet[$date] = [
                                'clicks' => ($record->mobile_click ?? 0) + ($record->desktop_click ?? 0),
                                'views' => ($record->mobile_view ?? 0) + ($record->desktop_view ?? 0),
                            ];
                        }
                    }
                } else {
                    $dateList = ['Jan', 'Feb', 'March', 'April', 'May', 'Jun', 'July', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                    $dataSet = [];
                    $startDateTs = strtotime($startDate);
                    $endDateTs = strtotime($endDate);
                    $stepVal = '+1 month';
                    while ($startDateTs <= $endDateTs) {
                        $dataSet[date('m', $startDateTs)] = [
                            'clicks' => 0,
                            'views' => 0,
                        ];
                        $startDateTs = strtotime($stepVal, $startDateTs);
                    }
                    foreach ($records as $record) {
                        $date = date('m', strtotime($record->created_date));
                        if (isset($dataSet[$date])) {
                            $dataSet[$date]['clicks'] += ($record->mobile_click ?? 0) + ($record->desktop_click ?? 0);
                            $dataSet[$date]['views'] += ($record->mobile_view ?? 0) + ($record->desktop_view ?? 0);
                        } else {
                            $dataSet[$date] = [
                                'clicks' => ($record->mobile_click ?? 0) + ($record->desktop_click ?? 0),
                                'views' => ($record->mobile_view ?? 0) + ($record->desktop_view ?? 0),
                            ];
                        }
                    }
                }
                foreach ($dataSet as $data) {
                    $clickData[] = $data['clicks'];
                    $viewData[] = $data['views'];
                }
            } elseif ($time == 'custom') {
                $startDateStr = $request->start_date ?? date('Y-m-d');
                $endDateStr = $request->end_date ?? date('Y-m-d');
                $startDate = date('Y-m-d 00:00:00', strtotime($startDateStr));
                $endDate = date('Y-m-d 23:59:59', strtotime($endDateStr));
                $dateDiff = strtotime($endDate) - strtotime($startDate);
                $numOfDays = round($dateDiff / (60 * 60 * 24));

                $records = WidgetView::select(DB::raw('widget_views.mobile_view, widget_views.desktop_view, widget_views.mobile_click, widget_views.desktop_click, widget_views.created_date'))
                    ->leftJoin('widgets', 'widget_views.widget_id', '=', 'widgets.id')
                    ->whereBetween('widget_views.created_date', [$startDate, $endDate])
                    ->where('widgets.user_id', $userData->id)
                    ->where('widgets.is_deleted', 0)
                    ->get();

                if ($numOfDays > 365) {
                    $dataSet = [];
                    $startDateTs = strtotime($startDate);
                    $endDateTs = strtotime($endDate);
                    $stepVal = '+1 year';
                    while ($startDateTs <= $endDateTs) {
                        $dateList[] = date('Y', $startDateTs);
                        $dataSet[date('Y', $startDateTs)] = [
                            'clicks' => 0,
                            'views' => 0,
                        ];
                        $startDateTs = strtotime($stepVal, $startDateTs);
                    }
                    foreach ($records as $record) {
                        $date = date('Y', strtotime($record->created_date));
                        if (isset($dataSet[$date])) {
                            $dataSet[$date]['clicks'] += ($record->mobile_click ?? 0) + ($record->desktop_click ?? 0);
                            $dataSet[$date]['views'] += ($record->mobile_view ?? 0) + ($record->desktop_view ?? 0);
                        } else {
                            $dataSet[$date] = [
                                'clicks' => ($record->mobile_click ?? 0) + ($record->desktop_click ?? 0),
                                'views' => ($record->mobile_view ?? 0) + ($record->desktop_view ?? 0),
                            ];
                        }
                    }
                } elseif ($numOfDays > 31) {
                    $dataSet = [];
                    $startDateTs = strtotime($startDate);
                    $endDateTs = strtotime($endDate);
                    $stepVal = '+1 month';
                    while ($startDateTs <= $endDateTs) {
                        $dateList[] = date('M', $startDateTs);
                        $dataSet[date('m', $startDateTs)] = [
                            'clicks' => 0,
                            'views' => 0,
                        ];
                        $startDateTs = strtotime($stepVal, $startDateTs);
                    }
                    foreach ($records as $record) {
                        $date = date('m', strtotime($record->created_date));
                        if (isset($dataSet[$date])) {
                            $dataSet[$date]['clicks'] += ($record->mobile_click ?? 0) + ($record->desktop_click ?? 0);
                            $dataSet[$date]['views'] += ($record->mobile_view ?? 0) + ($record->desktop_view ?? 0);
                        } else {
                            $dataSet[$date] = [
                                'clicks' => ($record->mobile_click ?? 0) + ($record->desktop_click ?? 0),
                                'views' => ($record->mobile_view ?? 0) + ($record->desktop_view ?? 0),
                            ];
                        }
                    }
                } else {
                    $dataSet = [];
                    $startDateTs = strtotime($startDate);
                    $endDateTs = strtotime($endDate);
                    $stepVal = '+1 day';
                    while ($startDateTs <= $endDateTs) {
                        $dateList[] = date('d, M', $startDateTs);
                        $dataSet[date('Y-m-d', $startDateTs)] = [
                            'clicks' => 0,
                            'views' => 0,
                        ];
                        $startDateTs = strtotime($stepVal, $startDateTs);
                    }
                    foreach ($records as $record) {
                        $date = date('Y-m-d', strtotime($record->created_date));
                        if (isset($dataSet[$date])) {
                            $dataSet[$date]['clicks'] += ($record->mobile_click ?? 0) + ($record->desktop_click ?? 0);
                            $dataSet[$date]['views'] += ($record->mobile_view ?? 0) + ($record->desktop_view ?? 0);
                        } else {
                            $dataSet[$date] = [
                                'clicks' => ($record->mobile_click ?? 0) + ($record->desktop_click ?? 0),
                                'views' => ($record->mobile_view ?? 0) + ($record->desktop_view ?? 0),
                            ];
                        }
                    }
                }
                foreach ($dataSet as $data) {
                    $clickData[] = $data['clicks'];
                    $viewData[] = $data['views'];
                }
            }

            $Tviews = 0;
            foreach ($viewData as $views) {
                $Tviews += $views;
            }
            $Tclicks = 0;
            foreach ($clickData as $clicks) {
                $Tclicks += $clicks;
            }
            $clickRate = ($Tclicks != 0 && $Tviews != 0) ? round(($Tclicks / $Tviews) * 100, 2) : '0.0';

            $totalForms = Widget::where('user_id', $userData->id)
                ->where('is_deleted', 0)
                ->count();

            $response['status'] = 1;
            $response['message'] = 'Success';
            $response['dateList'] = $dateList;
            $response['clickData'] = $clickData;
            $response['viewData'] = $viewData;
            $response['totalViews'] = $Tviews;
            $response['totalClicks'] = $Tclicks;
            $response['clickRate'] = $clickRate;
            $response['total_forms'] = $totalForms;
        }

        return response()->json($response);
    }
}
