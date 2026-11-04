<?php

namespace App\Http\Controllers\documents;

use App\Http\Controllers\Controller;
use App\Mail\ShareAccessCode;
use App\Mail\ShareLinkNotice;
use App\Models\company\CompanyModel;
use App\Models\DocumentShare;
use App\Models\labour\LabourDocument;
use App\Models\labour\LabourModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * "แชร์โฟลเดอร์" like Google Drive: an Admin creates a link to one worker's or one
 * company's documents. Access is either "anyone with the link" or "only these e-mails"
 * (the recipient proves the address with a one-time code when mail is configured).
 * Public routes never expose real file paths.
 */
class DocumentShareController extends Controller
{
    private const CODE_TTL_MINUTES = 10;

    // ------------------------------------------------------------------ management (Admin+)

    public function index()
    {
        abort_unless(DocumentShare::tableReady(), 404, 'ยังไม่ได้สร้างตาราง document_shares กรุณารัน php artisan migrate');

        $shares = DocumentShare::query()
            ->orderByRaw('(revoked_at IS NULL AND (expires_at IS NULL OR expires_at > ?)) DESC', [now()])
            ->orderByDesc('id')
            ->paginate(30);

        return view('documents.shares', ['shares' => $shares]);
    }

    public function store(Request $request)
    {
        abort_unless(DocumentShare::tableReady(), 404, 'ยังไม่ได้สร้างตาราง document_shares กรุณารัน php artisan migrate');

        $data = $request->validate([
            'kind' => ['required', 'in:labour,company'],
            'target_id' => ['required', 'integer'],
            'label' => ['nullable', 'string', 'max:150'],
            'recipient_email' => ['nullable', 'string', 'max:500'],
            'access' => ['nullable', 'in:anyone,email'],
            'return_to' => ['nullable', 'string', 'max:2000'],
            'expires_days' => ['required', 'integer', 'in:' . implode(',', DocumentShare::EXPIRY_DAYS)],
            'password' => ['nullable', 'string', 'min:4', 'max:50'],
            'can_download' => ['nullable', 'boolean'],
            'message' => ['nullable', 'string', 'max:1000'],
        ], [
            'password.min' => 'รหัสผ่านต้องมีอย่างน้อย 4 ตัวอักษร',
        ]);

        $target = $data['kind'] === 'company' ? CompanyModel::find($data['target_id']) : LabourModel::find($data['target_id']);
        abort_unless($target, 404);

        $emails = $this->cleanEmails($data['recipient_email'] ?? null);
        if (is_string($emails)) {
            return back()->withInput()->withErrors(['recipient_email' => $emails]);
        }
        // Safe by default: a link is restricted to its recipients unless "anyone" was chosen on purpose.
        // (No recipient at all can only mean "anyone" - there is nobody to restrict it to.)
        $access = ($data['access'] ?? ($emails ? 'email' : 'anyone')) === 'anyone' ? 'anyone' : 'email';
        if ($access === 'email' && ! $emails) {
            return back()->withInput()->withErrors(['recipient_email' => 'โหมด "เฉพาะอีเมลที่ระบุ" ต้องใส่อีเมลอย่างน้อย 1 รายการ']);
        }
        if (($access === 'email' || $emails) && ! DocumentShare::hasEmailColumn()) {
            return back()->withInput()->withErrors(['recipient_email' => 'ยังใช้อีเมลไม่ได้ กรุณารัน php artisan migrate ก่อน (migration 2026_11_03_000003)']);
        }

        // The e-mail columns arrive with migration 2026_11_03_000003; never mention them before that.
        $share = DocumentShare::create((DocumentShare::hasEmailColumn() ? ['recipient_email' => $emails ? implode(', ', $emails) : null, 'access' => $access] : []) + [
            'token' => DocumentShare::newToken(),
            'kind' => $data['kind'],
            'target_id' => $data['target_id'],
            'label' => trim((string) ($data['label'] ?? '')) ?: null,
            'can_download' => (bool) ($data['can_download'] ?? false),
            'password' => ($data['password'] ?? '') !== '' ? Hash::make($data['password']) : null,
            'expires_at' => (int) $data['expires_days'] > 0 ? now()->addDays((int) $data['expires_days']) : null,
            'created_by' => $request->user()->name,
        ]);

        [$sent, $mailError] = $this->notifyRecipients($share, $emails, $request->user()->name, $data['message'] ?? null);

        return redirect($this->returnTo($data['return_to'] ?? null, $share))
            ->with('success', 'สร้างลิงก์แชร์แล้ว ' . ($share->expires_at ? 'ใช้ได้ถึง ' . fmt_datetime($share->expires_at) : 'ไม่มีวันหมดอายุ') . ($access === 'email' ? ' · เปิดได้เฉพาะอีเมลที่ระบุ' : ''))
            ->with('share_url', $share->url())
            ->with('share_id', $share->id)
            ->with('share_title', $share->title())
            ->with('share_emails', implode(', ', $emails))
            ->with('share_sent', implode(', ', $sent))
            ->with('share_mail_error', $mailError)
            ->with('share_restricted', $share->isRestricted())
            ->with('share_has_password', $share->hasPassword());
    }

