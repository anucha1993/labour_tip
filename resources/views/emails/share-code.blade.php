<!DOCTYPE html>
<html lang="th">
<head><meta charset="utf-8"></head>
<body style="font-family: 'Noto Sans Thai', Arial, sans-serif; color: #1f2937; line-height: 1.6;">
    <p>รหัสยืนยันสำหรับเปิดเอกสาร <b>{{ $share->title() }}</b></p>
    <p style="font-size: 28px; letter-spacing: 6px; font-weight: 700; margin: 16px 0;">{{ $code }}</p>
    <p>รหัสนี้ใช้ได้ 10 นาที และใช้ได้กับอีเมลที่ได้รับลิงก์นี้เท่านั้น หากคุณไม่ได้ขอรหัส กรุณาละเว้นอีเมลฉบับนี้</p>
    <p style="margin: 20px 0;"><a href="{{ $share->openUrl($email) }}" style="display: inline-block; background: #2563eb; color: #fff; text-decoration: none; padding: 10px 20px; border-radius: 8px; font-weight: 600;">หรือกดที่นี่เพื่อเปิดทันที</a></p>
    <p style="color: #6b7280; font-size: 13px;">The Importer 168 Co., Ltd.</p>
</body>
</html>
