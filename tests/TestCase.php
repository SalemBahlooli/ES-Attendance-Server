<?php

namespace Tests;

use App\Models\User;
use App\Support\Permissions\PermissionTree;
use App\Support\Settings;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use PragmaRX\Google2FA\Google2FA;

abstract class TestCase extends BaseTestCase
{
    /**
     * Tests wipe the database (RefreshDatabase). Stop at once if they are not on the
     * in-memory SQLite database from phpunit.xml, so a real database is never touched.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $connection = $app['config']->get('database.default');
        $database = $app['config']->get("database.connections.{$connection}.database");
        if ($connection !== 'sqlite' || $database !== ':memory:') {
            throw new \RuntimeException("Tests refuse to run on the [{$connection}] database \"{$database}\": they would erase it. Check DB_* in phpunit.xml.");
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        PermissionTree::flush();
        app(Settings::class)->flush();
    }

    /** A signed-in user holding exactly these permissions (direct, value 1). */
    protected function signInWith(array $permissions = [], array $attributes = []): User
    {
        $user = User::factory()->withPermissions(array_fill_keys($permissions, 1))->create($attributes);
        $this->actingAs($user);

        return $user;
    }

    protected function signInAsSuperUser(): User
    {
        $user = User::factory()->superUser()->create();
        $this->actingAs($user);

        return $user;
    }

    /** Store settings for this test (real rows, so every code path sees them). */
    protected function setSettings(array $values): void
    {
        app(Settings::class)->set($values);
    }

    /** The current 6-digit code for a secret. */
    protected function totp(string $secret): string
    {
        return (new Google2FA)->getCurrentOtp($secret);
    }
}
