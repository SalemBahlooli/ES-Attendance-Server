<?php

namespace Tests\Feature\Attendance;

use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Employee;
use App\Models\Punch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The device side: handshake, punches, user names and commands. */
class AdmsTest extends TestCase
{
    use RefreshDatabase;

    public function test_handshake_registers_the_device_and_sends_options(): void
    {
        $this->get('/iclock/cdata?SN=ABC123&options=all&pushver=2.4.1')
            ->assertOk()
            ->assertSee('GET OPTION FROM: ABC123')
            ->assertSee('TimeZone=3')
            ->assertSee('Realtime=1');

        $device = Device::where('sn', 'ABC123')->first();
        $this->assertNotNull($device->last_seen_at);
        $this->assertNotNull($device->first_seen_at);
    }

    public function test_punches_are_saved_once_without_a_csrf_token_or_session(): void
    {
        $body = "1\t2026-11-16 08:01:22\t0\t1\t0\t0\n2\t2026-11-16 08:05:00\t0\t1\t0\t0\n";

        $this->call('POST', '/iclock/cdata?SN=ABC123&table=ATTLOG', [], [], [], ['CONTENT_TYPE' => 'text/plain'], $body)
            ->assertOk()->assertSee('OK: 2');
        // The device sends the same punches again
        $this->call('POST', '/iclock/cdata?SN=ABC123&table=ATTLOG', [], [], [], ['CONTENT_TYPE' => 'text/plain'], $body)->assertOk();

        $this->assertSame(2, Punch::count());
        $this->assertSame(['1', '2'], Employee::ordered()->pluck('pin')->all());
        $punch = Punch::where('pin', '1')->first();
        $this->assertSame('2026-11-16 08:01:22', $punch->time());
        $this->assertSame('adms', $punch->source);
        $this->assertSame(0, $punch->status);
    }

    public function test_user_records_fill_in_names_but_never_overwrite_them(): void
    {
        Employee::create(['pin' => '2', 'name' => 'Typed in the app']);

        $body = "USER PIN=1\tName=Ahmed\tPri=0\nUSER PIN=2\tName=From device\n";
        $this->call('POST', '/iclock/cdata?SN=ABC123&table=OPERLOG', [], [], [], ['CONTENT_TYPE' => 'text/plain'], $body)->assertOk();
        $this->call('POST', '/iclock/cdata?SN=ABC123&table=USERINFO', [], [], [], ['CONTENT_TYPE' => 'text/plain'], "PIN=3\tName=Saad\n")->assertOk();

        $this->assertSame('Ahmed', Employee::where('pin', '1')->value('name'));
        $this->assertSame('Typed in the app', Employee::where('pin', '2')->value('name'));
        $this->assertSame('Saad', Employee::where('pin', '3')->value('name'));
    }

    public function test_commands_are_handed_out_once_and_their_result_is_saved(): void
    {
        $this->get('/iclock/cdata?SN=ABC123');
        $command = DeviceCommand::create(['sn' => 'ABC123', 'command' => 'DATA QUERY USERINFO']);

        $this->get('/iclock/getrequest?SN=ABC123')->assertOk()->assertSee("C:{$command->id}:DATA QUERY USERINFO");
        $this->get('/iclock/getrequest?SN=ABC123')->assertOk()->assertSee('OK');
        $this->assertSame('sent', $command->fresh()->status);

        $this->call('POST', '/iclock/devicecmd?SN=ABC123', [], [], [], ['CONTENT_TYPE' => 'text/plain'], "ID={$command->id}&Return=0&CMD=DATA")->assertOk();
        $this->assertSame('done', $command->fresh()->status);
    }

    public function test_only_listed_devices_may_send_punches_when_a_list_is_set(): void
    {
        $this->setSettings(['allowed_devices' => "GOOD1\nGOOD2"]);

        $this->call('POST', '/iclock/cdata?SN=OTHER&table=ATTLOG', [], [], [], ['CONTENT_TYPE' => 'text/plain'], "1\t2026-11-16 08:01:22\t0\t1")->assertOk();
        $this->call('POST', '/iclock/cdata?SN=GOOD2&table=ATTLOG', [], [], [], ['CONTENT_TYPE' => 'text/plain'], "1\t2026-11-16 08:01:22\t0\t1")->assertOk();

        $this->assertSame(['GOOD2'], Punch::pluck('sn')->all());
    }

    public function test_any_other_device_call_answers_ok(): void
    {
        $this->get('/iclock/ping?SN=ABC123')->assertOk()->assertSee('OK');
        $this->post('/iclock/registry?SN=ABC123')->assertOk();
    }

    public function test_a_punch_deleted_by_hand_does_not_come_back(): void
    {
        $this->call('POST', '/iclock/cdata?SN=ABC123&table=ATTLOG', [], [], [], ['CONTENT_TYPE' => 'text/plain'], "1\t2026-11-16 08:01:22\t0\t1");
        Punch::first()->delete();

        $this->call('POST', '/iclock/cdata?SN=ABC123&table=ATTLOG', [], [], [], ['CONTENT_TYPE' => 'text/plain'], "1\t2026-11-16 08:01:22\t0\t1");

        $this->assertSame(0, Punch::count());
        $this->assertSame(1, Punch::withTrashed()->count());
    }
}
