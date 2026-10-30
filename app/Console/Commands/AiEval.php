<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Ai\LabourAssistant;
use Illuminate\Console\Command;

/**
 * Runs the questions in tests/ai-eval.json against the REAL model and checks each answer:
 *   expect_tools    – every listed tool must have been called
 *   expect_any_tools – at least one of these must have been called
 *   forbid_tools    – none of these may be called
 *   expect_contains – every string must appear in the answer (case-insensitive)
 *   role            – Member | Admin | MasterAdmin (default MasterAdmin)
 *
 *   php artisan ai:eval                    # all cases
 *   php artisan ai:eval --only=expiry      # cases whose id contains "expiry"
 *   php artisan ai:eval --model=gpt-4.1    # try another model without touching .env
 *
 * Run it after changing the prompt, the tools or the model: it tells you whether the assistant got better or worse.
 * Costs real tokens (one question ≈ 1–3 tool rounds). Nothing is written to the database: edits stop at propose_update.
 */
class AiEval extends Command
{
    protected $signature = 'ai:eval {--only= : run only cases whose id contains this text} {--model= : model override} {--file=tests/ai-eval.json}';

    protected $description = 'Evaluate the AI assistant against a fixed set of questions (uses the real model)';

    public function handle(): int
    {
        $path = base_path($this->option('file'));
        $cases = json_decode((string) @file_get_contents($path), true);
        if (! is_array($cases) || ! $cases) {
            $this->error("No cases in {$path}");

            return self::FAILURE;
        }
        if ($this->option('model')) {
            config(['services.openai.model' => $this->option('model')]);
            app()->forgetInstance(\App\Services\Ai\OpenAiClient::class);
        }
        if ($only = $this->option('only')) {
            $cases = array_values(array_filter($cases, fn ($c) => str_contains($c['id'] ?? '', $only)));
        }

        $users = [];
        $pass = 0;
        $rows = [];
        $tokens = 0;
        $this->line('Model: ' . config('services.openai.model') . ' · ' . count($cases) . ' cases');

        foreach ($cases as $i => $c) {
            $role = $c['role'] ?? 'MasterAdmin';
            $users[$role] = $users[$role] ?? (User::where('type', $role)->where('status', 'enable')->first() ?: User::where('type', $role)->first());
            if (! $users[$role]) {
                $rows[] = [$c['id'] ?? $i, 'SKIP', 'no user with role ' . $role, ''];
                continue;
            }

            $assistant = app(LabourAssistant::class);
            $t = microtime(true);
            try {
                $r = $assistant->ask((string) $c['question'], $c['history'] ?? [], $users[$role]);
            } catch (\Throwable $e) {
                $rows[] = [$c['id'] ?? $i, 'ERROR', mb_substr($e->getMessage(), 0, 80), ''];
                continue;
            }
            $took = round(microtime(true) - $t, 1) . 's';
            $tokens += (int) ($r['usage']['prompt_tokens'] ?? 0) + (int) ($r['usage']['completion_tokens'] ?? 0);

            $problems = [];
            foreach ((array) ($c['expect_tools'] ?? []) as $tool) {
                if (! in_array($tool, $r['tools_used'], true)) {
                    $problems[] = "missing tool {$tool}";
                }
            }
            if (($any = (array) ($c['expect_any_tools'] ?? [])) && ! array_intersect($any, $r['tools_used'])) {
                $problems[] = 'none of ' . implode('/', $any);
            }
            foreach ((array) ($c['forbid_tools'] ?? []) as $tool) {
                if (in_array($tool, $r['tools_used'], true)) {
                    $problems[] = "called forbidden {$tool}";
                }
            }
            foreach ((array) ($c['expect_contains'] ?? []) as $needle) {
                if (! str_contains(mb_strtolower($r['answer']), mb_strtolower($needle))) {
                    $problems[] = "answer lacks \"{$needle}\"";
                }
            }
            $ok = ! $problems;
            $pass += $ok ? 1 : 0;
            $rows[] = [$c['id'] ?? $i, $ok ? 'PASS' : 'FAIL', $ok ? implode(',', $r['tools_used']) : implode('; ', $problems), $took];
            if (! $ok && $this->output->isVerbose()) {
                $this->line('   Q: ' . $c['question']);
                $this->line('   A: ' . mb_substr(str_replace("\n", ' ', $r['answer']), 0, 400));
            }
        }

        $this->table(['case', 'result', 'detail', 'time'], $rows);
        $this->info("{$pass}/" . count($cases) . ' passed · ' . number_format($tokens) . ' tokens');

        return $pass === count($cases) ? self::SUCCESS : self::FAILURE;
    }
}
