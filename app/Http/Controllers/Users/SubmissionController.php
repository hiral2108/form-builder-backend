<?php

namespace App\Http\Controllers\Users;

use App\Http\Controllers\Controller;
use App\Models\Submission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubmissionController extends Controller
{
    /**
     * Fetch all submissions of all widgets created by currently logged in user with pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $userData = $this->authUser($request);

        $limit = $request->input('limit', config('global.pagination_limit', 10));

        // Start building the query
        $query = Submission::select('submissions.*', 'widgets.title as widget_title', 'widgets.unique_id as widget_unique_id')
            ->join('widgets', 'widgets.id', '=', 'submissions.widget_id')
            ->where('widgets.user_id', $userData->id)
            ->where('widgets.is_deleted', 0);

        // Search filter
        $search = $request->input('search');
        if (! empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('submissions.page_url', 'like', '%'.$search.'%')
                    ->orWhere('submissions.ip_address', 'like', '%'.$search.'%')
                    ->orWhere('widgets.title', 'like', '%'.$search.'%')
                    ->orWhere('submissions.fields', 'like', '%'.$search.'%');
            });
        }

        // Time filter
        $time = $request->input('time', 'all_time');
        $startDate = null;
        $endDate = null;

        if ($time === 'today') {
            $startDate = date('Y-m-d 00:00:00');
            $endDate = date('Y-m-d 23:59:59');
        } elseif ($time === 'yesterday') {
            $startDate = date('Y-m-d 00:00:00', strtotime('yesterday'));
            $endDate = date('Y-m-d 23:59:59', strtotime('yesterday'));
        } elseif ($time === 'last_7_days') {
            $startDate = date('Y-m-d 00:00:00', strtotime('-7 days'));
            $endDate = date('Y-m-d 23:59:59');
        } elseif ($time === 'last_30_days') {
            $startDate = date('Y-m-d 00:00:00', strtotime('-30 days'));
            $endDate = date('Y-m-d 23:59:59');
        } elseif ($time === 'this_month') {
            $startDate = date('Y-m-01 00:00:00');
            $endDate = date('Y-m-t 23:59:59');
        } elseif ($time === 'last_month') {
            $startDate = date('Y-m-01 00:00:00', strtotime('first day of last month'));
            $endDate = date('Y-m-t 23:59:59', strtotime('last day of last month'));
        } elseif ($time === 'custom') {
            if ($request->filled('start_date') && $request->filled('end_date')) {
                $startDate = date('Y-m-d 00:00:00', strtotime($request->input('start_date')));
                $endDate = date('Y-m-d 23:59:59', strtotime($request->input('end_date')));
            }
        }

        if ($startDate && $endDate) {
            $query->whereBetween('submissions.created_at', [$startDate, $endDate]);
        }

        $submissions = $query->orderBy('submissions.created_at', 'desc')->paginate($limit);

        return response()->json([
            'status' => 1,
            'message' => 'Submissions fetched successfully',
            'data' => $submissions,
        ], 200);
    }

    public function remove_lead(Request $request)
    {

        $response = [
            'status' => 0,
            'message' => 'Invalid Request, Please try again',
        ];

        $userData = $this->authUser($request);

        $ids = is_array($request->id) ? $request->id : [$request->id];
        $ids = array_values(array_filter($ids, fn ($id) => ! empty($id)));

        if (empty($ids)) {
            return response()->json($response);
        }

        // Only delete submissions belonging to a widget owned by the authenticated
        // user — previously this deleted by ID alone with no ownership check.
        Submission::whereIn('submissions.id', $ids)
            ->whereIn('submissions.widget_id', function ($query) use ($userData) {
                $query->select('id')->from('widgets')->where('user_id', $userData->id);
            })
            ->delete();

        $response['status'] = 1;
        $response['message'] = count($ids) > 1
            ? 'Selected Submissions removed successfully'
            : 'Submission removed successfully';

        return response()->json($response);
    }

    public function remove_all_lead(Request $request)
    {

        $response = [
            'status' => 0,
            'message' => 'Invalid Request, Please try again',
        ];

        $userData = $this->authUser($request);

        // Scoped via a real WHERE on the owning widget's user_id — the previous
        // version put the filter inside a LEFT JOIN's ON clause, which meant the
        // DELETE had no row-restricting condition at all and wiped every shop's
        // submissions. It also filtered on widgets.created_by (a nullable label
        // column), not widgets.user_id (the actual foreign key used everywhere
        // else in this controller).
        Submission::whereIn('widget_id', function ($query) use ($userData) {
            $query->select('id')->from('widgets')->where('user_id', $userData->id);
        })->delete();

        $response['status'] = 1;
        $response['message'] = 'All Submissions removed successfully';

        return response()->json($response);
    }

    public function export_lead(Request $request)
    {
        $userData = $this->authUser($request);

        // Start building the query
        $query = Submission::select('submissions.*', 'widgets.title as widget_title', 'widgets.unique_id as widget_unique_id')
            ->join('widgets', 'widgets.id', '=', 'submissions.widget_id')
            ->where('widgets.user_id', $userData->id)
            ->where('widgets.is_deleted', 0);

        // Search filter
        $search = $request->input('search');
        if (! empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('submissions.page_url', 'like', '%'.$search.'%')
                    ->orWhere('submissions.ip_address', 'like', '%'.$search.'%')
                    ->orWhere('widgets.title', 'like', '%'.$search.'%')
                    ->orWhere('submissions.fields', 'like', '%'.$search.'%');
            });
        }

        // Time filter
        $time = $request->input('time', 'all_time');
        $startDate = null;
        $endDate = null;

        if ($time === 'today') {
            $startDate = date('Y-m-d 00:00:00');
            $endDate = date('Y-m-d 23:59:59');
        } elseif ($time === 'yesterday') {
            $startDate = date('Y-m-d 00:00:00', strtotime('yesterday'));
            $endDate = date('Y-m-d 23:59:59', strtotime('yesterday'));
        } elseif ($time === 'last_7_days') {
            $startDate = date('Y-m-d 00:00:00', strtotime('-7 days'));
            $endDate = date('Y-m-d 23:59:59');
        } elseif ($time === 'last_30_days') {
            $startDate = date('Y-m-d 00:00:00', strtotime('-30 days'));
            $endDate = date('Y-m-d 23:59:59');
        } elseif ($time === 'this_month') {
            $startDate = date('Y-m-01 00:00:00');
            $endDate = date('Y-m-t 23:59:59');
        } elseif ($time === 'last_month') {
            $startDate = date('Y-m-01 00:00:00', strtotime('first day of last month'));
            $endDate = date('Y-m-t 23:59:59', strtotime('last day of last month'));
        } elseif ($time === 'custom') {
            if ($request->filled('start_date') && $request->filled('end_date')) {
                $startDate = date('Y-m-d 00:00:00', strtotime($request->input('start_date')));
                $endDate = date('Y-m-d 23:59:59', strtotime($request->input('end_date')));
            }
        }

        if ($startDate && $endDate) {
            $query->whereBetween('submissions.created_at', [$startDate, $endDate]);
        }

        $submissions = $query->orderBy('submissions.created_at', 'desc')->get();

        $uniqueFields = [];
        foreach ($submissions as $sub) {
            $fields = $sub->fields;
            if (is_array($fields)) {
                foreach ($fields as $field) {
                    $fieldName = $field['field_name'] ?? ($field['name'] ?? null);
                    if ($fieldName && ! in_array($fieldName, $uniqueFields)) {
                        $uniqueFields[] = $fieldName;
                    }
                }
            }
        }

        $data_rows = [];
        foreach ($submissions as $sub) {
            $row = [];
            $row['widget_name'] = $sub->widget_title;
            $row['ip_address'] = $sub->ip_address;
            $row['page_url'] = $sub->page_url;
            $row['device'] = $sub->is_from_mobile ? 'Mobile' : 'Desktop';
            $row['created_on'] = $sub->created_at;

            $fieldsMap = [];
            if (is_array($sub->fields)) {
                foreach ($sub->fields as $field) {
                    $name = $field['field_name'] ?? ($field['name'] ?? null);
                    if ($name) {
                        $val = $field['value'] ?? '';
                        if (is_array($val)) {
                            $val = implode(', ', $val);
                        }
                        $fieldsMap[$name] = $val;
                    }
                }
            }

            foreach ($uniqueFields as $uf) {
                $row[$uf] = $fieldsMap[$uf] ?? '';
            }

            $data_rows[] = $row;
        }

        $filename = 'export_form_'.time().'.csv';

        $header_row = ['Widget Name', 'IP Address', 'Page Url', 'Device', 'Created On'];
        foreach ($uniqueFields as $uf) {
            $header_row[] = $uf;
        }

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename={$filename}",
        ];

        return response()->stream(function () use ($header_row, $data_rows) {
            $fh = fopen('php://output', 'w');
            fputcsv($fh, $header_row);
            foreach ($data_rows as $value) {
                fputcsv($fh, $value);
            }
            fclose($fh);
        }, 200, $headers);
    }
}
