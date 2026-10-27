<?php

namespace App\Http\Controllers\settings;

use App\Http\Controllers\Controller;
use App\Models\settings\settingsModel;
use App\Services\ExpiryService;
use Illuminate\Http\Request;

class settingsController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index()
    {
        $settings = settingsModel::orderBy('set_id')->get();

        return view('settings.expire.index', [
            'settings' => $settings,
            'labels' => ExpiryService::LABELS,
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'set_id' => ['required', 'array'],
            'set_id.*' => ['required', 'integer', 'exists:settings,set_id'],
            'set_expire' => ['required', 'array'],
            'set_expire.*' => ['required', 'integer', 'min:0', 'max:3650'],
        ], [
            'set_expire.*.required' => 'กรุณากรอกจำนวนวันให้ครบทุกรายการ',
            'set_expire.*.integer' => 'จำนวนวันต้องเป็นตัวเลขเท่านั้น',
            'set_expire.*.min' => 'จำนวนวันต้องไม่ติดลบ',
            'set_expire.*.max' => 'จำนวนวันต้องไม่เกิน 3650 วัน',
        ]);

        foreach ($data['set_id'] as $i => $id) {
            if (! array_key_exists($i, $data['set_expire'])) {
                continue;
            }
            settingsModel::where('set_id', $id)->update(['set_expire' => (int) $data['set_expire'][$i]]);
        }

        return back()->with('success', 'บันทึกการตั้งค่าวันแจ้งเตือนเรียบร้อยแล้ว');
    }
}
