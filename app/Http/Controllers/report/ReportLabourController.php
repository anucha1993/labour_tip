<?php

namespace App\Http\Controllers\report;

use App\Http\Controllers\Controller;
use App\Models\company\CompanyModel;

class ReportLabourController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index()
    {
        $company = CompanyModel::orderBy('company_name')->get(['company_id', 'company_name']);

        return view('report.index', compact('company'));
    }
}
