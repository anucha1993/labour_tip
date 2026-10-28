<?php

namespace App\Http\Controllers\member;

use App\Http\Controllers\Controller;
use App\Services\AvatarService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * The logged-in user's own account: name, password and profile picture.
 * (Role, email and status are changed by a MasterAdmin on the member page.)
 */
class ProfileController extends Controller
{
    public function __construct(private AvatarService $avatars)
    {
        $this->middleware('auth');
    }

    public function edit()
    {
        return view('member.profile', ['user' => auth()->user()]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'current_password' => ['required_with:password', 'nullable', 'current_password'],
            'password' => ['nullable', 'string', 'min:6', 'confirmed'],
        ], [
            'name.required' => 'กรุณากรอกชื่อ-สกุล',
            'current_password.required_with' => 'กรุณากรอกรหัสผ่านปัจจุบันเพื่อเปลี่ยนรหัสผ่าน',
            'current_password.current_password' => 'รหัสผ่านปัจจุบันไม่ถูกต้อง',
            'password.min' => 'รหัสผ่านใหม่ต้องมีอย่างน้อย 6 ตัวอักษร',
            'password.confirmed' => 'ยืนยันรหัสผ่านใหม่ไม่ตรงกัน',
        ]);

        $user = $request->user();
        $user->name = trim($data['name']);
        if (! empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }
        $user->save();

        return redirect()->route('profile.edit')->with('success', ! empty($data['password']) ? 'บันทึกข้อมูลและเปลี่ยนรหัสผ่านเรียบร้อยแล้ว' : 'บันทึกข้อมูลเรียบร้อยแล้ว');
    }

    public function updateAvatar(Request $request)
    {
        $request->validate([
            'avatar' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
        ], [
            'avatar.required' => 'กรุณาเลือกรูปภาพ',
            'avatar.image' => 'ไฟล์ต้องเป็นรูปภาพ',
            'avatar.mimes' => 'รองรับเฉพาะ JPG, PNG, WEBP หรือ GIF',
            'avatar.max' => 'รูปต้องมีขนาดไม่เกิน 5 MB',
        ]);

        try {
            $this->avatars->store($request->user(), $request->file('avatar'));
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('profile.edit')->with('success', 'เปลี่ยนรูปโปรไฟล์เรียบร้อยแล้ว');
    }

    public function destroyAvatar(Request $request)
    {
        $this->avatars->remove($request->user());

        return redirect()->route('profile.edit')->with('success', 'ลบรูปโปรไฟล์แล้ว');
    }
}
