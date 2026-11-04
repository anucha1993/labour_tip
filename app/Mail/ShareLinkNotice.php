<?php

namespace App\Mail;

use App\Models\DocumentShare;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/** "แชร์ให้คุณแล้ว" e-mail: the link itself, sent to each recipient when an Admin shares a folder (like Google Drive). */
class ShareLinkNotice extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public DocumentShare $share, public string $recipient, public string $sender, public ?string $note = null)
    {
    }

    public function build()
    {
        return $this->subject($this->sender . ' แชร์เอกสาร "' . $this->share->title() . '" ให้คุณ')
            ->view('emails.share-link');
    }
}
