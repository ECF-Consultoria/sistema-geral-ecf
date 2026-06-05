<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\CompanyGrant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Inertia\Inertia;

class GrantController extends Controller
{
    public function index()
    {
        $allCompanies = Company::where('active', true)->count();

        $activeGrants = CompanyGrant::where('status', 'active')->count();
        $expiredGrants = CompanyGrant::where('status', 'expired')->count();

        $expiringSoon = CompanyGrant::with('company')
            ->expiringSoon(30)
            ->orderBy('expires_at', 'asc')
            ->get()
            ->map(fn($g) => [
                'id'             => $g->id,
                'company_id'     => $g->company_id,
                'company_name'   => $g->company->name,
                'status'         => $g->status,
                'expires_at'     => $g->expires_at?->toDateString(),
                'days_remaining' => $g->days_remaining,
                'granted_at'     => $g->granted_at?->toDateString(),
                'regranted_at'   => $g->regranted_at?->toDateString(),
            ]);

        $grants = CompanyGrant::with('company')
            ->orderByRaw("FIELD(status,'active','pending','expired')")
            ->orderBy('expires_at', 'asc')
            ->get()
            ->map(fn($g) => [
                'id'             => $g->id,
                'company_id'     => $g->company_id,
                'company_name'   => $g->company->name,
                'ml_grant_token' => $g->ml_grant_token,
                'ml_email'       => $g->ml_email,
                'ml_phone'       => $g->ml_phone,
                'ml_cust_id'     => $g->ml_cust_id,
                'segmento'       => $g->segmento,    // Phase 20 — campo vindo da API ECF Drive
                'status'         => $g->status,
                'granted_at'     => $g->granted_at?->toDateString(),
                'expires_at'     => $g->expires_at?->toDateString(),
                'regranted_at'   => $g->regranted_at?->toDateString(),
                'days_remaining' => $g->days_remaining,
                'notes'          => $g->notes,
            ]);

        // Empresas sem nenhum grant cadastrado
        $companiesWithGrant = CompanyGrant::pluck('company_id')->unique();
        $noGrant = Company::where('active', true)
            ->whereNotIn('id', $companiesWithGrant)
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('Grants/Index', [
            'stats' => [
                'total_companies'   => $allCompanies,
                'active_grants'     => $activeGrants,
                'expiring_soon'     => $expiringSoon->count(),
                'expired_grants'    => $expiredGrants,
                'no_grant'          => $noGrant->count(),
            ],
            'grants'        => $grants,
            'expiring_soon' => $expiringSoon,
            'no_grant'      => $noGrant,
            'sync_pending'  => session('sync_pending', false),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'company_id'     => 'required|exists:companies,id',
            'ml_grant_token' => 'nullable|string|max:500',
            'status'         => 'required|in:pending,active,expired',
            'granted_at'     => 'nullable|date',
            'expires_at'     => 'nullable|date',
            'notes'          => 'nullable|string',
        ]);

        CompanyGrant::create($data);

        return back()->with('success', 'Grant cadastrado com sucesso.');
    }

    public function update(Request $request, CompanyGrant $grant)
    {
        $data = $request->validate([
            'ml_grant_token' => 'nullable|string|max:500',
            'status'         => 'required|in:pending,active,expired',
            'granted_at'     => 'nullable|date',
            'expires_at'     => 'nullable|date',
            'regranted_at'   => 'nullable|date',
            'notes'          => 'nullable|string',
        ]);

        $grant->update($data);

        return back()->with('success', 'Grant atualizado.');
    }

    public function destroy(CompanyGrant $grant)
    {
        $grant->delete();
        return back()->with('success', 'Grant removido.');
    }

    public function regrant(Request $request, CompanyGrant $grant)
    {
        $data = $request->validate([
            'ml_grant_token' => 'required|string|max:500',
            'expires_at'     => 'required|date|after:today',
        ]);

        $grant->regrant($data['ml_grant_token'], \Carbon\Carbon::parse($data['expires_at']));

        return back()->with('success', 'Regrant registrado com sucesso.');
    }

    public function syncNow()
    {
        $statusFile = storage_path('app/grants_sync_status.json');
        $logFile    = storage_path('logs/grants_sync.log');

        @mkdir(storage_path('app'), 0755, true);
        @mkdir(storage_path('logs'), 0755, true);

        // Se já estiver rodando há menos de 10 minutos, apenas aguarda
        if (file_exists($statusFile)) {
            $current = json_decode(file_get_contents($statusFile), true);
            if (($current['status'] ?? '') === 'running' && (time() - ($current['started_at'] ?? 0)) < 600) {
                return back()->with('sync_pending', true);
            }
        }

        file_put_contents($statusFile, json_encode(['status' => 'running', 'started_at' => time()]));

        // Tentativa 1: exec() com nohup (desacopla do processo pai)
        if (function_exists('exec')) {
            $php     = PHP_BINARY ?: 'php';
            $artisan = escapeshellarg(base_path('artisan'));
            $log     = escapeshellarg($logFile);
            @exec("nohup $php $artisan grants:sync-ecf >> $log 2>&1 &"); // Phase 20
        }

        // Tentativa 2: curl fire-and-forget — sempre tentado (não depende do exec)
        // curl abandona após 3s mas o servidor continua com ignore_user_abort(true)
        $launched = false;
        if (function_exists('curl_init')) {
            $secret = hash('sha256', config('app.key') . '|sync-run');
            $ch = curl_init(rtrim(config('app.url'), '/') . '/internal/grants/sync/run');
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => '',
                CURLOPT_TIMEOUT        => 3,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_HTTPHEADER     => ["X-Sync-Secret: $secret"],
            ]);
            curl_exec($ch);
            curl_close($ch);
            $launched = true;
        }

        // Tentativa 3: hook de encerramento FastCGI (roda após a resposta ser enviada)
        if (!$launched) {
            app()->terminating(function () {
                if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
                set_time_limit(300);
                ignore_user_abort(true);
                Artisan::call('grants:sync-ecf'); // Phase 20
            });
        }

        return back()->with('sync_pending', true);
    }

    public function syncStatus()
    {
        $statusFile = storage_path('app/grants_sync_status.json');

        if (!file_exists($statusFile)) {
            return response()->json(['status' => 'idle']);
        }

        $data = json_decode(file_get_contents($statusFile), true) ?? ['status' => 'idle'];

        // Detecta processo preso: mais de 10 minutos em "running"
        if (($data['status'] ?? '') === 'running' && (time() - ($data['started_at'] ?? 0)) > 600) {
            $log  = storage_path('logs/grants_sync.log');
            $tail = file_exists($log) ? trim(implode('', array_slice(file($log), -5))) : '';
            $data = [
                'status'  => 'done',
                'success' => false,
                'error'   => 'A sincronização excedeu 10 minutos sem resposta. ' . ($tail ? "Último log: $tail" : 'Verifique os logs do servidor.'),
            ];
            file_put_contents($statusFile, json_encode($data));
        }

        return response()->json($data);
    }

    public function syncRun(Request $request)
    {
        $expected = hash('sha256', config('app.key') . '|sync-run');
        if ($request->header('X-Sync-Secret') !== $expected) abort(403);

        ignore_user_abort(true);
        set_time_limit(300);
        Artisan::call('grants:sync-ecf'); // Phase 20

        return response()->noContent();
    }
}
