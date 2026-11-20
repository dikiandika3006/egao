<?php

use App\Livewire\GoogleCalendarPanel;
use App\Livewire\Modules\JanjiTemu;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Doctor;
use App\Models\GoogleCalendar;
use App\Models\GoogleCalendarShare;
use App\Models\Patient;
use App\Models\User;
use App\Services\GoogleCalendar\GoogleCalendarSync;
use App\Support\BranchContext;
use App\Support\Settings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed();
    Cache::flush();
    $this->batu = Branch::where('code', 'BTU')->first();

    // Kunci service account palsu (RSA dibuat saat test).
    $this->keyFile = tempnam(sys_get_temp_dir(), 'gcal');
    file_put_contents($this->keyFile, json_encode([
        'type' => 'service_account', 'client_email' => 'egao@proyek.iam.gserviceaccount.com', 'private_key' => gcalTestKey(),
    ]));
    config(['google_calendar.credentials' => $this->keyFile]);

    // Janji contoh dari seeder tidak ikut dikirim.
    Appointment::query()->update(['calendar_dirty' => false]);

    Settings::set('gcal_enabled', '1');
    Settings::set('gcal_extra_emails', 'tiara@gmail.com,fandi@gmail.com');

    $this->doctor = Doctor::where('is_active', true)->firstOrFail();
    $this->doctor->update(['calendar_email' => 'Dokter@Gmail.com']);
    $this->patient = Patient::first();
    $this->patient->update(['phone' => '081234567890']);

    $this->seq = 0;
    $this->calendarDown = false;
    Http::fake(function (Request $request) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        return match (true) {
            str_contains($request->url(), 'oauth2.googleapis.com/token') => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            $this->calendarDown => Http::response(['error' => ['message' => 'Not Found']], 404),
            $request->method() === 'POST' && str_ends_with($path, '/calendar/v3/calendars') => Http::response(['id' => 'cal'.(++$this->seq).'@group.calendar.google.com']),
            $request->method() === 'POST' && str_ends_with($path, '/acl') => Http::response(['id' => 'user:'.$request['scope']['value']]),
            $request->method() === 'POST' && str_ends_with($path, '/events') => Http::response(['id' => 'ev'.(++$this->seq)]),
            $request->method() === 'PATCH' => Http::response(['id' => basename($path)]),
            $request->method() === 'DELETE' => Http::response(null, 204),
            default => Http::response([], 500),
        };
    });
});

afterEach(fn () => @unlink($this->keyFile));

/** Kunci RSA uji (dibuat sekali per proses; openssl.cnf minimal agar jalan juga di Windows). */
function gcalTestKey(): string
{
    static $pem = null;
    if ($pem === null) {
        $cnf = tempnam(sys_get_temp_dir(), 'cnf');
        file_put_contents($cnf, "[req]\ndistinguished_name=dn\n[dn]\n");
        $opts = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => $cnf];
        openssl_pkey_export(openssl_pkey_new($opts), $pem, null, $opts);
        @unlink($cnf);
    }

    return $pem;
}

function gcalAppointment(array $attrs = []): Appointment
{
    $t = test();

    return Appointment::create($attrs + [
        'clinic_id' => $t->patient->clinic_id, 'patient_id' => $t->patient->id, 'branch_id' => $t->batu->id,
        'doctor_id' => $t->doctor->id, 'date' => today()->addDays(2)->toDateString(), 'time' => '14:30',
        'purpose' => 'Scaling', 'status' => Appointment::SCHEDULED, 'source' => 'manual',
    ]);
}

/** Request ke Calendar API dengan method & akhiran path tertentu (mis. '/events', '/acl'). */
function gcalRequests(string $method, string $suffix = ''): array
{
    return Http::recorded()->map(fn ($pair) => $pair[0])
        ->filter(fn (Request $r) => $r->method() === $method && str_contains($r->url(), '/calendar/v3/')
            && ($suffix === '' || str_contains((string) parse_url($r->url(), PHP_URL_PATH), $suffix)))
        ->values()->all();
}

function gcalCalendarOf(?int $doctorId): ?string
{
    return GoogleCalendar::where('doctor_id', $doctorId)->value('calendar_id');
}

