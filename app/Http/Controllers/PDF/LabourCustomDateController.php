<?php

namespace App\Http\Controllers\PDF;

use App\Exports\ExportCustom\LabourExportCustomDate;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;

class LabourCustomDateController extends Controller
{
    use LabourPdfReport;

    /** Date columns the report may filter on (whitelist: never pass a raw column name to SQL). */
    public const DATE_TYPES = [
        'labour.labour_visa_date_end' => 'วีซ่าหมดอายุ',
        'labour.labour_workpremit_date_end' => 'ใบอนุญาตทำงานหมดอายุ',
        'labour.labour_day90_date_end' => 'รายงานตัว 90 วันหมดอายุ',
        'labour.labour_passport_date_end' => 'หนังสือเดินทางหมดอายุ',
    ];

    public function __construct()
    {
        $this->middleware('auth');
    }

    public function exportto(Request $request)
    {
        $data = $request->validate([
            'company' => ['required', 'string'],
            'status' => ['required', Rule::in(['ALL', 'job', 'resign', 'escape'])],
            'type_date' => ['required', Rule::in(array_keys(self::DATE_TYPES))],
            'date_start' => ['required', 'date'],
            'date_end' => ['required', 'date', 'after_or_equal:date_start'],
            'type' => ['required', Rule::in(['excel', 'pdf'])],
        ], [
            'date_start.required' => 'กรุณาระบุวันที่เริ่มต้น',
            'date_end.required' => 'กรุณาระบุวันที่สิ้นสุด',
            'date_end.after_or_equal' => 'วันที่สิ้นสุดต้องไม่น้อยกว่าวันที่เริ่มต้น',
        ]);

        $company = $data['company'] === 'ALL' ? 'ALL' : (int) $data['company'];

        if ($data['type'] === 'excel') {
            return Excel::download(
                new LabourExportCustomDate($company, $data['status'], $data['type_date'], $data['date_start'], $data['date_end']),
                'LabourExportByDate_' . date('Ymd_His') . '.xlsx'
            );
        }

        $rows = DB::table('labour')
            ->leftJoin('company', 'company.company_id', '=', 'labour.company_id')
            ->leftJoin('agency', 'agency.agency_id', '=', 'labour.labour_agency')
            ->leftJoin('nationality', 'nationality.code', '=', 'labour.labour_nationality')
            ->select('labour.*', 'company.company_name', 'agency.agency_name', 'nationality.name_th')
            ->when($company !== 'ALL', fn ($q) => $q->where('labour.company_id', $company))
            ->when($data['status'] !== 'ALL', fn ($q) => $q->where('labour.labour_status_job', $data['status']))
            ->whereBetween($data['type_date'], [$data['date_start'], $data['date_end']])
            ->orderBy($data['type_date'])
            ->get();

        $title = 'รายงานข้อมูลคนต่างด้าว (' . self::DATE_TYPES[$data['type_date']] . ' ' . fmt_date($data['date_start']) . ' - ' . fmt_date($data['date_end']) . ')';

        return $this->streamPdf($rows, $title, 'LabourReportByDate_' . date('Ymd_His') . '.pdf');
    }
}