    /** Change who a link is shared with (add / remove e-mails) or its access mode. */
    public function update(Request $request, DocumentShare $share)
    {
        abort_unless(DocumentShare::hasEmailColumn(), 404, 'กรุณารัน php artisan migrate ก่อน (migration 2026_11_03_000003)');

        $data = $request->validate([
            'recipient_email' => ['nullable', 'string', 'max:500'],
            'access' => ['nullable', 'in:anyone,email'],
            'return_to' => ['nullable', 'string', 'max:2000'],
        ]);

        $emails = $this->cleanEmails($data['recipient_email'] ?? null);
        if (is_string($emails)) {
            return back()->withErrors(['recipient_email' => $emails]);
        }
        $access = $data['access'] ?? ($share->access ?: 'anyone');
        if ($access === 'email' && ! $emails) {
            return back()->withErrors(['recipient_email' => 'โหมด "เฉพาะอีเมลที่ระบุ" ต้องเหลืออีเมลอย่างน้อย 1 รายการ ถ้าต้องการปิดทั้งลิงก์ให้กดยกเลิกลิงก์แทน']);
        }

        $removed = array_diff($share->emails(), $emails);
        $added = array_values(array_diff($emails, $share->emails()));
        $share->update(['recipient_email' => $emails ? implode(', ', $emails) : null, 'access' => $access]);
        [$sent, $mailError] = $this->notifyRecipients($share, $added, $request->user()->name);

        $msg = $removed
            ? 'ลบสิทธิ์ของ ' . implode(', ', $removed) . ' แล้ว' . ($share->isRestricted() ? ' คนนั้นจะเปิดลิงก์ไม่ได้อีก' : '')
            : ($sent ? 'ส่งลิงก์ไปที่ ' . implode(', ', $sent) . ' แล้ว' : 'อัปเดตรายชื่อผู้รับของลิงก์แล้ว');

        return redirect($this->returnTo($data['return_to'] ?? null, $share))->with('success', $msg)->with($mailError ? 'warning' : 'share_noop', $mailError);
    }

    /** Send (again) the link to the recipients on file, or to one address given as `email`. */
    public function notify(Request $request, DocumentShare $share)
    {
        abort_unless($share->isActive(), 404, 'ลิงก์นี้ถูกยกเลิกหรือหมดอายุแล้ว');
        if (! DocumentShare::mailReady()) {
            return back()->withErrors(['email' => 'server ยังไม่ได้ตั้งค่าส่งอีเมล (MAIL_* ใน .env)']);
        }

        $data = $request->validate(['email' => ['nullable', 'email', 'max:150'], 'message' => ['nullable', 'string', 'max:1000']]);
        $emails = ! empty($data['email']) ? [strtolower(trim($data['email']))] : $share->emails();
        if (! $emails) {
            return back()->withErrors(['email' => 'ลิงก์นี้ยังไม่มีอีเมลผู้รับ']);
        }
        if ($share->isRestricted() && ! $share->allows($emails[0])) {
            return back()->withErrors(['email' => 'อีเมลนี้ไม่อยู่ในรายชื่อผู้รับของลิงก์แบบจำกัดอีเมล กดเพิ่มอีเมลในหน้าต่างแชร์แทน']);
        }

        [$sent, $mailError] = $this->notifyRecipients($share, $emails, $request->user()->name, $data['message'] ?? null);

        return back()->with($sent ? 'success' : 'warning', $sent ? 'ส่งลิงก์ไปที่ ' . implode(', ', $sent) . ' แล้ว' : ($mailError ?: 'ส่งอีเมลไม่สำเร็จ'));
    }

