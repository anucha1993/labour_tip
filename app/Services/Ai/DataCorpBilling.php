<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Plan / credits / payment links of the AI key when the AI goes through DataCorp
 * (OPENAI_BASE_URL=https://datacorpapi.com/v1). The key never leaves this server.
 */
class DataCorpBilling
{
    private const CACHE_KEY = 'datacorp.ai.status';

    public function enabled(): bool
    {
        $flag = config('services.openai.datacorp');

        return (bool) config('services.openai.key')
            && ($flag !== null && $flag !== '' ? filter_var($flag, FILTER_VALIDATE_BOOLEAN) : str_contains((string) config('services.openai.base_url'), 'datacorpapi.com'));
    }

    /** https://datacorpapi.com (base_url without /v1) */
    private function origin(): string
    {
        return preg_replace('#/v1/?$#', '', rtrim((string) config('services.openai.base_url'), '/'));
    }

    /**
     * ['plan', 'used', 'included', 'remaining', 'renews_at', 'blocked', 'reason', 'message',
     *  'upgrade_url', 'upgrade_plan', 'upgrade_price', 'pay_url']  — null when not using DataCorp or unreachable.
     */
    public function status(bool $fresh = false): ?array
    {
        if (! $this->enabled()) {
            return null;
        }
        if ($fresh) {
            Cache::forget(self::CACHE_KEY);
        }

        return Cache::remember(self::CACHE_KEY, 60, function () {
            try {
                $r = Http::withToken(config('services.openai.key'))->acceptJson()->timeout(15)->get($this->origin() . '/api/v1/usage');
            } catch (\Throwable $e) {
                return null;
            }
            $j = $r->json() ?: [];
            $b = $j['billing'] ?? [];
            $q = $j['quota'] ?? [];

            return [
                'plan' => $j['plan'] ?? null,
                'used' => (int) ($q['used'] ?? 0),
                'included' => isset($q['included']) ? (int) $q['included'] : null,
                'remaining' => isset($q['remaining']) ? (int) $q['remaining'] : null,
                'renews_at' => $j['renews_at'] ?? null,
                'blocked' => ! ($j['ok'] ?? false),
                'reason' => $j['error'] ?? null,
                'message' => isset($j['message']) ? explode(' — ', $j['message'])[0] : null,
                'upgrade_url' => $b['upgrade_url'] ?? null,
                'upgrade_plan' => $b['upgrade_plan'] ?? null,
                'upgrade_price' => $b['upgrade_price_thb'] ?? null,
                'pay_url' => $j['action_url'] ?? ($b['checkout_url'] ?? ($b['pay_url'] ?? null)),
            ];
        });
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
