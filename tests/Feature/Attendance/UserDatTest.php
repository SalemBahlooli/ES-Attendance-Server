<?php

namespace Tests\Feature\Attendance;

use App\Models\Employee;
use App\Models\PunchImport;
use App\Services\Attendance\ZkUsers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** user.dat from the device's USB export: the employees' names, matched by employee ID. */
class UserDatTest extends TestCase
{
    use RefreshDatabase;

    /** A 72-byte record (colour-screen devices). The password and group look like numbers on purpose. */
    private function record72(int $uid, string $pin, string $name, string $password = '1234', string $group = '1'): string
    {
        return pack('vC', $uid, 0).str_pad($password, 8, "\0").str_pad($name, 24, "\0").pack('V', 0)
            ."\0".str_pad($group, 7, "\0")."\0".str_pad($pin, 24, "\0");
    }

    /** A 28-byte record (older black-and-white devices). */
    private function record28(int $uid, int $pin, string $name): string
    {
        return pack('vC', $uid, 0).str_pad('', 5, "\0").str_pad($name, 8, "\0").pack('V', 0)."\0".chr(1).pack('v', 0).pack('V', $pin);
    }

    private function userDat(string $bytes, string $name = 'user.dat'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    public function test_the_colour_screen_layout_is_read_with_utf8_and_windows_arabic_names(): void
    {
        $bytes = $this->record72(1, '12', 'Saad')
            .str_repeat("\0", 72) // an empty slot
            .$this->record72(2, '13', "\xC3\xCD\xE3\xCF") // أحمد in Windows Arabic (CP1256)
            .$this->record72(3, '14', 'خالد');

        $this->assertSame([
            ['pin' => '12', 'name' => 'Saad'],
            ['pin' => '13', 'name' => 'أحمد'],
            ['pin' => '14', 'name' => 'خالد'],
        ], ZkUsers::fromFile($bytes));
    }

    public function test_the_older_layout_and_a_size_header_are_understood(): void
    {
        $body = $this->record28(1, 7, 'Ali').$this->record28(2, 0, 'Omar'); // user ID 0: the device number counts

        $this->assertSame([['pin' => '7', 'name' => 'Ali'], ['pin' => '2', 'name' => 'Omar']], ZkUsers::fromFile($body));
        $this->assertSame(
            [['pin' => '12', 'name' => 'Saad']],
            ZkUsers::fromFile(pack('V', 72).$this->record72(1, '12', 'Saad')),
        );
    }

    public function test_an_unknown_layout_falls_back_to_name_and_number_pairs(): void
    {
        $bytes = "\x01Saad\x02\x0312\x01\x01Ali Hassan\x0213\x01\x05";

        $this->assertSame([['pin' => '12', 'name' => 'Saad'], ['pin' => '13', 'name' => 'Ali Hassan']], ZkUsers::fromFile($bytes));
    }

    public function test_user_dat_alone_fills_in_missing_names_only(): void
    {
        $this->signInWith(['imports.manage']);
        Employee::create(['pin' => '12']);
        Employee::create(['pin' => '13', 'name' => 'Typed in the app']);
        $file = fn () => $this->userDat($this->record72(1, '12', 'Saad').$this->record72(2, '13', 'Device name').$this->record72(3, '99', 'Never punched'));

        $this->post(route('imports.preview'), ['file' => $file()])
            ->assertOk()
            ->assertJson(['kind' => 'names', 'names' => 3, 'matched' => 2, 'fill' => 1])
            ->assertJsonPath('sample.0', ['pin' => '12', 'name' => 'Saad']);

        $this->post(route('imports.store'), ['users_file' => $file()])
            ->assertOk()->assertJson(['kind' => 'names', 'names' => 1]);

        $this->assertSame('Saad', Employee::where('pin', '12')->value('name'));
        $this->assertSame('Typed in the app', Employee::where('pin', '13')->value('name'));
        $this->assertFalse(Employee::where('pin', '99')->exists(), 'Someone who never punched is not added');
        $this->assertSame(1, PunchImport::first()->names);
        $this->assertDatabaseHas('activity_logs', ['action' => 'imported']);
    }

    public function test_punches_and_user_dat_are_imported_together(): void
    {
        $this->signInWith(['imports.manage']);
        $attlog = fn () => UploadedFile::fake()->createWithContent('RKQ_attlog.dat', "12\t2026-11-16 08:01:22\t1\t0\t1\t0\n");
        $users = fn () => $this->userDat($this->record72(1, '12', 'Saad').$this->record72(2, '50', 'Elsewhere'), 'RKQ_user.dat');

        $this->post(route('imports.preview'), ['file' => $attlog(), 'users_file' => $users()])
            ->assertOk()
            ->assertJson(['kind' => 'punches', 'count' => 1, 'device_names' => ['names' => 2, 'matched' => 1, 'fill' => 1]]);

        $this->post(route('imports.store'), ['file' => $attlog(), 'users_file' => $users()])
            ->assertOk()->assertJson(['inserted' => 1, 'names' => 1]);

        $this->assertSame('Saad', Employee::where('pin', '12')->value('name'));
        $this->assertFalse(Employee::where('pin', '50')->exists());
        $this->assertSame('RKQ_attlog.dat + RKQ_user.dat', PunchImport::first()->label);
    }

    public function test_a_file_without_names_is_refused(): void
    {
        $this->signInWith(['imports.manage']);

        $this->post(route('imports.preview'), ['users_file' => $this->userDat(str_repeat("\0", 144))])
            ->assertStatus(422)->assertJsonStructure(['message']);
        $this->post(route('imports.preview'), [])->assertSessionHasErrors('file');
    }

    public function test_importing_names_needs_the_import_permission(): void
    {
        $this->signInWith(['employees.edit']);

        $this->post(route('imports.store'), ['users_file' => $this->userDat($this->record72(1, '12', 'Saad'))])->assertForbidden();
    }
}
