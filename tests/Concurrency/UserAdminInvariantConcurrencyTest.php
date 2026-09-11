<?php

namespace Tests\Concurrency;

use App\Enums\UserRole;
use App\Exceptions\UserAccountInvariantViolation;
use App\Models\User;
use App\Services\UserAccountService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Proves the last-active-admin invariant with real MySQL row locks.
 * Run only with phpunit.concurrency.xml, never SQLite.
 */
class UserAdminInvariantConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('users')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function test_competing_admin_demotions_cannot_leave_zero_active_admins(): void
    {
        $firstTarget = User::factory()->admin()->create();
        $secondTarget = User::factory()->admin()->create();
        $directory = storage_path('framework/testing/user-admin-invariant-'.getmypid());
        @mkdir($directory, 0775, true);
        $startFile = $directory.'/start';

        DB::disconnect();
        $children = [];

        foreach ([$firstTarget->id, $secondTarget->id] as $targetId) {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid, 'pcntl_fork() failed.');

            if ($pid === 0) {
                DB::purge('mysql');
                DB::reconnect('mysql');

                while (! file_exists($startFile)) {
                    usleep(1000);
                }

                try {
                    $target = User::query()->findOrFail($targetId);
                    $actor = User::query()->whereKey($targetId === $firstTarget->id ? $secondTarget->id : $firstTarget->id)->firstOrFail();

                    app(UserAccountService::class)->update($actor, $target, [
                        'name' => $target->name,
                        'username' => $target->username,
                        'email' => $target->email,
                        'role' => UserRole::Agent,
                        'is_active' => true,
                    ]);

                    exit(0);
                } catch (UserAccountInvariantViolation) {
                    exit(2);
                } catch (\Throwable) {
                    exit(1);
                }
            }

            $children[] = $pid;
        }

        file_put_contents($startFile, '1');
        $exitCodes = [];

        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $exitCodes[] = pcntl_wexitstatus($status);
        }

        sort($exitCodes);
        $this->assertSame([0, 2], $exitCodes);

        DB::reconnect('mysql');
        $this->assertSame(1, User::query()->where('role', UserRole::Admin)->where('is_active', true)->count());
        @unlink($startFile);
        @rmdir($directory);
    }

    public function test_competing_deactivation_and_demotion_cannot_leave_zero_active_admins(): void
    {
        $firstTarget = User::factory()->admin()->create();
        $secondTarget = User::factory()->admin()->create();
        $directory = storage_path('framework/testing/user-admin-mixed-'.getmypid());
        @mkdir($directory, 0775, true);
        $startFile = $directory.'/start';

        DB::disconnect();
        $children = [];
        $operations = [
            [$firstTarget->id, $secondTarget->id, UserRole::Admin, false],
            [$secondTarget->id, $firstTarget->id, UserRole::Agent, true],
        ];

        foreach ($operations as [$targetId, $actorId, $role, $isActive]) {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid, 'pcntl_fork() failed.');

            if ($pid === 0) {
                DB::purge('mysql');
                DB::reconnect('mysql');

                while (! file_exists($startFile)) {
                    usleep(1000);
                }

                try {
                    $target = User::query()->findOrFail($targetId);
                    $actor = User::query()->findOrFail($actorId);

                    app(UserAccountService::class)->update($actor, $target, [
                        'name' => $target->name,
                        'username' => $target->username,
                        'email' => $target->email,
                        'role' => $role,
                        'is_active' => $isActive,
                    ]);

                    exit(0);
                } catch (UserAccountInvariantViolation) {
                    exit(2);
                } catch (\Throwable) {
                    exit(1);
                }
            }

            $children[] = $pid;
        }

        file_put_contents($startFile, '1');
        $exitCodes = [];

        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $exitCodes[] = pcntl_wexitstatus($status);
        }

        sort($exitCodes);
        $this->assertSame([0, 2], $exitCodes);

        DB::reconnect('mysql');
        $this->assertGreaterThanOrEqual(1, User::query()->where('role', UserRole::Admin)->where('is_active', true)->count());
        @unlink($startFile);
        @rmdir($directory);
    }
}
