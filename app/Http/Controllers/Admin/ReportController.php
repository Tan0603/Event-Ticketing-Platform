<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Reports\DashboardSummary;
use App\Actions\Reports\EventReport;
use App\Actions\Reports\SalesByDay;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\EventReportRequest;
use App\Http\Requests\Admin\SalesReportRequest;
use App\Models\Tenant\Event;
use App\Support\ArrayPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Read-only admin reports. Route group middleware `can:access-admin` keeps door staff out. */
class ReportController extends Controller
{
    public function dashboard(Request $request, DashboardSummary $summary): JsonResponse
    {
        return response()->json(['data' => $summary->execute()]);
    }

    public function sales(SalesReportRequest $request, SalesByDay $sales): JsonResponse
    {
        [$from, $to] = $request->range();
        $report = $sales->execute($from->toDateString(), $to->toDateString());
        $page = ArrayPage::slice($report['days'], $request->page(), $request->perPage());

        // Totals always cover the whole range; only the day-by-day list is paged.
        return response()->json(['data' => ['days' => $page['rows']] + $report, 'meta' => $page['meta']]);
    }

    public function event(EventReportRequest $request, Event $event, EventReport $report): JsonResponse
    {
        $this->authorize('view', $event);

        $data = $report->execute($event);
        $page = ArrayPage::slice($data['ticket_types'], $request->page(), $request->perPage());

        return response()->json(['data' => ['ticket_types' => $page['rows']] + $data, 'meta' => $page['meta']]);
    }
}
