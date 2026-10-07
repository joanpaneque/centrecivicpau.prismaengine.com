<?php

use App\Models\Invoice;
use Inertia\Testing\AssertableInertia as Assert;

test('an unknown token shows the pending state instead of a 404', function () {
    $this->get(route('invoice-request', str_repeat('b', 32)))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('public/InvoiceRequest')->where('ticket', null));
});

test('too short tokens are not found', function () {
    $this->get(route('invoice-request', 'short'))->assertNotFound();
});

test('a customer can request an invoice for a ticket and download it', function () {
    $token = str_repeat('c', 32);
    $ticket = issueTpvTicket($this, $token);

    $this->get(route('invoice-request', $token))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('ticket.fullNumber', $ticket->full_number)->where('invoice', null));

    $this->post(route('invoice-request.store', $token), [
        'customer_name' => 'Empresa Prova SL',
        'customer_tax_id' => 'B12345678',
        'address' => 'Carrer Major 1',
        'postal_code' => '08001',
        'city' => 'Barcelona',
        'customer_email' => null,
    ])->assertRedirect(route('invoice-request', $token));

    $invoice = Invoice::query()->where('ticket_id', $ticket->id)->firstOrFail();

    expect($invoice->customer_tax_id)->toBe('B12345678')
        ->and($invoice->ticket_id)->toBe($ticket->id)
        ->and($invoice->full_number)->not->toBe($ticket->full_number);

    $this->get(route('invoice-request.pdf', $token))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

test('an invoice cannot be requested twice for the same ticket', function () {
    $token = str_repeat('d', 32);
    $ticket = issueTpvTicket($this, $token);
    $data = ['customer_name' => 'Primer', 'customer_tax_id' => 'B12345678', 'address' => 'C/ 1', 'postal_code' => '08001', 'city' => 'Barcelona'];

    $this->post(route('invoice-request.store', $token), $data)->assertRedirect();
    $this->post(route('invoice-request.store', $token), ['customer_name' => 'Segon'] + $data)->assertSessionHasErrors('customer_name');

    expect(Invoice::query()->where('ticket_id', $ticket->id)->count())->toBe(1);
});
