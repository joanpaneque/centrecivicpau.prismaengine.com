<?php

use App\Models\Ticket;
use App\Models\User;
use App\Services\Devices\DeviceManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Register a TPV device for the user and return the raw device cookie value.
 */
function registerTpvDevice(TestCase $test, User $user, string $type = 'tablet'): string
{
    $response = $test->actingAs($user)->postJson(route('tpv.device'), ['name' => 'Test '.$type, 'type' => $type])->assertOk();

    return (string) $response->getCookie(DeviceManager::COOKIE)?->getValue();
}

/**
 * @param  array<string, mixed>  $payload
 * @return array<string, mixed>
 */
function tpvOperation(string $type, array $payload, ?int $operatorId = null): array
{
    return [
        'uuid' => (string) Str::uuid(),
        'type' => $type,
        'payload' => $payload,
        'createdAt' => now()->toIso8601String(),
        'operatorId' => $operatorId,
    ];
}

/**
 * Issue a 5,00 € ticket through the sync API from a fresh cashier device.
 */
function issueTpvTicket(TestCase $test, string $publicToken): Ticket
{
    $admin = User::factory()->admin()->create();
    $cookie = registerTpvDevice($test, $admin, 'cashier');
    $sessionUuid = (string) Str::uuid();
    $ticketUuid = (string) Str::uuid();
    $client = fn () => $test->withCredentials()->withCookie(DeviceManager::COOKIE, $cookie);

    $client()->postJson(route('tpv.push'), ['operations' => [tpvOperation('cash.open', ['sessionUuid' => $sessionUuid], $admin->id)]])->assertOk();
    $series = $client()->getJson(route('tpv.bootstrap'))->json('cashier.series.code');

    $client()->postJson(route('tpv.push'), ['operations' => [tpvOperation('ticket.issue', [
        'ticket' => [
            'uuid' => $ticketUuid,
            'seriesCode' => $series,
            'number' => 1,
            'cashSessionUuid' => $sessionUuid,
            'issuedAt' => now()->toIso8601String(),
            'surchargeRate' => 0,
            'total' => 500,
            'publicToken' => $publicToken,
            'lines' => [['name' => ['ca' => 'Entrepà', 'es' => 'Bocadillo'], 'quantity' => 1, 'unitPrice' => 500, 'vatRate' => 10, 'total' => 500]],
            'payments' => [['method' => 'card', 'amount' => 500]],
        ],
    ], $admin->id)]])->assertOk();

    return Ticket::query()->where('uuid', $ticketUuid)->firstOrFail();
}
