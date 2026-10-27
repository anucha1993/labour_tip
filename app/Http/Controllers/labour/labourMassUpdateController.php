<?php

namespace App\Http\Controllers\labour;

use App\Http\Controllers\Controller;
use App\Models\labour\LabourModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class labourMassUpdateController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function massupdate(Request $request)
    {
        $data = $request->validate([
            'column_name' => ['required', Rule::in(array_keys(LabourController::DATE_COLUMNS))],
            'mass_date' => ['required', 'date'],
            'labour_ids' => ['required', 'array', 'min:1'],
            'labour_ids.*' => ['integer'],
        ], [
            'column_name.required' => 'กรุณาเลือกเงื่อนไขที่ต้องการอัปเดต',
            'column_name.in' => 'เงื่อนไขที่เลือกไม่ถูกต้อง',
            'mass_date.required' => 'กรุณาระบุวันที่ที่ต้องการอัปเดต',
            'labour_ids.required' => 'กรุณาเลือกแรงงานอย่างน้อย 1 รายการก่อนทำ Mass Update',
        ]);

        $updatedBy = $request->user()->name;

        // Update through Eloquent so the action log records each change.
        $count = DB::transaction(function () use ($data, $updatedBy) {
            $count = 0;
            LabourModel::whereIn('labour_id', $data['labour_ids'])
                ->get()
                ->each(function (LabourModel $labour) use ($data, $updatedBy, &$count) {
                    $labour->update([
                        $data['column_name'] => $data['mass_date'],
                        'updated_by' => $updatedBy,
                    ]);
                    $count++;
                });

            return $count;
        });

        $label = LabourController::DATE_COLUMNS[$data['column_name']];

        return back()->with('success', "อัปเดต{$label} เป็น " . fmt_date($data['mass_date']) . " ให้แรงงาน {$count} รายการเรียบร้อยแล้ว");
    }
}
