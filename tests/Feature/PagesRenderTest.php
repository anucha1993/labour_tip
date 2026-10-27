<?php

namespace Tests\Feature;

use App\Models\labour\LabourModel;
use App\Models\User;
use Tests\TestCase;

/**
 * Smoke test: every screen renders and role gates behave.
 *
 * Runs against the configured database but only performs reads
 * (the POST cases below stop at validation, before any write).
 */
class PagesRenderTest extends TestCase
{
    private function user(string $type): User
    {
        $u = new User(['name' => 'Test ' . $type, 'email' => strtolower($type) . '@test.local', 'type' => $type, 'status' => 'enable']);
        $u->id = 999999; // not persisted; auth()->id() is only used for log attribution

        return $u;
    }

    private function sampleLabourId(): ?int
    {
        return optional(LabourModel::query()->orderBy('labour_id')->first())->labour_id;
    }

    public function test_guest_is_redirected_to_login()
    {
        foreach (['/', '/labour', '/company', '/agency', '/Report', '/settings', '/member', '/action-logs'] as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
        $this->get('/login')->assertOk()->assertSee('เข้าสู่ระบบ');
        $this->get('/password/reset')->assertOk();
    }

    public function test_master_admin_can_open_every_page()
    {
        $this->actingAs($this->user('MasterAdmin'));

        $pages = [
            '/', '/totalnotify',
            '/labour', '/labour?keyword=a&labour_status_job=job&column_name_type=labour_visa_date_end&start_date=2020-01-01&end_date=2030-01-01&per_page=25',
            '/labour/form-add', '/import/labour/form',
            '/company', '/company?keyword=x&status=enable', '/company/form-add',
            '/agency', '/agency/form-add',
            '/Report', '/settings',
            '/member', '/register', '/action-logs', '/action-logs?search=a&action=updated',
        ];
        foreach ($pages as $url) {
            $this->get($url)->assertOk();
        }

        $this->get('/home')->assertRedirect(route('dashboard'));

        if ($id = $this->sampleLabourId()) {
            $this->get("/labour/form-show/{$id}")->assertOk();
            $this->get("/labour/form-edit/{$id}")->assertOk();
        }
        if ($cid = optional(\App\Models\company\CompanyModel::query()->orderBy('company_id')->first())->company_id) {
            $this->get("/company/form-edit/{$cid}")->assertOk();
        }
        if ($aid = optional(\App\Models\Agency\AgencyModel::query()->orderBy('agency_id')->first())->agency_id) {
            $this->get("/agency/form-edit/{$aid}")->assertOk();
        }
        $this->get('/labour/form-show/0')->assertNotFound();
        $this->get('/no-such-page')->assertNotFound()->assertSee('404');
    }

    public function test_member_is_read_only()
    {
        $this->actingAs($this->user('Member'));

        $this->get('/')->assertOk();
        $this->get('/labour')->assertOk()->assertDontSee('id="massBar"', false);
        $this->get('/company')->assertOk();

        foreach (['/labour/form-add', '/company/form-add', '/agency/form-add', '/settings', '/import/labour/form'] as $url) {
            $this->get($url)->assertRedirect(route('dashboard'));
        }
        foreach (['/member', '/register', '/action-logs'] as $url) {
            $this->get($url)->assertRedirect(route('dashboard'));
        }
        $this->post('/labour/store')->assertRedirect(route('dashboard'));
        if ($cid = optional(\App\Models\company\CompanyModel::query()->orderBy('company_id')->first())->company_id) {
            $this->delete("/company/delete/{$cid}")->assertRedirect(route('dashboard'));
        }
    }

    public function test_admin_cannot_reach_master_only_pages()
    {
        $this->actingAs($this->user('Admin'));

        $this->get('/labour/form-add')->assertOk();
        $this->get('/settings')->assertOk();
        foreach (['/member', '/register', '/action-logs'] as $url) {
            $this->get($url)->assertRedirect(route('dashboard'));
        }
        if ($aid = optional(\App\Models\Agency\AgencyModel::query()->orderBy('agency_id')->first())->agency_id) {
            $this->delete("/agency/delete/{$aid}")->assertRedirect(route('dashboard'));
        }
    }

    public function test_validation_blocks_bad_writes()
    {
        $this->actingAs($this->user('MasterAdmin'));

        $this->from('/labour/form-add')->post('/labour/store', [])
            ->assertRedirect('/labour/form-add')
            ->assertSessionHasErrors(['labour_passport_number', 'labour_fullname']);

        $this->from('/labour')->post('/labour/massupdate', ['column_name' => 'labour_fullname', 'mass_date' => '2030-01-01'])
            ->assertSessionHasErrors(['column_name', 'labour_ids']);

        $this->from('/company/form-add')->post('/company/store', ['company_name' => 'x'])
            ->assertSessionHasErrors(['company_tax', 'company_address']);

        $this->from('/agency/form-add')->post('/agency/store', ['agency_name' => 'x', 'agency_nationality' => 'mars'])
            ->assertSessionHasErrors(['agency_nationality', 'agency_tax']);

        $this->from('/settings')->post('/settings/update', ['set_id' => [1], 'set_expire' => [-5]])
            ->assertSessionHasErrors(['set_expire.0']);

        $this->from('/Report')->post('/Report/custom/date', ['company' => 'ALL', 'status' => 'ALL', 'type_date' => 'labour.labour_id) OR 1=1 --', 'date_start' => '2024-01-01', 'date_end' => '2024-12-31', 'type' => 'excel'])
            ->assertSessionHasErrors(['type_date']);

        $this->from('/register')->post('/register', ['name' => 'x', 'email' => 'bad', 'password' => '123', 'password_confirmation' => '456', 'type' => 'God', 'status' => 'on'])
            ->assertSessionHasErrors(['email', 'password', 'type', 'status']);

        $this->from('/import/labour/form')->post('/import/labour-excel', [])
            ->assertSessionHasErrors(['company', 'agency', 'nationality', 'file']);
    }

    public function test_pdf_and_excel_reports_download()
    {
        $this->actingAs($this->user('Member'));

        $this->post('/Report/custom/pdf', ['company' => 'ALL', 'status' => 'escape', 'type' => 'pdf'])
            ->assertOk()->assertHeader('content-type', 'application/pdf');

        $this->get('/export/expire/visa')->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }
}
