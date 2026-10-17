<?php

use App\Events\PrintJobsWaiting;
use App\Models\PrintAgent;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\User;
use App\Services\Devices\DeviceManager;
use App\Services\Printing\PrintDocumentEncoder;
use App\Services\Printing\PrintService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/**
 * @return array{0: PrintAgent, 1: string, 2: Printer}
 */
function agentWithPrinter(string $output = 'escpos'): array
{
    [$agent, $token] = PrintAgent::issue('PC de la barra');
    $printer = Printer::query()->create([
        'name' => 'Tiquets', 'type' => 'system', 'print_agent_id' => $agent->id, 'output' => $output, 'codepage' => 'cp858',
        'system_name' => 'POS-80C', 'paper_width' => 48, 'is_ticket_printer' => true, 'active' => true,
    ]);

    return [$agent, $token, $printer];
}

/**
 * @param  list<array<string, mixed>>  $lines
 */
function queueJob(Printer $printer, array $lines = []): PrintJob
{
    return app(PrintService::class)->store((string) Str::uuid(), 'ticket', 'Tiquet T-1', ['width' => 48, 'lines' => $lines ?: [
        ['type' => 'text', 'text' => 'Cafè amb llet · 1,80 €', 'align' => 'center', 'bold' => true],
        ['type' => 'qr', 'data' => 'https://example.com/factura/abc'],
        ['type' => 'cut'],
    ]], $printer->id, null, null);
}

test('admins register the program and get a one-line key; the key authenticates it', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post(route('admin.print-agents.store'), ['name' => 'PC barra'])->assertRedirect();
    expect(PrintAgent::query()->where('name', 'PC barra')->exists())->toBeTrue();

    [$agent, $token] = PrintAgent::issue('PC cuina');
    expect(PrintAgent::connectionKey($token))->toEndWith('/api/print-agent/'.$token)
        ->and($agent->token_hash)->not->toBe($token);

    $this->postJson(route('api.print-agent.hello'), [], ['Authorization' => 'Bearer nope'])->assertUnauthorized();
    $this->withToken($token)->postJson(route('api.print-agent.hello'), ['machine' => 'BARRA-PC', 'version' => '1.0.3', 'printers' => ['POS-80C', 'Microsoft Print to PDF']])
        ->assertOk()
        ->assertJsonPath('agent.name', 'PC cuina');

    expect($agent->refresh()->system_printers)->toBe(['POS-80C', 'Microsoft Print to PDF'])
        ->and($agent->isOnline())->toBeTrue()
        ->and($agent->machine)->toBe('BARRA-PC');
});

test('a job for a printer of the program waits for it, wakes it up, and is printed once', function () {
    Event::fake([PrintJobsWaiting::class]);
    [$agent, $token, $printer] = agentWithPrinter();

    $job = queueJob($printer);

    expect($job->status)->toBe('pending');
    Event::assertDispatched(PrintJobsWaiting::class, fn ($e) => $e->channel === $agent->channel);

    $this->withToken($token)->postJson(route('api.print-agent.hello'))
        ->assertJsonPath('printers.0.systemName', 'POS-80C')
        ->assertJsonPath('printers.0.target', 'windows')
        ->assertJsonPath('realtime.channel', 'print-agent.'.$agent->channel);

    $response = $this->withToken($token)->postJson(route('api.print-agent.jobs'))->assertOk()->assertJsonCount(1, 'jobs');
    $bytes = base64_decode((string) $response->json('jobs.0.escpos'));

    expect($response->json('jobs.0.uuid'))->toBe($job->uuid)
        ->and($bytes)->toContain("\x1Bt\x13")               // code page 858
        ->and($bytes)->toContain("Caf\x8A amb llet")          // è in CP858
        ->and($bytes)->toContain("\xD5")                       // €
        ->and($bytes)->toContain("\x1D(k")                     // native QR
        ->and($job->refresh()->status)->toBe('printing');

    // Already claimed: not handed out twice.
    $this->withToken($token)->postJson(route('api.print-agent.jobs'))->assertJsonCount(0, 'jobs');

    $this->withToken($token)->postJson(route('api.print-agent.ack', $job), ['status' => 'printed'])->assertOk();
    expect($job->refresh()->status)->toBe('printed');
});

