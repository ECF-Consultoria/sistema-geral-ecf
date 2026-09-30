<?php

namespace App\Http\Controllers;

use App\Models\Setor;
use App\Models\SetorGoal;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Dashboard do líder de setor. Mostra membros + métricas + metas do setor.
 * Acesso: admin OU líder do setor específico (verificado inline).
 */
class LiderancaController extends Controller
{
    public function show(Request $request, Setor $setor)
    {
        $user = $request->user();

        if (!$user->isAdmin() && !$user->isLiderDe($setor->id)) {
            abort(403, 'Você não lidera este setor.');
        }

        $setor->load([
            'cargos:id,setor_id,nome,slug,meta_publicacoes',
            'lideres:id,name',
            'metas' => fn($q) => $q->where('active', true)->with([
                'results' => fn($r) => $r->orderBy('period_start', 'desc')->limit(3),
            ]),
        ]);

        // Linhas cruas (1 por cargo — D-01/D-10, Fase 159). Extraídas pra array
        // simples antes do agrupamento pra não depender de acesso encadeado
        // ao objeto pivot dentro do groupBy/pluck.
        $linhas = $setor->membros()
            ->get(['users.id', 'users.name', 'users.email', 'users.active'])
            ->map(fn ($u) => [
                'id'       => $u->id,
                'name'     => $u->name,
                'email'    => $u->email,
                'active'   => (bool) $u->active,
                'cargo_id' => $u->pivot->cargo_id,
            ]);

        // Colapsa por pessoa: quem tem dois cargos no mesmo setor vira UMA
        // entrada, com `cargo_nome` = nomes unidos por " · " (ordem de
        // `$setor->cargos`, já ordenada por `ordem`/`nome` na relação) e
        // `cargo_slug` = slug do primeiro cargo do grupo. A tela
        // Lideranca/Setor.jsx usa `key={m.id}` — precisa de um id único.
        $membros = $linhas->groupBy('id')->map(function ($grupo) use ($setor) {
            $primeiro = $grupo->first();

            $cargosDoGrupo = $setor->cargos->whereIn(
                'id',
                $grupo->pluck('cargo_id')->filter()->unique()
            );

            return [
                'id'         => $primeiro['id'],
                'name'       => $primeiro['name'],
                'email'      => $primeiro['email'],
                'active'     => $primeiro['active'],
                'cargo_nome' => $cargosDoGrupo->isEmpty() ? null : $cargosDoGrupo->pluck('nome')->implode(' · '),
                'cargo_slug' => $cargosDoGrupo->first()?->slug,
            ];
        })
            ->sortBy('cargo_nome')
            ->values();

        // KPIs agregados básicos
        $kpis = [
            'total_membros'  => $membros->count(),
            'membros_ativos' => $membros->where('active', true)->count(),
            'total_metas'    => $setor->metas->count(),
            'total_lideres'  => $setor->lideres->count(),
        ];

        // Outros setores que esse líder lidera, pra navegação rápida
        $outrosSetores = $user->isAdmin()
            ? Setor::where('id', '!=', $setor->id)->orderBy('nome')->get(['id', 'nome', 'slug'])
            : $user->setoresLiderados()->where('setores.id', '!=', $setor->id)->get(['setores.id', 'nome', 'slug']);

        return Inertia::render('Lideranca/Setor', [
            'setor'           => $setor,
            'membros'         => $membros,
            'kpis'            => $kpis,
            'outros_setores'  => $outrosSetores,
            'metric_labels'   => SetorGoal::$metricLabels,
            'can_definir_metas' => $user->hasPermission(\App\Support\Permissions::LIDERANCA_DEFINIR_METAS) || $user->isAdmin(),
        ]);
    }

    /**
     * Atalho: se o user é líder de apenas 1 setor, redireciona pra esse setor.
     * Se lidera múltiplos, mostra picker. Útil pro item de menu "Meu Setor".
     */
    public function indexOrFirst(Request $request)
    {
        $user = $request->user();
        $setores = $user->setoresLiderados()->orderBy('setores.nome')->get(['setores.id', 'nome', 'slug']);

        if ($setores->count() === 0) {
            abort(403, 'Você não lidera nenhum setor.');
        }

        if ($setores->count() === 1) {
            return redirect()->route('lideranca.setor', $setores->first()->slug);
        }

        return Inertia::render('Lideranca/Picker', [
            'setores' => $setores,
        ]);
    }
}
