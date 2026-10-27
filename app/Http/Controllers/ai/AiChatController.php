<?php

namespace App\Http\Controllers\ai;

use App\Http\Controllers\Controller;
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
        $data = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
            'history' => ['nullable', 'array', 'max:40'],
            'history.*.role' => ['required', 'in:user,assistant'],
            'history.*.content' => ['required', 'string', 'max:8000'],
        ]);

        if (! $this->assistant->isConfigured()) {
            return response()->json(['error' => 'ยังไม่ได้ตั้งค่า OPENAI_API_KEY ใน .env'], 503);
        }

        try {
            $result = $this->assistant->ask($data['message'], $data['history'] ?? [], $request->user()->name);
        } catch (\Throwable $e) {
            Log::error('AI chat failed', ['user' => $request->user()->id, 'error' => $e->getMessage()]);

            return response()->json(['error' => $e->getMessage()], 502);
        }

        Log::info('AI chat', ['user' => $request->user()->id, 'tools' => $result['tools_used'], 'usage' => $result['usage']]);

        return response()->json($result);
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
