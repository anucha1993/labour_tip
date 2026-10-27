<?php

namespace App\Http\Controllers\company;

use App\Http\Controllers\Controller;
use App\Models\company\CompanyModel;
use App\Models\labour\LabourModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CompanyController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index(Request $request)
    {
        $keyword = trim((string) $request->input('keyword'));
        $status = $request->input('status');
        if (! in_array($status, ['enable', 'disable'], true)) {
            $status = null;
        }

        $companies = CompanyModel::query()
            ->withCount('labours')
            ->when($keyword, function ($q, $keyword) {
                $like = '%' . $keyword . '%';
                $q->where(function ($w) use ($like) {
                    $w->where('company_name', 'LIKE', $like)
                        ->orWhere('company_tax', 'LIKE', $like)
                        ->orWhere('company_contact', 'LIKE', $like)
                        ->orWhere('company_contact_sale', 'LIKE', $like)
                        ->orWhere('company_phone', 'LIKE', $like);
                });
            })
            ->when($status, fn ($q, $v) => $q->where('company_status', $v))
            ->orderBy('company_name')
            ->paginate(25)
            ->withQueryString();

        return view('company.index', compact('companies', 'keyword', 'status'));
    }

    public function create()
    {
        return view('company.form-add', [
            'company' => new CompanyModel(['company_status' => 'enable']),
            'provinces' => DB::table('provinces')->orderBy('name_th')->get(),
            'amphures' => collect(),
            'districts' => collect(),
        ]);
    }

    // ---- Address cascade: returns <option> markup for the dependent select.
    public function province(Request $request)
    {
        $rows = DB::table('amphures')->where('province_id', $request->input('province'))->orderBy('name_th')->get();

        return response($this->options($rows, 'เลือกอำเภอ/เขต'));
    }

    public function amphur(Request $request)
    {
        $rows = DB::table('districts')->where('amphure_id', $request->input('amphur'))->orderBy('name_th')->get();

        return response($this->options($rows, 'เลือกตำบล/แขวง'));
    }

    public function district(Request $request)
    {
        $zip = DB::table('zipcodes')->where('district_code', $request->input('district'))->first();

        return response((string) ($zip->zipcode ?? ''));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['created_by'] = $request->user()->name;

        $company = CompanyModel::create($data);

        return redirect()->route('company.index')->with('success', 'เพิ่มบริษัท ' . $company->company_name . ' เรียบร้อยแล้ว');
    }

    public function edit(CompanyModel $companyModel)
    {
        return view('company.form-edit', [
            'company' => $companyModel,
            'provinces' => DB::table('provinces')->orderBy('name_th')->get(),
            'amphures' => DB::table('amphures')->where('province_id', $companyModel->company_province)->orderBy('name_th')->get(),
            'districts' => DB::table('districts')->where('amphure_id', $companyModel->company_amphur)->orderBy('name_th')->get(),
        ]);
    }

    public function update(Request $request, CompanyModel $companyModel)
    {
        $data = $this->validated($request);
        $data['updated_by'] = $request->user()->name;

        $companyModel->update($data);

        return redirect()->route('company.index')->with('success', 'บันทึกข้อมูลบริษัท ' . $companyModel->company_name . ' เรียบร้อยแล้ว');
    }

    public function destroy(CompanyModel $companyModel)
    {
        $inUse = LabourModel::where('company_id', $companyModel->company_id)->count();
        if ($inUse > 0) {
            return back()->with('error', "ไม่สามารถลบบริษัท {$companyModel->company_name} ได้ เนื่องจากมีแรงงาน {$inUse} รายการอ้างอิงอยู่");
        }

        $name = $companyModel->company_name;
        $companyModel->delete();

        return redirect()->route('company.index')->with('success', 'ลบบริษัท ' . $name . ' เรียบร้อยแล้ว');
    }

    // ------------------------------------------------------------------ helpers

    private function validated(Request $request): array
    {
        return $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'company_tax' => ['required', 'string', 'max:255'],
            'company_address' => ['required', 'string', 'max:255'],
            'company_province' => ['required', 'string', 'max:255'],
            'company_amphur' => ['required', 'string', 'max:255'],
            'company_district' => ['required', 'string', 'max:255'],
            'company_zipcode' => ['required', 'string', 'max:10'],
            'company_contact' => ['required', 'string', 'max:100'],
            'company_phone' => ['required', 'string', 'max:100'],
            'company_email' => ['nullable', 'email', 'max:100'],
            'company_fax' => ['nullable', 'string', 'max:100'],
            'company_contact1' => ['nullable', 'string', 'max:100'],
            'company_contact2' => ['nullable', 'string', 'max:100'],
            'company_contact3' => ['nullable', 'string', 'max:100'],
            'company_contact4' => ['nullable', 'string', 'max:100'],
            'company_contact5' => ['nullable', 'string', 'max:100'],
            'company_type' => ['required', 'string', 'max:100'],
            'company_contact_sale' => ['required', 'string', 'max:100'],
            'company_note' => ['nullable', 'string', 'max:5000'],
            'company_status' => ['required', Rule::in(['enable', 'disable'])],
        ], [
            'company_name.required' => 'กรุณากรอกชื่อบริษัท/นายจ้าง',
            'company_tax.required' => 'กรุณากรอกเลขทะเบียนบริษัท',
            'company_address.required' => 'กรุณากรอกที่อยู่',
            'company_province.required' => 'กรุณาเลือกจังหวัด',
            'company_amphur.required' => 'กรุณาเลือกอำเภอ/เขต',
            'company_district.required' => 'กรุณาเลือกตำบล/แขวง',
            'company_zipcode.required' => 'กรุณากรอกรหัสไปรษณีย์',
            'company_contact.required' => 'กรุณากรอกชื่อผู้ติดต่อ',
            'company_phone.required' => 'กรุณากรอกเบอร์ติดต่อ',
            'company_type.required' => 'กรุณากรอกประเภทกิจการ',
            'company_contact_sale.required' => 'กรุณากรอกผู้ประสานงานการตลาด',
            'company_status.required' => 'กรุณาเลือกสถานะ',
            'company_email.email' => 'รูปแบบอีเมลไม่ถูกต้อง',
            // company_email column is NOT NULL in the database
        ]) + ['company_email' => (string) $request->input('company_email'), 'company_contact1' => (string) $request->input('company_contact1')];
    }

    private function options($rows, string $placeholder): string
    {
        $html = '<option value="">' . e($placeholder) . '</option>';
        foreach ($rows as $row) {
            $html .= '<option value="' . e($row->id) . '">' . e($row->name_th) . '</option>';
        }

        return $html;
    }
}
