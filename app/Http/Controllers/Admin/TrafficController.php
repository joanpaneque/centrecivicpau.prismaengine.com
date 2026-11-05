<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Afluència: how busy each hour of each weekday is, from the orders (tables or groups)
 * opened, to see the peak hours and the quiet ones. Averages are per day the place was
 * open (a day with at least one order), so closed days do not drag them down.
 */
class TrafficController extends Controller
{
    public const PERIODS = [30, 90, 365];

    public function __invoke(Request $request): Response
    {
        $days = in_array((int) $request->query('dies'), self::PERIODS, true) ? (int) $request->query('dies') : 90;
        $from = CarbonImmutable::today()->subDays($days - 1);

        /** @var array<int, array<int, array{orders: int, guests: int}>> $cells weekday (1 = Monday) → hour */
        $cells = [];
        /** @var array<int, array<string, true>> $open weekday → dates with activity */
        $open = [];
        $total = 0;

        Order::query()
            ->where('opened_at', '>=', $from)
            ->where('status', '!=', 'cancelled')
            ->select(['id', 'opened_at', 'guests'])
            ->orderBy('id')
            ->chunk(2000, function ($orders) use (&$cells, &$open, &$total) {
                foreach ($orders as $order) {
                    /** @var Order $order */
                    $at = $order->opened_at;
                    $weekday = $at->dayOfWeekIso;
                    $hour = $at->hour;
                    $cells[$weekday][$hour] ??= ['orders' => 0, 'guests' => 0];
                    $cells[$weekday][$hour]['orders']++;
                    $cells[$weekday][$hour]['guests'] += (int) $order->guests;
                    $open[$weekday][$at->toDateString()] = true;
                    $total++;
                }
            });

        $rows = [];

        foreach ($cells as $weekday => $hours) {
            foreach ($hours as $hour => $counts) {
                $rows[] = ['weekday' => $weekday, 'hour' => $hour] + $counts;
            }
        }

        return Inertia::render('admin/Traffic', [
            'days' => $days,
            'periods' => self::PERIODS,
            'from' => $from->toDateString(),
            'total' => $total,
            'cells' => $rows,
            'openDays' => array_map(fn (int $weekday) => count($open[$weekday] ?? []), array_combine(range(1, 7), range(1, 7))),
        ]);
    }
}