    public function revoke(Request $request, DocumentShare $share)
    {
        if (! $share->revoked_at) {
            $share->update(['revoked_at' => now()]);
        }

        return back()->with('success', 'ยกเลิกลิงก์แชร์ "' . $share->title() . '" แล้ว ลิงก์เดิมเปิดไม่ได้อีก');
    }

    // ------------------------------------------------------------------ public

    public function show(Request $request, string $token)
    {
        $share = $this->resolve($token);
        if ($share instanceof \Symfony\Component\HttpFoundation\Response) {
            return $share;
        }
        if ($gate = $this->gate($request, $share)) {
            return $gate;
        }

        // Count a view once per browser session, not on every click inside the folder.
        $seenKey = 'share_seen_' . $share->id;
        if (! $request->session()->get($seenKey)) {
            $share->increment('views');
            $share->forceFill(['last_viewed_at' => now()])->saveQuietly();
            $request->session()->put($seenKey, true);
        }

        $labours = $share->labours();
        $counts = LabourDocument::tableReady()
            ? LabourDocument::whereIn('labour_id', $labours->pluck('labour_id'))->select('labour_id', DB::raw('COUNT(*) AS c'))->groupBy('labour_id')->pluck('c', 'labour_id')
            : collect();
        $countOf = fn (LabourModel $l) => LabourDocument::tableReady() ? (int) ($counts[$l->labour_id] ?? 0) : $l->documentList()->count();
        $total = $labours->sum($countOf);

        // A company link opens as a folder of workers; ?labour=ID (or a one-worker link) shows that worker's files.
        $current = null;
        if ($share->kind === 'labour') {
            $current = $labours->first();
        } elseif ($id = (int) $request->input('labour')) {
            $current = $labours->firstWhere('labour_id', $id);
            abort_unless($current, 404);
        }

        return view('documents.share-public', [
            'share' => $share,
            'labours' => $labours,
            'countOf' => $countOf,
            'total' => $total,
            'current' => $current,
            'documents' => $current ? $current->documentList() : collect(),
            'viewerEmail' => $this->unlockedEmail($request, $share),
        ]);
    }

    /**
     * One endpoint for the whole gate: e-mail (+ link password) → one-time code → open.
     * Without a configured mailer the e-mail is checked against the list only.
     */
    /**
     * One-click link from the e-mail (signed per recipient). Proves the address without a code; a
     * password-protected link still asks for the password. Forwarded, expired or tampered links fall
     * back to the normal e-mail + code page.
     */
    public function open(Request $request, string $token)
    {
        $share = $this->resolve($token);
        if ($share instanceof \Symfony\Component\HttpFoundation\Response) {
            return $share;
        }
        $email = strtolower(trim((string) $request->query('email')));
        if (! $share->isRestricted() || ! $request->hasValidSignature() || ! $share->allows($email)) {
            return redirect()->route('share.show', $share->token)
                ->withErrors(['email' => 'ลิงก์ยืนยันในอีเมลใช้ไม่ได้แล้ว (หมดอายุ ถูกถอนสิทธิ์ หรือถูกแก้ไข) กรอกอีเมลของคุณเพื่อรับรหัสยืนยันใหม่']);
        }
        $request->session()->forget('share_pending_' . $share->id);
        if ($share->hasPassword()) {
            $request->session()->put($this->verifiedKey($share), $email);

            return redirect()->route('share.show', $share->token)->with('status', 'ยืนยันอีเมล ' . $email . ' แล้ว ใส่รหัสผ่านของลิงก์เพื่อเปิด');
        }
        $request->session()->forget($this->verifiedKey($share));
        $request->session()->put($this->sessionKey($share), ['email' => $email, 'at' => now()->toDateTimeString()]);

        return redirect()->route('share.show', $share->token);
    }

