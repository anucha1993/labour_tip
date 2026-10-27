<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Foundation\Auth\RegistersUsers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * "Add member" screen. Only a MasterAdmin can reach it (see routes/web.php);
 * it creates another account without logging the current user out.
 */
class RegisterController extends Controller
{
    use RegistersUsers;

    public function __construct()
    {
        $this->middleware('auth');
    }

    public function register(Request $request)
    {
        $this->validator($request->all())->validate();

        $user = $this->create($request->all());

        return redirect()->route('member.index')->with('success', 'เพิ่มสมาชิก ' . $user->name . ' เรียบร้อยแล้ว');
    }

    protected function validator(array $data)
    {
        return Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'type' => ['required', Rule::in(['MasterAdmin', 'Admin', 'Member'])],
            'status' => ['required', Rule::in(['enable', 'disable'])],
        ], [
            'name.required' => 'กรุณากรอกชื่อ-สกุล',
            'email.required' => 'กรุณากรอกอีเมล',
            'email.email' => 'รูปแบบอีเมลไม่ถูกต้อง',
            'email.unique' => 'อีเมลนี้ถูกใช้งานแล้ว',
            'password.required' => 'กรุณากรอกรหัสผ่าน',
            'password.min' => 'รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร',
            'password.confirmed' => 'ยืนยันรหัสผ่านไม่ตรงกัน',
            'type.required' => 'กรุณาเลือกระดับการใช้งาน',
            'status.required' => 'กรุณาเลือกสถานะการใช้งาน',
        ]);
    }

    protected function create(array $data)
    {
        return User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'type' => $data['type'],
            'status' => $data['status'],
            'password' => Hash::make($data['password']),
        ]);
    }
}
