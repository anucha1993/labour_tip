<?php

namespace App\Services\Ai;

use RuntimeException;

/**
 * The AI provider (DataCorp) refused the request because the plan has expired, the credits are used up
 * or a bill is unpaid. $payUrl opens the payment / upgrade page directly (no login needed).
 */
class AiPaymentRequired extends RuntimeException
{
    public function __construct(string $message, public string $reason, public ?string $payUrl = null, int $status = 402)
    {
        parent::__construct($message, $status);
    }

    /** Thai message for the user, by DataCorp error code. */
    public function userMessage(): string
    {
        return [
            'inactive' => 'ช่วงทดลองใช้ / แพ็กเกจ AI หมดอายุแล้ว',
            'quota_exceeded' => 'Credits ของแพ็กเกจ AI ใช้ครบแล้ว',
            'payment_required' => 'มียอดค้างชำระค่าบริการ AI',
        ][$this->reason] ?? 'ต้องชำระค่าบริการ AI ก่อนใช้งานต่อ';
    }
}
