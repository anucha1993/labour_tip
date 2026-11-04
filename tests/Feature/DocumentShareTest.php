<?php

namespace Tests\Feature;

use App\Models\DocumentShare;
use App\Models\labour\LabourDocument;
use App\Models\labour\LabourModel;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Share-folder links (like Google Drive) on an in-memory SQLite database with fake disks.
 */
class DocumentShareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.testing_sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        config(['database.default' => 'testing_sqlite']);
        config(['cache.default' => 'array']); // throttle counters and one-time codes must not leak between tests or runs
        DB::purge('testing_sqlite');
        LabourDocument::forgetTableReady();
        DocumentShare::forgetTableReady();

        Schema::create('labour', function (Blueprint $t) {
            $t->bigIncrements('labour_id');
            foreach ((new LabourModel)->getFillable() as $col) {
                $t->string($col)->nullable();
            }
            $t->timestamps();
        });
        Schema::create('nationality', function (Blueprint $t) { $t->increments('id'); $t->string('code'); $t->string('name_th'); });
        DB::table('nationality')->insert(['code' => 'myanmar', 'name_th' => 'พม่า']);
        Schema::create('company', function (Blueprint $t) { $t->increments('company_id'); $t->string('company_name'); $t->string('company_status')->default('enable'); });
        Schema::create('agency', function (Blueprint $t) { $t->increments('agency_id'); $t->string('agency_name'); });
        Schema::create('action_logs', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->unsignedBigInteger('user_id')->nullable(); $t->string('action_type'); $t->string('model_type');
            $t->unsignedBigInteger('model_id'); $t->text('old_values')->nullable(); $t->text('new_values')->nullable(); $t->timestamp('created_at')->nullable();
        });
        Artisan::call('migrate', ['--path' => 'database/migrations/2026_10_27_000001_create_labour_documents_table.php', '--force' => true]);
        Artisan::call('migrate', ['--path' => 'database/migrations/2026_11_03_000002_create_document_shares_table.php', '--force' => true]);
        Artisan::call('migrate', ['--path' => 'database/migrations/2026_11_03_000003_add_recipient_email_to_document_shares_table.php', '--force' => true]);
        LabourDocument::forgetTableReady();
        DocumentShare::forgetTableReady();
        DocumentShare::forgetEmailColumn();

        Storage::fake('public');
        Storage::fake('r2');
        config(['filesystems.labour_documents_disk' => 'public']);

        DB::table('company')->insert([
            ['company_id' => 1, 'company_name' => 'บริษัท เอ จำกัด'],
            ['company_id' => 2, 'company_name' => 'บริษัท บี จำกัด'],
        ]);
    }

    protected function tearDown(): void
    {
        LabourDocument::forgetTableReady();
        DocumentShare::forgetTableReady();
        DocumentShare::forgetEmailColumn();
        parent::tearDown();
    }

    private function user(string $type): User
    {
        $u = new User(['name' => $type . ' Tester', 'email' => strtolower($type) . '@test.local', 'type' => $type, 'status' => 'enable']);
        $u->id = 1;

        return $u;
    }

    private function labour(string $name, string $passport, $companyId = 1): LabourModel
    {
        return LabourModel::create([
            'labour_prefix' => 'Mr', 'labour_fullname' => $name, 'labour_sex' => 'male', 'labour_nationality' => 'myanmar',
            'labour_passport_number' => $passport, 'labour_status_job' => 'job', 'labour_status' => 'enable', 'company_id' => $companyId,
        ]);
    }

    private function doc(LabourModel $labour, string $name, string $file = 'doc.pdf'): LabourDocument
    {
        $path = $labour->labour_passport_number . '/' . $file;
        Storage::disk('public')->put($path, '%PDF-1.4 test');

        return $labour->documents()->create(['name' => $name, 'disk' => 'public', 'path' => $path, 'original_name' => $file, 'size' => 12, 'mime' => 'application/pdf']);
    }

    public function test_admin_creates_a_password_protected_view_only_link_and_the_public_can_use_it()
    {
        $a = $this->labour('AUNG KO', 'MA000001');
        $passport = $this->doc($a, 'Passport');
        $other = $this->doc($this->labour('MYO MIN', 'MB000002', 2), 'Passport', 'other.pdf');

        // Member cannot share.
        $this->actingAs($this->user('Member'))->post('/documents/share', ['kind' => 'labour', 'target_id' => $a->labour_id, 'expires_days' => 7])
            ->assertRedirect(route('dashboard'));
        $this->assertSame(0, DocumentShare::count());

        // Admin creates: 7 days, password, no download.
        $res = $this->actingAs($this->user('Admin'))->post('/documents/share', [
            'kind' => 'labour', 'target_id' => $a->labour_id, 'expires_days' => 7, 'password' => 'secret1', 'label' => 'ส่งให้ ตม.',
            'recipient_email' => 'HR@Company.com, officer@immigration.go.th', 'access' => 'anyone', // chosen on purpose: e-mails alone now default to a restricted link
        ]);
        $res->assertRedirect('/documents?labour=' . $a->labour_id)->assertSessionHas('share_url');
        $share = DocumentShare::first();
        $this->assertSame('labour', $share->kind);
        $this->assertFalse($share->can_download);
        $this->assertTrue($share->hasPassword());
        $this->assertNotNull($share->expires_at);
        $this->assertSame('Admin Tester', $share->created_by);
        $this->assertSame(40, strlen($share->token));
        $this->assertSame(['hr@company.com', 'officer@immigration.go.th'], $share->emails());
        $this->assertSame('hr@company.com, officer@immigration.go.th', session('share_emails'));

        // Folder tiles show who it is shared with and carry a "..." menu with the share action.
        $this->actingAs($this->user('Admin'))->get('/documents?company=1')->assertOk()
            ->assertSee('แชร์ให้ hr@company.com, officer@immigration.go.th')->assertSee('mdi-dots-vertical', false)->assertSee('data-share=', false);
        $this->actingAs($this->user('Member'))->get('/documents?company=1')->assertOk()->assertDontSee('data-share=', false)->assertDontSee('แชร์ให้');

        // A bad e-mail is refused with a Thai message.
        $this->actingAs($this->user('Admin'))->from('/documents?company=1')
            ->post('/documents/share', ['kind' => 'labour', 'target_id' => $a->labour_id, 'expires_days' => 7, 'recipient_email' => 'not-an-email'])
            ->assertRedirect('/documents?company=1')->assertSessionHasErrors('recipient_email');
        $this->assertSame(1, DocumentShare::count());

        // return_to brings the user back to the page the dialog was opened from.
        $this->actingAs($this->user('Admin'))->post('/documents/share', ['kind' => 'labour', 'target_id' => $a->labour_id, 'expires_days' => 1, 'return_to' => url('/documents?company=1&w=AUNG')])
            ->assertRedirect('/documents?company=1&w=AUNG');
        $this->actingAs($this->user('Admin'))->post('/documents/share', ['kind' => 'labour', 'target_id' => $a->labour_id, 'expires_days' => 1, 'return_to' => 'https://evil.example/x'])
            ->assertRedirect('/documents?labour=' . $a->labour_id);
        DocumentShare::where('id', '>', $share->id)->delete();

        // Explorer shows the share button and the existing link for Admin, nothing for Member.
        $this->actingAs($this->user('Admin'))->get('/documents?labour=' . $a->labour_id)->assertOk()
            ->assertSee('แชร์โฟลเดอร์')->assertSee($share->url())->assertSee('ส่งให้ ตม.');
        $this->actingAs($this->user('Member'))->get('/documents?labour=' . $a->labour_id)->assertOk()->assertDontSee('แชร์โฟลเดอร์');

        // Public: password gate first.
        $this->get($share->url())->assertOk()->assertSee('โฟลเดอร์นี้มีรหัสผ่าน')->assertDontSee('AUNG KO');
        $this->get(route('share.file', [$share->token, $passport->id]))->assertForbidden();
        $this->post(route('share.unlock', $share->token), ['password' => 'wrong'])->assertSessionHasErrors('password');

        $this->post(route('share.unlock', $share->token), ['password' => 'secret1'])->assertRedirect($share->url());
        $this->get($share->url())->assertOk()->assertSee('AUNG KO')->assertSee('Passport')->assertSee('ดูอย่างเดียว')->assertDontSee('ดาวน์โหลด</a>', false);
        $this->assertSame(1, $share->fresh()->views);

        // Files: own file inline, download param ignored (view-only), other worker's file 404.
        $this->get(route('share.file', [$share->token, $passport->id]))->assertOk()
            ->assertHeader('content-disposition', 'inline; filename="Passport - MA000001.pdf"');
        $this->get(route('share.file', [$share->token, $passport->id]) . '?download=1')->assertOk()
            ->assertHeader('content-disposition', 'inline; filename="Passport - MA000001.pdf"');
        $this->get(route('share.file', [$share->token, $other->id]))->assertNotFound();

        // Unknown token = friendly 410 page.
        $this->get('/share/' . str_repeat('x', 40))->assertStatus(410)->assertSee('ลิงก์นี้ใช้ไม่ได้แล้ว');
    }

    public function test_company_link_lists_every_worker_and_stops_working_when_revoked_or_expired()
    {
        $a = $this->labour('AUNG KO', 'MA000001', 1);
        $b = $this->labour('ZAW ZAW', 'MA000009', 1);
        $this->labour('MYO MIN', 'MB000002', 2);
        $docA = $this->doc($a, 'Passport');
        $this->doc($b, 'Visa', 'visa.pdf');

        $this->actingAs($this->user('MasterAdmin'))->post('/documents/share', ['kind' => 'company', 'target_id' => 1, 'expires_days' => 0, 'can_download' => 1])
            ->assertRedirect('/documents?company=1');
        $share = DocumentShare::first();
        $this->assertNull($share->expires_at);
        $this->assertTrue($share->can_download);

        $this->actingAs($this->user('Admin'))->get('/documents')->assertOk()->assertSee('แชร์ให้ 1 ลิงก์')->assertSee('mdi-folder-account', false);

        // Company link opens as a folder of workers (Drive style); a worker folder shows the files.
        $res = $this->get($share->url())->assertOk();
        $res->assertSee('บริษัท เอ จำกัด')->assertSee('AUNG KO')->assertSee('ZAW ZAW')->assertDontSee('MYO MIN')->assertSee('2 คน · 2 ไฟล์')->assertSee('ไม่มีวันหมดอายุ')
            ->assertSee('?labour=' . $a->labour_id, false)->assertDontSee('data-url=', false);
        $this->get($share->url() . '?labour=' . $a->labour_id)->assertOk()->assertSee('Passport')->assertSee('data-url="' . route('share.file', [$share->token, $docA->id]) . '"', false)->assertDontSee('ZAW ZAW');
        $this->get($share->url() . '?labour=' . $this->labour('OUTSIDER', 'MX000001', 2)->labour_id)->assertNotFound();
        // Views count once per browser session.
        $this->assertSame(1, $share->fresh()->views);
        $this->get(route('share.file', [$share->token, $docA->id]) . '?download=1')->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename="Passport - MA000001.pdf"');

        // Management page lists it; revoke kills the link immediately.
        $this->actingAs($this->user('Admin'))->get('/documents/shares')->assertOk()->assertSee('บริษัท เอ จำกัด')->assertSee('ใช้งานได้')->assertSee($share->url());
        $this->actingAs($this->user('Admin'))->delete(route('documents.share.revoke', $share->id))->assertSessionHas('success');
        $this->assertNotNull($share->fresh()->revoked_at);
        $this->get($share->url())->assertStatus(410)->assertSee('ยกเลิกการแชร์');
        $this->get(route('share.file', [$share->token, $docA->id]))->assertStatus(410);
        $this->actingAs($this->user('Admin'))->get('/documents/shares')->assertOk()->assertSee('ยกเลิกแล้ว');

        // Expired link behaves the same.
        $expired = DocumentShare::create(['token' => DocumentShare::newToken(), 'kind' => 'company', 'target_id' => 1, 'expires_at' => now()->subMinute(), 'can_download' => true]);
        $this->get($expired->url())->assertStatus(410)->assertSee('ลิงก์หมดอายุเมื่อ');
        $this->assertSame(0, DocumentShare::active()->count());
    }

    public function test_restricted_link_admits_only_listed_emails_and_a_removed_email_loses_access()
    {
        $a = $this->labour('AUNG KO', 'MA000001');
        $doc = $this->doc($a, 'Passport');
        config(['mail.default' => 'log']); // no mailer on this server: e-mail is matched against the list only

        $this->actingAs($this->user('Admin'))->post('/documents/share', [
            'kind' => 'labour', 'target_id' => $a->labour_id, 'expires_days' => 7, 'access' => 'email',
            'recipient_email' => 'hr@company.com, officer@immigration.go.th', 'can_download' => 1,
        ])->assertRedirect('/documents?labour=' . $a->labour_id)->assertSessionHasNoErrors();
        $share = DocumentShare::first();
        $this->assertTrue($share->isRestricted());
        $this->actingAs($this->user('Admin'))->get('/documents?labour=' . $a->labour_id)->assertOk()->assertSee('เปิดได้เฉพาะอีเมลที่ระบุ ผู้รับต้องกรอกอีเมล')
            ->assertSee('id="shAccessEmail" value="email" checked', false)->assertDontSee('id="shAccessAnyone" value="anyone" checked', false); // even without a mailer the safe mode is the default

        // Restricted mode without any e-mail is refused.
        $this->actingAs($this->user('Admin'))->from('/documents')->post('/documents/share', ['kind' => 'labour', 'target_id' => $a->labour_id, 'expires_days' => 7, 'access' => 'email'])
            ->assertRedirect('/documents')->assertSessionHasErrors('recipient_email');

        // Public: must identify with a listed e-mail.
        $this->get($share->url())->assertOk()->assertSee('เฉพาะอีเมลที่ได้รับสิทธิ์')->assertDontSee('Passport');
        $this->get(route('share.file', [$share->token, $doc->id]))->assertForbidden();
        $this->post(route('share.unlock', $share->token), ['email' => 'stranger@x.com'])->assertSessionHasErrors('email');
        $this->post(route('share.unlock', $share->token), ['email' => 'HR@Company.com'])->assertRedirect($share->url());
        $this->get($share->url())->assertOk()->assertSee('Passport')->assertSee('hr@company.com');
        $this->get(route('share.file', [$share->token, $doc->id]))->assertOk();

        // Admin removes hr@company.com (keeps the other): the open session loses access at once.
        $this->actingAs($this->user('Admin'))->patch(route('documents.share.update', $share->id), ['recipient_email' => 'officer@immigration.go.th'])
            ->assertRedirect('/documents?labour=' . $a->labour_id)->assertSessionHas('success');
        $this->assertSame(['officer@immigration.go.th'], $share->fresh()->emails());
        $this->get($share->url())->assertOk()->assertSee('เฉพาะอีเมลที่ได้รับสิทธิ์')->assertDontSee('Passport');
        $this->get(route('share.file', [$share->token, $doc->id]))->assertForbidden();
        $this->post(route('share.unlock', $share->token), ['email' => 'hr@company.com'])->assertSessionHasErrors('email');

        // Cannot empty the list of a restricted link; adding works; Member cannot touch it.
        $this->actingAs($this->user('Admin'))->patch(route('documents.share.update', $share->id), ['recipient_email' => ''])->assertSessionHasErrors('recipient_email');
        $this->actingAs($this->user('Admin'))->patch(route('documents.share.update', $share->id), ['recipient_email' => 'officer@immigration.go.th, new@x.com'])->assertSessionHas('success');
        $this->assertSame(['officer@immigration.go.th', 'new@x.com'], $share->fresh()->emails());
        $this->actingAs($this->user('Member'))->patch(route('documents.share.update', $share->id), ['recipient_email' => 'x@y.com'])->assertRedirect(route('dashboard'));

        // Tiles and the share list show the lock.
        $this->actingAs($this->user('Admin'))->get('/documents?company=1')->assertOk()->assertSee('mdi-lock', false)->assertSee('officer@immigration.go.th');
        $this->actingAs($this->user('Admin'))->get('/documents/shares')->assertOk()->assertSee('เฉพาะอีเมลเหล่านี้');
    }

    public function test_restricted_link_sends_a_one_time_code_when_mail_is_configured()
    {
        $a = $this->labour('AUNG KO', 'MA000001');
        $doc = $this->doc($a, 'Passport');
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.com', 'mail.from.address' => 'noreply@importer168.com']);
        \Illuminate\Support\Facades\Mail::fake();
        $this->assertTrue(DocumentShare::mailReady());

        $share = DocumentShare::create(['token' => DocumentShare::newToken(), 'kind' => 'labour', 'target_id' => $a->labour_id, 'access' => 'email', 'recipient_email' => 'hr@company.com', 'can_download' => true]);

        // Listed e-mail -> code page, mail sent, still locked.
        $this->post(route('share.unlock', $share->token), ['email' => 'hr@company.com'])->assertOk()->assertSee('กรอกรหัสที่ส่งไปทางอีเมล');
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\ShareAccessCode::class, fn ($m) => $m->hasTo('hr@company.com') && strlen($m->code) === 6);
        $this->get(route('share.file', [$share->token, $doc->id]))->assertForbidden();
        $this->get($share->url())->assertOk()->assertSee('กรอกรหัสที่ส่งไปทางอีเมล');

        // Wrong code refused, right code opens the folder.
        $code = \Illuminate\Support\Facades\Cache::get('share_otp_' . $share->id . '_' . sha1('hr@company.com'));
        $this->assertNotEmpty($code);
        $this->post(route('share.unlock', $share->token), ['email' => 'hr@company.com', 'code' => '000000' === $code ? '111111' : '000000'])->assertSessionHasErrors('code');
        $this->post(route('share.unlock', $share->token), ['email' => 'hr@company.com', 'code' => $code])->assertRedirect($share->url());
        $this->get($share->url())->assertOk()->assertSee('Passport');
        $this->get(route('share.file', [$share->token, $doc->id]))->assertOk();
    }

    public function test_the_link_is_mailed_to_recipients_when_mail_is_configured()
    {
        $a = $this->labour('AUNG KO', 'MA000001');
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.com', 'mail.from.address' => 'noreply@importer168.com']);
        \Illuminate\Support\Facades\Mail::fake();

        // Creating a share with two e-mails mails the link to both, with the sender's note.
        $this->actingAs($this->user('Admin'))->post('/documents/share', [
            'kind' => 'labour', 'target_id' => $a->labour_id, 'expires_days' => 7, 'access' => 'anyone',
            'recipient_email' => 'hr@company.com, ap.anucha.ap@gmail.com', 'can_download' => 1, 'message' => 'รบกวนตรวจเอกสารครับ',
        ])->assertRedirect('/documents?labour=' . $a->labour_id)->assertSessionHas('share_sent', 'hr@company.com, ap.anucha.ap@gmail.com');
        $share = DocumentShare::first();
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\ShareLinkNotice::class, 2);
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\ShareLinkNotice::class, function ($m) use ($share) {
            $html = view('emails.share-link', ['share' => $m->share, 'recipient' => $m->recipient, 'sender' => $m->sender, 'note' => $m->note])->render(); // MailFake has no render() on Laravel 8

            return $m->hasTo('ap.anucha.ap@gmail.com') && $m->note === 'รบกวนตรวจเอกสารครับ' && str_contains($html, $share->url()) && str_contains($html, 'AUNG KO');
        });
        $this->actingAs($this->user('Admin'))->get('/documents?labour=' . $a->labour_id)->assertOk()->assertSee('ส่งลิงก์ไปที่')->assertSee('ส่งอีเมลอีกครั้ง')
            ->assertSee('ลิงก์นี้ใครก็เปิดได้โดยไม่ต้องยืนยันตัวตน') // this one was made with access=anyone
            ->assertSee('id="shAccessEmail" value="email" checked', false); // ...but the safe mode is always the default

        // Adding one e-mail mails only the new one; removing mails nobody.
        $this->actingAs($this->user('Admin'))->patch(route('documents.share.update', $share->id), ['recipient_email' => 'hr@company.com, ap.anucha.ap@gmail.com, new@x.com'])->assertSessionHas('success', 'ส่งลิงก์ไปที่ new@x.com แล้ว');
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\ShareLinkNotice::class, 3);
        $this->actingAs($this->user('Admin'))->patch(route('documents.share.update', $share->id), ['recipient_email' => 'hr@company.com']);
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\ShareLinkNotice::class, 3);

        // "ส่งลิงก์อีกครั้ง" resends to everyone on file, or to one address; refused once revoked.
        $this->actingAs($this->user('Admin'))->from('/documents/shares')->post(route('documents.share.notify', $share->id))->assertRedirect('/documents/shares')->assertSessionHas('success', 'ส่งลิงก์ไปที่ hr@company.com แล้ว');
        $this->actingAs($this->user('Admin'))->post(route('documents.share.notify', $share->id), ['email' => 'extra@x.com'])->assertSessionHas('success', 'ส่งลิงก์ไปที่ extra@x.com แล้ว');
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\ShareLinkNotice::class, 5);
        $this->actingAs($this->user('Member'))->post(route('documents.share.notify', $share->id))->assertRedirect(route('dashboard'));
        $share->update(['revoked_at' => now()]);
        $this->actingAs($this->user('Admin'))->post(route('documents.share.notify', $share->id))->assertNotFound();

        // Without a mailer nothing is sent and the dialog falls back to mailto:.
        config(['mail.default' => 'log']);
        $this->actingAs($this->user('Admin'))->post('/documents/share', ['kind' => 'labour', 'target_id' => $a->labour_id, 'expires_days' => 7, 'recipient_email' => 'x@y.com'])->assertSessionHas('share_sent', '')->assertSessionHas('share_restricted', true);
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\ShareLinkNotice::class, 5);
        $this->actingAs($this->user('Admin'))->get('/documents?labour=' . $a->labour_id)->assertOk()->assertSee('mailto:x%40y.com', false);
    }

    public function test_the_button_in_the_e_mail_opens_a_restricted_folder_without_a_code()
    {
        $a = $this->labour('AUNG KO', 'MA000001');
        $doc = $this->doc($a, 'Passport');
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.com', 'mail.from.address' => 'noreply@importer168.com']);
        \Illuminate\Support\Facades\Mail::fake();

        $this->actingAs($this->user('Admin'))->post('/documents/share', ['kind' => 'labour', 'target_id' => $a->labour_id, 'expires_days' => 7, 'access' => 'email', 'recipient_email' => 'hr@company.com', 'can_download' => 1]);
        $share = DocumentShare::first();
        $open = $share->openUrl('hr@company.com');
        $this->assertStringContainsString('/share/' . $share->token . '/open?', $open);
        $this->assertStringContainsString('signature=', $open);
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\ShareLinkNotice::class, fn ($m) => str_contains(view('emails.share-link', ['share' => $m->share, 'recipient' => $m->recipient, 'sender' => $m->sender, 'note' => null])->render(), e($open)));

        // One click: straight in, no code mailed.
        $this->get($open)->assertRedirect($share->url());
        $this->get($share->url())->assertOk()->assertSee('Passport')->assertSee('hr@company.com');
        $this->get(route('share.file', [$share->token, $doc->id]))->assertOk();
        \Illuminate\Support\Facades\Mail::assertNotSent(\App\Mail\ShareAccessCode::class);

        // Tampered (another address on the same signature), expired, or forwarded after removal: back to the e-mail + code page.
        $this->flushSession();
        $this->get(str_replace('email=hr%40company.com', 'email=stranger%40x.com', $open))->assertRedirect($share->url())->assertSessionHasErrors('email');
        $this->get($share->url())->assertOk()->assertSee('เฉพาะอีเมลที่ได้รับสิทธิ์')->assertDontSee('Passport');
        $expired = \Illuminate\Support\Facades\URL::temporarySignedRoute('share.open', now()->subMinute(), ['token' => $share->token, 'email' => 'hr@company.com']);
        $this->get($expired)->assertRedirect($share->url())->assertSessionHasErrors('email');
        $share->update(['recipient_email' => 'other@x.com']);
        $this->get($open)->assertRedirect($share->url())->assertSessionHasErrors('email');
        $this->get($share->url())->assertDontSee('Passport');
        $share->update(['recipient_email' => 'hr@company.com']);

        // The link in the e-mail never outlives the share.
        $share->update(['expires_at' => now()->addDays(2)]);
        $this->assertSame($share->expires_at->timestamp, (int) \Illuminate\Support\Arr::get(\Illuminate\Support\Str::of(parse_url($share->openUrl('hr@company.com'), PHP_URL_QUERY))->explode('&')->mapWithKeys(fn ($p) => [explode('=', $p)[0] => explode('=', $p)[1]])->all(), 'expires'));

        // With a password the button proves the address, and only the password is still asked (no code).
        $pw = DocumentShare::create(['token' => DocumentShare::newToken(), 'kind' => 'labour', 'target_id' => $a->labour_id, 'access' => 'email', 'recipient_email' => 'hr@company.com', 'can_download' => true, 'password' => \Illuminate\Support\Facades\Hash::make('secret1')]);
        $this->flushSession();
        $this->get($pw->openUrl('hr@company.com'))->assertRedirect($pw->url());
        $this->get($pw->url())->assertOk()->assertSee('ยืนยันอีเมลจากลิงก์ในอีเมลแล้ว')->assertSee('value="hr@company.com" readonly', false)->assertDontSee('Passport');
        $this->post(route('share.unlock', $pw->token), ['email' => 'hr@company.com', 'password' => 'wrong'])->assertSessionHasErrors('password');
        $this->post(route('share.unlock', $pw->token), ['email' => 'hr@company.com', 'password' => 'secret1'])->assertRedirect($pw->url());
        $this->get($pw->url())->assertOk()->assertSee('Passport');
        \Illuminate\Support\Facades\Mail::assertNotSent(\App\Mail\ShareAccessCode::class);

        // The code e-mail carries the same one-click button.
        $this->flushSession();
        $this->post(route('share.unlock', $share->token), ['email' => 'hr@company.com'])->assertOk();
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\ShareAccessCode::class, fn ($m) => $m->email === 'hr@company.com' && str_contains(view('emails.share-code', ['share' => $m->share, 'code' => $m->code, 'email' => $m->email])->render(), '/open?'));
    }

    public function test_recipients_can_zip_a_selection_only_inside_the_shared_folder_and_only_when_download_is_allowed()
    {
        $a = $this->labour('AUNG KO', 'MA000001', 1);
        $b = $this->labour('ZAW ZAW', 'MA000009', 1);
        $other = $this->labour('MYO MIN', 'MB000002', 2);
        $a1 = $this->doc($a, 'Passport');
        $b1 = $this->doc($b, 'Visa', 'visa.pdf');
        $o1 = $this->doc($other, 'Passport');
        $share = DocumentShare::create(['token' => DocumentShare::newToken(), 'kind' => 'company', 'target_id' => 1, 'can_download' => true]);

        // The page offers the selection bar and per-folder checkboxes with sizes.
        $this->get($share->url())->assertOk()->assertSee('id="dxSelBar"', false)->assertSee(route('share.zip', $share->token))
            ->assertSee('data-kind="labour" value="' . $a->labour_id . '" data-files="1" data-bytes="' . $a1->size . '"', false);
        $this->get($share->url() . '?labour=' . $a->labour_id)->assertOk()->assertSee('data-kind="document" value="' . $a1->id . '"', false);

        // Anything outside the link (worker of another company, file of another company) is dropped silently.
        $res = $this->post(route('share.zip', $share->token), ['labours' => [$a->labour_id, $other->labour_id], 'documents' => [$b1->id, $o1->id]]);
        $res->assertOk();
        $this->assertStringContainsString('application/zip', $res->headers->get('content-type'));
        $tmp = tempnam(sys_get_temp_dir(), 'zt');
        file_put_contents($tmp, $res->streamedContent());
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($tmp));
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();
        @unlink($tmp);
        $this->assertSame(['MA000001 AUNG KO/Passport - MA000001.pdf', 'MA000009 ZAW ZAW/Visa - MA000009.pdf'], $names);

        // Only foreign items selected -> nothing to zip -> warning, no download.
        $this->from($share->url())->post(route('share.zip', $share->token), ['labours' => [$other->labour_id]])->assertRedirect($share->url())->assertSessionHas('warning');

        // View-only link: no checkboxes, zip refused. Revoked link: gone.
        $share->update(['can_download' => false]);
        $this->get($share->url())->assertOk()->assertDontSee('id="dxSelBar"', false)->assertDontSee('class="dx-sel"', false);
        $this->post(route('share.zip', $share->token), ['labours' => [$a->labour_id]])->assertForbidden();
        $share->update(['can_download' => true, 'revoked_at' => now()]);
        $this->post(route('share.zip', $share->token), ['labours' => [$a->labour_id]])->assertStatus(410);

        // Restricted link that is still locked: 403 until the recipient identifies.
        $locked = DocumentShare::create(['token' => DocumentShare::newToken(), 'kind' => 'labour', 'target_id' => $a->labour_id, 'can_download' => true, 'access' => 'email', 'recipient_email' => 'hr@company.com']);
        $this->post(route('share.zip', $locked->token), ['documents' => [$a1->id]])->assertForbidden();
        config(['mail.default' => 'log']);
        $this->post(route('share.unlock', $locked->token), ['email' => 'hr@company.com'])->assertRedirect($locked->url());
        $this->post(route('share.zip', $locked->token), ['documents' => [$a1->id]])->assertOk();
    }

    public function test_validation_and_missing_target()
    {
        $this->actingAs($this->user('Admin'))->from('/documents')->post('/documents/share', ['kind' => 'labour', 'target_id' => 1, 'expires_days' => 7, 'password' => 'ab'])
            ->assertRedirect('/documents')->assertSessionHasErrors('password');
        $this->actingAs($this->user('Admin'))->post('/documents/share', ['kind' => 'labour', 'target_id' => 999, 'expires_days' => 7])->assertNotFound();
        $this->actingAs($this->user('Admin'))->from('/documents')->post('/documents/share', ['kind' => 'labour', 'target_id' => 1, 'expires_days' => 5])
            ->assertRedirect('/documents')->assertSessionHasErrors('expires_days');
    }
}
