<?php

namespace App\Http\Controllers\Admin;

use App\Events\TpvChanged;
use App\Http\Controllers\Controller;
use App\Models\Shift;
use App\Models\ShiftTemplate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ShiftController extends Controller
{
    public function index(Request $request): Response
    {
        $data = $request->validate([
            'date' => ['nullable', 'date'],
            'view' => ['nullable', Rule::in(['week', 'month'])],
        ]);

        $view = $data['view'] ?? 'week';
        $anchor = isset($data['date']) ? CarbonImmutable::parse($data['date']) : CarbonImmutable::now();
        [$from, $to] = $view === 'month'
            ? [$anchor->startOfMonth()->startOfWeek(), $anchor->endOfMonth()->endOfWeek()]
            : [$anchor->startOfWeek(), $anchor->endOfWeek()];

        return Inertia::render('admin/Shifts', [
            'view' => $view,
            'anchor' => $anchor->toDateString(),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'workers' => User::query()->where('active', true)->where('role', '!=', 'kitchen')->orderBy('name')->get(['id', 'name', 'color']),
            'templates' => ShiftTemplate::query()->orderBy('sort')->get()->map(fn (ShiftTemplate $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'startTime' => substr($t->start_time, 0, 5),
                'endTime' => substr($t->end_time, 0, 5),
                'breakMinutes' => $t->break_minutes,
                'color' => $t->color,
            ]),
            'shifts' => Shift::query()->whereBetween('date', [$from->toDateString(), $to->toDateString()])->orderBy('start_time')->get()->map(fn (Shift $s) => $this->shift($s)),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $template = isset($data['templateId']) ? ShiftTemplate::query()->whereKey($data['templateId'])->first() : null;

        Shift::query()->create([
            'user_id' => $data['userId'],
            'date' => $data['date'],
            'start_time' => $data['startTime'] ?? ($template !== null ? $template->start_time : '09:00'),
            'end_time' => $data['endTime'] ?? ($template !== null ? $template->end_time : '17:00'),
            'break_minutes' => $data['breakMinutes'] ?? ($template !== null ? $template->break_minutes : 0),
            'shift_template_id' => $template?->id,
            'notes' => $data['notes'] ?? null,
        ]);

        return $this->changed();
    }

    public function update(Request $request, Shift $shift): RedirectResponse
    {
        $data = $this->validated($request, partial: true);
        $shift->fill(array_filter([
            'user_id' => $data['userId'] ?? null,
            'date' => $data['date'] ?? null,
            'start_time' => $data['startTime'] ?? null,
            'end_time' => $data['endTime'] ?? null,
        ], fn ($v) => $v !== null));

        if (array_key_exists('breakMinutes', $data)) {
            $shift->break_minutes = (int) $data['breakMinutes'];
        }

        if (array_key_exists('notes', $data)) {
            $shift->notes = $data['notes'];
        }

        if ($request->boolean('copy')) {
            $copy = $shift->replicate();
            $copy->save();
            $shift->refresh();
        } else {
            $shift->save();
        }

        return $this->changed();
    }

    public function destroy(Shift $shift): RedirectResponse
    {
        $shift->delete();

        return $this->changed();
    }

    /**
     * Copies the previous week's shifts into the week starting at `date`, skipping
     * workers that already have a shift that day.
     */
    public function copyPreviousWeek(Request $request): RedirectResponse
    {
        $start = CarbonImmutable::parse($request->validate(['date' => ['required', 'date']])['date'])->startOfWeek();
        $previous = Shift::query()->whereBetween('date', [$start->subWeek()->toDateString(), $start->subDay()->toDateString()])->get();
        $copied = 0;

        DB::transaction(function () use ($previous, &$copied) {
            foreach ($previous as $shift) {
                $date = CarbonImmutable::instance($shift->date)->addWeek()->toDateString();

                if (Shift::query()->where('user_id', $shift->user_id)->whereDate('date', $date)->exists()) {
                    continue;
                }

                $copy = $shift->replicate();
                $copy->date = Carbon::parse($date);
                $copy->save();
                $copied++;
            }
        });

        return $this->changed(__('tpv.shifts_copied', ['count' => $copied]));
    }

    public function storeTemplate(Request $request): RedirectResponse
    {
        ShiftTemplate::query()->create($this->templateData($request) + ['sort' => (int) ShiftTemplate::query()->max('sort') + 1]);

        return $this->changed();
    }

    public function updateTemplate(Request $request, ShiftTemplate $template): RedirectResponse
    {
        $template->update($this->templateData($request));

        return $this->changed();
    }

    public function destroyTemplate(ShiftTemplate $template): RedirectResponse
    {
        $template->delete();

        return $this->changed();
    }

    /**
     * @return array<string, mixed>
     */
    private function shift(Shift $s): array
    {
        return [
            'id' => $s->id,
            'userId' => $s->user_id,
            'date' => $s->date->toDateString(),
            'startTime' => substr($s->start_time, 0, 5),
            'endTime' => substr($s->end_time, 0, 5),
            'breakMinutes' => $s->break_minutes,
            'templateId' => $s->shift_template_id,
            'notes' => $s->notes,
            'minutes' => $s->plannedMinutes(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'userId' => [$required, 'integer', 'exists:users,id'],
            'date' => [$required, 'date'],
            'templateId' => ['nullable', 'integer', 'exists:shift_templates,id'],
            'startTime' => ['nullable', 'date_format:H:i'],
            'endTime' => ['nullable', 'date_format:H:i'],
            'breakMinutes' => ['nullable', 'integer', 'min:0', 'max:600'],
            'notes' => ['nullable', 'string', 'max:200'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function templateData(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:40'],
            'startTime' => ['required', 'date_format:H:i'],
            'endTime' => ['required', 'date_format:H:i'],
            'breakMinutes' => ['nullable', 'integer', 'min:0', 'max:600'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        return [
            'name' => $data['name'],
            'start_time' => $data['startTime'],
            'end_time' => $data['endTime'],
            'break_minutes' => (int) ($data['breakMinutes'] ?? 0),
            'color' => $data['color'] ?? '#00056a',
        ];
    }

    private function changed(string $message = ''): RedirectResponse
    {
        TpvChanged::notify(['shifts']);

        if ($message !== '') {
            $this->toast($message);
        }

        return back();
    }
}
