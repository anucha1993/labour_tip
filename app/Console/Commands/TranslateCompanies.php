<?php

namespace App\Console\Commands;

use App\Models\company\CompanyModel;
use App\Services\GoogleTranslate;
use Illuminate\Console\Command;

/**
 * Fills company_name_en / company_address_en with Google Translate.
 *
 *   php artisan company:translate           # only companies still missing an English name/address
 *   php artisan company:translate --force   # re-translate all (overwrites hand edits)
 */
class TranslateCompanies extends Command
{
    protected $signature = 'company:translate {--force : Overwrite existing English values}';

    protected $description = 'Translate company names and street lines to English for the Worker App';

    public function handle(GoogleTranslate $google): int
    {
        if (! $google->isConfigured()) {
            $this->error('GOOGLE_TRANSLATE_API_KEY is not set in .env');

            return self::FAILURE;
        }

        $query = CompanyModel::query()->orderBy('company_id');
        if (! $this->option('force')) {
            $query->where(function ($q) {
                $q->whereNull('company_name_en')->orWhere('company_name_en', '')
                    ->orWhereNull('company_address_en')->orWhere('company_address_en', '')
                    ->orWhereNull('company_type_en')->orWhere('company_type_en', '');
            });
        }
        $companies = $query->get();
        if ($companies->isEmpty()) {
            $this->info('Nothing to translate');

            return self::SUCCESS;
        }

        $this->line('Translating ' . $companies->count() . ' companies…');
        $done = 0;
        foreach ($companies->chunk(50) as $chunk) {
            $texts = [];
            foreach ($chunk as $c) {
                $texts[] = $this->option('force') || blank($c->company_name_en) ? (string) $c->company_name : '';
                $texts[] = $this->option('force') || blank($c->company_address_en) ? (string) $c->company_address : '';
                $texts[] = $this->option('force') || blank($c->company_type_en) ? (string) $c->company_type : '';
            }
            try {
                $en = $google->translate($texts);
            } catch (\RuntimeException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
            $i = 0;
            foreach ($chunk as $c) {
                $name = $en[$i++];
                $addr = $en[$i++];
                $type = $en[$i++];
                $update = [];
                if ($name !== '') {
                    $update['company_name_en'] = mb_substr($name, 0, 255);
                }
                if ($addr !== '') {
                    $update['company_address_en'] = mb_substr($addr, 0, 255);
                }
                if ($type !== '') {
                    $update['company_type_en'] = mb_substr($type, 0, 100);
                }
                if ($update) {
                    $c->forceFill($update)->saveQuietly();
                    $done++;
                }
            }
        }
        $this->info("Updated {$done} companies");

        return self::SUCCESS;
    }
}
