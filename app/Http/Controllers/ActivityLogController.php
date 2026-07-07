<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Spatie\Activitylog\Models\Activity;

class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        $query = Activity::with('causer')
            ->latest();

        if ($request->filled('user_id')) {
            $query->where('causer_id', $request->user_id)
                  ->where('causer_type', \App\Models\User::class);
        }

        if ($request->filled('subject_type')) {
            $query->where('subject_type', $request->subject_type);
        }

        // Filtro subject_id (Phase 62 META-04): link "Ver log completo" do drawer aponta pra activity-log?subject_type=...&subject_id=X
        if ($request->filled('subject_id')) {
            $query->where('subject_id', $request->integer('subject_id'));
        }

        if ($request->filled('event')) {
            $query->where('event', $request->event);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                  ->orWhere('log_name', 'like', "%{$search}%");
            });
        }

        $logs = $query->paginate(50)->withQueryString();

        $logs->getCollection()->transform(function ($activity) {
            return [
                'id'           => $activity->id,
                'description'  => $activity->description,
                'event'        => $activity->event,
                'subject_type' => $this->formatSubjectType($activity->subject_type),
                'subject_id'   => $activity->subject_id,
                'causer_name'  => $activity->causer?->name ?? 'Sistema',
                'causer_id'    => $activity->causer_id,
                'properties'   => $activity->properties,
                'created_at'   => $activity->created_at->format('d/m/Y H:i:s'),
            ];
        });

        $users = \App\Models\User::orderBy('name')->get(['id', 'name']);

        $subjectTypes = Activity::select('subject_type')
            ->distinct()
            ->whereNotNull('subject_type')
            ->pluck('subject_type')
            ->map(fn($t) => [
                'value' => $t,
                'label' => $this->formatSubjectType($t),
            ]);

        return Inertia::render('ActivityLog/Index', [
            'logs'         => $logs,
            'users'        => $users,
            'subjectTypes' => $subjectTypes,
            'filters'      => $request->only(['user_id', 'subject_type', 'subject_id', 'event', 'date_from', 'date_to', 'search']),
        ]);
    }

    private function formatSubjectType(?string $type): string
    {
        if (!$type) return '—';
        return match ($type) {
            'App\\Models\\User'            => 'Usuário',
            'App\\Models\\Company'         => 'Empresa',
            'App\\Models\\Meeting'         => 'Reunião',
            'App\\Models\\Ppa'             => 'PPA',
            'App\\Models\\PpaTask'         => 'Tarefa PPA',
            'App\\Models\\Goal'            => 'Meta',
            'App\\Models\\NpsSurvey'       => 'NPS',
            'App\\Models\\Sugador'         => 'Sugador',
            'App\\Models\\Publicacao'      => 'Publicação MLB',
            'App\\Models\\MlbEmpresa'      => 'Empresa MLB',
            'App\\Models\\MlbTreinamento'  => 'Treinamento MLB',
            'App\\Models\\CompanyGrant'    => 'Grant',
            default => class_basename($type),
        };
    }
}
