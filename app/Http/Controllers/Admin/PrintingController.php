<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DeviceType;
use App\Events\TpvChanged;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\PrintAgent;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\ProductionDestination;
use App\Services\Printing\PrintAgentRelease;
use App\Services\Printing\PrintService;
use App\Support\Translation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PrintingController extends Controller
{
    public function index(PrintAgentRelease $release): Response
    {
        return Inertia::render('admin/Printing', [
            'destinations' => ProductionDestination::query()->with('printers:id')->orderBy('sort')->get()->map(fn (ProductionDestination $d) => [
                'id' => $d->id,
                'name' => $d->name,
                'code' => $d->code,
                'mode' => $d->mode,
                'printerIds' => $d->printers->pluck('id'),
            ]),
            'printers' => Printer::query()->with('destinations:id')->orderBy('name')->get()->map(fn (Printer $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'type' => $p->type,
                'printAgentId' => $p->print_agent_id,
                'managed' => $p->managedByAgent(),
                'output' => $p->output,
                'codepage' => $p->codepage,
                'ip' => $p->ip,
                'port' => $p->port,
                'model' => $p->model,
                'systemName' => $p->system_name,
                'paperWidth' => $p->paper_width,
                'isTicketPrinter' => $p->is_ticket_printer,
                'active' => $p->active,
                'destinationIds' => $p->destinations->pluck('id'),
            ]),
            'types' => Printer::TYPES,
            'agents' => PrintAgent::query()->orderBy('name')->get()->map(fn (PrintAgent $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'online' => $a->isOnline(),
                'lastSeenAt' => $a->last_seen_at?->toIso8601String(),
                'machine' => $a->machine,
                'version' => $a->version,
                'systemPrinters' => $a->system_printers ?? [],
            ]),
            'devices' => Device::query()->where('active', true)->whereIn('type', [DeviceType::Cashier, DeviceType::Tablet])->orderBy('name')->get()->map(fn (Device $d) => [
                'id' => $d->id,
                'name' => $d->name,
                'type' => $d->type->value,
                'ticketPrinterId' => $d->ticket_printer_id,
            ]),
            'program' => ['available' => $release->available(), 'version' => $release->version(), 'size' => $release->size()],
        ]);
    }

    public function log(Request $request): Response
    {
        $filters = $request->validate([
            'kind' => ['nullable', 'string', 'max:20'],
            'printer' => ['nullable', 'integer'],
            'date' => ['nullable', 'date'],
        ]);

        $jobs = PrintJob::query()
            ->with('creator:id,name')
            ->when($filters['kind'] ?? null, fn ($q, $kind) => $q->where('kind', $kind))
            ->when($filters['printer'] ?? null, fn ($q, $printer) => $q->where('printer_id', $printer))
            ->when($filters['date'] ?? null, fn ($q, $date) => $q->whereDate('created_at', $date))
            ->latest('id')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (PrintJob $job) => [
                'id' => $job->id,
                'kind' => $job->kind,
                'title' => $job->title,
                'printerName' => $job->printer_name,
                'status' => $job->status,
                'createdBy' => $job->creator?->name,
                'createdAt' => $job->created_at?->toIso8601String(),
                'document' => $job->document,
            ]);

        return Inertia::render('admin/PrintLog', [
            'jobs' => $jobs,
            'filters' => $filters,
            'printers' => Printer::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function storeDestination(Request $request): RedirectResponse
    {
        $data = $this->destinationData($request);
        $destination = ProductionDestination::query()->create($data['attributes'] + ['sort' => (int) ProductionDestination::query()->max('sort') + 1]);
        $destination->printers()->sync($data['printerIds']);

        return $this->changed();
    }

    public function updateDestination(Request $request, ProductionDestination $destination): RedirectResponse
    {
        $data = $this->destinationData($request, $destination);
        $destination->update($data['attributes']);
        $destination->printers()->sync($data['printerIds']);

        return $this->changed();
    }

    public function destroyDestination(ProductionDestination $destination): RedirectResponse
    {
        $destination->delete();

        return $this->changed(__('tpv.deleted'));
    }

    public function storePrinter(Request $request): RedirectResponse
    {
        $data = $this->printerData($request);
        $printer = Printer::query()->create($data['attributes']);
        $printer->destinations()->sync($data['destinationIds']);
        $this->singleTicketPrinter($printer);

        return $this->changed();
    }

    public function updatePrinter(Request $request, Printer $printer): RedirectResponse
    {
        // Name, where and how it prints are set up in the print program; here only what it prints.
        if ($printer->managedByAgent()) {
            $data = $request->validate([
                'isTicketPrinter' => ['boolean'],
                'active' => ['boolean'],
                'destinationIds' => ['array'],
                'destinationIds.*' => ['integer', 'exists:production_destinations,id'],
            ]);
            $printer->update(['is_ticket_printer' => $data['isTicketPrinter'] ?? false, 'active' => $data['active'] ?? true]);
            $printer->destinations()->sync(array_values(array_map('intval', $data['destinationIds'] ?? [])));
            $this->singleTicketPrinter($printer);

            return $this->changed();
        }

        $data = $this->printerData($request);
        $printer->update($data['attributes']);
        $printer->destinations()->sync($data['destinationIds']);
        $this->singleTicketPrinter($printer);

        return $this->changed();
    }

    public function destroyPrinter(Printer $printer): RedirectResponse
    {
        if ($printer->managedByAgent()) {
            $this->toast(__('tpv.printer_managed_by_agent'), 'error');

            return back();
        }

        $printer->delete();

        return $this->changed(__('tpv.deleted'));
    }

    public function test(Request $request, Printer $printer, PrintService $print): RedirectResponse
    {
        $width = $printer->paper_width;
        $print->store((string) Str::uuid(), 'test', 'Prova / Prueba · '.$printer->name, [
            'width' => $width,
            'lines' => [
                ['type' => 'text', 'text' => 'PROVA D\'IMPRESSIÓ / PRUEBA', 'align' => 'center', 'bold' => true, 'size' => 2],
                ['type' => 'text', 'text' => $printer->name, 'align' => 'center'],
                ['type' => 'text', 'text' => now()->format('d/m/Y H:i:s'), 'align' => 'center'],
                ['type' => 'divider'],
                ['type' => 'text', 'text' => 'ÀÉÈÍÏÓÒÚÜÇ àéèíïóòúüç ñÑ €'],
                ['type' => 'row', 'left' => 'Esquerra / Izquierda', 'right' => 'Dreta / Derecha'],
                ['type' => 'qr', 'data' => url('/')],
                ['type' => 'feed', 'lines' => 2],
                ['type' => 'cut'],
            ],
        ], $printer->id, $request->user()?->id, null);

        TpvChanged::notify(['prints', 'printing']);
        $this->toast(match (true) {
            $printer->agent !== null => __('tpv.test_print_agent', ['name' => $printer->agent->name]),
            $printer->type === 'system' => __('tpv.test_print_queued'),
            default => __('tpv.test_printed'),
        });

        return back();
    }

    /**
     * Which printer the tickets of a device come out on (null = the general one).
     */
    public function updateDevice(Request $request, Device $device): RedirectResponse
    {
        $data = $request->validate(['ticketPrinterId' => ['nullable', 'integer', 'exists:printers,id']]);
        $device->forceFill(['ticket_printer_id' => $data['ticketPrinterId'] ?? null])->save();

        return $this->changed();
    }

    public function download(PrintAgentRelease $release): BinaryFileResponse
    {
        return $release->response();
    }

    /**
     * Register a PC with the print program; its key is shown once, to paste in the program.
     */
    public function storeAgent(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:60']]);
        [$agent, $token] = PrintAgent::issue($data['name']);
        Inertia::flash('agentKey', ['id' => $agent->id, 'name' => $agent->name, 'key' => PrintAgent::connectionKey($token)]);

        return back();
    }

    public function updateAgent(Request $request, PrintAgent $agent): RedirectResponse
    {
        $agent->update($request->validate(['name' => ['required', 'string', 'max:60']]));

        return $this->changed();
    }

    /**
     * A new key (lost key, or a new PC): the old one stops working at once.
     */
    public function agentKey(PrintAgent $agent): RedirectResponse
    {
        $token = $agent->newToken();
        $agent->save();
        Inertia::flash('agentKey', ['id' => $agent->id, 'name' => $agent->name, 'key' => PrintAgent::connectionKey($token)]);

        return back();
    }

    public function destroyAgent(PrintAgent $agent): RedirectResponse
    {
        $agent->delete();

        return $this->changed(__('tpv.deleted'));
    }

    private function singleTicketPrinter(Printer $printer): void
    {
        if ($printer->is_ticket_printer) {
            Printer::query()->whereKeyNot($printer->id)->update(['is_ticket_printer' => false]);
        }
    }

    /**
     * @return array{attributes: array<string, mixed>, printerIds: list<int>}
     */
    private function destinationData(Request $request, ?ProductionDestination $destination = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'array'],
            'name.ca' => ['required_without:name.es', 'nullable', 'string', 'max:60'],
            'name.es' => ['required_without:name.ca', 'nullable', 'string', 'max:60'],
            'code' => ['required', 'alpha_dash', 'max:30', Rule::unique('production_destinations', 'code')->ignore($destination?->id)],
            'mode' => ['required', Rule::in(['printer', 'screen', 'both'])],
            'printerIds' => ['array'],
            'printerIds.*' => ['integer', 'exists:printers,id'],
        ]);

        return [
            'attributes' => [
                'name' => Translation::normalize($data['name']),
                'code' => $data['code'],
                'mode' => $data['mode'],
            ],
            'printerIds' => array_values(array_map('intval', $data['printerIds'] ?? [])),
        ];
    }

    /**
     * @return array{attributes: array<string, mixed>, destinationIds: list<int>}
     */
    private function printerData(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'type' => ['required', Rule::in(Printer::TYPES)],
            'output' => ['nullable', Rule::in(Printer::OUTPUTS)],
            'codepage' => ['nullable', Rule::in(Printer::CODEPAGES)],
            'ip' => ['nullable', 'required_if:type,escpos_network', 'ip'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'model' => ['nullable', 'string', 'max:60'],
            'systemName' => ['nullable', 'string', 'max:80'],
            'paperWidth' => ['required', 'integer', Rule::in([32, 42, 48])],
            'isTicketPrinter' => ['boolean'],
            'active' => ['boolean'],
            'destinationIds' => ['array'],
            'destinationIds.*' => ['integer', 'exists:production_destinations,id'],
        ]);

        return [
            'attributes' => [
                'name' => $data['name'],
                'type' => $data['type'],
                // Printers of the print program are created in the program.
                'print_agent_id' => null,
                'agent_key' => null,
                'output' => $data['output'] ?? 'escpos',
                'codepage' => $data['codepage'] ?? 'cp858',
                'ip' => $data['ip'] ?? null,
                'port' => $data['port'] ?? ($data['type'] === 'escpos_network' ? 9100 : null),
                'model' => $data['model'] ?? null,
                'system_name' => $data['type'] === 'system' ? ($data['systemName'] ?: $data['name']) : null,
                'paper_width' => (int) $data['paperWidth'],
                'is_ticket_printer' => $data['isTicketPrinter'] ?? false,
                'active' => $data['active'] ?? true,
            ],
            'destinationIds' => array_values(array_map('intval', $data['destinationIds'] ?? [])),
        ];
    }

    private function changed(string $message = ''): RedirectResponse
    {
        TpvChanged::notify(['printing']);
        $this->toast($message === '' ? __('tpv.saved') : $message);

        return back();
    }
}
