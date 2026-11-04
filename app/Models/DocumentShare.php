<?php

namespace App\Models;

use App\Models\company\CompanyModel;
use App\Models\labour\LabourDocument;
use App\Models\labour\LabourModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * A public link to one document folder (a worker or a whole company), like sharing a
 * folder in Google Drive: optional expiry, optional password, download on/off, revocable.
 */
class DocumentShare extends Model
{
    protected $table = 'document_shares';

    protected $fillable = [
        'token', 'kind', 'target_id', 'label', 'recipient_email', 'access', 'can_download', 'password', 'expires_at', 'views', 'last_viewed_at', 'created_by', 'revoked_at',
    ];

    protected $casts = [
        'can_download' => 'boolean',
        'target_id' => 'integer',
        'views' => 'integer',
        'expires_at' => 'datetime',
        'last_viewed_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    protected $hidden = ['password'];

    public const KINDS = ['labour', 'company'];

    /** Expiry choices offered in the UI (days; 0 = never). */
    public const EXPIRY_DAYS = [1, 7, 30, 90, 0];

    private static ?bool $tableReady = null;

    /** True once the migration has run; lets every page render before that. */
    public static function tableReady(): bool
    {
        if (self::$tableReady === null) {
            try {
                self::$tableReady = Schema::hasTable('document_shares');
            } catch (\Throwable $e) {
                self::$tableReady = false;
            }
        }

        return self::$tableReady;
    }

    public static function forgetTableReady(): void
    {
        self::$tableReady = null;
    }

    private static ?bool $emailColumn = null;

    /** True once migration 2026_11_03_000003 added recipient_email. */
    public static function hasEmailColumn(): bool
    {
        if (self::$emailColumn === null) {
            try {
                self::$emailColumn = self::tableReady() && Schema::hasColumn('document_shares', 'recipient_email');
            } catch (\Throwable $e) {
                self::$emailColumn = false;
            }
        }

        return self::$emailColumn;
    }

    public static function forgetEmailColumn(): void
    {
        self::$emailColumn = null;
    }

    /** Split "a@x.com, b@y.com" into a clean list (also accepts ; and spaces). */
    public static function parseEmails(?string $raw): array
    {
        $parts = preg_split('/[\s,;]+/u', trim((string) $raw)) ?: [];

        return array_values(array_unique(array_filter(array_map('strtolower', array_map('trim', $parts)))));
    }

    /** Can the server really send mail? (MAIL_* in .env; the default "mailhog" placeholder does not count.) */
    public static function mailReady(): bool
    {
        $mailer = (string) config('mail.default');
        if (in_array($mailer, ['', 'log', 'array'], true) || (string) config('mail.from.address') === '') {
            return false;
        }
        if ($mailer === 'smtp') {
            $host = (string) config('mail.mailers.smtp.host');

            return $host !== '' && ! in_array($host, ['mailhog', 'smtp.mailgun.org'], true);
        }

        return true;
    }

    /** "email" = only the listed e-mails may open the link (after proving the address). */
    public function isRestricted(): bool
    {
        return ($this->access ?? 'anyone') === 'email';
    }

    public function allows(?string $email): bool
    {
        $email = strtolower(trim((string) $email));

        return $email !== '' && in_array($email, $this->emails(), true);
    }

    /** @return string[] */
    public function emails(): array
    {
        return self::parseEmails($this->recipient_email ?? null);
    }

    /** Short description shown next to a link in the share dialog. */
    public function summary(): string
    {
        $bits = [$this->expiryLabel(), $this->can_download ? 'ดาวน์โหลดได้' : 'ดูอย่างเดียว'];
        if ($this->isRestricted()) {
            $bits[] = 'เฉพาะอีเมลที่ระบุ';
        }
        if ($this->hasPassword()) {
            $bits[] = 'มีรหัสผ่าน';
        }
        $bits[] = 'เปิดดู ' . number_format($this->views) . ' ครั้ง';
        if ($this->label) {
            $bits[] = $this->label;
        }

        return implode(' · ', $bits);
    }

    /** Data the share dialog needs for one existing link. */
    public function toDialogArray(): array
    {
        return [
            'url' => $this->url(),
            'emails' => implode(', ', $this->emails()),
            'list' => $this->emails(),
            'restricted' => $this->isRestricted(),
            'summary' => $this->summary(),
            'revoke' => route('documents.share.revoke', $this->id),
            'update' => route('documents.share.update', $this->id),
        ];
    }

    public static function newToken(): string
    {
        do {
            $token = Str::random(40);
        } while (self::where('token', $token)->exists());

        return $token;
    }

    public function scopeActive($q)
    {
        return $q->whereNull('revoked_at')->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function scopeFor($q, string $kind, int $targetId)
    {
        return $q->where('kind', $kind)->where('target_id', $targetId);
    }

    public function isActive(): bool
    {
        return ! $this->revoked_at && (! $this->expires_at || $this->expires_at->isFuture());
    }

    /** Longest a one-click (signed) link in an e-mail stays valid when the share itself never expires. */
    public const OPEN_LINK_DAYS = 30;

    /**
     * One-click link for one recipient of a restricted share: the signature proves the link came from
     * the e-mail we sent to that address, so no code has to be typed. Still subject to allows() and
     * revocation at open time.
     */
    public function openUrl(string $email): string
    {
        $until = now()->addDays(self::OPEN_LINK_DAYS);
        if ($this->expires_at && $this->expires_at->lt($until)) {
            $until = $this->expires_at;
        }

        return URL::temporarySignedRoute('share.open', $until, ['token' => $this->token, 'email' => strtolower(trim($email))]);
    }

    public function hasPassword(): bool
    {
        return (string) $this->password !== '';
    }

    public function url(): string
    {
        return route('share.show', $this->token);
    }

    /** LabourModel or CompanyModel this link points to (null if it was deleted). */
    public function target()
    {
        return $this->kind === 'company'
            ? CompanyModel::find($this->target_id)
            : LabourModel::with('company')->find($this->target_id);
    }

    public function title(): string
    {
        $t = $this->target();
        if (! $t) {
            return 'ไม่พบข้อมูล';
        }

        return $this->kind === 'company' ? $t->company_name : trim($t->labour_fullname . ' · ' . $t->labour_passport_number, ' ·');
    }

    /** Workers covered by this link, each with their document list. */
    public function labours(): Collection
    {
        if ($this->kind === 'company') {
            return LabourModel::where('company_id', $this->target_id)->orderBy('labour_fullname')->get();
        }
        $l = LabourModel::find($this->target_id);

        return $l ? collect([$l]) : collect();
    }

    /** Is this document inside the shared folder? */
    public function covers(LabourDocument $doc): bool
    {
        if ($this->kind === 'labour') {
            return (int) $doc->labour_id === $this->target_id;
        }
        $labour = $doc->labour ?: LabourModel::find($doc->labour_id);

        return $labour && (int) $labour->company_id === $this->target_id;
    }

    public function coversLabour(LabourModel $labour): bool
    {
        return $this->kind === 'labour'
            ? (int) $labour->labour_id === $this->target_id
            : (int) $labour->company_id === $this->target_id;
    }

    public function expiryLabel(): string
    {
        if ($this->revoked_at) {
            return 'ยกเลิกแล้ว';
        }
        if (! $this->expires_at) {
            return 'ไม่มีวันหมดอายุ';
        }

        return ($this->expires_at->isPast() ? 'หมดอายุเมื่อ ' : 'หมดอายุ ') . fmt_datetime($this->expires_at);
    }
}
