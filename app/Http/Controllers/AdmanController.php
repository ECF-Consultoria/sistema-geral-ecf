<?php

namespace App\Http\Controllers;

use App\Models\AdmanMetric;

class AdmanController extends Controller
{
    // O método `syncNow` foi REMOVIDO na Phase 16 (SC-5): a API Adman é D-1
    // (publica dados 1×/dia, às ~10h BRT, com limite de 10 req/min). Sync
    // manual síncrono violava o rate limit (~168 chamadas em burst). Agora
    // o sync roda apenas via scheduler (`adman:sync` em `routes/console.php`),
    // diariamente às 11h BRT. Ver `.planning/phases/16-adequacao-cadencia-d1-adman/`.

    public function lastSync()
    {
        $last = AdmanMetric::latest('synced_at')->first();
        return response()->json([
            'synced_at' => $last?->synced_at?->format('d/m H:i') ?? 'Nunca',
        ]);
    }
}
