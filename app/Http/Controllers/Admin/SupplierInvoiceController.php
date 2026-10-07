<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SupplierInvoice;
use App\Services\Images\ImageProcessor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupplierInvoiceController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'supplier' => ['nullable', 'string', 'max:120'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'status' => ['nullable', 'in:pending,reviewed'],
        ]);

        $invoices = SupplierInvoice::query()
            ->with('uploader:id,name')
            ->when($filters['supplier'] ?? null, fn ($q, $s) => $q->where('supplier', 'like', "%{$s}%"))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->whereDate('invoice_date', '>=', $d))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->whereDate('invoice_date', '<=', $d))
            ->when(($filters['status'] ?? null) === 'pending', fn ($q) => $q->where(fn ($q) => $q->whereNull('supplier')->orWhereNull('amount')))
            ->when(($filters['status'] ?? null) === 'reviewed', fn ($q) => $q->whereNotNull('supplier')->whereNotNull('amount'))
            ->latest('id')
            ->paginate(24)
            ->withQueryString()
            ->through(fn (SupplierInvoice $i) => [
                'id' => $i->id,
                'supplier' => $i->supplier,
                'invoiceDate' => $i->invoice_date?->toDateString(),
                'amount' => $i->amount,
                'notes' => $i->notes,
                'mimeType' => $i->mime_type,
                'originalName' => $i->original_name,
                'size' => $i->size,
                'ocrStatus' => $i->ocr_status,
                'uploadedBy' => $i->uploader?->name,
                'createdAt' => $i->created_at?->toIso8601String(),
                'fileUrl' => route('admin.suppliers.file', $i),
            ]);

        return Inertia::render('admin/SupplierInvoices', [
            'invoices' => $invoices,
            'filters' => $filters,
            'suppliers' => SupplierInvoice::query()->whereNotNull('supplier')->distinct()->orderBy('supplier')->pluck('supplier'),
        ]);
    }

    public function store(Request $request, ImageProcessor $images): RedirectResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,heic,pdf', 'max:20480'],
            'supplier' => ['nullable', 'string', 'max:120'],
            'invoiceDate' => ['nullable', 'date'],
            'amount' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $file = $request->file('file');
        $isPdf = $file->getMimeType() === 'application/pdf';
        $directory = 'supplier-invoices/'.now()->format('Y/m');

        if ($isPdf) {
            $path = $file->storeAs($directory, Str::uuid().'.pdf', 'local');
            $mime = 'application/pdf';
        } else {
            $path = $images->document($file, $directory);
            $mime = 'image/jpeg';
        }

        SupplierInvoice::query()->create([
            'uuid' => (string) Str::uuid(),
            'supplier' => $data['supplier'] ?? null,
            'invoice_date' => $data['invoiceDate'] ?? null,
            'amount' => $data['amount'] ?? null,
            'notes' => $data['notes'] ?? null,
            'file_path' => (string) $path,
            'mime_type' => $mime,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 250),
            'size' => (int) Storage::disk('local')->size((string) $path),
            'ocr_status' => 'none',
            'uploaded_by' => $request->user()?->id,
        ]);

        $this->toast(__('tpv.saved'));

        return back();
    }

    public function update(Request $request, SupplierInvoice $invoice): RedirectResponse
    {
        $data = $request->validate([
            'supplier' => ['nullable', 'string', 'max:120'],
            'invoiceDate' => ['nullable', 'date'],
            'amount' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $invoice->update([
            'supplier' => $data['supplier'] ?? null,
            'invoice_date' => $data['invoiceDate'] ?? null,
            'amount' => $data['amount'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        $this->toast(__('tpv.saved'));

        return back();
    }

    public function destroy(SupplierInvoice $invoice): RedirectResponse
    {
        $invoice->delete();
        $this->toast(__('tpv.deleted'));

        return back();
    }

    public function file(SupplierInvoice $invoice): StreamedResponse
    {
        abort_unless(Storage::disk('local')->exists($invoice->file_path), 404);

        return Storage::disk('local')->response($invoice->file_path, $invoice->original_name ?? basename($invoice->file_path), [
            'Content-Type' => $invoice->mime_type,
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