    public function unlock(Request $request, string $token)
    {
        $share = $this->resolve($token);
        if ($share instanceof \Symfony\Component\HttpFoundation\Response) {
            return $share;
        }
        $email = strtolower(trim((string) $request->input('email')));
        $pendingKey = 'share_pending_' . $share->id;

        // Step 2: the code from the e-mail.
        if ($request->filled('code') || $request->boolean('resend')) {
            abort_unless($share->isRestricted() && $share->allows($email) && $request->session()->get($pendingKey) === $email, 403);
            if ($request->boolean('resend')) {
                $this->sendCode($share, $email);

                return back()->with('status', 'ส่งรหัสใหม่ไปที่ ' . $email . ' แล้ว');
            }
            $cacheKey = $this->codeKey($share, $email);
            if (! hash_equals((string) Cache::get($cacheKey, ''), preg_replace('/\D/', '', (string) $request->input('code')))) {
                return back()->withErrors(['code' => 'รหัสไม่ถูกต้องหรือหมดอายุ'])->withInput(['email' => $email]);
            }
            Cache::forget($cacheKey);
            $request->session()->forget($pendingKey);
            $request->session()->put($this->sessionKey($share), ['email' => $email, 'at' => now()->toDateTimeString()]);

            return redirect()->route('share.show', $share->token);
        }

        // Step 1: e-mail and/or password.
        if ($share->isRestricted()) {
            $request->validate(['email' => ['required', 'email']], ['email.required' => 'กรุณากรอกอีเมล', 'email.email' => 'รูปแบบอีเมลไม่ถูกต้อง']);
            if (! $share->allows($email)) {
                return back()->withInput()->withErrors(['email' => 'อีเมลนี้ไม่ได้รับสิทธิ์ให้เปิดโฟลเดอร์นี้']);
            }
        }
        if ($share->hasPassword()) {
            $request->validate(['password' => ['required', 'string']], ['password.required' => 'กรุณาใส่รหัสผ่าน']);
            if (! Hash::check($request->input('password'), $share->password)) {
                return back()->withInput($request->only('email'))->withErrors(['password' => 'รหัสผ่านไม่ถูกต้อง']);
            }
        }
        if (! $share->isRestricted() && ! $share->hasPassword()) {
            return redirect()->route('share.show', $share->token);
        }

        $verified = $share->isRestricted() && $request->session()->get($this->verifiedKey($share)) === $email;
        if ($share->isRestricted() && DocumentShare::mailReady() && ! $verified) {
            $this->sendCode($share, $email);
            $request->session()->put($pendingKey, $email);

            return view('documents.share-access', ['share' => $share, 'step' => 'code', 'email' => $email, 'mailReady' => true]);
        }

        $request->session()->forget($this->verifiedKey($share));
        $request->session()->put($this->sessionKey($share), ['email' => $share->isRestricted() ? $email : null, 'at' => now()->toDateTimeString()]);

        return redirect()->route('share.show', $share->token);
    }

    public function file(Request $request, string $token, LabourDocument $document)
    {
        $share = $this->guardFile($request, $token);
        abort_unless($share->covers($document) && $document->exists(), 404);

        return $document->openResponse($share->can_download && $request->boolean('download'));
    }

    /** Files that still live only in a legacy labour_file_* column. */
    public function legacy(Request $request, string $token, LabourModel $labourModel, string $column)
    {
        $share = $this->guardFile($request, $token);
        abort_unless($share->coversLabour($labourModel) && array_key_exists($column, LabourDocument::LEGACY_COLUMNS) && $labourModel->{$column}, 404);

        $path = $labourModel->labour_passport_number . '/' . $labourModel->{$column};
        abort_unless(Storage::disk('public')->exists($path), 404);

        return Storage::disk('public')->response($path, $labourModel->{$column}, [], $share->can_download && $request->boolean('download') ? 'attachment' : 'inline');
    }

    // ------------------------------------------------------------------ helpers

    /** The share for a token, or a friendly "gone" page. */
    private function resolve(string $token)
    {
        $share = DocumentShare::tableReady() ? DocumentShare::where('token', $token)->first() : null;
        if (! $share || ! $share->isActive() || ! $share->target()) {
            return response()->view('documents.share-gone', ['share' => $share], 410);
        }

        return $share;
    }

