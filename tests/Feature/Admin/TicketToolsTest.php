<?php

use App\Events\PrintJobsWaiting;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\User;
use Illuminate\Support\Facades\Event;

test('admins switch a ticket between cash and card without touching amounts or the fingerprint', function () {
    $ticket = issueTpvTicket($this, 'tok-payments-1');
    $payment = $ticket->payments()->firstOrFail();
    $hash = $ticket->hash;
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get(route('admin.tickets'))->assertOk();
    $this->actingAs($admin)->patch(route('admin.tickets.payments', $ticket), ['payments' => [['id' => $payment->id, 'method' => 'cash']]])->assertRedirect();

    expect($payment->refresh()->method)->toBe('cash')
        ->and($payment->amount)->toBe(500)
        ->and($ticket->refresh()->hash)->toBe($hash)
        ->and(AuditLog::query()->where('action', 'ticket.payment_method')->exists())->toBeTrue();

    // Only payments of this ticket.
    $other = issueTpvTicket($this, 'tok-payments-2')->payments()->firstOrFail();
    $this->actingAs($admin)->patch(route('admin.tickets.payments', $ticket), ['payments' => [['id' => $other->id, 'method' => 'cash']]])->assertSessionHasErrors();

    $waiter = User::factory()->create();
    $this->actingAs($waiter)->patch(route('admin.tickets.payments', $ticket), ['payments' => [['id' => $payment->id, 'method' => 'card']]])->assertForbidden();
});

test('the simulation gives the layout context of the chosen device and prints the jobs to their printers', function () {
    Event::fake([PrintJobsWaiting::class]);
    $ticket = issueTpvTicket($this, 'tok-simulate-1');
    $admin = User::factory()->admin()->create();
    $kitchen = Printer::query()->create(['name' => 'Cuina', 'type' => 'simulated', 'paper_width' => 42, 'is_ticket_printer' => false, 'active' => true]);
    $bar = Printer::query()->create(['name' => 'Caixa', 'type' => 'simulated', 'paper_width' => 48, 'is_ticket_printer' => true, 'active' => true]);
    $device = Device::query()->latest('id')->firstOrFail();
    $device->forceFill(['ticket_printer_id' => $kitchen->id])->save();

    $this->actingAs($admin)->get(route('admin.tickets.show', $ticket))->assertOk();
    $this->actingAs($admin)->getJson(route('admin.tickets.simulation', ['ticket' => $ticket, 'device' => $device->id]))
        ->assertOk()
        ->assertJsonPath('context.device.ticketPrinterId', $kitchen->id)
        ->assertJsonPath('ticket.fullNumber', $ticket->full_number)
        ->assertJsonPath('ticket.payments.0.method', 'card')
        ->assertJsonPath('ticket.lines.0.name', 'Entrepà');

    $document = ['width' => 42, 'lines' => [['type' => 'text', 'text' => '*** SIMULACIÓ ***'], ['type' => 'cut']]];
    $this->actingAs($admin)->postJson(route('admin.tickets.simulate', $ticket), ['device' => $device->id, 'jobs' => [
        ['kind' => 'order', 'title' => 'Cuina · 4', 'printerId' => $kitchen->id, 'document' => $document],
        ['kind' => 'ticket', 'title' => 'Tiquet', 'printerId' => $bar->id, 'document' => $document],
    ]])->assertOk()->assertJsonPath('printers', ['Cuina', 'Caixa']);

    expect(PrintJob::query()->where('printer_id', $kitchen->id)->where('kind', 'order')->exists())->toBeTrue()
        ->and(PrintJob::query()->where('printer_id', $bar->id)->where('kind', 'ticket')->exists())->toBeTrue();

    $this->actingAs($admin)->postJson(route('admin.tickets.simulate', $ticket), ['jobs' => [['kind' => 'z_report', 'title' => 'x', 'document' => $document]]])->assertUnprocessable();
});
