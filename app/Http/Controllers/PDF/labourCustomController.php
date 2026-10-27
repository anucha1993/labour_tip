<?php

namespace App\Http\Controllers\PDF;

use App\Exports\ExportCustom\LabourExportCustom;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;

class labourCustomController extends Controller
{
    use LabourPdfReport;

    public function __construct()
    {
        $this->middleware('auth');
    }

    public function exportPDF(Request $request)
    {
        $data = $request->validate([
            'company' => ['required', 'string'],
            'status' => ['required', Rule::in(['ALL', 'job', 'resign', 'escape'])],
            'type' => ['required', Rule::in(['excel', 'pdf'])],
        ]);

        $company = $data['company'] === 'ALL' ? 'ALL' : (int) $data['company'];
        $status = $data['status'];

        if ($data['type'] === 'excel') {
            return Excel::download(new LabourExportCustom($company, $status), 'LabourExportCustom_' . date('Ymd_His') . '.xlsx');
        }

        $rows = DB::table('labour')
            ->leftJoin('company', 'company.company_id', '=', 'labour.company_id')
            ->leftJoin('nationality', 'nationality.code', '=', 'labour.labour_nationality')
            ->select('labour.*', 'company.company_name', 'nationality.name_th')
            ->when($company !== 'ALL', fn ($q) => $q->where('labour.company_id', $company))
            ->when($status !== 'ALL', fn ($q) => $q->where('labour.labour_status_job', $status))
            ->orderBy('company.company_name')
            ->orderBy('labour.labour_fullname')
            ->get();

        return $this->streamPdf($rows, 'รายงานข้อมูลคนต่างด้าว', 'LabourReport_' . date('Ymd_His') . '.pdf');
    }
}
