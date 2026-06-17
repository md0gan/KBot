<?php

namespace App\Http\Controllers;

use App\Models\BotLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TradeController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        $query = $user->trades()->with('coin')->latest();

        if ($request->filled('coin')) {
            $query->where('coin_id', $request->integer('coin'));
        }
        if ($request->filled('kind')) {
            $query->where('kind', $request->string('kind'));
        }
        if ($request->filled('mode')) {
            $query->where('mode', $request->string('mode'));
        }

        $trades = $query->paginate(30)->withQueryString();
        $coins = $user->coins()->orderBy('base_asset')->get();

        return view('trades.index', compact('trades', 'coins'));
    }

    public function logs(Request $request): View
    {
        $logs = BotLog::where('user_id', $request->user()->id)
            ->with('coin')
            ->latest()
            ->paginate(40);

        return view('logs.index', compact('logs'));
    }
}
