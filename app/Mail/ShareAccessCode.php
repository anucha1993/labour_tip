<?php

namespace App\Mail;

use App\Models\DocumentShare;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/** One-time code sent to a recipient of a restricted share link ("เฉพาะอีเมลที่ระบุ"). */
class ShareAccessCode extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public DocumentShare $share, public string $code, public string $email = '')
    {
    }

    public function build()
    {
        return $this->subject('รหัสยืนยันสำหรับเปิดเอกสาร ' . $this->share->title())
            ->view('emails.share-code');
    }
}