    /** The access page when the viewer still has to identify, otherwise null. */
    private function gate(Request $request, DocumentShare $share)
    {
        if (! $share->isRestricted() && ! $share->hasPassword()) {
            return null;
        }
        if ($this->unlocked($request, $share)) {
            return null;
        }
        $pending = $request->session()->get('share_pending_' . $share->id);
        if ($pending && $share->allows($pending) && DocumentShare::mailReady()) {
            return view('documents.share-access', ['share' => $share, 'step' => 'code', 'email' => $pending, 'mailReady' => true]);
        }

        $verified = (string) $request->session()->get($this->verifiedKey($share));

        return view('documents.share-access', ['share' => $share, 'step' => 'email', 'mailReady' => DocumentShare::mailReady(), 'verifiedEmail' => $verified !== '' && $share->allows($verified) ? $verified : null]);
    }

    private function guardFile(Request $request, string $token): DocumentShare
    {
        $share = $this->resolve($token);
        abort_if($share instanceof \Symfony\Component\HttpFoundation\Response, 410, 'ลิงก์นี้หมดอายุหรือถูกยกเลิกแล้ว');
        abort_if(($share->isRestricted() || $share->hasPassword()) && ! $this->unlocked($request, $share), 403, 'กรุณายืนยันตัวตนก่อนเปิดไฟล์');

        return $share;
    }

    /** Unlocked in this session, and (for restricted links) the e-mail is still on the list. */
    private function unlocked(Request $request, DocumentShare $share): bool
    {
        $state = $request->session()->get($this->sessionKey($share));
        if (! $state) {
            return false;
        }
        if ($share->isRestricted()) {
            return $share->allows((string) (is_array($state) ? ($state['email'] ?? '') : ''));
        }

        return true;
    }

    private function unlockedEmail(Request $request, DocumentShare $share): ?string
    {
        $state = $request->session()->get($this->sessionKey($share));

        return is_array($state) ? ($state['email'] ?? null) : null;
    }

    /**
     * E-mail the link to each address (when the server can send mail). Never lets a mail failure
     * lose the share that was just created: returns [sent addresses, error message|null].
     */
    private function notifyRecipients(DocumentShare $share, array $emails, string $sender, ?string $message = null): array
    {
        if (! $emails || ! DocumentShare::mailReady()) {
            return [[], null];
        }
        $sent = [];
        try {
            foreach ($emails as $email) {
                Mail::to($email)->send(new ShareLinkNotice($share, $email, $sender, trim((string) $message) ?: null));
                $sent[] = $email;
            }
        } catch (\Throwable $e) {
            report($e);

            return [$sent, 'ส่งอีเมลไม่สำเร็จ (' . $e->getMessage() . ') ลิงก์ถูกสร้างแล้ว คัดลอกไปส่งเองได้'];
        }

        return [$sent, null];
    }

    private function sendCode(DocumentShare $share, string $email): void
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Cache::put($this->codeKey($share, $email), $code, now()->addMinutes(self::CODE_TTL_MINUTES));
        Mail::to($email)->send(new ShareAccessCode($share, $code, $email));
    }

    private function codeKey(DocumentShare $share, string $email): string
    {
        return 'share_otp_' . $share->id . '_' . sha1($email);
    }

    private function sessionKey(DocumentShare $share): string
    {
        return 'share_unlocked_' . $share->id;
    }

    /** Session slot for an address proven by the one-click link but still waiting for the password. */
    private function verifiedKey(DocumentShare $share): string
    {
        return 'share_verified_' . $share->id;
    }

    /** Clean list of e-mails, or an error message string. */
    private function cleanEmails(?string $raw)
    {
        $emails = DocumentShare::parseEmails($raw);
        foreach ($emails as $email) {
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return 'อีเมลไม่ถูกต้อง: ' . $email;
            }
        }

        return $emails;
    }

    /** Go back to the explorer page the dialog was opened from, never elsewhere. */
    private function returnTo(?string $returnTo, DocumentShare $share): string
    {
        $returnTo = (string) $returnTo;

        return str_starts_with($returnTo, route('documents.index'))
            ? $returnTo
            : route('documents.index', $share->kind === 'company' ? ['company' => $share->target_id] : ['labour' => $share->target_id]);
    }
}
