<?php

namespace App\Http\Controllers\Api;

use App\Events\TpvChanged;
use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\Zone;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Public integration API (e.g. a booking form on the website). Requires a Sanctum token
 * with the reservations:read / reservations:write abilities.
 */
class ReservationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $from = isset($data['from']) ? CarbonImmutable::parse($data['from'])->startOfDay() : CarbonImmutable::now()->startOfDay();
        $to = isset($data['to']) ? CarbonImmutable::parse($data['to'])->endOfDay() : $from->addDays(30)->endOfDay();

        return response()->json([
            'data' => Reservation::query()->with('zone:id,slug')->whereBetween('reserved_at', [$from, $to])->orderBy('reserved_at')->get()->map(fn (Reservation $r) => $this->resource($r)),
        ]);
    }

    public function show(string $uuid): JsonResponse
    {
        return response()->json(['data' => $this->resource(Reservation::query()->where('uuid', $uuid)->firstOrFail())]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'party_size' => ['required', 'integer', 'min:1', 'max:60'],
            'reserved_at' => ['required', 'date', 'after:now'],
            'duration_minutes' => ['nullable', 'integer', 'min:15', 'max:600'],
            'zone' => ['nullable', 'string', Rule::exists('zones', 'slug')->whereNull('deleted_at')],
            'notes' => ['nullable', 'string', 'max:1000'],
            'source' => ['nullable', Rule::in(['web', 'phone', 'api'])],
        ]);

        $reservation = Reservation::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => $data['name'],
            'phone' => $data['phone'] ?? null,
            'party_size' => $data['party_size'],
            'reserved_at' => CarbonImmutable::parse($data['reserved_at']),
            'duration_minutes' => $data['duration_minutes'] ?? 90,
            'zone_id' => isset($data['zone']) ? Zone::query()->where('slug', $data['zone'])->value('id') : null,
            'notes' => $data['notes'] ?? null,
            'status' => 'confirmed',
            'source' => $data['source'] ?? 'api',
        ]);

        TpvChanged::notify(['reservations'], notice: 'reservation.new');

        return response()->json(['data' => $this->resource($reservation)], 201);
    }

    public function cancel(string $uuid): JsonResponse
    {
        $reservation = Reservation::query()->where('uuid', $uuid)->firstOrFail();
        $reservation->forceFill(['status' => 'cancelled'])->save();
        TpvChanged::notify(['reservations']);

        return response()->json(['data' => $this->resource($reservation)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function resource(Reservation $r): array
    {
        return [
            'uuid' => $r->uuid,
            'name' => $r->name,
            'phone' => $r->phone,
            'party_size' => $r->party_size,
            'reserved_at' => $r->reserved_at->toIso8601String(),
            'duration_minutes' => $r->duration_minutes,
            'zone' => $r->zone?->slug,
            'notes' => $r->notes,
            'status' => $r->status,
            'source' => $r->source,
        ];
    }
}
