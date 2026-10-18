<?php

namespace App\Http\Controllers\Api;

use App\Events\TpvChanged;
use App\Http\Controllers\Controller;
use App\Models\PrintAgent;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Services\Printing\PrintAgentRelease;
use App\Services\Printing\PrintDocumentEncoder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * What the Windows print program talks to. It only ever calls out (no ports to open in the
 * bar): it says hello, pulls the jobs of its printers when woken up by the websocket (and
 * every few seconds anyway), and acknowledges each one.
 */
class PrintAgentController extends Controller
{
    public function __construct(private readonly PrintDocumentEncoder $encoder) {}

    /**
     * Check in: report the PC, its Windows printers and the printers set up in the program;
     * get those printers back (with what the web decides about them) and how to listen for
     * new jobs.
     */
    public function hello(Request $request, PrintAgentRelease $release): JsonResponse
    {
        $agent = $this->agent($request);
        $this->checkIn($agent, $request);
        $this->syncPrinters($agent, $request);

        $key = config('broadcasting.connections.reverb.key');
        $host = (string) config('services.print_agent.ws_host');
        $scheme = (string) (config('services.print_agent.ws_scheme') ?: $request->getScheme());

        return response()->json([
            'agent' => ['id' => $agent->id, 'name' => $agent->name],
            'latestVersion' => $release->version(),
            'realtime' => is_string($key) && $key !== '' ? [
                'key' => $key,
                'host' => $host === '' || $host === 'localhost' ? $request->getHost() : $host,
                'port' => (int) (config('services.print_agent.ws_port') ?: ($scheme === 'https' ? 443 : 80)),
                'scheme' => $scheme,
                'channel' => 'print-agent.'.$agent->channel,
                'event' => 'jobs',
            ] : null,
            'printers' => $agent->printers()->orderBy('name')->get()->map(fn (Printer $p) => $this->printer($p))->values(),
        ]);
    }

    /**
     * Claim and return the jobs waiting for this program's printers.
     */
    public function jobs(Request $request): JsonResponse
    {
        $agent = $this->agent($request);
        $this->checkIn($agent, $request);

        $printers = $agent->printers()->where('active', true)->get()->keyBy('id');
        $stale = now()->subMinutes(PrintJob::STALE_CLAIM_MINUTES);
        // In-progress jobs come back only if never acknowledged (the program closed while
        // printing); never while it may still be printing them, or they would come out twice.
        $waiting = fn (Builder $query) => $query
            ->whereIn('printer_id', $printers->keys())
            ->where(fn ($q) => $q->where('status', 'pending')->orWhere(fn ($q) => $q->where('status', 'printing')->where('updated_at', '<', $stale)));

        // Orders from long ago would only confuse the kitchen: they are not printed late.
        $expired = PrintJob::query()->tap($waiting)->where('created_at', '<', now()->subMinutes(PrintAgent::MAX_JOB_AGE_MINUTES))
            ->update(['status' => 'failed', 'error' => 'Caducat: el programa d\'impressió no estava connectat.', 'updated_at' => now()]);

        $jobs = [];

        foreach (PrintJob::query()->tap($waiting)->orderBy('id')->limit(20)->get() as $job) {
            $claimed = PrintJob::query()->tap($waiting)->whereKey($job->id)
                ->update(['status' => 'printing', 'claimed_by_agent_id' => $agent->id, 'updated_at' => now()]);

            if ($claimed === 0) {
                continue;
            }

            $printer = $printers->get($job->printer_id);

            if ($printer instanceof Printer) {
                $jobs[] = $this->job($job, $printer);
            }
        }

        if ($jobs !== [] || $expired > 0) {
            TpvChanged::notify(['prints']);
        }

        return response()->json(['jobs' => $jobs]);
    }

    public function ack(Request $request, PrintJob $printJob): JsonResponse
    {
        $agent = $this->agent($request);
        $data = $request->validate([
            'status' => ['required', Rule::in(['printed', 'failed'])],
            'error' => ['nullable', 'string', 'max:250'],
        ]);

        if ($printJob->claimed_by_agent_id !== $agent->id || $printJob->status !== 'printing') {
            return response()->json(['ok' => false, 'status' => $printJob->status], 409);
        }

        $printJob->forceFill([
            'status' => $data['status'],
            'error' => $data['status'] === 'failed' ? ($data['error'] ?? 'Error') : null,
        ])->save();

        TpvChanged::notify(['prints']);

        return response()->json(['ok' => true]);
    }

    /**
     * The latest version of the program, for its automatic update.
     */
    public function download(Request $request, PrintAgentRelease $release): BinaryFileResponse
    {
        $this->agent($request);

        return $release->response();
    }

