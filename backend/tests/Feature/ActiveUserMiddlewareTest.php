<?php

namespace Tests\Feature;

use App\Http\Middleware\ActiveUserMiddleware;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class ActiveUserMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_writes_a_missing_heartbeat_once_and_throttles_follow_up_requests(): void
    {
        Carbon::setTestNow('2026-08-30 10:00:00');
        $user = $this->activeUser();

        $this->runMiddleware($user);

        $firstHeartbeat = $this->freshUser($user);
        $this->assertSame('2026-08-30 10:00:00', $firstHeartbeat->last_active_at?->toDateTimeString());
        $this->assertSame('2026-08-30 10:00:00', $firstHeartbeat->updated_at?->toDateTimeString());

        Carbon::setTestNow('2026-08-30 10:01:00');
        $this->runMiddleware($firstHeartbeat);

        $throttledHeartbeat = $this->freshUser($user);
        $this->assertSame('2026-08-30 10:00:00', $throttledHeartbeat->last_active_at?->toDateTimeString());
        $this->assertSame('2026-08-30 10:00:00', $throttledHeartbeat->updated_at?->toDateTimeString());
    }

    public function test_it_refreshes_a_stale_heartbeat_at_the_interval_boundary(): void
    {
        Carbon::setTestNow('2026-08-30 10:05:00');
        $user = $this->activeUser('2026-08-30 10:00:00');

        $this->runMiddleware($user);

        $this->assertSame(
            '2026-08-30 10:05:00',
            $this->freshUser($user)->last_active_at?->toDateTimeString(),
        );
    }

    public function test_a_stale_authenticated_instance_does_not_overwrite_a_concurrent_heartbeat(): void
    {
        Carbon::setTestNow('2026-08-30 10:05:00');
        $user = $this->activeUser('2026-08-30 10:00:00');

        // Simulate another request completing first while this request still
        // holds the old authenticated model instance.
        $user->newQuery()
            ->whereKey($user->getKey())
            ->update([
                'last_active_at' => '2026-08-30 10:04:00',
                'updated_at' => '2026-08-30 10:04:00',
            ]);

        $this->runMiddleware($user);

        $freshUser = $this->freshUser($user);
        $this->assertSame('2026-08-30 10:04:00', $freshUser->last_active_at?->toDateTimeString());
        $this->assertSame('2026-08-30 10:04:00', $freshUser->updated_at?->toDateTimeString());
    }

    private function activeUser(?string $lastActiveAt = null): User
    {
        return User::create([
            'name' => 'مستخدم نشط',
            'username' => 'active-user-'.str()->random(12),
            'phone' => '077'.fake()->unique()->numerify('#######'),
            'password' => 'password',
            'role' => 'courier',
            'status' => 'active',
            'last_active_at' => $lastActiveAt,
        ]);
    }

    private function runMiddleware(User $user): void
    {
        $this->actingAs($user);

        app(ActiveUserMiddleware::class)->handle(
            Request::create('/app', 'GET'),
            static fn () => response('ok'),
        );
    }

    private function freshUser(User $user): User
    {
        return User::query()->findOrFail($user->getKey());
    }
}
