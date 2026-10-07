<?php

use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\User;
use App\Services\Devices\DeviceManager;
use App\Services\Printing\EscPosPrinterDriver;
use App\Services\Printing\PrintService;
use Illuminate\Support\Str;

function makePrinter(string $type = 'system', array $extra = []): Printer
{
    return Printer::query()->create([
        'name' => $extra['name'] ?? 'Impressora tiquets',
        'type' => $type,
        'ip' => $extra['ip'] ?? null,
        'port' => $extra['port'] ?? null,
        'system_name' => $extra['system_name'] ?? 'Impressora tiquets',
        'paper_width' => 48,
        'is_ticket_printer' => true,
        'active' => true,
    ]);
}

function sampleDocument(): array
{
    return [
        'width' => 48,
        'lines' => [
            ['type' => 'text', 'text' => 'PROVA', 'align' => 'center', 'bold' => true],
            ['type' => 'cut'],
        ],
    ];
}

test('system printers leave jobs pending for the cashier tpv', function () {
    $printer = makePrinter();
    $job = app(PrintService::class)->store((string) Str::uuid(), 'test', 'Prova', sampleDocument(), $printer->id, null, null);

    expect($job->status)->toBe('pending');
});

test('simulated printers mark jobs as simulated', function () {
    $printer = makePrinter('simulated');
    $job = app(PrintService::class)->store((string) Str::uuid(), 'test', 'Prova', sampleDocument(), $printer->id, null, null);

    expect($job->status)->toBe('simulated');
});

test('escpos printers without a reachable host fail', function () {
    $printer = makePrinter('escpos_network', ['ip' => '127.0.0.1', 'port' => 1]);
    $job = app(PrintService::class)->store((string) Str::uuid(), 'test', 'Prova', sampleDocument(), $printer->id, null, null);

    expect($job->status)->toBe('failed');
});

test('escpos encoding includes the ticket text', function () {
    $bytes = app(EscPosPrinterDriver::class)->encode(sampleDocument());

    expect($bytes)->toContain('PROVA')->toContain("\x1D".'V');
});

test('cashier bootstrap includes pending print jobs and tablets do not', function () {
    $printer = makePrinter();
    $job = app(PrintService::class)->store((string) Str::uuid(), 'test', 'Prova caixa', sampleDocument(), $printer->id, null, null);

    $admin = User::factory()->admin()->create();
    $cashierCookie = registerTpvDevice($this, $admin, 'cashier');
    $waiter = User::factory()->create();
    $tabletCookie = registerTpvDevice($this, $waiter, 'tablet');

    $this->withCredentials()->withCookie(DeviceManager::COOKIE, $cashierCookie)
        ->getJson(route('tpv.bootstrap'))
        ->assertOk()
        ->assertJsonPath('printJobs.0.uuid', $job->uuid)
        ->assertJsonPath('printJobs.0.systemName', 'Impressora tiquets');

    $this->withCredentials()->withCookie(DeviceManager::COOKIE, $tabletCookie)
        ->getJson(route('tpv.bootstrap'))
        ->assertOk()
        ->assertJsonPath('printJobs', null);
});

test('the cashier can claim and ack a print job', function () {
    $printer = makePrinter();
    $job = app(PrintService::class)->store((string) Str::uuid(), 'test', 'Prova', sampleDocument(), $printer->id, null, null);
    $admin = User::factory()->admin()->create();
    $cookie = registerTpvDevice($this, $admin, 'cashier');

    $this->withCredentials()->withCookie(DeviceManager::COOKIE, $cookie)
        ->postJson(route('tpv.print-jobs.claim', $job))
        ->assertOk()
        ->assertJsonPath('status', 'printing');

    expect($job->fresh()?->status)->toBe('printing');

    $this->withCredentials()->withCookie(DeviceManager::COOKIE, $cookie)
        ->postJson(route('tpv.print-jobs.ack', $job), ['status' => 'printed'])
        ->assertOk()
        ->assertJsonPath('status', 'printed');

    expect($job->fresh()?->status)->toBe('printed');
});

test('a tablet cannot claim print jobs', function () {
    $printer = makePrinter();
    $job = app(PrintService::class)->store((string) Str::uuid(), 'test', 'Prova', sampleDocument(), $printer->id, null, null);
    $user = User::factory()->create();
    $cookie = registerTpvDevice($this, $user, 'tablet');

    $this->withCredentials()->withCookie(DeviceManager::COOKIE, $cookie)
        ->postJson(route('tpv.print-jobs.claim', $job))
        ->assertForbidden();
});

test('admins can create a system printer', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post(route('admin.printers.store'), [
        'name' => 'EPSON TM-T20',
        'type' => 'system',
        'systemName' => 'EPSON TM-T20',
        'paperWidth' => 48,
        'isTicketPrinter' => true,
        'active' => true,
        'destinationIds' => [],
    ])->assertRedirect();

    $printer = Printer::query()->where('name', 'EPSON TM-T20')->first();

    expect($printer)->not->toBeNull()
        ->and($printer?->type)->toBe('system')
        ->and($printer?->system_name)->toBe('EPSON TM-T20')
        ->and($printer?->is_ticket_printer)->toBeTrue();
});

test('admin test print queues a system job', function () {
    $printer = makePrinter();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post(route('admin.printers.test', $printer))->assertRedirect();

    expect(PrintJob::query()->where('printer_id', $printer->id)->where('kind', 'test')->first()?->status)->toBe('pending');
});
