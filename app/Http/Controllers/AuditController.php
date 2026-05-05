<?php

namespace App\Http\Controllers;

use App\Exports\AuditExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Activitylog\Models\Activity;

class AuditController extends Controller
{
    // ── Liste des logs ─────────────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $query = Activity::with('causer')->latest();

        if ($request->filled('log_name')) {
            $query->where('log_name', $request->log_name);
        }
        if ($request->filled('user_id')) {
            $query->where('causer_id', $request->user_id);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }
        if ($request->filled('search')) {
            $query->where('description', 'like', '%' . $request->search . '%');
        }

        return response()->json($query->paginate($request->get('per_page', 25)));
    }

    // ── Export Excel ───────────────────────────────────────────────────────
    public function export(Request $request)
    {
        $filters = $request->only([
            'log_name', 'date_from',
            'date_to',  'search',
        ]);

        $filename = 'journal-audit-' . now()->format('Y-m-d') . '.xlsx';

        return Excel::download(new AuditExport($filters), $filename);
    }

    // ── Statistiques ───────────────────────────────────────────────────────
    public function stats(): JsonResponse
    {
        $total    = Activity::count();
        $today    = Activity::whereDate('created_at', today())->count();
        $thisWeek = Activity::whereBetween('created_at', [
            now()->startOfWeek(), now()->endOfWeek()
        ])->count();

        $byModule = Activity::selectRaw('log_name, COUNT(*) as count')
            ->groupBy('log_name')
            ->orderByDesc('count')
            ->limit(5)
            ->get();

        $byUser = Activity::selectRaw('causer_id, COUNT(*) as count')
            ->whereNotNull('causer_id')
            ->groupBy('causer_id')
            ->orderByDesc('count')
            ->with('causer:id,name')
            ->limit(5)
            ->get();

        return response()->json([
            'total'     => $total,
            'today'     => $today,
            'this_week' => $thisWeek,
            'by_module' => $byModule,
            'by_user'   => $byUser,
        ]);
    }
}