<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Reservation;
use App\Models\SupplierInvoice;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TimeTracking\WorkedTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, WorkedTime $worked): Response|RedirectResponse
    {
        if (! $request->user()?->isAdmin()) {
            return redirect()->route('tpv');
        }

        $today = CarbonImmutable::now()->startOfDay();
        $tickets = Ticket::query()->where('issued_at', '>=', $today);

        $working = [];
        $alerts = [];

        foreach (User::query()->where('active', true)->orderBy('name')->get() as $user) {
            $state = $worked->clockState($user);

            if ($state['state'] !== 'out') {
                $working[] = ['name' => $user->name, 'state' => $state['state'], 'since' => $state['since']];
            }

            foreach ($worked->report($user, $today->subDays(6), $today->endOfDay())['alerts'] as $alert) {
                $alerts[] = ['name' => $user->name, 'userId' => $user->id] + $alert;
            }
        }

        $daily = [];

        for ($day = $today->subDays(6); $day->lte($today); $day = $day->addDay()) {
            $daily[] = [
                'date' => $day->toDateString(),
                'total' => (int) Ticket::query()->whereBetween('issued_at', [$day, $day->endOfDay()])->sum('total'),
            ];
        }

        return Inertia::render('Dashboard', [
            'stats' => [
                'salesToday' => (int) (clone $tickets)->sum('total'),
                'ticketsToday' => (clone $tickets)->count(),
                'openTables' => Order::query()->whereIn('status', Order::ACTIVE_STATUSES)->count(),
                'reservationsToday' => Reservation::query()->whereBetween('reserved_at', [$today, $today->endOfDay()])->whereIn('status', ['confirmed', 'seated'])->sum('party_size'),
                'pendingSupplierInvoices' => SupplierInvoice::query()->where(fn ($q) => $q->whereNull('supplier')->orWhereNull('amount'))->count(),
            ],
            'working' => $working,
            'alerts' => array_slice($alerts, 0, 20),
            'daily' => $daily,
        ]);
    }
}
