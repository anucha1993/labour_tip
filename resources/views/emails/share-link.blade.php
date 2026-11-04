<!DOCTYPE html>
<html lang="th">
<head><meta charset="utf-8"></head>
<body style="font-family: 'Noto Sans Thai', Arial, sans-serif; color: #1f2937; line-height: 1.6; max-width: 560px;">
    <p><b>{{ $sender }}</b> แชร์โฟลเดอร์เอกสาร <b>{{ $share->title() }}</b>{{ $share->kind === 'company' ? ' (เอกสารแรงงานทุกคนของบริษัท)' : '' }} ให้คุณ</p>
    @if ($note)
        <blockquote style="margin: 12px 0; padding: 10px 14px; border-left: 3px solid #d1d5db; color: #374151; white-space: pre-line;">{{ $note }}</blockquote>
    @endif
    <p style="margin: 20px 0;">
        <a href="{{ $share->isRestricted() ? $share->openUrl($recipient) : $share->url() }}" style="display: inline-block; background: #2563eb; color: #fff; text-decoration: none; padding: 10px 20px; border-radius: 8px; font-weight: 600;">เปิดโฟลเดอร์</a>
    </p>
    <p style="font-size: 13px; color: #6b7280; word-break: break-all;">หรือเปิดลิงก์นี้: <a href="{{ $share->url() }}" style="color: #2563eb;">{{ $share->url() }}</a></p>
    <ul style="padding-left: 18px; font-size: 14px;">
        @if ($share->isRestricted())
            <li>ลิงก์นี้เปิดได้เฉพาะอีเมล <b>{{ $recipient }}</b> ปุ่ม "เปิดโฟลเดอร์" ด้านบนผูกกับอีเมลของคุณ กดแล้วเข้าได้ทันทีโดยไม่ต้องกรอกรหัส <b>กรุณาอย่าส่งต่ออีเมลฉบับนี้</b> หากเปิดจากลิงก์ธรรมดา ระบบจะขอให้กรอกอีเมลนี้และรหัสยืนยัน 6 หลักที่ส่งไปทางอีเมล</li>
        @endif
        @if ($share->hasPassword())
            <li>ลิงก์นี้มีรหัสผ่าน ผู้แชร์จะแจ้งรหัสให้แยกต่างหาก</li>
        @endif
        <li>{{ $share->can_download ? 'ดูและดาวน์โหลดไฟล์ได้' : 'ดูได้อย่างเดียว ดาวน์โหลดไม่ได้' }} · {{ $share->expiryLabel() }}</li>
    </ul>
    <p style="color: #6b7280; font-size: 13px;">หากคุณไม่รู้จักผู้ส่ง กรุณาละเว้นอีเมลฉบับนี้<br>The Importer 168 Co., Ltd.</p>
</body>
</html>
