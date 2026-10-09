<?php

namespace App\Http\Controllers\Tpv;

use App\Enums\DeviceType;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Services\Devices\DeviceManager;
use App\Services\Printing\DocumentPdfRenderer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TicketPdfController extends Controller
{
    public function __construct(private readonly DeviceManager $devices) {}

    public function store(Request $request, DocumentPdfRenderer $renderer): Response
    {
        $this->cashier($request);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'document' => ['required', 'array'],
            'document.width' => ['required', 'integer', 'min:20', 'max:64'],
            'document.lines' => ['required', 'array', 'max:500'],
            'document.lines.*.type' => ['required', 'string', Rule::in(['text', 'row', 'divider', 'qr', 'image', 'feed', 'cut'])],
            'document.lines.*.text' => ['nullable', 'string', 'max:500'],
            'document.lines.*.left' => ['nullable', 'string', 'max:500'],
            'document.lines.*.right' => ['nullable', 'string', 'max:500'],
            'document.lines.*.data' => ['nullable', 'string', 'max:2000'],
            'document.lines.*.caption' => ['nullable', 'string', 'max:200'],
            'document.lines.*.url' => ['nullable', 'string', 'max:4000'],
            'document.lines.*.align' => ['nullable', 'string', Rule::in(['left', 'center', 'right'])],
            'document.lines.*.bold' => ['nullable', 'boolean'],
            'document.lines.*.size' => ['nullable', 'integer', Rule::in([1, 2])],
            'document.lines.*.char' => ['nullable', 'string', 'max:1'],
            'document.lines.*.lines' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        $filename = Str::slug($data['title']) ?: 'ticket';

        return response($renderer->render($data['document'], $data['title']), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'.pdf"',
        ]);
    }

    private function cashier(Request $request): Device
    {
        $device = $this->devices->fromRequest($request);

        if ($device === null || $device->type !== DeviceType::Cashier) {
            abort(403);
        }

        return $device;
    }
}
