<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Reports\DashboardSummary;
use App\Actions\Reports\EventReport;
use App\Actions\Reports\SalesByDay;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SalesReportRequest;
use App\Models\Tenant\Event;
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

        return response()->json(['data' => $sales->execute($from->toDateString(), $to->toDateString())]);
    }

    public function event(Request $request, Event $event, EventReport $report): JsonResponse
    {
        $this->authorize('view', $event);

        return response()->json(['data' => $report->execute($event)]);
    }
}