    private function agent(Request $request): PrintAgent
    {
        return PrintAgent::findByToken($request->bearerToken()) ?? abort(401, 'Clau no vàlida. Torna a connectar el programa amb una clau nova de Gestió → Impressió.');
    }

    private function checkIn(PrintAgent $agent, Request $request): void
    {
        $data = $request->validate([
            'machine' => ['nullable', 'string', 'max:120'],
            'version' => ['nullable', 'string', 'max:20'],
            'printers' => ['nullable', 'array', 'max:50'],
            'printers.*' => ['string', 'max:200'],
        ]);

        $agent->forceFill(array_filter([
            'machine' => $data['machine'] ?? null,
            'version' => $data['version'] ?? null,
            'system_printers' => isset($data['printers']) ? array_values($data['printers']) : null,
        ], fn ($value) => $value !== null) + ['last_seen_at' => now()])->save();
    }

    /**
     * The printers are set up in the program: create, update and remove them to match its
     * list. Older programs (or one that has not adopted the existing printers yet) send no
     * list, and then nothing changes.
     */
    private function syncPrinters(PrintAgent $agent, Request $request): void
    {
        if (! is_array($request->input('definitions'))) {
            return;
        }

        $data = $request->validate([
            'definitions' => ['array', 'max:30'],
            'definitions.*.key' => ['required', 'string', 'max:40', 'distinct'],
            'definitions.*.name' => ['required', 'string', 'max:60'],
            'definitions.*.target' => ['required', Rule::in(['windows', 'network'])],
            'definitions.*.systemName' => ['nullable', 'required_if:definitions.*.target,windows', 'string', 'max:80'],
            'definitions.*.ip' => ['nullable', 'required_if:definitions.*.target,network', 'ip'],
            'definitions.*.port' => ['nullable', 'integer', 'between:1,65535'],
            'definitions.*.output' => ['required', Rule::in(Printer::OUTPUTS)],
            'definitions.*.codepage' => ['nullable', Rule::in(Printer::CODEPAGES)],
            'definitions.*.paperWidth' => ['required', 'integer', Rule::in([32, 42, 48])],
        ]);

        $changed = false;
        $keys = [];

        foreach ($data['definitions'] as $definition) {
            $network = $definition['target'] === 'network';
            $keys[] = $definition['key'];
            $printer = Printer::query()->firstOrNew(['print_agent_id' => $agent->id, 'agent_key' => $definition['key']]);

            if (! $printer->exists) {
                $printer->forceFill(['active' => true, 'is_ticket_printer' => false]);
            }

            $printer->forceFill([
                'name' => $definition['name'],
                'type' => $network ? 'escpos_network' : 'system',
                'output' => $network ? 'escpos' : $definition['output'],
                'codepage' => $definition['codepage'] ?? 'cp858',
                'system_name' => $network ? null : $definition['systemName'],
                'ip' => $network ? $definition['ip'] : null,
                'port' => $network ? ($definition['port'] ?? 9100) : null,
                'paper_width' => (int) $definition['paperWidth'],
            ]);

            if ($printer->isDirty()) {
                $printer->save();
                $changed = true;
            }
        }

        $removed = $agent->printers()->where(fn ($q) => $q->whereNull('agent_key')->orWhereNotIn('agent_key', $keys))->get();

        foreach ($removed as $printer) {
            $printer->delete();
            $changed = true;
        }

        if ($changed) {
            TpvChanged::notify(['printing']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function printer(Printer $printer): array
    {
        return [
            'id' => $printer->id,
            'key' => $printer->agent_key,
            'name' => $printer->name,
            'active' => $printer->active,
            'target' => $printer->type === 'escpos_network' ? 'network' : 'windows',
            'systemName' => $printer->system_name,
            'ip' => $printer->ip,
            'port' => $printer->port ?? 9100,
            'output' => $printer->type === 'escpos_network' ? 'escpos' : $printer->output,
            'codepage' => $printer->codepage,
            'paperWidth' => $printer->paper_width,
            'isTicketPrinter' => $printer->is_ticket_printer,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function job(PrintJob $job, Printer $printer): array
    {
        $config = $this->printer($printer);
        $document = $printer->withMargins($job->document);

        return [
            'uuid' => $job->uuid,
            'kind' => $job->kind,
            'title' => $job->title,
            'createdAt' => $job->created_at?->toIso8601String(),
            'printer' => $config,
            'escpos' => $config['output'] === 'escpos' ? base64_encode($this->encoder->escpos($document, $printer->paper_width, $printer->codepage)) : null,
            'document' => $config['output'] === 'driver' ? $this->encoder->forDriver($document, $printer->paper_width) : null,
        ];
    }
}
