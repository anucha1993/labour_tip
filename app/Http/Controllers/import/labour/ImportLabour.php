<?php

namespace App\Http\Controllers\import\labour;

use App\Http\Controllers\Controller;
use App\Imports\labour\ImportLabour as LabourImport;
use App\Models\agency\AgencyModel;
use App\Models\company\CompanyModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;

class ImportLabour extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function importform()
    {
        return view('labour.import-form', [
            'nationality' => DB::table('nationality')->orderBy('id')->get(),
            'agency' => AgencyModel::orderBy('agency_name')->get(['agency_id', 'agency_name']),
            'company' => CompanyModel::orderBy('company_name')->get(['company_id', 'company_name']),
        ]);
    }

    public function import(Request $request)
    {
        $data = $request->validate([
            'company' => ['required', 'integer', Rule::exists('company', 'company_id')],
            'agency' => ['required', 'integer', Rule::exists('agency', 'agency_id')],
            'nationality' => ['required', 'string', Rule::exists('nationality', 'code')],
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:20480'],
        ], [
            'company.required' => 'กรุณาเลือกบริษัท',
            'agency.required' => 'กรุณาเลือกเอเจนซี่',
            'nationality.required' => 'กรุณาเลือกสัญชาติ',
            'file.required' => 'กรุณาเลือกไฟล์ Excel',
            'file.mimes' => 'ไฟล์ต้องเป็น .xlsx, .xls หรือ .csv เท่านั้น',
            'file.max' => 'ไฟล์ต้องมีขนาดไม่เกิน 20 MB',
        ]);

        $import = new LabourImport((int) $data['company'], (int) $data['agency'], $data['nationality'], $request->user()->name);

        try {
            // All-or-nothing: a broken row must not leave a half-imported file behind.
            DB::transaction(fn () => Excel::import($import, $request->file('file')));
        } catch (\Throwable $e) {
            report($e);

            return back()->withInput()->with('error', 'นำเข้าไม่สำเร็จ: ' . $e->getMessage());
        }

        $message = "นำเข้าข้อมูลแรงงานสำเร็จ {$import->imported} รายการ";
        if ($import->skipped) {
            $message .= " (ข้าม {$import->skipped} รายการ: " . implode(', ', array_slice($import->skippedReasons, 0, 5)) . (count($import->skippedReasons) > 5 ? ' ...' : '') . ')';
        }

        return redirect()->route('labour.index')->with($import->imported ? 'success' : 'warning', $message);
    }
}
