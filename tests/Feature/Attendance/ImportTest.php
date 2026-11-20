<?php

namespace Tests\Feature\Attendance;

use App\Models\Device;
use App\Models\Employee;
use App\Models\Punch;
use App\Models\PunchImport;
use App\Services\Attendance\PunchImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** USB / CSV files: reading, preview and import. */
class ImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_date_and_time_spellings_are_understood(): void
    {
        $this->assertSame('2026-11-16 08:01:22', PunchImporter::normaliseDateTime('2026-11-16 08:01:22'));
        $this->assertSame('2026-11-16 08:01:00', PunchImporter::normaliseDateTime('2026/11/16', '08:01'));
        $this->assertSame('2026-11-16 20:01:00', PunchImporter::normaliseDateTime('16/11/2026 8:01 PM'));
        $this->assertSame('2026-03-04 08:00:00', PunchImporter::normaliseDateTime('04/03/2026 08:00'));
        $this->assertSame('2026-04-03 08:00:00', PunchImporter::normaliseDateTime('04/03/2026 08:00', '', 'mdy'));
        $this->assertSame('2026-11-16 08:01:22', PunchImporter::normaliseDateTime('2026-11-16T08:01:22.000Z'));
        $this->assertNull(PunchImporter::normaliseDateTime('not a date'));
        $this->assertNull(PunchImporter::normaliseDateTime('2026-13-01 08:00'));
    }

    public function test_the_usb_attlog_and_csv_exports_are_read(): void
    {
        $dat = PunchImporter::parse("     1\t2026-11-16 08:01:22\t1\t0\t1\t0\n     2\t2026-11-16 08:03:00\t1\t1\t1\t0\n", '1_attlog.dat');
        $this->assertSame('ZKTeco USB log (attlog.dat)', $dat['format']);
        $this->assertCount(2, $dat['rows']);
        $this->assertSame(['pin' => '1', 'time' => '2026-11-16 08:01:22'], array_intersect_key($dat['rows'][0], ['pin' => 1, 'time' => 1]));
        $this->assertSame(0, $dat['rows'][0]['status']);

        $csv = PunchImporter::parse("\xEF\xBB\xBFAC-No.;Name;Time\n7;Saad;16/11/2026 08:01\n8;\"Al-Harbi, Ahmed\";16/11/2026 17:30\nbad;line\n");
        $this->assertSame('CSV / spreadsheet', $csv['format']);
        $this->assertCount(2, $csv['rows']);
        $this->assertSame(1, $csv['skipped']);
        $this->assertSame([['pin' => '7', 'name' => 'Saad'], ['pin' => '8', 'name' => 'Al-Harbi, Ahmed']], $csv['users']);

        $split = PunchImporter::parse("Employee ID,Date,Time\n5,2026-11-16,08:00\n");
        $this->assertSame('2026-11-16 08:00:00', $split['rows'][0]['time']);
    }

    public function test_preview_then_import_a_file(): void
    {
        $user = $this->signInWith(['imports.manage']);
        Punch::create(['sn' => 'X', 'pin' => '1', 'punch_time' => '2026-11-16 08:01:22']);
        $file = fn () => UploadedFile::fake()->createWithContent('1_attlog.dat', "1\t2026-11-16 08:01:22\t1\t0\t1\t0\n1\t2026-11-16 16:00:00\t1\t1\t1\t0\n3\t2026-11-16 08:00:00\t1\t0\t1\t0\n");

        $this->post(route('imports.preview'), ['file' => $file()])
            ->assertOk()
            ->assertJson(['kind' => 'punches', 'count' => 3, 'employees' => 2, 'existing' => 1, 'first' => '2026-11-16 08:00:00']);
        $this->assertSame(1, Punch::count());

        $this->post(route('imports.store'), ['file' => $file(), 'sn' => 'DEV1'])
            ->assertOk()->assertJson(['inserted' => 2, 'duplicates' => 1]);

        $this->assertSame(3, Punch::count());
        $this->assertSame('file', Punch::where('pin', '3')->value('source'));
        $this->assertTrue(Employee::where('pin', '3')->exists());
        $this->assertSame($user->id, PunchImport::first()->user_id);
        $this->assertDatabaseHas('activity_logs', ['action' => 'imported']);
    }

    public function test_a_file_without_punches_is_refused(): void
    {
        $this->signInWith(['imports.manage']);

        $this->post(route('imports.preview'), ['file' => UploadedFile::fake()->createWithContent('x.txt', "hello\nworld\n")])
            ->assertStatus(422)->assertJsonStructure(['message']);
    }

    public function test_lan_needs_a_valid_address(): void
    {
        $this->signInWith(['imports.manage']);

        $this->postJson(route('imports.lan.test'), ['ip' => 'not an ip!'])->assertStatus(422)->assertJsonValidationErrors('ip');
        // Nothing listens there: a clear message, not a crash
        $this->postJson(route('imports.lan.test'), ['ip' => '127.0.0.1', 'port' => 9])->assertStatus(422)->assertJsonStructure(['message']);
    }

    public function test_the_lan_bridge_sends_punches_with_an_api_token(): void
    {
        $user = $this->signInWith(['imports.manage']);
        $token = $user->createToken('bridge')->plainTextToken;
        auth()->forgetGuards();

        $this->withToken($token)->postJson('/api/v1/punches/import', [
            'sn' => 'ABC123', 'ip' => '192.168.1.201',
            'records' => [['pin' => '1', 'time' => '2026-11-16 08:01:22'], ['pin' => '1', 'time' => 'garbage']],
            'users' => [['pin' => '1', 'name' => 'Ahmed']],
        ])->assertOk()->assertJson(['inserted' => 1, 'skipped' => 1, 'names' => 1]);

        $this->assertSame('lan', Punch::first()->source);
        $this->assertSame('192.168.1.201', Device::where('sn', 'ABC123')->value('lan_ip'));
    }

    public function test_the_lan_bridge_needs_the_import_permission(): void
    {
        $user = $this->signInWith(['reports.view']);
        $token = $user->createToken('bridge')->plainTextToken;
        auth()->forgetGuards();

        $this->withToken($token)->postJson('/api/v1/punches/import', ['records' => []])->assertForbidden();
    }
}
