<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $setting = $user->settings();

        $coins = $user->coins()->with('position')->orderBy('base_asset')->get();

        $invested = (float) $coins->sum(fn ($c) => $c->position?->cost_basis ?? 0);
        $currentValue = (float) $coins->sum(fn ($c) => $c->position
            ? ($c->position->last_value ?? $c->position->cost_basis)
            : 0);
        $realized = (float) $coins->sum(fn ($c) => $c->position?->realized_profit ?? 0);
        $unrealized = $currentValue - $invested;

        $recentTrades = $user->trades()->with('coin')->latest()->limit(12)->get();

        return view('dashboard', compact(
            'setting',
            'coins',
            'invested',
            'currentValue',
            'unrealized',
            'realized',
            'recentTrades',
        ));
    }
}
