<?php

namespace App\Http\Controllers\agency;

use App\Http\Controllers\Controller;
use App\Models\agency\AgencyModel;
use App\Models\labour\LabourModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AgencyController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index(Request $request)
    {
        $keyword = trim((string) $request->input('keyword'));

        $agencies = AgencyModel::query()
            ->leftJoin('nationality', 'nationality.code', '=', 'agency.agency_nationality')
            ->select('agency.*', 'nationality.name_th')
            ->withCount('labours')
            ->when($keyword, function ($q, $keyword) {
                $like = '%' . $keyword . '%';
                $q->where(function ($w) use ($like) {
                    $w->where('agency.agency_name', 'LIKE', $like)
                        ->orWhere('agency.agency_contact', 'LIKE', $like)
                        ->orWhere('agency.agency_phone', 'LIKE', $like)
                        ->orWhere('nationality.name_th', 'LIKE', $like);
                });
            })
            ->orderBy('agency.agency_name')
            ->paginate(25)
            ->withQueryString();

        return view('agency.index', compact('agencies', 'keyword'));
    }

    public function create()
    {
        return view('agency.form-add', [
            'agency' => new AgencyModel(),
            'nationalities' => DB::table('nationality')->orderBy('id')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['created_by'] = $request->user()->name;

        $agency = AgencyModel::create($data);

        return redirect()->route('agency.index')->with('success', 'เพิ่มเอเจนซี่ ' . $agency->agency_name . ' เรียบร้อยแล้ว');
    }

    public function edit(AgencyModel $agencyModel)
    {
        return view('agency.form-edit', [
            'agency' => $agencyModel,
            'nationalities' => DB::table('nationality')->orderBy('id')->get(),
        ]);
    }

    public function update(Request $request, AgencyModel $agencyModel)
    {
        $data = $this->validated($request);
        $data['updated_by'] = $request->user()->name;

        $agencyModel->update($data);

        return redirect()->route('agency.index')->with('success', 'บันทึกข้อมูลเอเจนซี่ ' . $agencyModel->agency_name . ' เรียบร้อยแล้ว');
    }

    public function destroy(AgencyModel $agencyModel)
    {
        $inUse = LabourModel::where('labour_agency', $agencyModel->agency_id)->count();
        if ($inUse > 0) {
            return back()->with('error', "ไม่สามารถลบเอเจนซี่ {$agencyModel->agency_name} ได้ เนื่องจากมีแรงงาน {$inUse} รายการอ้างอิงอยู่");
        }

        $name = $agencyModel->agency_name;
        $agencyModel->delete();

        return redirect()->route('agency.index')->with('success', 'ลบเอเจนซี่ ' . $name . ' เรียบร้อยแล้ว');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'agency_name' => ['required', 'string', 'max:255'],
            'agency_tax' => ['required', 'string', 'max:20'],
            'agency_nationality' => ['required', 'string', Rule::exists('nationality', 'code')],
            'agency_address' => ['required', 'string', 'max:255'],
            'agency_email' => ['nullable', 'email', 'max:150'],
            'agency_contact' => ['nullable', 'string', 'max:150'],
            'agency_phone' => ['nullable', 'string', 'max:150'],
            'agency_note' => ['nullable', 'string', 'max:150'],
        ], [
            'agency_name.required' => 'กรุณากรอกชื่อเอเจนซี่',
            'agency_tax.required' => 'กรุณากรอกเลขทะเบียนบริษัท',
            'agency_tax.max' => 'เลขทะเบียนบริษัทต้องไม่เกิน 20 ตัวอักษร',
            'agency_nationality.required' => 'กรุณาเลือกสัญชาติ',
            'agency_address.required' => 'กรุณากรอกที่อยู่',
            'agency_email.email' => 'รูปแบบอีเมลไม่ถูกต้อง',
            'agency_note.max' => 'หมายเหตุต้องไม่เกิน 150 ตัวอักษร',
        ]);
    }
}