test('the program only gets its own printers, old jobs are not printed late, and failures are recorded', function () {
    Event::fake([PrintJobsWaiting::class]);
    [, $token, $printer] = agentWithPrinter();
    [, $otherToken] = PrintAgent::issue('Un altre PC');

    $old = queueJob($printer);
    $old->forceFill(['created_at' => now()->subHour()])->save();
    $job = queueJob($printer);

    $this->withToken($otherToken)->postJson(route('api.print-agent.jobs'))->assertJsonCount(0, 'jobs');
    $this->withToken($token)->postJson(route('api.print-agent.jobs'))->assertJsonCount(1, 'jobs')->assertJsonPath('jobs.0.uuid', $job->uuid);

    expect($old->refresh()->status)->toBe('failed')->and($old->error)->toContain('Caducat');

    $this->withToken($otherToken)->postJson(route('api.print-agent.ack', $job), ['status' => 'printed'])->assertStatus(409);
    $this->withToken($token)->postJson(route('api.print-agent.ack', $job), ['status' => 'failed', 'error' => 'La impressora no respon'])->assertOk();

    expect($job->refresh()->status)->toBe('failed')->and($job->error)->toBe('La impressora no respon');
});

test('printers through the windows driver get drawable lines with the qr as a matrix', function () {
    Event::fake([PrintJobsWaiting::class]);
    [, $token, $printer] = agentWithPrinter('driver');
    queueJob($printer);

    $job = $this->withToken($token)->postJson(route('api.print-agent.jobs'))->json('jobs.0');

    expect($job['escpos'])->toBeNull()
        ->and($job['document']['lines'][0]['text'])->toBe('Cafè amb llet · 1,80 €')
        ->and($job['document']['lines'][1]['matrix'][0])->toMatch('/^[01]{21,}$/');
});

test('admins see the program, test a printer through it and manage its key', function () {
    Event::fake([PrintJobsWaiting::class]);
    $admin = User::factory()->admin()->create();
    [$agent, $token, $printer] = agentWithPrinter();

    $this->actingAs($admin)->get(route('admin.printing'))->assertOk();
    $this->actingAs($admin)->post(route('admin.printers.test', $printer))->assertRedirect();
    expect(PrintJob::query()->where('printer_id', $printer->id)->value('status'))->toBe('pending');

    $this->actingAs($admin)->post(route('admin.print-agents.key', $agent))->assertRedirect();
    $this->withToken($token)->postJson(route('api.print-agent.jobs'))->assertUnauthorized();

    $folder = sys_get_temp_dir().'/print-agent-'.Str::random(8);
    config(['services.print_agent.release_path' => $folder]);
    $this->actingAs($admin)->get(route('admin.print-agents.download'))->assertNotFound();

    mkdir($folder);
    file_put_contents($folder.'/CentreCivicImpressio.exe', 'MZ fake');
    file_put_contents($folder.'/version.txt', "1.0.7\n");
    $this->actingAs($admin)->get(route('admin.print-agents.download'))->assertOk()->assertDownload('CentreCivicImpressio.exe');
    $this->withToken($token)->postJson(route('api.print-agent.hello'))->assertUnauthorized();
    [, $fresh] = PrintAgent::issue('PC nou');
    $this->withToken($fresh)->postJson(route('api.print-agent.hello'))->assertJsonPath('latestVersion', '1.0.7');
    $this->withToken($fresh)->get(route('api.print-agent.download'))->assertOk();
    array_map('unlink', glob($folder.'/*') ?: []);
    rmdir($folder);

    $this->actingAs($admin)->delete(route('admin.print-agents.destroy', $agent))->assertRedirect();
    expect($printer->refresh()->print_agent_id)->toBeNull();
});

test('escpos text falls back to plain letters on printers without a code page', function () {
    $encoder = app(PrintDocumentEncoder::class);

    expect($encoder->text('Crème brûlée 3 €', 'ascii'))->toBe('Creme brulee 3 EUR')
        ->and($encoder->text('Paella ñ', 'cp1252'))->toBe("Paella \xF1");
});

test('the cashier browser leaves the jobs of the program alone and knows the ticket printer prints by itself', function () {
    Event::fake([PrintJobsWaiting::class]);
    [, , $printer] = agentWithPrinter();
    $job = queueJob($printer);
    $admin = User::factory()->admin()->create();
    $cookie = registerTpvDevice($this, $admin, 'cashier');

    $this->withCredentials()->withCookie(DeviceManager::COOKIE, $cookie)
        ->getJson(route('tpv.bootstrap'))
        ->assertOk()
        ->assertJsonPath('printJobs', [])
        ->assertJsonPath('printers.0.direct', true);

    $this->withCredentials()->withCookie(DeviceManager::COOKIE, $cookie)
        ->postJson(route('tpv.print-jobs.claim', $job))
        ->assertStatus(409);
});
