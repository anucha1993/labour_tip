<?php

namespace App\Http\Controllers\ai;

use App\Http\Controllers\Controller;
use App\Models\AiChatLog;
use App\Models\labour\LabourDocument;
use App\Services\Ai\LabourAssistant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AiChatController extends Controller
{
    public function __construct(private LabourAssistant $assistant)
    {
        $this->middleware('auth');
    }

    public function index()
    {
        return view('ai.chat', [
            'configured' => $this->assistant->isConfigured(),
            'model' => config('services.openai.model', 'gpt-4o-mini'),
            'suggestions' => [
                'มีแรงงานกี่คนที่วีซ่าจะหมดอายุภายใน 30 วัน',
                'สรุปจำนวนแรงงานที่ทำงานอยู่แยกตามสัญชาติ',
                'บริษัทไหนมีแรงงานมากที่สุด 10 อันดับ',
                'ขอ Excel รายชื่อแรงงานที่ใบอนุญาตทำงานหมดอายุแล้ว',
                'แรงงานของบริษัท เก่ง เอ็นจิเนียริ่ง มีใครบ้าง',
                'ขอรายงาน Excel แรงงานสัญชาติกัมพูชาที่เริ่มงานปี 2025',
            ],
        ]);
    }

    public function message(Request $request)
    {
        // With a file the page sends multipart/form-data and the history as a JSON string.
        if (is_string($request->input('history'))) {
            $request->merge(['history' => json_decode($request->input('history'), true) ?: []]);
        }
        $data = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
            'history' => ['nullable', 'array', 'max:40'],
            'history.*.role' => ['required', 'in:user,assistant'],
            'history.*.content' => ['required', 'string', 'max:8000'],
            'conversation' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp,xlsx,xls,csv,txt', 'max:15360'],
        ], [
            'attachment.mimes' => 'ไฟล์แนบต้องเป็น PDF, JPG, PNG, WEBP, Excel หรือ CSV',
            'attachment.max' => 'ไฟล์แนบต้องมีขนาดไม่เกิน 15 MB',
        ]);

        if (! $this->assistant->isConfigured()) {
            return response()->json(['error' => 'ยังไม่ได้ตั้งค่า OPENAI_API_KEY ใน .env'], 503);
        }

        $started = microtime(true);
        $attachment = $request->hasFile('attachment') ? $this->storeAttachment($request) : null;
        if ($attachment) {
            $data['message'] = '[ไฟล์แนบ: ' . $attachment->fileName() . '] ' . $data['message'];
        }

        try {
            $result = $this->assistant->ask($request->input('message'), $data['history'] ?? [], $request->user(), $attachment);
        } catch (\Throwable $e) {
            Log::error('AI chat failed', ['user' => $request->user()->id, 'error' => $e->getMessage()]);
            $this->record($request, $data, $started, null, $e->getMessage());

            return response()->json(['error' => $e->getMessage()], 502);
        }

        Log::info('AI chat', ['user' => $request->user()->id, 'tools' => $result['tools_used'], 'usage' => $result['usage']]);
        $this->record($request, $data, $started, $result);

        return response()->json($result);
    }

    /**
     * A file attached to the question is kept for 24 h under storage/app/ai-uploads/<user>/ and handed to the
     * assistant as an unsaved LabourDocument so the same reader (OCR) can process it.
     */
    private function storeAttachment(Request $request): LabourDocument
    {
        $file = $request->file('attachment');
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $base = \Illuminate\Support\Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME), '-') ?: 'file';
        $name = now()->format('Ymd_His') . '_' . \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(6)) . '_' . mb_substr($base, 0, 60) . '.' . $ext;
        $dir = 'ai-uploads/' . $request->user()->id;
        $file->storeAs($dir, $name, 'local');

        // housekeeping: drop uploads older than a day
        try {
            foreach (Storage::disk('local')->files($dir) as $old) {
                if (Storage::disk('local')->lastModified($old) < now()->subDay()->getTimestamp()) {
                    Storage::disk('local')->delete($old);
                }
            }
        } catch (\Throwable $e) {
        }

        return new LabourDocument(['name' => 'ไฟล์แนบ', 'disk' => 'local', 'path' => $dir . '/' . $name, 'original_name' => $file->getClientOriginalName(), 'size' => $file->getSize()]);
    }

    /** Keep a copy of every exchange so MasterAdmin can review what people ask (never blocks the reply). */
    private function record(Request $request, array $data, float $started, ?array $result, ?string $error = null): void
    {
        try {
            AiChatLog::create([
                'user_id' => $request->user()->id,
                'conversation_id' => $data['conversation'] ?? null,
                'question' => $data['message'],
                'answer' => $result['answer'] ?? null,
                'tools_used' => $result['tools_used'] ?? [],
                'reports' => $result['reports'] ?? [],
                'prompt_tokens' => (int) ($result['usage']['prompt_tokens'] ?? 0),
                'completion_tokens' => (int) ($result['usage']['completion_tokens'] ?? 0),
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'status' => $error === null ? 'ok' : 'error',
                'error' => $error ? mb_substr($error, 0, 2000) : null,
                'ip' => $request->ip(),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('AI chat log not saved', ['error' => $e->getMessage()]);
        }
    }

    /** Download an Excel file produced by the assistant (files live 24h in storage/app/ai-reports). */
    public function report(string $file)
    {
        abort_unless(preg_match('/^[A-Za-z0-9_]+\.xlsx$/', $file), 404);
        $path = 'ai-reports/' . $file;
        abort_unless(Storage::disk('local')->exists($path), 404, 'ไฟล์รายงานหมดอายุหรือไม่พบ กรุณาสร้างใหม่');

        return Storage::disk('local')->download($path, 'AI_Report_' . $file);
    }
}
