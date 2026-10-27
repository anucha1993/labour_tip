<?php

namespace App\Http\Controllers\member;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    public const ROLES = ['MasterAdmin', 'Admin', 'Member'];

    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index(Request $request)
    {
        $keyword = trim((string) $request->input('keyword'));

        $users = User::query()
            ->when($keyword, function ($q, $keyword) {
                $like = '%' . $keyword . '%';
                $q->where(fn ($w) => $w->where('name', 'LIKE', $like)->orWhere('email', 'LIKE', $like));
            })
            ->orderByRaw("FIELD(type, 'MasterAdmin', 'Admin', 'Member')")
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view('member.index', ['users' => $users, 'keyword' => $keyword, 'roles' => self::ROLES]);
    }

    /** Kept for backwards compatibility with any bookmarked AJAX URL. */
    public function member_ajax()
    {
        return redirect()->route('member.index');
    }

    public function update(Request $request, $id)
    {
        $user = User::findOrFail($id);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:6', 'confirmed'],
            'type' => ['required', Rule::in(self::ROLES)],
            'status' => ['required', Rule::in(['enable', 'disable'])],
        ], [
            'name.required' => 'กรุณากรอกชื่อ-สกุล',
            'email.required' => 'กรุณากรอกอีเมล',
            'email.email' => 'รูปแบบอีเมลไม่ถูกต้อง',
            'email.unique' => 'อีเมลนี้ถูกใช้งานแล้ว',
            'password.min' => 'รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร',
            'password.confirmed' => 'ยืนยันรหัสผ่านไม่ตรงกัน',
        ]);

        // Protect the account you are logged in with from locking itself out.
        if ((int) $user->id === (int) $request->user()->id) {
            if ($data['status'] === 'disable') {
                return back()->with('error', 'ไม่สามารถปิดการใช้งานบัญชีที่กำลังใช้งานอยู่ได้');
            }
            if ($data['type'] !== 'MasterAdmin') {
                return back()->with('error', 'ไม่สามารถลดสิทธิ์ของบัญชีที่กำลังใช้งานอยู่ได้');
            }
        }

        $user->name = $data['name'];
        $user->email = $data['email'];
        $user->type = $data['type'];
        $user->status = $data['status'];
        if (! empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }
        $user->save();

        return redirect()->route('member.index')->with('success', 'บันทึกข้อมูลสมาชิก ' . $user->name . ' เรียบร้อยแล้ว');
    }

    public function destroy(Request $request, $id)
    {
        $user = User::findOrFail($id);

        if ((int) $user->id === (int) $request->user()->id) {
            return back()->with('error', 'ไม่สามารถลบบัญชีที่กำลังใช้งานอยู่ได้');
        }

        if ($user->type === 'MasterAdmin' && User::where('type', 'MasterAdmin')->where('status', 'enable')->count() <= 1) {
            return back()->with('error', 'ต้องมีผู้ดูแลระบบสูงสุดที่เปิดใช้งานอย่างน้อย 1 บัญชี');
        }

        $name = $user->name;
        $user->delete();

        return redirect()->route('member.index')->with('success', 'ลบสมาชิก ' . $name . ' เรียบร้อยแล้ว');
    }
}