it('membuat kalender dokter & owner, mengundang emailnya, lalu menulis janji ke sana', function () {
    $a = gcalAppointment();
    expect($a->fresh()->calendar_dirty)->toBeTrue();

    $this->artisan('egao:gcal-sync')->assertSuccessful();

    // Dua kalender: dokter + Owner.
    $created = collect(gcalRequests('POST'))->filter(fn ($r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/calendars'));
    expect($created->map(fn ($r) => $r['summary'])->all())
        ->toEqualCanonicalizing(['Egao – '.$this->doctor->name, 'Egao – Semua janji temu']);

    // Undangan ke tiga email, dengan notifikasi.
    $acl = gcalRequests('POST', '/acl');
    expect(collect($acl)->map(fn ($r) => $r['scope']['value'])->sort()->values()->all())->toBe(['dokter@gmail.com', 'fandi@gmail.com', 'tiara@gmail.com'])
        ->and(collect($acl)->every(fn ($r) => str_contains($r->url(), 'sendNotifications=true') && $r['role'] === 'reader'))->toBeTrue();

    // Event ditulis ke kalender milik Service Account, bukan ke email.
    $inserts = gcalRequests('POST', '/events');
    $calendars = collect($inserts)->map(fn ($r) => urldecode(explode('/', parse_url($r->url(), PHP_URL_PATH))[4]))->sort()->values()->all();
    expect($calendars)->toBe(collect([gcalCalendarOf($this->doctor->id), gcalCalendarOf(null)])->sort()->values()->all());

    $body = $inserts[0]->data();
    expect($body['start'])->toBe(['dateTime' => today()->addDays(2)->format('Y-m-d').'T14:30:00', 'timeZone' => 'Asia/Jakarta'])
        ->and($body['end']['dateTime'])->toEndWith('T15:30:00')
        ->and($body['summary'])->toContain($this->patient->name)->toContain('Scaling')
        ->and(json_encode($body))->not->toContain('081234567890');

    $a->refresh();
    expect($a->calendar_dirty)->toBeFalse()
        ->and($a->calendar_synced_at)->not->toBeNull()
        ->and($a->calendarEvents()->count())->toBe(2)
        ->and(GoogleCalendarShare::whereNotNull('invited_at')->count())->toBe(3);
});

it('kalender & undangan tidak dibuat ulang pada putaran berikutnya', function () {
    gcalAppointment();
    $this->artisan('egao:gcal-sync');
    gcalAppointment(['time' => '16:00']);
    $this->artisan('egao:gcal-sync');

    expect(gcalRequests('POST', '/acl'))->toHaveCount(3)
        ->and(GoogleCalendar::count())->toBe(2)
        ->and(gcalRequests('POST', '/events'))->toHaveCount(4);
});

it('janji tanpa jam dipasang 11.00 selama satu jam', function () {
    gcalAppointment(['time' => null]);
    $this->artisan('egao:gcal-sync');

    $body = gcalRequests('POST', '/events')[0]->data();
    expect($body['start']['dateTime'])->toEndWith('T11:00:00')
        ->and($body['end']['dateTime'])->toEndWith('T12:00:00');
});

it('janji tanpa dokter dikirim ke kalender dokter yang bertugas hari itu', function () {
    $sync = app(GoogleCalendarSync::class);
    $probe = fn ($d) => new Appointment(['clinic_id' => $this->patient->clinic_id, 'branch_id' => $this->batu->id, 'date' => $d]);

    $date = collect(range(1, 14))->map(fn ($i) => today()->addDays($i))->first(fn ($d) => $sync->doctors($probe($d))->isNotEmpty());
    expect($date)->not->toBeNull();

    $onDuty = $sync->doctors($probe($date));
    $onDuty->each(fn (Doctor $d) => $d->update(['calendar_email' => 'jaga'.$d->id.'@gmail.com']));

    $a = gcalAppointment(['doctor_id' => null, 'date' => $date->toDateString()]);
    $sync->syncCalendars($this->patient->clinic_id);

    expect($sync->recipients($a->fresh()))->toEqualCanonicalizing([
        ...$onDuty->map(fn ($d) => gcalCalendarOf($d->id))->all(), gcalCalendarOf(null),
    ]);
});

it('ganti dokter menghapus acara di kalender dokter lama dan memperbarui kalender owner', function () {
    $a = gcalAppointment();
    $this->artisan('egao:gcal-sync');
    $oldCal = gcalCalendarOf($this->doctor->id);

    $other = Doctor::where('is_active', true)->whereKeyNot($this->doctor->id)->firstOrFail();
    $other->update(['calendar_email' => 'dokter2@gmail.com']);
    $a->update(['doctor_id' => $other->id]);
    expect($a->fresh()->calendar_dirty)->toBeTrue();

    $this->artisan('egao:gcal-sync');

    expect(collect(gcalRequests('DELETE', '/events/'))->map->url()->implode(' '))->toContain(rawurlencode($oldCal))
        ->and(gcalRequests('PATCH'))->toHaveCount(1)
        ->and($a->calendarEvents()->pluck('calendar_id')->sort()->values()->all())
        ->toBe(collect([gcalCalendarOf($other->id), gcalCalendarOf(null)])->sort()->values()->all());
});

it('janji batal menghapus semua acara', function () {
    $a = gcalAppointment();
    $this->artisan('egao:gcal-sync');

    $a->update(['status' => Appointment::CANCELLED]);
    $this->artisan('egao:gcal-sync');

    expect(gcalRequests('DELETE', '/events/'))->toHaveCount(2)
        ->and($a->calendarEvents()->count())->toBe(0)
        ->and($a->fresh()->calendar_dirty)->toBeFalse();
});

it('ganti email dokter mengundang email baru dan mencabut yang lama', function () {
    gcalAppointment();
    $this->artisan('egao:gcal-sync');

    $this->doctor->update(['calendar_email' => 'baru@gmail.com']);
    app(GoogleCalendarSync::class)->syncCalendars($this->doctor->clinic_id);

    expect(collect(gcalRequests('DELETE', '/acl/'))->map->url()->implode(' '))->toContain(rawurlencode('user:dokter@gmail.com'))
        ->and(collect(gcalRequests('POST', '/acl'))->map(fn ($r) => $r['scope']['value']))->toContain('baru@gmail.com')
        ->and(GoogleCalendar::where('doctor_id', $this->doctor->id)->first()->shares()->pluck('email')->all())->toBe(['baru@gmail.com']);
});

it('dokter nonaktif dicabut aksesnya tanpa menghapus kalender', function () {
    gcalAppointment();
    $this->artisan('egao:gcal-sync');
    $calId = gcalCalendarOf($this->doctor->id);

    $this->doctor->update(['is_active' => false]);
    app(GoogleCalendarSync::class)->syncCalendars($this->doctor->clinic_id);

    expect(gcalRequests('DELETE', '/acl/'))->toHaveCount(1)
        ->and(gcalCalendarOf($this->doctor->id))->toBe($calId)
        ->and(GoogleCalendar::where('doctor_id', $this->doctor->id)->first()->shares()->count())->toBe(0)
        ->and(collect(gcalRequests('DELETE'))->filter(fn ($r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), rawurlencode($calId))))->toBeEmpty();
});

it('tidak mengirim apa pun bila integrasi nonaktif', function () {
    Settings::set('gcal_enabled', '0');
    gcalAppointment();

    $this->artisan('egao:gcal-sync')->assertSuccessful();

    Http::assertNothingSent();
});

it('google gagal → janji tetap ditandai perlu sinkron dan dicoba ulang', function () {
    $this->calendarDown = true;

    $a = gcalAppointment();
    $this->artisan('egao:gcal-sync');

    $a->refresh();
    expect($a->calendar_dirty)->toBeTrue()
        ->and($a->calendar_attempts)->toBe(1)
        ->and($a->calendar_error)->toContain('belum siap')
        ->and($a->calendarState())->toBe('failed');
});

it('janji yang disimpan dari modul janji temu ditandai perlu sinkron', function () {
    $this->actingAs(User::where('username', 'admin')->firstOrFail())->withSession([
        BranchContext::SESSION_ROLE => 'admin', BranchContext::SESSION_BRANCH => $this->batu->id,
    ]);

    Livewire::test(JanjiTemu::class)
        ->call('create')
        ->set('form.patient_id', $this->patient->id)
        ->set('form.date', today()->addDays(3)->toDateString())
        ->set('form.time', '16:00')
        ->set('form.doctor_id', (string) $this->doctor->id)
        ->call('save')
        ->assertHasNoErrors();

    expect(Appointment::latest('id')->first()->calendar_dirty)->toBeTrue();
});

it('riwayat pengiriman menampilkan status dan bisa difilter', function () {
    $ok = gcalAppointment();
    $this->artisan('egao:gcal-sync');
    $waiting = gcalAppointment(['time' => '17:00', 'patient_id' => Patient::whereKeyNot($this->patient->id)->value('id')]);

    $this->actingAs(User::where('username', 'owner')->firstOrFail())->withSession([
        BranchContext::SESSION_ROLE => 'owner', BranchContext::SESSION_BRANCH => 'all',
    ]);
    session([BranchContext::SESSION_ROLE => 'owner', BranchContext::SESSION_BRANCH => 'all']);

    Livewire::test(GoogleCalendarPanel::class)
        ->assertSee('Riwayat pengiriman janji')
        ->assertSee('Masuk kalender')
        ->assertSee('Egao – Semua janji temu')
        ->assertSee($waiting->patient->name)
        ->set('statusFilter', 'synced')
        ->assertSee($ok->patient->name)
        ->assertDontSee($waiting->patient->name)
        ->set('statusFilter', 'pending')
        ->assertSee($waiting->patient->name)
        ->assertDontSee($ok->patient->name);
});

it('ganti email di data dokter langsung mengirim undangan baru', function () {
    gcalAppointment();
    $this->artisan('egao:gcal-sync');

    $this->actingAs(User::where('username', 'owner')->firstOrFail())->withSession([
        BranchContext::SESSION_ROLE => 'owner', BranchContext::SESSION_BRANCH => 'all',
    ]);
    session([BranchContext::SESSION_ROLE => 'owner', BranchContext::SESSION_BRANCH => 'all']);

    Livewire::test(\App\Livewire\Modules\Dokter::class)
        ->call('edit', $this->doctor->id)
        ->set('joined_at', today()->subYear()->toDateString())
        ->set('calendar_email', 'Email.Baru@gmail.com')
        ->call('save')
        ->assertHasNoErrors();

    expect(collect(gcalRequests('POST', '/acl'))->map(fn ($r) => $r['scope']['value']))->toContain('email.baru@gmail.com')
        ->and(collect(gcalRequests('DELETE', '/acl/'))->map->url()->implode(' '))->toContain(rawurlencode('user:dokter@gmail.com'));
});

it('email yang belum diundang mendapat tombol kirim undangan', function () {
    $this->actingAs(User::where('username', 'owner')->firstOrFail())->withSession([
        BranchContext::SESSION_ROLE => 'owner', BranchContext::SESSION_BRANCH => 'all',
    ]);
    session([BranchContext::SESSION_ROLE => 'owner', BranchContext::SESSION_BRANCH => 'all']);

    Livewire::test(GoogleCalendarPanel::class)
        ->assertSee('Kirim undangan')
        ->call('prepareCalendars')
        ->assertSee('Kirim ulang undangan');

    expect(GoogleCalendarShare::where('email', 'dokter@gmail.com')->whereNotNull('invited_at')->exists())->toBeTrue();
});

it('panel google calendar hanya untuk owner', function () {
    $this->actingAs(User::where('username', 'admin')->firstOrFail())->withSession([
        BranchContext::SESSION_ROLE => 'admin', BranchContext::SESSION_BRANCH => $this->batu->id,
    ]);
    session([BranchContext::SESSION_ROLE => 'admin', BranchContext::SESSION_BRANCH => $this->batu->id]);

    Livewire::test(GoogleCalendarPanel::class)->assertForbidden();
});

it('owner menyimpan pengaturan, kalender disiapkan, dan bisa kirim ulang undangan', function () {
    $this->actingAs(User::where('username', 'owner')->firstOrFail())->withSession([
        BranchContext::SESSION_ROLE => 'owner', BranchContext::SESSION_BRANCH => 'all',
    ]);
    session([BranchContext::SESSION_ROLE => 'owner', BranchContext::SESSION_BRANCH => 'all']);

    $panel = Livewire::test(GoogleCalendarPanel::class)
        ->assertSee('egao@proyek.iam.gserviceaccount.com')
        ->set('extraEmails', "tiara@gmail.com\nbukan-email")
        ->call('saveConfig')
        ->assertHasErrors('extraEmails')
        ->set('extraEmails', "Tiara@gmail.com\nfandi@gmail.com")
        ->set('defaultTime', '10:30')
        ->call('saveConfig')
        ->assertHasNoErrors()
        ->assertSee('Kalender dibuat')
        ->assertSee('Undangan terkirim')
        ->assertSee(rawurlencode('https://calendar.google.com/calendar/u/0?cid='.rtrim(base64_encode(gcalCalendarOf(null)), '=')), false);

    expect(Settings::get('gcal_extra_emails'))->toBe('tiara@gmail.com,fandi@gmail.com')
        ->and(GoogleCalendarSync::defaultTime())->toBe('10:30');

    $share = GoogleCalendarShare::where('email', 'tiara@gmail.com')->firstOrFail();
    $panel->assertSee('Link belum dikirim')
        ->assertSee('https://wa.me/?text=', false)
        ->call('markLinkSent', $share->id)
        ->call('resendInvite', $share->id);
    expect($share->fresh()->link_sent_at)->not->toBeNull();

    expect(gcalRequests('DELETE', '/acl/'))->toHaveCount(1)
        ->and(collect(gcalRequests('POST', '/acl'))->filter(fn ($r) => $r['scope']['value'] === 'tiara@gmail.com'))->toHaveCount(2);
});
