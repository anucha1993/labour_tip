<?php

namespace App\Http\Controllers;

use App\Models\company\CompanyModel;
use App\Services\AnalyticsService;
use Illuminate\Http\Request;

/**
 * Dashboard (/dashboard): analytics over the workforce, separate from the home page's expiry alerts.
 * Needs permission analytics.view; the system-use card needs admin.logs as well.
 */
class AnalyticsController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index(Request $request, AnalyticsService $analytics)
    {
        $company = (int) $request->input('company', 0);
        $months = (int) $request->input('months', 12);
        if (! in_array($months, AnalyticsService::MONTH_OPTIONS, true)) {
            $months = 12;
        }
        $companies = CompanyModel::query()->orderBy('company_name')->get(['company_id', 'company_name']);
        if ($company && ! $companies->contains('company_id', $company)) {
            $company = 0;
        }
        $withSystem = $request->user()->can('admin.logs');

        return view('analytics.index', [
            'data' => $analytics->build($company, $months, $withSystem),
            'company' => $company,
            'companyName' => $company ? optional($companies->firstWhere('company_id', $company))->company_name : null,
            'months' => $months,
            'monthOptions' => AnalyticsService::MONTH_OPTIONS,
            'companies' => $companies,
            'labels' => AnalyticsService::LABELS,
            'types' => AnalyticsService::TYPES,
            'cacheMinutes' => AnalyticsService::CACHE_MINUTES,
        ]);
    }
}
