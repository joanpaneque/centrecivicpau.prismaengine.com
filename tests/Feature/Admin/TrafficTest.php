<?php

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

test('the traffic page counts the orders opened by weekday and hour, without cancelled ones', function () {
    $admin = User::factory()->admin()->create();
    $monday = now()->startOfWeek()->setTime(14, 10);
    $open = fn ($at, string $status = 'paid', int $guests = 2) => Order::query()->create(['uuid' => (string) Str::uuid(), 'status' => $status, 'opened_at' => $at, 'guests' => $guests]);

    $open($monday);
    $open($monday->copy()->addMinutes(20), 'paid', 4);
    $open($monday->copy()->setTime(21, 5));
    $open($monday->copy()->setTime(14, 30), 'cancelled');

    $this->actingAs(User::factory()->create())->get(route('admin.traffic'))->assertForbidden();

    $this->actingAs($admin)->get(route('admin.traffic', ['dies' => 30]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/Traffic')
            ->where('days', 30)
            ->where('total', 3)
            ->where('openDays.1', 1)
            ->where('cells', fn ($cells) => collect($cells)->firstWhere('hour', 14)['orders'] === 2 && collect($cells)->firstWhere('hour', 14)['guests'] === 6));
});
