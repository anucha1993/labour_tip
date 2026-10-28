<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use App\Models\labour\LabourModel;
use App\Services\LabourPhotoService;
use App\Services\Portal\WorkerProfile;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * JSON API consumed by the worker portal (labour.importer168.com).
 * Every /api/portal route is protected by the X-Portal-Key shared secret (see VerifyPortalKey).
 *
 * Worker credentials:  username = passport number, password = birthday as ddmmyyyy.
 */
class PortalController extends Controller
{
    public function __construct(private WorkerProfile $profile)
    {
    }

    /**
     * POST /api/portal/login  {passport, password}
     * "passport" may also be the worker's name: spaces, dots and dashes are ignored on both sides,
     * so MYO MIN OO / myominoo / Myo MinOo all match. Same name + same birthday on two different
     * passports -> ambiguous_name (the worker must use the passport number).
     */
    public function login(Request $request)
    {
        $data = $request->validate([
            'passport' => ['required', 'string', 'max:50'],
            'password' => ['required', 'string', 'max:20'],
        ]);

        $passport = strtoupper(preg_replace('/\s+/', '', $data['passport']));
        $password = preg_replace('/\D+/', '', $data['password']);

        // If the same passport exists more than once (re-imported worker), prefer the enabled, newest row.
        $labour = LabourModel::query()
            ->whereRaw('UPPER(labour_passport_number) = ?', [$passport])
            ->orderByRaw("CASE WHEN labour_status = 'enable' THEN 0 ELSE 1 END")
            ->orderByDesc('labour_id')
            ->first();

        if (! $labour) {
            $labour = $this->findByName($data['passport'], $password);
        }

        if (! $labour || ! $this->birthdayMatches($labour, $password)) {
            throw ValidationException::withMessages(['passport' => ['invalid_credentials']]);
        }

        if ($labour->labour_status !== 'enable') {
            throw ValidationException::withMessages(['passport' => ['account_disabled']]);
        }

        return response()->json([
            'ok' => true,
            'labour_id' => (int) $labour->labour_id,
            'profile' => $this->profile->make($labour),
        ]);
    }

    /** GET /api/portal/labours/{labourModel} */
    public function show(LabourModel $labourModel)
    {
        if ($labourModel->labour_status !== 'enable') {
            return response()->json(['message' => 'account_disabled'], 403);
        }

        return response()->json(['ok' => true, 'profile' => $this->profile->make($labourModel)]);
    }

    /** GET /api/portal/labours/{labourModel}/documents/{key}/link */
    public function documentLink(LabourModel $labourModel, string $key)
    {
        if ($labourModel->labour_status !== 'enable') {
            return response()->json(['message' => 'account_disabled'], 403);
        }

        $link = $this->profile->documentLink($labourModel, $key);
        if (! $link) {
            return response()->json(['message' => 'document_not_found'], 404);
        }

        return response()->json(['ok' => true] + $link);
    }

    /**
     * GET /portal-file/{labourModel}/{key}  (temporary signed URL, no login: the signature is the credential).
     * Streams local files and redirects R2 files to a presigned URL.
     */
    public function file(LabourModel $labourModel, string $key)
    {
        if ($key === 'photo') {
            $photos = app(LabourPhotoService::class);
            abort_unless($photos->exists($labourModel), 404, 'No photo');

            return $photos->response($labourModel);
        }

        $doc = WorkerProfile::findDocument($labourModel, $key);
        abort_unless($doc && $doc->exists(), 404, 'ไม่พบไฟล์เอกสาร / Document not found');

        return $doc->openResponse();
    }

    /** Name login: compare letters/digits only, then use the birthday to tell namesakes apart. */
    private function findByName(string $input, string $password): ?LabourModel
    {
        $key = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $input));
        if (strlen($key) < 3) {
            return null;
        }

        $candidates = LabourModel::query()
            ->whereRaw("UPPER(REPLACE(REPLACE(REPLACE(REPLACE(labour_fullname, ' ', ''), '-', ''), '.', ''), ',', '')) = ?", [$key])
            ->orderByRaw("CASE WHEN labour_status = 'enable' THEN 0 ELSE 1 END")
            ->orderByDesc('labour_id')
            ->get()
            ->filter(fn (LabourModel $l) => $this->birthdayMatches($l, $password));

        $people = $candidates->map(fn (LabourModel $l) => strtoupper(trim((string) $l->labour_passport_number)))->unique();
        if ($people->count() > 1) {
            throw ValidationException::withMessages(['passport' => ['ambiguous_name']]);
        }

        return $candidates->first();
    }

    private function birthdayMatches(LabourModel $labour, string $password): bool
    {
        if (strlen($password) !== 8 || is_blank_date($labour->labour_birthday)) {
            return false;
        }
        try {
            $expected = Carbon::parse($labour->labour_birthday)->format('dmY');
        } catch (\Throwable $e) {
            return false;
        }

        return hash_equals($expected, $password);
    }
}
