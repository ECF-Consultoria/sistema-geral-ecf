<?php

namespace App\Http\Controllers;

use App\Jobs\MlbColetaJob;
use App\Jobs\SyncTodasVendasAdmanJob;
use App\Models\Company;
use App\Models\MlbColeta;
use App\Models\MlbConfiguracao;
use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
use App\Models\MlbTreinamento;
use App\Models\Publicacao;
use App\Models\User;
use App\Services\AdmanService;
use App\Services\PublicadorScoreService;
use App\Services\VendasSyncService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class MlbController extends Controller
{
    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Verifica acesso ao módulo MLB. Se $permission for passada, checa também
     * a key específica (ex: 'vendas' → 'mlb.vendas') no novo sistema de setor.
     */
    private function checkPubAccess(?string $permission = null): void
    {
        $user = auth()->user();
        if ($user->isAdmin()) return;

        // "Estar no módulo MLB" = ser membro do setor Publicação OU ter qualquer mlb.* permissão
        $temAcessoMlb = $user->setores()->where('slug', 'publicacao')->exists()
            || collect(\App\Support\Permissions::all())
                ->filter(fn($k) => str_starts_with($k, 'mlb.'))
                ->some(fn($k) => $user->hasPermission($k));

        if (!$temAcessoMlb) {
            abort(403, 'Acesso restrito ao módulo de publicações MLB.');
        }

        if ($permission && !$user->hasPermission("mlb.{$permission}")) {
            abort(403, 'Permissão insuficiente para esta área.');
        }
    }

    /**
     * Verifica que o user tem cargo específico no setor Publicação.
     * Aceita slugs antigos ('gestor', 'lider', 'publicador', 'analista') que mapeiam pros novos cargos.
     */
    private function checkPubRole(array $roles): void
    {
        $user = auth()->user();
        if ($user->isAdmin()) return;

        $cargoSlugs = collect($roles)->map(fn($r) => match ($r) {
            'gestor'     => 'gestor-de-publicacao',
            'lider'      => 'lider-de-publicacao',
            'publicador' => 'publicador',
            'analista'   => 'analista',
            default      => $r,
        })->filter()->values()->all();

        $temCargo = $user->setores()
            ->where('slug', 'publicacao')
            ->whereHas('cargos', fn($q) => $q->whereIn('slug', $cargoSlugs))
            ->exists();

        if (!$temCargo) {
            abort(403, 'Permissão insuficiente.');
        }
    }

    /**
     * Helper: retorna true se o user tem o cargo `$cargoSlug` no setor Publicação.
     * Substitui o antigo `$user->publication_role === 'X'` que aparece inline em
     * vários métodos do controller.
     */
    private function userHasPubCargo($user, string $cargoSlug): bool
    {
        if ($user->isAdmin()) return false; // admin não tem cargo específico — usa flag dedicada

        // Amarra ao cargo REAL do user no pivot (user_setores.cargo_id).
        // CUIDADO: `whereHas('cargos')` consultava o CATÁLOGO de cargos do setor
        // (Setor::cargos() é hasMany e sempre contém gestor/lider/publicador),
        // então retornava true pra QUALQUER membro do setor Publicação — bug que
        // trocava os papeis (publicador virava gestor em vendas/dashboard, e
        // qualquer membro resolvia comentário/gerenciava treinamentos de outros).
        return DB::table('user_setores')
            ->join('setores', 'setores.id', '=', 'user_setores.setor_id')
            ->join('cargos', 'cargos.id', '=', 'user_setores.cargo_id')
            ->where('user_setores.user_id', $user->id)
            ->where('setores.slug', 'publicacao')
            ->where('cargos.slug', $cargoSlug)
            ->exists();
    }

    /**
     * True se o user enxerga a visão consolidada da equipe de Publicação.
     * Admin, Gestor e Líder de Publicação veem todos os publicadores;
     * publicador/analista veem apenas os próprios dados.
     */
    private function podeVerTodosPub($user): bool
    {
        return $user->isAdmin()
            || $this->userHasPubCargo($user, 'gestor-de-publicacao')
            || $this->userHasPubCargo($user, 'lider-de-publicacao');
    }

    /**
     * Extrai códigos MLB de texto livre.
     * Aceita: "MLB1234567890", só o número, separado por qualquer delimitador.
     */
    private function extractMlbs(string $text): array
    {
        preg_match_all('/\bMLB[\s\-]?(\d{7,13})\b|\b(\d{9,13})\b/i', $text, $matches);

        $found = [];
        $seen  = [];

        foreach ($matches[0] as $i => $_) {
            $code = $matches[1][$i] ?: $matches[2][$i];
            if (!$code) continue;
            $code = trim($code);
            if (strlen($code) < 9 || strlen($code) > 13) continue;
            $mlb = 'MLB' . $code;
            if (isset($seen[$mlb])) continue;
            $seen[$mlb] = true;
            $found[]    = $mlb;
        }

        return $found;
    }

    /** Conta dias úteis (seg–sex) entre duas datas, inclusive. */
    private function diasUteis(Carbon $start, Carbon $end): int
    {
        if ($start->gt($end)) return 0;
        $count   = 0;
        $current = $start->copy()->startOfDay();
        $endDay  = $end->copy()->startOfDay();
        while ($current->lte($endDay)) {
            if ($current->isWeekday()) $count++;
            $current->addDay();
        }
        return $count;
    }

    /** Calcula todos os KPIs do mês de referência para um usuário (ou toda equipe). */
    private function calcularKpis(?int $userId, Carbon $ref, int $meta): array
    {
        $primeiro = $ref->copy()->startOfMonth()->toDateString();
        $ultimo   = $ref->copy()->endOfMonth()->toDateString();
        $hoje     = Carbon::today();

        $query = Publicacao::whereBetween('data', [$primeiro, $ultimo])
            ->where('tipo', '!=', 'variacao');
        if ($userId !== null) {
            $query->where('user_id', $userId);
        }

        $feito     = $query->count();
        $vendas    = (clone $query)->where('vendido', true)->count();
        $vendasQty = (clone $query)->sum('vendas_qty');
        $faltantes = max($meta - $feito, 0);

        $primeiroC = Carbon::parse($primeiro);
        $ultimoC   = Carbon::parse($ultimo);
        $refYm     = $ref->format('Y-m');
        $hojeYm    = $hoje->format('Y-m');

        if ($refYm === $hojeYm) {
            $diasDec  = $this->diasUteis($primeiroC, $hoje);
            $diasRest = $this->diasUteis($hoje->copy()->addDay(), $ultimoC);
        } elseif ($refYm < $hojeYm) {
            $diasDec  = $this->diasUteis($primeiroC, $ultimoC);
            $diasRest = 0;
        } else {
            $diasDec  = 0;
            $diasRest = $this->diasUteis($primeiroC, $ultimoC);
        }

        $diasTotal  = max($diasDec + $diasRest, 1);
        $mediaAtual = $diasDec  > 0 ? round($feito     / $diasDec,  1) : 0.0;
        $mediaAlvo  = $diasRest > 0 ? round($faltantes / $diasRest, 1) : 0.0;
        $projecao   = (int) round($mediaAtual * $diasTotal);
        $percentual = $meta > 0 ? round($feito / $meta * 100, 1) : 0.0;
        $conversao  = $feito > 0 ? round($vendas / $feito * 100, 1) : 0.0;

        if ($feito >= $meta) {
            $status = 'Acima da meta'; $statusClasse = 'above';
        } elseif ($projecao >= $meta * 0.95) {
            $status = 'No alvo'; $statusClasse = 'ontrack';
        } else {
            $status = 'Abaixo da meta'; $statusClasse = 'below';
        }

        return [
            'meta'                  => $meta,
            'feito'                 => $feito,
            'faltantes'             => $faltantes,
            'vendas'                => $vendas,
            'vendas_qty'            => (int) $vendasQty,
            'conversao_vendas'      => $conversao,
            'media_diaria_atual'    => $mediaAtual,
            'media_diaria_alvo'     => $mediaAlvo,
            'projecao'              => $projecao,
            'percentual'            => $percentual,
            'status'                => $status,
            'status_classe'         => $statusClasse,
            'dias_uteis_decorridos' => $diasDec,
            'dias_uteis_restantes'  => $diasRest,
            'dias_uteis_total'      => $diasTotal,
        ];
    }

    /**
     * Meta de um usuário vigente no mês indicado (YYYY-MM).
     * Fallback: cargo do user no setor Publicação (cargos.meta_publicacoes), senão 220.
     */
    private function metaParaMes(int $userId, string $mes): int
    {
        $registro = DB::table('mlb_meta_historico')
            ->where('user_id', $userId)
            ->where('mes_inicio', '<=', $mes)
            ->orderByDesc('mes_inicio')
            ->value('meta');

        if ($registro !== null) return (int) $registro;

        $user = User::find($userId);
        if (!$user) return 220;

        // Pega meta_publicacoes do cargo do user no setor Publicação
        $meta = DB::table('user_setores')
            ->join('setores', 'setores.id', '=', 'user_setores.setor_id')
            ->join('cargos', 'cargos.id', '=', 'user_setores.cargo_id')
            ->where('user_setores.user_id', $userId)
            ->where('setores.slug', 'publicacao')
            ->value('cargos.meta_publicacoes');

        return (int) ($meta ?? 220);
    }

    /**
     * Meta CADASTRADA na página Metas, vigente no mês (YYYY-MM), SEM fallback.
     * Retorna null quando o publicador não tem registro em mlb_meta_historico.
     * Usada na "Meta da Equipe", que soma apenas o que está definido em Metas.
     */
    private function metaCadastrada(int $userId, string $mes): ?int
    {
        $registro = DB::table('mlb_meta_historico')
            ->where('user_id', $userId)
            ->where('mes_inicio', '<=', $mes)
            ->orderByDesc('mes_inicio')
            ->value('meta');

        return $registro !== null ? (int) $registro : null;
    }

    /**
     * Retorna a coleção de usuários que publicam (cargo "publicador" ou
     * "lider-de-publicacao" no setor Publicação).
     */
    private function publicadores(): Collection
    {
        // Filtra pelo cargo REAL do user no pivot (user_setores.cargo_id).
        // Cuidado: `whereHas('cargos')` dentro de `setores` consulta o CATÁLOGO
        // de cargos do setor (Setor::cargos() é hasMany), que sempre contém
        // 'publicador'/'lider-de-publicacao' — isso fazia a query retornar TODOS
        // os membros do setor Publicação (gestores, analistas, etc.), inflando a
        // Meta da Equipe (soma das metas individuais). Amarramos ao pivot.
        return User::query()
            ->whereExists(function ($q) {
                $q->from('user_setores')
                  ->join('setores', 'setores.id', '=', 'user_setores.setor_id')
                  ->join('cargos', 'cargos.id', '=', 'user_setores.cargo_id')
                  ->whereColumn('user_setores.user_id', 'users.id')
                  ->where('setores.slug', 'publicacao')
                  ->whereIn('cargos.slug', ['publicador', 'lider-de-publicacao']);
            })
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /** Lista de meses disponíveis (com dados), garantindo o mês atual. */
    private function mesesDisponiveis(?int $userId = null): array
    {
        // substr(data,1,7) extrai 'YYYY-MM' — portável entre MySQL (prod) e SQLite (testes).
        $query = Publicacao::selectRaw("substr(data, 1, 7) as mes")
            ->distinct()
            ->orderByDesc('mes');
        if ($userId) $query->where('user_id', $userId);
        $meses = $query->pluck('mes')->toArray();
        $atual = now()->format('Y-m');
        if (!in_array($atual, $meses)) array_unshift($meses, $atual);
        return $meses;
    }

    // =========================================================================
    // PÁGINAS
    // =========================================================================

    public function dashboard(Request $request): Response
    {
        $this->checkPubAccess('dashboard');

        $user     = $request->user();
        // Publicador/analista veem só os próprios dados; admin/gestor/líder a equipe.
        $verTodos = $this->podeVerTodosPub($user);
        $scopeId  = $verTodos ? null : $user->id;

        $mesRef = $request->get('mes', now()->format('Y-m'));
        $ref    = Carbon::createFromFormat('Y-m', $mesRef)->startOfMonth();
        $meses  = $this->mesesDisponiveis($scopeId);

        $publicadores = $verTodos
            ? $this->publicadores()
            : User::whereKey($user->id)->get(['id', 'name']);
        // Meta da Equipe = soma APENAS das metas cadastradas na página Metas
        // (mlb_meta_historico). Publicador sem registro conta 0 — não usa o
        // fallback de cargo/220 ("puxar de acordo com o que está em Metas").
        // Na visão individual usa a meta do próprio publicador (com fallback de
        // cargo, igual ao Meu Painel) para o card não ficar zerado.
        $metaGeral = $verTodos
            ? max($publicadores->sum(fn($p) => $this->metaCadastrada($p->id, $mesRef) ?? 0), 1)
            : max($this->metaParaMes($user->id, $mesRef), 1);

        $kpisGerais = $this->calcularKpis($scopeId, $ref, $metaGeral);

        $primeiro = $ref->copy()->startOfMonth()->toDateString();
        $ultimo   = $ref->copy()->endOfMonth()->toDateString();

        $ranking = $publicadores->map(function ($pub) use ($primeiro, $ultimo, $mesRef) {
            $base           = Publicacao::where('user_id', $pub->id)->whereBetween('data', [$primeiro, $ultimo])->where('tipo', '!=', 'variacao');
            $feito          = $base->count();
            $vendas         = (clone $base)->where('vendido', true)->count();
            $vendasQty      = (clone $base)->sum('vendas_qty');
            $metaIndividual = $this->metaParaMes($pub->id, $mesRef);
            return [
                'id'        => $pub->id,
                'nome'      => $pub->name,
                'feito'     => $feito,
                'vendas'    => $vendas,
                'vendas_qty'=> (int) $vendasQty,
                'meta'      => $metaIndividual,
            ];
        })->sortByDesc('feito')->values();

        $evolucaoDiaria = Publicacao::whereBetween('data', [$primeiro, $ultimo])
            ->where('tipo', '!=', 'variacao')
            ->when(!$verTodos, fn($q) => $q->where('user_id', $user->id))
            ->selectRaw('data, COUNT(*) as total')
            ->groupBy('data')
            ->orderBy('data')
            ->get()
            ->map(fn($r) => ['data' => Carbon::parse($r->data)->format('d/m'), 'total' => (int) $r->total]);

        $evolucaoMensal = Publicacao::where('tipo', '!=', 'variacao')
            ->when(!$verTodos, fn($q) => $q->where('user_id', $user->id))
            ->selectRaw("DATE_FORMAT(data, '%Y-%m') as mes, COUNT(*) as total, SUM(vendido) as vendas")
            ->groupBy('mes')
            ->orderBy('mes')
            ->limit(12)
            ->get()
            ->map(fn($r) => ['mes' => $r->mes, 'total' => (int) $r->total, 'vendas' => (int) $r->vendas]);

        // Distribuição de empresas por tipo de projeto
        // Inclui empresas com projeto canonical OU com fase ativa (compatibilidade)
        $fasesAtivasDash    = array_keys(MlbEmpresa::FASE_PARA_PROJETO);
        $projetosValidos    = ['POLOS', 'Assessoria', 'Incubadora', 'Implantação'];
        $todasEmpresas      = MlbEmpresa::where(function ($q) use ($fasesAtivasDash, $projetosValidos) {
            $q->whereIn('projeto', $projetosValidos)
              ->orWhereIn('fase', $fasesAtivasDash);
        })
        ->when(!$verTodos, fn($q) => $q->where('responsavel_id', $user->id))
        ->get();
        $totalEmpresasDash  = $todasEmpresas->count();
        $projetosGraficoDash = ['POLOS', 'Assessoria', 'Incubadora', 'Implantação'];
        $distribDash = [];
        foreach ($projetosGraficoDash as $proj) {
            $count = $todasEmpresas->filter(fn($e) => $e->projeto() === $proj)->count();
            if ($count > 0) {
                $distribDash[] = [
                    'nome'  => $proj,
                    'total' => $count,
                    'pct'   => $totalEmpresasDash > 0 ? round($count / $totalEmpresasDash * 100, 1) : 0,
                ];
            }
        }

        // Listagem (empresas com vs sem SKU)
        $listadas    = $todasEmpresas->filter(fn($e) => !in_array($e->estagio, ['Não Listado', null], true))->count();
        $naoListadas = $todasEmpresas->filter(fn($e) => in_array($e->estagio, ['Não Listado', null], true))->count();

        $porProjeto = [];
        foreach ($projetosGraficoDash as $proj) {
            $grupo    = $todasEmpresas->filter(fn($e) => $e->projeto() === $proj);
            $totalP   = $grupo->count();
            $listadasP = $grupo->filter(fn($e) => !in_array($e->estagio, ['Não Listado', null], true))->count();
            if ($totalP > 0) {
                $porProjeto[] = [
                    'nome'         => $proj,
                    'total'        => $totalP,
                    'listadas'     => $listadasP,
                    'nao_listadas' => $totalP - $listadasP,
                    'pct'          => round($listadasP / $totalP * 100),
                ];
            }
        }

        $semSkuEmpresas = $todasEmpresas
            ->filter(fn($e) => in_array($e->estagio, ['Não Listado', null], true))
            ->sortBy(fn($e) => $e->projeto() ?? 'z')
            ->map(fn($e) => ['nome' => $e->nome, 'projeto' => $e->projeto()])
            ->values()
            ->toArray();

        $listagem = [
            'total'        => $totalEmpresasDash,
            'listadas'     => $listadas,
            'nao_listadas' => $naoListadas,
            'por_projeto'  => $porProjeto,
            'sem_sku'      => $semSkuEmpresas,
        ];

        // Alertas de problemas — conta TODAS as empresas com problema, independente da fase
        $empresasProblema = MlbEmpresa::where('problema', true)
            ->when(!$verTodos, fn($q) => $q->where('responsavel_id', $user->id))
            ->orderBy('nome')
            ->get(['nome', 'problema_nota'])
            ->map(fn($e) => ['nome' => $e->nome, 'nota' => $e->problema_nota])
            ->values()
            ->toArray();

        $anunciosProblema = Publicacao::where('problema', true)
            ->when(!$verTodos, fn($q) => $q->where('user_id', $user->id))
            ->orderBy('empresa')
            ->get(['empresa', 'mlb_code', 'problema_nota'])
            ->map(fn($p) => [
                'empresa'  => $p->empresa,
                'mlb_code' => $p->mlb_code,
                'nota'     => $p->problema_nota,
            ])
            ->values()
            ->toArray();

        $alertas = [
            'empresas'       => count($empresasProblema),
            'anuncios'       => count($anunciosProblema),
            'empresas_lista' => $empresasProblema,
            'anuncios_lista' => $anunciosProblema,
        ];

        // Distribuição por estágio — agrupa pelo COALESCE para unir NULLs com 'Não Listado'
        $totalTodasEmpresas = MlbEmpresa::when(!$verTodos, fn($q) => $q->where('responsavel_id', $user->id))->count();

        $empresasPorEstagio = DB::table('mlb_empresas')
            ->when(!$verTodos, fn($q) => $q->where('responsavel_id', $user->id))
            ->select('nome', DB::raw("COALESCE(estagio, 'Não Listado') as estagio"))
            ->orderBy('nome')
            ->get()
            ->groupBy('estagio');

        $estagiosDistrib = DB::table('mlb_empresas')
            ->when(!$verTodos, fn($q) => $q->where('responsavel_id', $user->id))
            ->selectRaw("COALESCE(estagio, 'Não Listado') as estagio, COUNT(*) as total")
            ->groupByRaw("COALESCE(estagio, 'Não Listado')")
            ->orderByDesc('total')
            ->get()
            ->map(fn($r) => [
                'estagio'  => $r->estagio,
                'total'    => (int) $r->total,
                'pct'      => $totalTodasEmpresas > 0 ? round($r->total / $totalTodasEmpresas * 100, 1) : 0,
                'empresas' => ($empresasPorEstagio[$r->estagio] ?? collect())->pluck('nome')->values()->toArray(),
            ])
            ->toArray();

        // ── Relatório de Atrasos ────────────────────────────────────────────
        $hoje = now()->startOfDay();
        $totalConclAtr = 0;
        $totalPendAtr  = 0;
        $porEmpresaAtr = [];

        MlbEmpresa::when(!$verTodos, fn($q) => $q->where('responsavel_id', $user->id))
            ->get([
            'id','nome','responsavel_id',
            'skus_estagio1','skus_estagio2','skus_estagio3',
            'prazo_estagio1','prazo_estagio2','prazo_estagio3',
        ])->each(function ($e) use ($hoje, &$totalConclAtr, &$totalPendAtr, &$porEmpresaAtr) {
            $concAtr = 0; $pendAtr = 0;
            for ($stage = 1; $stage <= 3; $stage++) {
                $skus        = $e->{"skus_estagio{$stage}"} ?? [];
                $prazoRaw    = $e->{"prazo_estagio{$stage}"};
                $prazoVenc   = $prazoRaw && $hoje->gt(Carbon::parse($prazoRaw)->startOfDay());
                foreach ($skus as $sku) {
                    if (trim($sku['sku'] ?? '') === '') continue;
                    if ($sku['ok'] ?? false) {
                        if ($sku['atrasado'] ?? false) { $concAtr++; $totalConclAtr++; }
                    } elseif ($prazoVenc) {
                        $pendAtr++; $totalPendAtr++;
                    }
                }
            }
            if ($concAtr + $pendAtr > 0) {
                $porEmpresaAtr[] = [
                    'nome'     => $e->nome,
                    'concluidos' => $concAtr,
                    'pendentes'  => $pendAtr,
                    'total'      => $concAtr + $pendAtr,
                ];
            }
        });

        usort($porEmpresaAtr, fn($a, $b) => $b['total'] - $a['total']);
        $relatorioAtrasos = [
            'concluidos_atrasados' => $totalConclAtr,
            'pendentes_atrasados'  => $totalPendAtr,
            'por_empresa'          => array_slice($porEmpresaAtr, 0, 10),
        ];

        // Ticket médio por publicador no mês selecionado
        $ticketRows = Publicacao::whereNotNull('net_billing')
            ->where('vendido', true)->where('vendas_qty', '>', 0)
            ->whereBetween('data', [$primeiro, $ultimo])
            ->when(!$verTodos, fn($q) => $q->where('user_id', $user->id))
            ->selectRaw('user_id, SUM(net_billing) as bill, SUM(vendas_qty) as qty')
            ->groupBy('user_id')->get();

        $pubMap = $publicadores->keyBy('id');
        $ticketPorPub = $ticketRows->map(fn($r) => [
            'nome'   => $pubMap[$r->user_id]?->name ?? "User #{$r->user_id}",
            'ticket' => $r->qty > 0 ? round($r->bill / $r->qty, 2) : 0,
            'bill'   => round($r->bill, 2),
            'qty'    => (int) $r->qty,
        ])->sortByDesc('ticket')->values();

        $totalBill   = $ticketRows->sum('bill');
        $totalQty    = $ticketRows->sum('qty');
        $ticketGeral = $totalQty > 0 ? round($totalBill / $totalQty, 2) : 0;

        return Inertia::render('Mlb/Dashboard', [
            'kpisGerais'         => $kpisGerais,
            'ranking'            => $ranking,
            'evolucaoDiaria'     => $evolucaoDiaria,
            'evolucaoMensal'     => $evolucaoMensal,
            'publicadores'       => $publicadores->map(fn($p) => ['id' => $p->id, 'nome' => $p->name]),
            'metaGeral'          => $metaGeral,
            'mesRef'             => $mesRef,
            'meses'              => $meses,
            'distribuicao'       => $distribDash,
            'totalEmpresas'      => $totalEmpresasDash,
            'listagem'           => $listagem,
            'alertas'            => $alertas,
            'estagiosDistrib'    => $estagiosDistrib,
            'totalTodasEmpresas' => $totalTodasEmpresas,
            'ticketPorPub'       => $ticketPorPub,
            'ticketGeral'        => $ticketGeral,
            'relatorioAtrasos'   => $relatorioAtrasos,
            'isGeral'            => $verTodos,
        ]);
    }

    public function meuPainel(Request $request): Response
    {
        $this->checkPubAccess('meu_painel');

        $user   = $request->user();
        $mesRef = $request->get('mes', now()->format('Y-m'));
        // Valida o formato YYYY-MM (entrada do usuário) antes de Carbon::createFromFormat — evita exceção.
        if (!preg_match('/^\d{4}-\d{2}$/', $mesRef)) {
            $mesRef = now()->format('Y-m');
        }
        $meta   = $this->metaParaMes($user->id, $mesRef);
        $ref    = Carbon::createFromFormat('Y-m', $mesRef)->startOfMonth();
        $meses  = $this->mesesDisponiveis($user->id);
        $kpis   = $this->calcularKpis($user->id, $ref, $meta);

        // Fase 38 — score 0-100 + 5 eixos do publicador (Painel do Publicador).
        // feito/vendas/meta vêm de calcularKpis()/metaParaMes() — o Service não recalcula.
        $scoreData = (new PublicadorScoreService())->compute(
            $user->id, $mesRef, (int) $kpis['feito'], (int) $kpis['vendas'], (int) $meta
        );

        $primeiro = $ref->copy()->startOfMonth()->toDateString();
        $ultimo   = $ref->copy()->endOfMonth()->toDateString();

        $evolucaoDiaria = Publicacao::where('user_id', $user->id)
            ->whereBetween('data', [$primeiro, $ultimo])
            ->where('tipo', '!=', 'variacao')
            ->selectRaw('data, COUNT(*) as total')
            ->groupBy('data')
            ->orderBy('data')
            ->get()
            ->map(fn($r) => ['data' => Carbon::parse($r->data)->format('d/m'), 'total' => (int) $r->total]);

        $topEmpresas = Publicacao::where('user_id', $user->id)
            ->whereBetween('data', [$primeiro, $ultimo])
            ->where('tipo', '!=', 'variacao')
            ->selectRaw('empresa, COUNT(*) as total')
            ->groupBy('empresa')
            ->orderByDesc('total')
            ->limit(5)
            ->get();

        $feedbacks = Publicacao::where('user_id', $user->id)
            ->whereNotNull('comentario')
            ->where('comentario', '!=', '')
            ->where('comentario_resolvido', false) // só pendentes
            ->with('comentarioAutor:id,name')
            ->orderByDesc('comentario_em')
            ->limit(10)
            ->get(['id', 'data', 'empresa', 'mlb_code', 'comentario', 'comentario_autor_id', 'comentario_em'])
            ->map(fn($p) => [
                'id'              => $p->id,
                'data'            => $p->data->format('d/m/Y'),
                'empresa'         => $p->empresa,
                'mlb_code'        => $p->mlb_code,
                'comentario'      => $p->comentario,
                'comentario_autor' => $p->comentarioAutor?->name,
                'comentario_em'   => $p->comentario_em?->format('d/m/Y'),
            ]);

        // Problemas do usuário
        $empresasProblema = MlbEmpresa::where('responsavel_id', $user->id)
            ->where('problema', true)
            ->orderByDesc('problema_em')
            ->get(['id', 'nome', 'fase', 'projeto', 'prioridade', 'problema_nota', 'problema_em'])
            ->map(fn($e) => [
                'id'        => $e->id,
                'nome'      => $e->nome,
                'projeto'   => $e->projeto(),
                'prioridade'=> $e->prioridade,
                'nota'      => $e->problema_nota,
                'em'        => $e->problema_em?->format('d/m/Y H:i'),
            ]);

        $anunciosProblema = Publicacao::where('user_id', $user->id)
            ->where('problema', true)
            ->orderByDesc('problema_em')
            ->get(['id', 'empresa', 'mlb_code', 'data', 'problema_nota', 'problema_em'])
            ->map(fn($p) => [
                'id'       => $p->id,
                'empresa'  => $p->empresa,
                'mlb_code' => $p->mlb_code,
                'nota'     => $p->problema_nota,
                'em'       => $p->problema_em?->format('d/m/Y'),
                'data_pub' => $p->data?->format('d/m/Y'),
            ]);

        // Ticket médio: evolução mensal + valor do mês atual
        $ticketEvolucao = Publicacao::where('user_id', $user->id)
            ->whereNotNull('net_billing')->where('vendido', true)->where('vendas_qty', '>', 0)
            ->selectRaw("substr(data, 1, 7) as mes, SUM(net_billing) as bill, SUM(vendas_qty) as qty")
            ->groupBy('mes')->orderBy('mes')->limit(12)->get()
            ->map(fn($r) => [
                'mes'    => $r->mes,
                'ticket' => $r->qty > 0 ? round($r->bill / $r->qty, 2) : 0,
                'bill'   => round($r->bill, 2),
                'qty'    => (int) $r->qty,
            ]);

        $ticketMes = Publicacao::where('user_id', $user->id)
            ->whereNotNull('net_billing')->where('vendido', true)->where('vendas_qty', '>', 0)
            ->whereBetween('data', [$primeiro, $ultimo])
            ->selectRaw('SUM(net_billing) as bill, SUM(vendas_qty) as qty')->first();
        $ticketAtual = ($ticketMes && $ticketMes->qty > 0) ? round($ticketMes->bill / $ticketMes->qty, 2) : 0;

        // Fase 38 — faturamento do mês (SUM net_billing, protegido contra null).
        $faturamentoMes = (float) (Publicacao::where('user_id', $user->id)
            ->whereBetween('data', [$primeiro, $ultimo])
            ->where('tipo', '!=', 'variacao')
            ->sum('net_billing') ?? 0);

        // Fase 38 — evolução acumulada do faturamento por dia (net_billing).
        $acumulado = 0.0;
        $netBillingTimeseries = Publicacao::where('user_id', $user->id)
            ->whereBetween('data', [$primeiro, $ultimo])
            ->where('tipo', '!=', 'variacao')
            ->whereNotNull('net_billing')
            ->selectRaw('data, SUM(net_billing) as billing_dia')
            ->groupBy('data')
            ->orderBy('data')
            ->get()
            ->map(function ($r) use (&$acumulado) {
                $acumulado += (float) $r->billing_dia;
                return ['date' => Carbon::parse($r->data)->format('Y-m-d'), 'realizado' => round($acumulado, 2)];
            });

        return Inertia::render('Mlb/MeuPainel', [
            'kpis'            => $kpis,
            'evolucaoDiaria'  => $evolucaoDiaria,
            'topEmpresas'     => $topEmpresas,
            'feedbacks'       => $feedbacks,
            'meta'            => $meta,
            'mesRef'          => $mesRef,
            'meses'           => $meses,
            'problemas'       => [
                'empresas' => $empresasProblema,
                'anuncios' => $anunciosProblema,
            ],
            'ticketEvolucao'  => $ticketEvolucao,
            'ticketAtual'     => $ticketAtual,
            // Fase 38 — Painel do Publicador (score + radar + KPIs + evolução do faturamento)
            'score_publicador'       => $scoreData,
            'faturamento_mes'        => $faturamentoMes,
            'anuncios_feitos'        => $kpis['feito'],
            'vendas_mes'             => $kpis['vendas'],
            'net_billing_timeseries' => $netBillingTimeseries,
        ]);
    }

    // =========================================================================
    // VENDAS — relatório visual + sync do publicador
    // =========================================================================

    public function vendas(Request $request): Response
    {
        $this->checkPubAccess('vendas');

        $user     = $request->user();
        // Admin/Gestor/Líder veem a equipe inteira; publicador/analista só o próprio.
        $verTodos = $this->podeVerTodosPub($user);

        $mesRef = $request->get('mes', now()->format('Y-m'));
        $ref    = Carbon::createFromFormat('Y-m', $mesRef)->startOfMonth();
        $meses  = $this->mesesDisponiveis($verTodos ? null : $user->id);

        $primeiro = $ref->copy()->startOfMonth()->toDateString();
        $ultimo   = $ref->copy()->endOfMonth()->toDateString();

        // ── Filtro por publicador (só no modo geral admin/gestor) ──────────────
        // Sem filtro → consolidado de todos; com filtro → visão individual.
        $publicadoresCol = $verTodos ? $this->publicadores() : collect();
        $pubFiltro = null;
        if ($verTodos) {
            $candidato = $request->integer('pub') ?: null;
            if ($candidato && $publicadoresCol->contains('id', $candidato)) {
                $pubFiltro = $candidato;
            }
        }

        $pubsQuery = Publicacao::whereBetween('data', [$primeiro, $ultimo]);
        if (!$verTodos) {
            $pubsQuery->where('user_id', $user->id);
        } elseif ($pubFiltro) {
            $pubsQuery->where('user_id', $pubFiltro);
        }
        // net_billing/preco_unitario precisam estar no select — sem eles a soma de
        // faturamento (e o ticket médio derivado) retornava 0 e os cards mostravam "—".
        $pubs = $pubsQuery->get(['id', 'user_id', 'cust_id', 'mlb_code', 'empresa', 'data', 'vendido', 'vendas_qty', 'tipo', 'preco_unitario', 'net_billing']);

        // Stats contam apenas anúncios (excluem variações)
        $pubsAnuncios  = $pubs->where('tipo', '!=', 'variacao');
        $total         = $pubsAnuncios->count();
        $vendidos      = $pubsAnuncios->where('vendido', true)->count();
        $nVendidos     = $total - $vendidos;
        $unidades      = (int) $pubsAnuncios->sum('vendas_qty');
        $conversao     = $total > 0 ? round($vendidos / $total * 100, 1) : 0;
        $faturamento   = round((float) $pubsAnuncios->whereNotNull('net_billing')->sum('net_billing'), 2);

        // Por empresa: converte para array simples (não collection)
        $porEmpresa = $pubsAnuncios->groupBy('empresa')
            ->map(fn($g, $nome) => [
                'nome'       => $nome,
                'total'      => $g->count(),
                'vendidos'   => $g->where('vendido', true)->count(),
                'nVendidos'  => $g->where('vendido', false)->count(),
                'unidades'   => (int) $g->sum('vendas_qty'),
            ])
            ->sortByDesc('unidades')
            ->values()
            ->toArray();

        // Top MLBs por unidades
        $topMlbs = $pubs->where('vendas_qty', '>', 0)
            ->sortByDesc('vendas_qty')
            ->take(15)
            ->map(fn($p) => [
                'mlb_code' => $p->mlb_code,
                'empresa'  => $p->empresa,
                'unidades' => (int) $p->vendas_qty,
            ])
            ->values()
            ->toArray();

        // Lista completa para tabela
        $lista = $pubs->sortByDesc('vendas_qty')
            ->map(fn($p) => [
                'id'             => $p->id,
                'mlb_code'       => $p->mlb_code,
                'empresa'        => $p->empresa,
                'data'           => Carbon::parse($p->data)->format('d/m/Y'),
                'vendido'        => $p->vendido,
                'vendas_qty'     => (int) $p->vendas_qty,
                'preco_unitario' => $p->preco_unitario ? (float) $p->preco_unitario : null,
                'net_billing'    => $p->net_billing    ? (float) $p->net_billing    : null,
            ])
            ->values()
            ->toArray();

        // ── Lojas com/sem venda ─────────────────────────────────────────────
        $lojasQuery = MlbEmpresa::whereNotNull('cust_id')->where('cust_id', '!=', '');
        if (!$verTodos) {
            $lojasQuery->where('responsavel_id', $user->id);
        } elseif ($pubFiltro) {
            $lojasQuery->where('responsavel_id', $pubFiltro);
        }
        $todasLojas = $lojasQuery->get(['id', 'nome', 'cust_id', 'responsavel_id']);

        $vendasPorCust = Publicacao::whereIn('cust_id', $todasLojas->pluck('cust_id'))
            ->where('vendido', true)
            ->where('tipo', '!=', 'variacao')
            ->whereBetween('data', [$primeiro, $ultimo])
            ->selectRaw('cust_id, SUM(vendas_qty) as qty, SUM(COALESCE(net_billing,0)) as billing, COUNT(*) as mlbs')
            ->groupBy('cust_id')
            ->get()
            ->keyBy('cust_id');

        $lojasVenderam = $todasLojas
            ->filter(fn($l) => $vendasPorCust->has($l->cust_id))
            ->map(function ($l) use ($vendasPorCust) {
                $v = $vendasPorCust[$l->cust_id];
                return [
                    'nome'    => $l->nome,
                    'cust_id' => $l->cust_id,
                    'qty'     => (int) $v->qty,
                    'billing' => round((float) $v->billing, 2),
                    'ticket'  => $v->qty > 0 ? round((float) $v->billing / $v->qty, 2) : 0,
                    'mlbs'    => (int) $v->mlbs,
                ];
            })
            ->sortByDesc('billing')
            ->values();

        $lojasNaoVenderam = $todasLojas
            ->filter(fn($l) => !$vendasPorCust->has($l->cust_id))
            ->map(fn($l) => ['nome' => $l->nome, 'cust_id' => $l->cust_id])
            ->sortBy('nome')
            ->values();

        // Ticket médio geral do período
        $ticketMedioGeral = $unidades > 0 && $faturamento > 0
            ? round($faturamento / $unidades, 2) : 0;

        // Sync só faz sentido para o próprio publicador; admin/gestor em modo geral não sincronizam
        $empresasSync = $verTodos ? [] : MlbEmpresa::where('responsavel_id', $user->id)
            ->whereNotNull('cust_id')
            ->where('cust_id', '!=', '')
            ->pluck('nome', 'id')
            ->toArray();

        // ── Ticket Médio ────────────────────────────────────────────────────
        // Visão "geral" (ranking entre publicadores) só quando sem filtro;
        // com publicador selecionado, mostra a evolução individual dele.
        $ticketUserId = $verTodos ? $pubFiltro : $user->id;
        if ($verTodos && !$pubFiltro) {
            // Admin/Gestor: ticket por publicador no mês selecionado
            $ticketPorPub = Publicacao::whereNotNull('net_billing')
                ->where('vendido', true)->where('vendas_qty', '>', 0)
                ->whereBetween('data', [$primeiro, $ultimo])
                ->selectRaw('user_id, SUM(net_billing) as bill, SUM(vendas_qty) as qty')
                ->groupBy('user_id')
                ->get()
                ->map(function ($r) {
                    $nome = \App\Models\User::find($r->user_id)?->name ?? "User #{$r->user_id}";
                    return [
                        'nome'   => $nome,
                        'ticket' => $r->qty > 0 ? round($r->bill / $r->qty, 2) : 0,
                        'bill'   => round($r->bill, 2),
                        'qty'    => (int) $r->qty,
                    ];
                })
                ->sortByDesc('ticket')->values();

            $totalBill = $ticketPorPub->sum('bill');
            $totalQty  = $ticketPorPub->sum('qty');
            $ticketGeral = $totalQty > 0 ? round($totalBill / $totalQty, 2) : 0;

            $ticketData = [
                'tipo'       => 'geral',
                'porPub'     => $ticketPorPub,
                'ticketGeral'=> $ticketGeral,
                'evolucao'   => [],
            ];
        } else {
            // Publicador (ou publicador filtrado): evolução mensal do ticket nos últimos 12 meses
            $evolucao = Publicacao::where('user_id', $ticketUserId)
                ->whereNotNull('net_billing')
                ->where('vendido', true)->where('vendas_qty', '>', 0)
                ->selectRaw("DATE_FORMAT(data, '%Y-%m') as mes, SUM(net_billing) as bill, SUM(vendas_qty) as qty")
                ->groupBy('mes')
                ->orderBy('mes')
                ->limit(12)
                ->get()
                ->map(fn($r) => [
                    'mes'    => $r->mes,
                    'ticket' => $r->qty > 0 ? round($r->bill / $r->qty, 2) : 0,
                    'bill'   => round($r->bill, 2),
                    'qty'    => (int) $r->qty,
                ]);

            $ticketData = [
                'tipo'       => 'individual',
                'porPub'     => [],
                'ticketGeral'=> $faturamento > 0 && $unidades > 0 ? round($faturamento / $unidades, 2) : 0,
                'evolucao'   => $evolucao,
            ];
        }

        return Inertia::render('Mlb/Vendas', [
            'kpis'          => compact('total', 'vendidos', 'nVendidos', 'unidades', 'conversao', 'faturamento', 'ticketMedioGeral'),
            'lojasVenderam' => $lojasVenderam,
            'lojasNaoVenderam' => $lojasNaoVenderam,
            'porEmpresa'  => $porEmpresa,
            'topMlbs'     => $topMlbs,
            'lista'       => $lista,
            'mesRef'      => $mesRef,
            'meses'       => $meses,
            'isGeral'     => $verTodos,
            'empresasSync'=> $empresasSync,
            'ticketData'  => $ticketData,
            'publicadores'=> $publicadoresCol->map(fn($p) => ['id' => $p->id, 'nome' => $p->name])->values(),
            'pubFiltro'   => $pubFiltro,
        ]);
    }

    /**
     * Sync total das publicações do publicador:
     * varre todas as empresas com cust_id atribuídas a ele e atualiza vendido/vendas_qty.
     */
    public function syncVendasPublicador(Request $request)
    {
        $this->checkPubAccess('vendas');

        set_time_limit(0);

        $request->validate([
            'date_from' => 'required|date|before_or_equal:today',
            'date_to'   => 'required|date|before_or_equal:today|after_or_equal:date_from',
        ]);

        $user = $request->user();

        $empresas = MlbEmpresa::where('responsavel_id', $user->id)
            ->whereNotNull('cust_id')
            ->where('cust_id', '!=', '')
            ->get();

        if ($empresas->isEmpty()) {
            return back()->with('error', 'Nenhuma empresa com Cust ID atribuída a você.');
        }

        $vendasSync = new VendasSyncService();
        $totais     = ['itens' => 0, 'com_venda' => 0, 'encontradas' => 0, 'erros' => 0];

        // Phase 18.5 — DECISAO LEAN: este e modulo MLB-only por desenho. O
        // foco do sistema sao empresas no Mercado Livre (135/169) e o catalogo
        // /mlb-empresas so opera sobre empresas Meli. As 34 contas Shopee/
        // Amazon nao chegam neste fluxo. Mantemos default 'meli' implicito no
        // fetchPerformance — refatorar para passar $empresa->company->marketplace
        // exigiria carregar a relacao em todos os call-sites sem ganho funcional.
        foreach ($empresas as $empresa) {
            try {
                // Escopo por user_id: o publicador só sincroniza as próprias publicações.
                $r = $vendasSync->syncEmpresa($empresa->cust_id, $request->date_from, $request->date_to, $user->id);
                $totais['itens']       += $r['itens'];
                $totais['com_venda']   += $r['com_venda'];
                $totais['encontradas'] += $r['atualizadas'];

                Log::info("[MLB SyncPub] {$empresa->nome} | itens={$r['itens']} | com_venda={$r['com_venda']}");
                // Throttle conforme AdmanService::ADMAN_RATE_LIMIT_RPM = 10 (60s/10 = 6s teorico, 7s com folga).
                // Phase 18 W4-T2: 600ms (100 rpm) violava o throttle global e contribuiu para os 741 erros 429
                // medidos na auditoria 30d. Alinhado com RefreshGrossBillingCacheJob e SyncTodasVendasAdmanJob.
                usleep(7_000_000);

            } catch (\Throwable $e) {
                Log::error("[MLB SyncPub] {$empresa->nome}: " . $e->getMessage());
                $totais['erros']++;
            }
        }

        $msg = "Sync concluído: {$totais['com_venda']} anúncio(s) com venda em {$totais['itens']} item(ns). {$totais['encontradas']} publicação(ões) sincronizadas.";
        if ($totais['erros'] > 0) $msg .= " ({$totais['erros']} erro(s) — veja o log.)";

        return back()->with('success', $msg);
    }

    public function publicacoes(Request $request): Response
    {
        $this->checkPubAccess('publicacoes');

        $user    = $request->user();
        $isAdmin = $user->isAdmin();
        $meta    = $this->metaParaMes($user->id, now()->format('Y-m'));
        $ref     = Carbon::now()->startOfMonth();
        $kpis    = $this->calcularKpis($user->id, $ref, $meta);
        $hoje    = Publicacao::where('user_id', $user->id)->where('data', now()->toDateString())->where('tipo', '!=', 'variacao')->count();

        // Admin vê todas as empresas de todos os responsáveis; publicador vê apenas as suas
        $empQuery = MlbEmpresa::with('responsavel:id,name', 'implementacao:id,empresa_id,token')
            ->orderByRaw("CASE WHEN estagio = 'Concluido' THEN 1 ELSE 0 END ASC")
            ->orderBy('nome');
        if (!$isAdmin) {
            $empQuery->where('responsavel_id', $user->id);
        }

        $empresas = $empQuery->get()->map(function ($e) {
            $mlbsCount = Publicacao::where('mlb_empresa_id', $e->id)->count();
            return [
                'id'             => $e->id,
                'nome'           => $e->nome,
                'cust_id'        => $e->cust_id,
                'estagio'        => $e->estagio,
                'fase'           => $e->fase,
                'projeto'        => $e->getAttributes()['projeto'] ?? null,
                'prioridade'     => $e->prioridade,
                'contexto'       => $e->contexto,
                'responsavel_id' => $e->responsavel_id,
                'responsavel'    => $e->responsavel?->name,
                'skus_estagio1'   => $e->skus_estagio1 ?? [],
                'skus_estagio2'   => $e->skus_estagio2 ?? [],
                'skus_estagio3'   => $e->skus_estagio3 ?? [],
                'prazo_estagio1'  => $e->prazo_estagio1?->format('Y-m-d'),
                'prazo_estagio2'  => $e->prazo_estagio2?->format('Y-m-d'),
                'prazo_estagio3'  => $e->prazo_estagio3?->format('Y-m-d'),
                'progresso'       => $e->progresso(),
                'mlbs_count'      => $mlbsCount,
                'problema'        => $e->problema,
                'problema_nota'   => $e->problema_nota,
                'problema_em'     => $e->problema_em?->format('d/m/Y'),
                'tipo'                => $e->tipo ?? 'POLO',
                'gmail'               => $e->gmail,
                'implementacao_token' => $e->implementacao?->token,
            ];
        });

        // Opções de filtro — admin vê de todas as empresas, publicador só das suas
        $optQuery = fn(string $col) => MlbEmpresa::when(!$isAdmin, fn($q) => $q->where('responsavel_id', $user->id))
            ->whereNotNull($col)->where($col, '!=', '')->distinct()->pluck($col)->sort()->values();

        $ultimos = Publicacao::where('user_id', $user->id)
            ->with('comentarioAutor:id,name')
            ->orderByDesc('data')
            ->orderByDesc('id')
            ->limit(30)
            ->get()
            ->map(fn($p) => [
                'id'                   => $p->id,
                'data'                 => $p->data->format('d/m/Y'),
                'empresa'              => $p->empresa,
                'cust_id'              => $p->cust_id,
                'mlb_code'             => $p->mlb_code,
                'mlb_empresa_id'       => $p->mlb_empresa_id,
                'tipo'                 => $p->tipo ?? 'anuncio',
                'sku_stage'            => $p->sku_stage,
                'sku_position'         => $p->sku_position,
                'vendido'              => $p->vendido,
                'revisado'             => $p->revisado,
                'problema'             => $p->problema,
                'problema_nota'        => $p->problema_nota,
                'problema_em'          => $p->problema_em?->format('d/m/Y'),
                'comentario'           => $p->comentario,
                'comentario_autor'     => $p->comentarioAutor?->name,
                'comentario_em'        => $p->comentario_em?->format('d/m/Y'),
                'comentario_resolvido' => $p->comentario_resolvido,
            ]);

        return Inertia::render('Mlb/Publicacoes', [
            'kpis'        => $kpis,
            'hoje'        => $hoje,
            'ultimos'     => $ultimos,
            'meta'        => $meta,
            'empresas'    => $empresas,
            'isAdmin'     => $isAdmin,
            'estagiosOpts' => $optQuery('estagio'),
            'fasesOpts'    => $optQuery('fase'),
            'projetosOpts' => $optQuery('projeto'),
        ]);
    }

    public function historico(Request $request): Response
    {
        $this->checkPubAccess('historico');

        $user  = $request->user();
        $isPub = $this->userHasPubCargo($user, 'publicador');

        $mes      = $request->get('mes', '');
        $func     = $request->get('func', '');
        $empresa  = $request->get('empresa', '');
        $busca    = $request->get('busca', '');
        $problema = $request->get('problema', '');

        $query = Publicacao::with('user:id,name', 'comentarioAutor:id,name')
            ->orderByDesc('data')
            ->orderByDesc('id');

        if ($isPub) $query->where('user_id', $user->id);
        if ($mes)   $query->whereRaw("DATE_FORMAT(data, '%Y-%m') = ?", [$mes]);
        if ($func && !$isPub) $query->where('user_id', $func);
        if ($empresa) $query->where('empresa', $empresa);
        if ($busca) {
            $query->where(fn($q) => $q
                ->where('mlb_code', 'like', "%{$busca}%")
                ->orWhere('empresa',  'like', "%{$busca}%")
                ->orWhere('cust_id',  'like', "%{$busca}%")
            );
        }
        if ($problema === '1') $query->where('problema', true);

        // Totais antes da paginação
        $totaisQ = clone $query;
        $totais  = [
            'total'      => $totaisQ->count(),
            'vendas'     => (clone $totaisQ)->where('vendido', true)->count(),
            'vendas_qty' => (clone $totaisQ)->sum('vendas_qty'),
            'empresas'   => (clone $totaisQ)->distinct('empresa')->count('empresa'),
        ];

        $publicacoes = $query->paginate(50)->through(fn($p) => [
            'id'                   => $p->id,
            'data'                 => $p->data->format('d/m/Y'),
            'usuario'              => $p->user?->name ?? '—',
            'usuario_removido'     => $p->user?->deleted_at !== null,
            'empresa'              => $p->empresa,
            'cust_id'              => $p->cust_id,
            'mlb_code'             => $p->mlb_code,
            'tipo'                 => $p->tipo ?? 'anuncio',
            'vendido'              => $p->vendido,
            'vendas_qty'           => $p->vendas_qty,
            'revisado'             => $p->revisado,
            'problema'             => $p->problema,
            'problema_nota'        => $p->problema_nota,
            'comentario'           => $p->comentario,
            'comentario_autor'     => $p->comentarioAutor?->name,
            'comentario_em'        => $p->comentario_em?->format('d/m/Y'),
            'comentario_resolvido' => $p->comentario_resolvido,
        ]);

        $empresasQ = Publicacao::select('empresa')->distinct()->orderBy('empresa');
        if ($isPub) $empresasQ->where('user_id', $user->id);
        if ($func && !$isPub) $empresasQ->where('user_id', $func);
        $empresas = $empresasQ->pluck('empresa')->toArray();

        $publicadoresLista = $isPub ? [] : $this->publicadores()
            ->map(fn($p) => ['id' => $p->id, 'nome' => $p->name])
            ->toArray();

        return Inertia::render('Mlb/Historico', [
            'publicacoes' => $publicacoes,
            'meses'       => $this->mesesDisponiveis($isPub ? $user->id : null),
            'empresas'    => $empresas,
            'publicadores' => $publicadoresLista,
            'totais'      => $totais,
            'filters'     => compact('mes', 'func', 'empresa', 'busca', 'problema'),
            'isPub'       => $isPub,
        ]);
    }

    public function revisao(Request $request): Response
    {
        $this->checkPubAccess('revisao');

        $mesRef = $request->get('mes', now()->format('Y-m'));
        $funcId = $request->get('func', '');
        $status = $request->get('status', 'todos');
        $busca  = $request->get('busca', '');

        $publicadores = $this->publicadores()
            ->map(fn($p) => ['id' => $p->id, 'nome' => $p->name])
            ->toArray();

        $query = Publicacao::with('user:id,name', 'comentarioAutor:id,name')
            ->whereHas('user.setores', function ($q) {
                $q->where('setores.slug', 'publicacao')
                  ->whereHas('cargos', fn($qc) => $qc->whereIn('cargos.slug', ['publicador', 'lider-de-publicacao']));
            })
            ->whereRaw("DATE_FORMAT(data, '%Y-%m') = ?", [$mesRef]);

        if ($funcId) $query->where('user_id', $funcId);

        match ($status) {
            'sem_revisao'    => $query->where('revisado', false),
            'sem_comentario' => $query->where(fn($q) => $q->whereNull('comentario')->orWhere('comentario', '')),
            'com_comentario' => $query->whereNotNull('comentario')->where('comentario', '!=', ''),
            'vendidos'       => $query->where('vendido', true),
            'nao_vendidos'   => $query->where('vendido', false),
            default          => null,
        };

        if ($busca) {
            $query->where(fn($q) => $q
                ->where('mlb_code', 'like', "%{$busca}%")
                ->orWhere('empresa', 'like', "%{$busca}%")
            );
        }

        $publicacoes = $query->orderByDesc('data')->orderByDesc('id')->limit(120)->get()
            ->map(fn($p) => [
                'id'                   => $p->id,
                'data'                 => $p->data->format('d/m/Y'),
                'usuario'              => $p->user?->name ?? '—',
                'usuario_removido'     => $p->user?->deleted_at !== null,
                'user_id'              => $p->user_id,
                'empresa'              => $p->empresa,
                'mlb_code'             => $p->mlb_code,
                'vendido'              => $p->vendido,
                'revisado'             => $p->revisado,
                'problema'             => $p->problema,
                'problema_nota'        => $p->problema_nota,
                'problema_em'          => $p->problema_em?->format('d/m/Y'),
                'comentario'           => $p->comentario,
                'comentario_autor'     => $p->comentarioAutor?->name,
                'comentario_em'        => $p->comentario_em?->format('d/m/Y'),
                'comentario_resolvido' => $p->comentario_resolvido,
            ]);

        // Problemas com prioridade no topo
        $publicacoes = $publicacoes->sortByDesc(fn($p) => $p['problema'] ? 1 : 0)->values();

        $kpis = [
            'total'          => $publicacoes->count(),
            'revisados'      => $publicacoes->where('revisado', true)->count(),
            'vendidos'       => $publicacoes->where('vendido', true)->count(),
            'com_comentario' => $publicacoes->filter(fn($p) => !empty($p['comentario']))->count(),
            'com_problema'   => $publicacoes->where('problema', true)->count(),
        ];

        // Empresas com pelo menos um SKU concluído fora do prazo
        $empresasComAtraso = MlbEmpresa::get()->filter(function ($e) {
            foreach ([1, 2, 3] as $stage) {
                foreach ($e->{"skus_estagio{$stage}"} ?? [] as $sku) {
                    if (!empty($sku['atrasado'])) return true;
                }
            }
            return false;
        })->pluck('nome')->unique()->values()->toArray();

        return Inertia::render('Mlb/Revisao', [
            'publicacoes'       => $publicacoes,
            'publicadores'      => $publicadores,
            'meses'             => $this->mesesDisponiveis(),
            'kpis'              => $kpis,
            'filters'           => compact('mesRef', 'funcId', 'status', 'busca'),
            'empresasComAtraso' => $empresasComAtraso,
        ]);
    }

    // =========================================================================
    // AÇÕES (PATCH / POST / DELETE)
    // =========================================================================

    public function store(Request $request)
    {
        $this->checkPubAccess('publicacoes');

        $request->validate([
            'data'           => 'required|date|before_or_equal:today',
            'empresa'        => 'required|string|max:200',
            'cust_id'        => 'nullable|string|max:50',
            'codigos'        => 'required|string',
            'mlb_empresa_id' => 'nullable|exists:mlb_empresas,id',
            'tipo'           => 'nullable|string|in:anuncio,variacao',
            'sku_stage'      => 'nullable|integer|in:1,2,3',
            'sku_position'   => 'nullable|integer|min:0',
        ]);

        $mlbs = $this->extractMlbs($request->codigos);

        if (empty($mlbs)) {
            return back()->withErrors(['codigos' => 'Nenhum código MLB válido encontrado.']);
        }

        $existentes = Publicacao::whereIn('mlb_code', $mlbs)->pluck('mlb_code')->toArray();
        $novos      = array_values(array_filter($mlbs, fn($m) => !in_array($m, $existentes)));

        if (empty($novos)) {
            return back()->with('error', 'Todos os MLBs informados já estão registrados.');
        }

        $rows = array_map(fn($mlb) => [
            'data'           => $request->data,
            'user_id'        => $request->user()->id,
            'empresa'        => trim($request->empresa),
            'cust_id'        => trim($request->cust_id ?? ''),
            'mlb_code'       => $mlb,
            'mlb_empresa_id' => $request->mlb_empresa_id ?? null,
            'tipo'           => $request->tipo ?? 'anuncio',
            'sku_stage'      => $request->sku_stage ?? null,
            'sku_position'   => $request->sku_position ?? null,
            'vendido'        => false,
            'revisado'       => false,
            'created_at'     => now(),
            'updated_at'     => now(),
        ], $novos);

        Publicacao::insert($rows);

        // insert() não dispara eventos do model — log manual
        activity('mlb')
            ->causedBy($request->user())
            ->withProperties([
                'empresa'  => trim($request->empresa),
                'tipo'     => $request->tipo ?? 'anuncio',
                'mlbs'     => $novos,
                'qtd'      => count($novos),
                'ignorados'=> $existentes,
                'data'     => $request->data,
                'estagio'  => $request->sku_stage ? "Estágio {$request->sku_stage}" : null,
            ])
            ->log('Publicações MLB registradas: ' . implode(', ', $novos) . ' — empresa "' . trim($request->empresa) . '"');

        $n   = count($novos);
        $ign = count($existentes);
        $msg = "{$n} anúncio(s) registrado(s) com sucesso";
        if ($ign > 0) $msg .= " ({$ign} já existentes foram ignorados)";

        return back()->with('success', $msg);
    }

    public function marcarVendido(Request $request, Publicacao $pub)
    {
        $this->checkPubAccess();
        $user = $request->user();

        if ($this->userHasPubCargo($user, 'publicador') && $pub->user_id !== $user->id && !$user->isAdmin()) {
            abort(403);
        }

        $novoStatus = !$pub->vendido;
        $pub->update(['vendido' => $novoStatus]);

        activity('mlb')
            ->causedBy($user)
            ->withProperties(['mlb_code' => $pub->mlb_code, 'empresa' => $pub->empresa, 'vendido' => $novoStatus])
            ->log('Publicação ' . $pub->mlb_code . ' (' . $pub->empresa . ') marcada como ' . ($novoStatus ? 'vendida' : 'não vendida'));

        return back();
    }

    public function marcarRevisado(Request $request, Publicacao $pub)
    {
        $this->checkPubAccess();
        $this->checkPubRole(['gestor', 'lider']);
        $novoStatus = !$pub->revisado;
        $pub->update(['revisado' => $novoStatus]);

        activity('mlb')
            ->causedBy($request->user())
            ->withProperties(['mlb_code' => $pub->mlb_code, 'empresa' => $pub->empresa, 'revisado' => $novoStatus])
            ->log('Publicação ' . $pub->mlb_code . ' (' . $pub->empresa . ') marcada como ' . ($novoStatus ? 'revisada' : 'não revisada'));

        return back();
    }

    public function salvarComentario(Request $request, Publicacao $pub)
    {
        $this->checkPubAccess();
        $this->checkPubRole(['gestor', 'lider']);
        $user = $request->user();

        $request->validate(['comentario' => 'nullable|string|max:1000']);

        $comentario = trim($request->comentario ?? '');

        $pub->update([
            'comentario'           => $comentario ?: null,
            'comentario_autor_id'  => $comentario ? $user->id : null,
            'comentario_em'        => $comentario ? now() : null,
            'comentario_resolvido' => false,
        ]);

        activity('mlb')
            ->causedBy($user)
            ->withProperties(['mlb_code' => $pub->mlb_code, 'empresa' => $pub->empresa, 'comentario' => $comentario ?: null])
            ->log($comentario
                ? 'Comentário adicionado na publicação ' . $pub->mlb_code . ' (' . $pub->empresa . '): "' . mb_strimwidth($comentario, 0, 100, '…') . '"'
                : 'Comentário removido da publicação ' . $pub->mlb_code . ' (' . $pub->empresa . ')');

        return back();
    }

    /** Publicador resolve (fecha) um comentário recebido. */
    public function resolverComentario(Request $request, Publicacao $pub)
    {
        $this->checkPubAccess();
        $user = $request->user();

        // Só o dono da publicação, gestor ou líder do setor pode marcar como resolvido
        $podeOutroDono = $this->userHasPubCargo($user, 'gestor-de-publicacao')
            || $this->userHasPubCargo($user, 'lider-de-publicacao');
        if ($pub->user_id !== $user->id && !$podeOutroDono) {
            abort(403);
        }

        $novoStatus = !$pub->comentario_resolvido;
        $pub->update(['comentario_resolvido' => $novoStatus]);

        activity('mlb')
            ->causedBy($user)
            ->withProperties(['mlb_code' => $pub->mlb_code, 'empresa' => $pub->empresa])
            ->log('Comentário ' . ($novoStatus ? 'resolvido' : 'reaberto') . ' na publicação ' . $pub->mlb_code . ' (' . $pub->empresa . ')');

        return back();
    }

    /** Publicador marca/desmarca uma publicação com problema. */
    public function marcarProblema(Request $request, Publicacao $pub)
    {
        $this->checkPubAccess();
        $user = $request->user();

        // Publicador só pode marcar suas próprias publicações
        if ($this->userHasPubCargo($user, 'publicador') && $pub->user_id !== $user->id) {
            abort(403);
        }

        $request->validate(['problema_nota' => 'nullable|string|max:500']);

        $problema = !$pub->problema;
        $nota = $problema ? trim($request->problema_nota ?? '') : null;
        $pub->update([
            'problema'      => $problema,
            'problema_nota' => $nota,
            'problema_em'   => $problema ? now() : null,
        ]);

        activity('mlb')
            ->causedBy($user)
            ->withProperties(['mlb_code' => $pub->mlb_code, 'empresa' => $pub->empresa, 'nota' => $nota])
            ->log('Problema ' . ($problema ? 'marcado' : 'removido') . ' na publicação ' . $pub->mlb_code . ' (' . $pub->empresa . ')' . ($nota ? ': "' . $nota . '"' : ''));

        return back();
    }

    public function destroy(Request $request, Publicacao $pub)
    {
        $this->checkPubAccess();
        $pub->delete();
        return back()->with('success', 'Publicação removida.');
    }

    // =========================================================================
    // EMPRESAS MLB (Analista / Líder / Gestor)
    // =========================================================================

    public function projetos(Request $request): Response
    {
        $this->checkPubAccess('projetos');

        $ordenPolos = ['M0', 'M1', 'M2', 'M3', 'M4'];

        $todas = MlbEmpresa::with(['responsavel:id,name', 'implementacao'])
            ->orderBy('nome')
            ->get()
            ->map(fn($e) => [
                'id'                  => $e->id,
                'nome'                => $e->nome,
                'fase'                => $e->fase,
                'estagio'             => $e->estagio,
                'prioridade'          => $e->prioridade,
                'responsavel_nome'    => $e->responsavel?->name,
                'progresso'           => $e->progresso(),
                'problema'            => $e->problema,
                'implementacao_token' => $e->implementacao?->token,
                // Campo canônico no banco; cai para mapeamento por fase como fallback
                // (compatibilidade com empresas antigas onde projeto ainda não foi preenchido)
                'projeto'             => $e->getAttributes()['projeto']
                                        ?? (MlbEmpresa::FASE_PARA_PROJETO[$e->fase ?? ''] ?? null),
            ]);

        // POLOS: agrupados por M (Status)
        $polos = $todas->filter(fn($e) => $e['projeto'] === 'POLOS');
        $gruposPolos = [];
        foreach ($ordenPolos as $m) {
            $grupo = $polos->filter(fn($e) => $e['fase'] === $m)->values()->toArray();
            if (!empty($grupo)) $gruposPolos[$m] = $grupo;
        }

        // Outros projetos: flat
        $projetosOrdem = ['Assessoria', 'Incubadora', 'Implantação'];
        $outros = [];
        foreach ($projetosOrdem as $proj) {
            $comp = $todas->filter(fn($e) => $e['projeto'] === $proj)->values()->toArray();
            if (!empty($comp)) $outros[$proj] = $comp;
        }

        // Distribuição: empresas com projeto definido (qualquer um)
        $totalGeral  = $todas->filter(fn($e) => !empty($e['projeto']))->count();
        $projetosGrafico = ['POLOS', 'Assessoria', 'Incubadora', 'Implantação'];
        $distribuicao = [];
        foreach ($projetosGrafico as $proj) {
            $count = $proj === 'POLOS'
                ? $polos->count()
                : count($outros[$proj] ?? []);
            if ($count > 0) {
                $distribuicao[] = [
                    'nome'  => $proj,
                    'total' => $count,
                    'pct'   => $totalGeral > 0 ? round($count / $totalGeral * 100, 1) : 0,
                ];
            }
        }

        return Inertia::render('Mlb/Projetos', [
            'polos'        => $gruposPolos,
            'outros'       => $outros,
            'totais'       => [
                'POLOS'      => $polos->count(),
                'Assessoria' => count($outros['Assessoria'] ?? []),
                'Incubadora' => count($outros['Incubadora'] ?? []),
                'Implantação'=> count($outros['Implantação'] ?? []),
            ],
            'distribuicao' => $distribuicao,
            'totalEmpresas'=> $totalGeral,
        ]);
    }

    /**
     * Visão de empresas POLOS agrupadas por fase M (M0–M4) com grid de cards.
     * Reaproveita a mesma lógica de agrupamento do método projetos(),
     * mas retorna apenas os dados de POLOS para a nova página dedicada.
     *
     * @return array{
     *   grupos: array<string, array>,
     *   contagens: array<string, int>,
     *   totalPolos: int
     * }
     */
    public function polosEmpresas(Request $request): Response
    {
        $this->checkPubAccess('projetos');

        $ordenPolos = ['M0', 'M1', 'M2', 'M3', 'M4'];

        // Carrega empresas com mesmo mapeamento de projetos()
        $todas = MlbEmpresa::with(['responsavel:id,name', 'implementacao'])
            ->orderBy('nome')
            ->get()
            ->map(fn($e) => [
                'id'                  => $e->id,
                'nome'                => $e->nome,
                'fase'                => $e->fase,
                'estagio'             => $e->estagio,
                'prioridade'          => $e->prioridade,
                'responsavel_nome'    => $e->responsavel?->name,
                'progresso'           => $e->progresso(),
                'problema'            => $e->problema,
                'implementacao_token' => $e->implementacao?->token,
                // Campo canônico no banco; cai para mapeamento por fase como fallback
                // (compatibilidade com empresas antigas onde projeto ainda não foi preenchido)
                'projeto'             => $e->getAttributes()['projeto']
                                        ?? (MlbEmpresa::FASE_PARA_PROJETO[$e->fase ?? ''] ?? null),
            ]);

        // Filtra apenas empresas do projeto POLOS e agrupa por fase M0..M4
        $polos = $todas->filter(fn($e) => $e['projeto'] === 'POLOS');

        $gruposPolos = [];
        foreach ($ordenPolos as $m) {
            $grupo = $polos->filter(fn($e) => $e['fase'] === $m)->values()->toArray();
            if (!empty($grupo)) {
                $gruposPolos[$m] = $grupo;
            }
        }

        // Contagens para todas as fases (inclusive as com zero empresas)
        $contagens = [];
        foreach ($ordenPolos as $m) {
            $contagens[$m] = count($gruposPolos[$m] ?? []);
        }

        $totalPolos = $polos->count();

        return Inertia::render('Polos/EmpresasPorM', [
            'grupos'     => $gruposPolos,
            'contagens'  => $contagens,
            'totalPolos' => $totalPolos,
        ]);
    }

    /**
     * Persiste uma nova opção customizada de campo antes de salvar a empresa.
     * Permite que a opção apareça em outros formulários mesmo antes do primeiro uso.
     */
    public function storeOpcaoCampo(Request $request)
    {
        $this->checkPubAccess('empresas');

        $data = $request->validate([
            'campo' => 'required|string|in:polo,fase,projeto,estagio',
            'valor' => 'required|string|max:200',
        ]);

        DB::table('mlb_campo_opcoes')->updateOrInsert(
            ['campo' => $data['campo'], 'valor' => $data['valor']],
            ['excluido' => false, 'updated_at' => now(), 'created_at' => now()]
        );

        return back();
    }

    /**
     * Remove uma opção de campo (padrão ou customizada).
     * Zera o valor em todas as empresas e marca como excluída para não reaparecer nos defaults.
     * Acessível apenas para gestor/lider/admin.
     */
    public function destroyOpcaoCampo(Request $request)
    {
        $this->checkPubAccess('empresas');
        $this->checkPubRole(['gestor', 'lider']);

        $data = $request->validate([
            'campo' => 'required|string|in:polo,fase,projeto,estagio',
            'valor' => 'required|string|max:200',
        ]);

        $affected = MlbEmpresa::where($data['campo'], $data['valor'])
            ->update([$data['campo'] => null]);

        // Upsert com excluido=true para suprimir a opção mesmo que seja um default hardcoded
        DB::table('mlb_campo_opcoes')->updateOrInsert(
            ['campo' => $data['campo'], 'valor' => $data['valor']],
            ['excluido' => true, 'updated_at' => now(), 'created_at' => now()]
        );

        return back()->with(
            'success',
            "Opção \"{$data['valor']}\" removida ({$affected} empresa" . ($affected !== 1 ? 's' : '') . ' atualizada' . ($affected !== 1 ? 's' : '') . ')'
        );
    }

    public function empresas(Request $request): Response
    {
        $this->checkPubAccess('empresas');

        $user        = $request->user();
        // Publicador/analista só enxergam empresas das quais são responsáveis;
        // admin/gestor/líder veem todas (com filtro opcional "minhas").
        $verTodos    = $this->podeVerTodosPub($user);
        $filtEstagio = $request->get('estagio', '');
        $filtFase    = $request->get('fase', '');
        $filtProjeto = $request->get('projeto', '');
        $filtMeu     = $request->boolean('meu', false);

        $query = MlbEmpresa::with(['responsavel:id,name', 'implementacao'])
            ->orderByRaw("estagio = 'Concluido' ASC")
            ->orderBy('nome');

        if ($filtEstagio) $query->where('estagio', $filtEstagio);
        if ($filtFase)    $query->where('fase', $filtFase);
        if ($filtProjeto) $query->where('projeto', $filtProjeto);
        if (!$verTodos) {
            $query->where('responsavel_id', $user->id);
        } elseif ($filtMeu) {
            $query->where('responsavel_id', $user->id);
        }

        $empresas = $query->get()->map(function ($e) {
                $problemasCount = Publicacao::where('mlb_empresa_id', $e->id)->where('problema', true)->count();
                $problemas = $problemasCount > 0
                    ? Publicacao::where('mlb_empresa_id', $e->id)
                        ->where('problema', true)
                        ->orderByDesc('problema_em')
                        ->get(['id', 'mlb_code', 'problema_nota', 'problema_em', 'user_id'])
                        ->map(fn($p) => [
                            'id'           => $p->id,
                            'mlb_code'     => $p->mlb_code,
                            'problema_nota'=> $p->problema_nota,
                            'problema_em'  => $p->problema_em?->format('d/m/Y'),
                        ])->toArray()
                    : [];

                return [
                    'id'               => $e->id,
                    'company_id'       => $e->company_id,
                    'nome'             => $e->nome,
                    'cust_id'          => $e->cust_id,
                    'polo'             => $e->polo,
                    'gmail'            => $e->gmail,
                    'estagio'          => $e->estagio,
                    'fase'             => $e->fase,
                    'projeto'          => $e->getAttributes()['projeto'] ?? null,
                    'prioridade'       => $e->prioridade,
                    'responsavel_id'   => $e->responsavel_id,
                    'responsavel_nome' => $e->responsavel?->name,
                    'contexto'         => $e->contexto,
                    'skus_estagio1'    => $e->skus_estagio1 ?? [],
                    'skus_estagio2'    => $e->skus_estagio2 ?? [],
                    'skus_estagio3'    => $e->skus_estagio3 ?? [],
                    'prazo_estagio1'   => $e->prazo_estagio1?->format('Y-m-d'),
                    'prazo_estagio2'   => $e->prazo_estagio2?->format('Y-m-d'),
                    'prazo_estagio3'   => $e->prazo_estagio3?->format('Y-m-d'),
                    'encerramento'     => $e->encerramento?->format('Y-m-d'),
                    'progresso'        => $e->progresso(),
                    'problemas_count'  => $problemasCount,
                    'problemas'        => $problemas,
                    'problema'         => $e->problema,
                    'problema_nota'    => $e->problema_nota,
                    'problema_em'           => $e->problema_em?->format('d/m/Y'),
                    'implementacao_token'   => $e->implementacao?->token,
                ];
            });

        $publicadores = $this->publicadores()
            ->map(fn($p) => ['id' => $p->id, 'nome' => $p->name]);

        // Opções: mescla valores das empresas + customs salvos antecipadamente (excluído=false)
        // Excluídos: opções marcadas como excluido=true (suprimem defaults hardcoded no frontend)
        $todasOpcoes = DB::table('mlb_campo_opcoes')->get()->groupBy('campo');
        $customFor   = fn(string $campo) => ($todasOpcoes[$campo] ?? collect())->where('excluido', false)->pluck('valor');
        $excluiFor   = fn(string $campo) => ($todasOpcoes[$campo] ?? collect())->where('excluido', true)->pluck('valor')->values();

        $estagiosDb = MlbEmpresa::whereNotNull('estagio')->where('estagio', '!=', '')
                        ->distinct()->pluck('estagio')
                        ->merge($customFor('estagio'))->unique()->sort()->values();
        $fasesDb    = MlbEmpresa::whereNotNull('fase')->where('fase', '!=', '')
                        ->distinct()->pluck('fase')
                        ->merge($customFor('fase'))->unique()->sort()->values();
        $polosDb    = MlbEmpresa::whereNotNull('polo')->where('polo', '!=', '')
                        ->distinct()->pluck('polo')
                        ->merge($customFor('polo'))->unique()->sort()->values();
        $projetosDb = MlbEmpresa::whereNotNull('projeto')->where('projeto', '!=', '')
                        ->distinct()->pluck('projeto')
                        ->merge($customFor('projeto'))->unique()->sort()->values();

        $excluidos = [
            'estagio' => $excluiFor('estagio'),
            'fase'    => $excluiFor('fase'),
            'polo'    => $excluiFor('polo'),
            'projeto' => $excluiFor('projeto'),
        ];

        // Companies cadastradas pelo Comercial que aguardam complemento de dados pelo time de Publicação
        // para whereHas('contratosServico') JOIN servicos.nome (D-01, RESEARCH §5).
        // atual lê (coexistência Wave 2 — drop no Plan 14-06). A chave
        // `servicos_contratados` é adicionada via transform para a UI nova
        // consumir progressivamente.
        $empresasPendentes = Company::where('status', 'pendente')
            ->whereHas('contratosServico', fn($q) =>
                $q->where('ativo', true)
                  ->whereHas('servico', fn($qs) =>
                      $qs->whereIn('nome', ['Publicação', 'Polos', 'Assessoria'])
                  )
            )
            ->with(['contratosServico' => fn($q) => $q->where('ativo', true)->with('servico')])
            ->orderBy('created_at', 'desc')
            ->get(['id', 'name', 'notes', 'created_at']);

        $empresasPendentes->transform(function ($e) {
            $nomes = $e->contratosServico->where('ativo', true)->pluck('servico.nome')->filter();
            $e->servicos_contratados = $nomes->values()->toArray();
            return $e;
        });

        return Inertia::render('Mlb/Empresas', [
            'empresas'          => $empresas,
            'publicadores'      => $publicadores,
            'estagiosDb'        => $estagiosDb,
            'fasesDb'           => $fasesDb,
            'polosDb'           => $polosDb,
            'projetosDb'        => $projetosDb,
            'excluidos'         => $excluidos,
            'filters'           => compact('filtEstagio', 'filtFase', 'filtProjeto', 'filtMeu'),
            'empresas_pendentes' => $empresasPendentes,
            'isGeral'           => $verTodos,
        ]);
    }

    /**
     * Ativa uma company pendente com tipo 'publicacao' como POLO ou Assessoria.
     * Chamado pelo time de Publicação ao receber uma empresa cadastrada pelo Comercial.
     *
     * Cria o registro MlbEmpresa correspondente e, se for POLO, cria a MlbImplementacao.
     * Não altera o status da Company — isso acontece normalmente ao editar a mlb_empresa
     * pela primeira vez (updateEmpresa já lida com o status pendente → ativo).
     */
    public function ativarEmpresaPendente(Request $request, Company $company)
    {
        $this->checkPubAccess('empresas');

        abort_if(
            MlbEmpresa::where('company_id', $company->id)->exists(),
            422,
            'Esta empresa já possui um registro MLB.'
        );

        $validated = $request->validate([
            'tipo' => 'required|in:polos,assessoria',
        ]);

        DB::transaction(function () use ($company, $validated, $request) {
            if ($validated['tipo'] === 'polos') {
                $empresa = MlbEmpresa::create([
                    'nome'       => $company->name,
                    'tipo'       => 'POLO',
                    'projeto'    => 'POLOS',
                    'fase'       => 'M0',
                    'estagio'    => 'Não Listado',
                    'company_id' => $company->id,
                    'criado_por' => $request->user()->id,
                ]);

                $dados = MlbImplementacao::dadosPadrao();
                $p     = MlbConfiguracao::implementacaoPadroes();
                if ($p['tutorial_intro']) {
                    $dados['tutorial_intro'] = $p['tutorial_intro'];
                }
                if (!empty($p['tutoriais'])) {
                    $dados['tutoriais'] = array_merge($dados['tutoriais'], $p['tutoriais']);
                }
                if (!empty($p['links_admin_extra'])) {
                    $dados['links_admin']['programa_decola'] = $p['links_admin_extra']['programa_decola'] ?? '';
                }
                MlbImplementacao::create([
                    'empresa_id' => $empresa->id,
                    'token'      => Str::random(48),
                    'dados'      => $dados,
                ]);
            } else {
                MlbEmpresa::create([
                    'nome'       => $company->name,
                    'tipo'       => 'ASSESSORIA',
                    'company_id' => $company->id,
                    'criado_por' => $request->user()->id,
                ]);
            }
        });

        $label = $validated['tipo'] === 'polos' ? 'Polos (com Onboarding)' : 'Assessoria';

        return back()->with('success', '"' . $company->name . '" ativada como ' . $label . '.');
    }

    public function storeEmpresa(Request $request)
    {
        $this->checkPubAccess('empresas');

        $data = $request->validate([
            'nome'           => 'required|string|max:200',
            'cust_id'        => 'nullable|string|max:50',
            'polo'           => 'nullable|string|max:100',
            'gmail'          => 'nullable|string|max:150',
            'estagio'        => 'nullable|string|max:80',
            'fase'           => 'nullable|string|max:80',
            'projeto'        => 'nullable|string|max:100',
            'prioridade'     => 'nullable|string|max:50',
            'responsavel_id' => 'nullable|exists:users,id',
            'contexto'       => 'nullable|string|max:2000',
            'skus_estagio1'  => 'nullable|array',
            'skus_estagio2'  => 'nullable|array',
            'skus_estagio3'  => 'nullable|array',
            'prazo_estagio1' => 'nullable|date',
            'prazo_estagio2' => 'nullable|date',
            'prazo_estagio3' => 'nullable|date',
            'encerramento'   => 'nullable|date',
        ]);

        MlbEmpresa::create([
            ...$data,
            'skus_estagio1' => MlbEmpresa::normalizaSkus($data['skus_estagio1'] ?? []),
            'skus_estagio2' => MlbEmpresa::normalizaSkus($data['skus_estagio2'] ?? []),
            'skus_estagio3' => MlbEmpresa::normalizaSkus($data['skus_estagio3'] ?? []),
            'criado_por'    => $request->user()->id,
        ]);

        return back()->with('success', 'Empresa cadastrada com sucesso.');
    }

    public function updateEmpresa(Request $request, MlbEmpresa $empresa)
    {
        $this->checkPubAccess('empresas');

        $data = $request->validate([
            'nome'           => 'required|string|max:200',
            'cust_id'        => 'nullable|string|max:50',
            'polo'           => 'nullable|string|max:100',
            'gmail'          => 'nullable|string|max:150',
            'estagio'        => 'nullable|string|max:80',
            'fase'           => 'nullable|string|max:80',
            'projeto'        => 'nullable|string|max:100',
            'prioridade'     => 'nullable|string|max:50',
            'responsavel_id' => 'nullable|exists:users,id',
            'contexto'       => 'nullable|string|max:2000',
            'skus_estagio1'  => 'nullable|array',
            'skus_estagio2'  => 'nullable|array',
            'skus_estagio3'  => 'nullable|array',
            'prazo_estagio1' => 'nullable|date',
            'prazo_estagio2' => 'nullable|date',
            'prazo_estagio3' => 'nullable|date',
            'encerramento'   => 'nullable|date',
        ]);

        // Preserva concluido_em/atrasado dos SKUs que já foram marcados pelo publicador
        $preservarHistorico = function(?array $novos, ?array $existentes): array {
            $existMap = [];
            foreach ($existentes ?? [] as $ex) {
                $k = trim($ex['sku'] ?? '');
                if ($k) $existMap[$k] = $ex;
            }
            return array_map(function ($item) use ($existMap) {
                $ex = $existMap[trim($item['sku'] ?? '')] ?? [];
                $item['concluido_em'] = $ex['concluido_em'] ?? null;
                $item['atrasado']     = (bool) ($ex['atrasado'] ?? false);
                return $item;
            }, $novos ?? []);
        };

        $empresa->update([
            ...$data,
            'skus_estagio1' => MlbEmpresa::normalizaSkus($preservarHistorico($data['skus_estagio1'] ?? null, $empresa->skus_estagio1)),
            'skus_estagio2' => MlbEmpresa::normalizaSkus($preservarHistorico($data['skus_estagio2'] ?? null, $empresa->skus_estagio2)),
            'skus_estagio3' => MlbEmpresa::normalizaSkus($preservarHistorico($data['skus_estagio3'] ?? null, $empresa->skus_estagio3)),
        ]);

        // Não chama atualizaEstagio() aqui: o usuário está controlando o estágio explicitamente.
        // A progressão automática ocorre apenas ao marcar SKUs (marcarSku).

        // Empresa cadastrada pelo Comercial: ao ser editada pela primeira vez, sai do estado pendente
        if ($empresa->company_id) {
            Company::where('id', $empresa->company_id)->where('status', 'pendente')->update(['status' => 'ativo']);
        }

        return back()->with('success', 'Empresa atualizada.');
    }

    public function destroyEmpresa(MlbEmpresa $empresa)
    {
        $this->checkPubAccess('empresas');
        $this->checkPubRole(['gestor', 'lider']);
        $empresa->delete();
        return back()->with('success', 'Empresa removida.');
    }

    /** Publicador ou líder marca/edita/remove problema na conta da empresa. */
    public function marcarProblemaEmpresa(Request $request, MlbEmpresa $empresa)
    {
        $this->checkPubAccess();

        $request->validate(['problema_nota' => 'nullable|string|max:500']);

        $acao = $request->input('acao', 'toggle');
        $user = $request->user();

        if ($acao === 'editar' && $empresa->problema) {
            $nota = trim($request->problema_nota ?? '');
            $empresa->update(['problema_nota' => $nota]);
            activity('mlb')
                ->causedBy($user)
                ->withProperties(['empresa' => $empresa->nome, 'nota' => $nota])
                ->log('Nota de problema atualizada na empresa MLB "' . $empresa->nome . '"');
            return back()->with('success', 'Nota atualizada.');
        }

        if ($acao === 'remover') {
            $empresa->update(['problema' => false, 'problema_nota' => null, 'problema_em' => null]);
            activity('mlb')
                ->causedBy($user)
                ->withProperties(['empresa' => $empresa->nome])
                ->log('Problema removido da empresa MLB "' . $empresa->nome . '"');
            return back()->with('success', 'Problema removido da conta.');
        }

        // 'marcar' ou toggle
        $problema = !$empresa->problema;
        $nota = $problema ? trim($request->problema_nota ?? '') : null;
        $empresa->update([
            'problema'      => $problema,
            'problema_nota' => $nota,
            'problema_em'   => $problema ? now() : null,
        ]);

        activity('mlb')
            ->causedBy($user)
            ->withProperties(['empresa' => $empresa->nome, 'nota' => $nota])
            ->log('Problema ' . ($problema ? 'registrado' : 'removido') . ' na empresa MLB "' . $empresa->nome . '"' . ($nota ? ': "' . $nota . '"' : ''));

        return back()->with('success', $problema ? 'Problema registrado na conta.' : 'Problema removido da conta.');
    }

    /** Publicador marca/desmarca um SKU como concluído. */
    public function marcarSku(Request $request, MlbEmpresa $empresa)
    {
        $this->checkPubAccess('publicacoes');

        $request->validate([
            'stage'    => 'required|integer|in:1,2,3',
            'position' => 'required|integer|min:0',
            'ok'       => 'required|boolean',
        ]);

        $col  = "skus_estagio{$request->stage}";
        $skus = $empresa->$col ?? [];

        if (!array_key_exists($request->position, $skus)) {
            return back()->withErrors(['position' => 'SKU não encontrado.']);
        }

        $skuCode = $skus[$request->position]['sku'] ?? "posição {$request->position}";
        $ok      = (bool) $request->ok;
        $atrasado = false;

        $skus[$request->position]['ok'] = $ok;

        if ($ok) {
            $skus[$request->position]['concluido_em'] = now()->toDateTimeString();
            $prazo = $empresa->{"prazo_estagio{$request->stage}"};
            $atrasado = $prazo && now()->startOfDay()->gt(Carbon::parse($prazo)->startOfDay());
            $skus[$request->position]['atrasado'] = $atrasado;
        } else {
            $skus[$request->position]['concluido_em'] = null;
            $skus[$request->position]['atrasado']     = false;
        }

        $empresa->update([$col => $skus]);
        $empresa->refresh()->atualizaEstagio();

        activity('mlb')
            ->causedBy($request->user())
            ->withProperties([
                'empresa'  => $empresa->nome,
                'sku'      => $skuCode,
                'estagio'  => $request->stage,
                'ok'       => $ok,
                'atrasado' => $atrasado,
            ])
            ->log('SKU "' . $skuCode . '" (Estágio ' . $request->stage . ') da empresa "' . $empresa->nome . '" marcado como ' . ($ok ? 'concluído' : 'pendente'));

        return back();
    }

    // =========================================================================
    // SYNC VENDAS VIA ADMAN API
    // =========================================================================

    /**
     * Sincroniza vendas de uma empresa: consulta GET /{marketplace}/performance/{custId}
     * e marca como vendido=true qualquer publicação cujo MLB aparece na resposta
     * com soldQuantity > 0 (orgânico + ADS, total do anúncio).
     */
    public function syncVendasAdman(Request $request, MlbEmpresa $empresa)
    {
        $this->checkPubAccess('vendas');

        $request->validate([
            'date_from' => 'required|date|before_or_equal:today',
            'date_to'   => 'required|date|before_or_equal:today|after_or_equal:date_from',
        ]);

        if (!$empresa->cust_id) {
            return back()->withErrors(['empresa' => 'Esta empresa não possui cust_id configurado.']);
        }

        try {
            $r = (new VendasSyncService())->syncEmpresa(
                $empresa->cust_id,
                $request->date_from,
                $request->date_to
            );
        } catch (\Throwable $e) {
            Log::error("[MLB SyncVendas] {$empresa->nome}: " . $e->getMessage());
            return back()->withErrors(['api' => 'Erro Adman: ' . $e->getMessage()]);
        }

        Log::info("[MLB SyncVendas] {$empresa->nome} | itens={$r['itens']} | com_venda={$r['com_venda']} | atualizadas={$r['atualizadas']}");

        return back()->with('success', sprintf(
            '%d de %d item(ns) com venda. %d publicação(ões) atualizada(s).',
            $r['com_venda'], $r['itens'], $r['atualizadas']
        ));
    }

    /**
     * Sincroniza vendas de TODAS as empresas com cust_id. Apenas gestor.
     * Despacha SyncTodasVendasAdmanJob para o queue worker e retorna imediatamente —
     * evita o timeout 504 do nginx no loop síncrono sobre ~17 empresas.
     */
    public function syncTodasVendasAdman(Request $request)
    {
        $this->checkPubAccess('vendas');
        $this->checkPubRole(['gestor']);

        $request->validate([
            'date_from' => 'required|date|before_or_equal:today',
            'date_to'   => 'required|date|before_or_equal:today|after_or_equal:date_from',
        ]);

        // Contagem leve para enriquecer a flash message sem rodar o loop
        $totalEmpresas = MlbEmpresa::whereNotNull('cust_id')->where('cust_id', '!=', '')->count();

        // pt-BR: Despacha o loop síncrono para queue worker — evita 504 do nginx em ~17 empresas * 120s.
        // O job SyncTodasVendasAdmanJob registra o progresso em mlb_sync_vendas_logs (visível em /dev/desenvolvimento).
        SyncTodasVendasAdmanJob::dispatch($request->date_from, $request->date_to, $request->user()?->id);

        return back()->with('success', sprintf(
            'Sync de vendas iniciado em background para %d empresa(s). Acompanhe o progresso em /dev/desenvolvimento.',
            $totalEmpresas
        ));
    }

    /**
     * Diagnóstico: retorna a resposta bruta da API Adman para inspeção manual.
     * Útil para identificar o campo correto de ID e quantidade por item.
     */
    public function debugSyncVendas(Request $request, MlbEmpresa $empresa)
    {
        $this->checkPubAccess();
        $this->checkPubRole(['gestor']);

        $request->validate([
            'date_from' => 'required|date',
            'date_to'   => 'required|date|after_or_equal:date_from',
        ]);

        if (!$empresa->cust_id) {
            return response()->json(['error' => 'Empresa sem cust_id'], 422);
        }

        try {
            $performance = (new AdmanService())->fetchPerformance(
                $empresa->cust_id,
                $request->date_from,
                $request->date_to
            );
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }

        $items = $performance['items'] ?? [];

        // Para cada item, mostra o que extraímos + se existe publicação correspondente
        $diagnostico = array_map(function ($item) {
            $soldRaw = $item['soldQuantity']   ??
                       $item['sold_quantity']  ??
                       $item['quantityVended'] ??
                       $item['quantity']       ?? 0;

            $qty = is_array($soldRaw)
                ? (float) ($soldRaw['value'] ?? $soldRaw['quantity'] ?? $soldRaw['total'] ?? array_values($soldRaw)[0] ?? 0)
                : (float) $soldRaw;

            $rawId = $item['itemId']   ?? $item['mlbId']     ?? $item['item_id'] ??
                     $item['id']       ?? $item['itemCode']   ?? $item['productId'] ??
                     $item['sku']      ?? $item['code']       ?? null;

            $raw   = strtoupper(trim((string) ($rawId ?? '')));
            $raw   = preg_replace('/^MLB[\s\-_]+/', 'MLB', $raw);
            $mlb   = ($raw && !str_starts_with($raw, 'MLB')) ? 'MLB' . $raw : $raw;
            $valid = $mlb && preg_match('/^MLB\d{7,}$/', $mlb);

            $pub = $valid
                ? Publicacao::where('mlb_code', $mlb)->first(['id', 'mlb_code', 'vendas_qty', 'vendido'])
                : null;

            return [
                'item_keys'    => array_keys($item),
                'id_raw'       => $rawId,
                'mlb_extraido' => $valid ? $mlb : null,
                'qty_extraida' => $qty,
                'soldQty_raw'  => $soldRaw,
                'publicacao'   => $pub
                    ? ['id' => $pub->id, 'vendas_qty_atual' => $pub->vendas_qty, 'vendido' => $pub->vendido]
                    : ($valid ? 'NÃO ENCONTRADA NO BD' : 'ID inválido'),
            ];
        }, $items);

        return response()->json([
            'empresa'       => $empresa->nome,
            'cust_id'       => $empresa->cust_id,
            'total_items'   => count($items),
            'summarized'    => $performance['summarizedData'] ?? null,
            'diagnostico'   => $diagnostico,
            'raw_amostra'   => array_slice($items, 0, 3), // primeiros 3 itens brutos
        ], 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    // =========================================================================
    // TREINAMENTOS
    // =========================================================================

    public function treinamentos(Request $request): Response
    {
        $this->checkPubAccess('treinamento');

        $user      = $request->user();
        $canManage = $user->isAdmin()
            || $this->userHasPubCargo($user, 'gestor-de-publicacao')
            || $this->userHasPubCargo($user, 'lider-de-publicacao');

        $treinamentos = MlbTreinamento::orderBy('ordem')->orderBy('id')->get()
            ->map(fn($t) => [
                'id'        => $t->id,
                'titulo'    => $t->titulo,
                'descricao' => $t->descricao,
                'url_video' => $t->url_video,
                'ordem'     => $t->ordem,
                'ativo'     => $t->ativo,
            ]);

        $config = MlbConfiguracao::get();

        return Inertia::render('Mlb/Treinamentos', [
            'treinamentos' => $treinamentos,
            'canManage'    => $canManage,
            'linkAcesso'   => $config->link_acesso,
        ]);
    }

    // =========================================================================
    // METAS POR MÊS
    // =========================================================================

    public function metasIndex(Request $request): Response
    {
        $this->checkPubAccess();
        $this->checkPubRole(['gestor']);

        $publicadores = $this->publicadores()
            ->map(fn($p) => ['id' => $p->id, 'nome' => $p->name]);

        $historico = DB::table('mlb_meta_historico')
            ->join('users', 'mlb_meta_historico.user_id', '=', 'users.id')
            ->select('mlb_meta_historico.id', 'mlb_meta_historico.user_id', 'mlb_meta_historico.mes_inicio', 'mlb_meta_historico.meta', 'users.name')
            ->orderBy('users.name')
            ->orderByDesc('mlb_meta_historico.mes_inicio')
            ->get();

        return Inertia::render('Mlb/Metas', [
            'publicadores' => $publicadores,
            'historico'    => $historico,
        ]);
    }

    public function storeMeta(Request $request)
    {
        $this->checkPubAccess();
        $this->checkPubRole(['gestor']);

        $data = $request->validate([
            'user_id'    => 'required|exists:users,id',
            'mes_inicio' => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'meta'       => 'required|integer|min:1|max:9999',
        ]);

        DB::table('mlb_meta_historico')->updateOrInsert(
            ['user_id' => $data['user_id'], 'mes_inicio' => $data['mes_inicio']],
            ['meta' => $data['meta'], 'updated_at' => now(), 'created_at' => now()]
        );

        return back()->with('success', 'Meta salva com sucesso.');
    }

    public function destroyMeta(Request $request, int $id)
    {
        $this->checkPubAccess();
        $this->checkPubRole(['gestor']);

        DB::table('mlb_meta_historico')->where('id', $id)->delete();

        return back()->with('success', 'Registro removido.');
    }

    public function salvarConfig(Request $request)
    {
        $this->checkPubAccess();
        $this->checkPubRole(['gestor', 'lider']);

        $data = $request->validate([
            'link_acesso' => 'nullable|string|max:2000',
        ]);

        MlbConfiguracao::get()->update($data);

        return back()->with('success', 'Credenciais da plataforma salvas.');
    }

    public function storeTreinamento(Request $request)
    {
        $this->checkPubAccess();
        $this->checkPubRole(['gestor', 'lider']);

        $data = $request->validate([
            'titulo'    => 'required|string|max:200',
            'descricao' => 'nullable|string|max:2000',
            'url_video' => 'required|string|max:500',
            'ordem'     => 'nullable|integer|min:0|max:255',
            'ativo'     => 'boolean',
        ]);

        MlbTreinamento::create($data);
        return back()->with('success', 'Treinamento cadastrado com sucesso.');
    }

    public function updateTreinamento(Request $request, MlbTreinamento $treinamento)
    {
        $this->checkPubAccess();
        $this->checkPubRole(['gestor', 'lider']);

        $data = $request->validate([
            'titulo'    => 'required|string|max:200',
            'descricao' => 'nullable|string|max:2000',
            'url_video' => 'required|string|max:500',
            'ordem'     => 'nullable|integer|min:0|max:255',
            'ativo'     => 'boolean',
        ]);

        $treinamento->update($data);
        return back()->with('success', 'Treinamento atualizado.');
    }

    public function destroyTreinamento(MlbTreinamento $treinamento)
    {
        $this->checkPubAccess();
        $this->checkPubRole(['gestor', 'lider']);
        $treinamento->delete();
        return back()->with('success', 'Treinamento removido.');
    }

    /**
     * Retorna [mlb_code => qty] para itens com soldQuantity > 0.
     * Casa pelo mlb_code apenas — independente de empresa ou cust_id.
     *
     * Normalização do ID:
     *   "MLB-4608785581"  → MLB4608785581
     *   "MLB 4608785581"  → MLB4608785581
     *   "MLB4608785581"   → MLB4608785581
     *   "4608785581"      → MLB4608785581
     */
    /**
     * Extrai vendas dos items da Adman.
     * Retorna [mlb_code => ['qty' => N, 'preco' => P, 'net_billing' => B]]
     */
    private function extrairMlbsVendidos(array $items): array
    {
        $result     = [];
        $semIdCount = 0;

        foreach ($items as $item) {
            // ── Quantidade vendida ──────────────────────────────────────────
            $soldRaw = $item['soldQuantity']   ??
                       $item['sold_quantity']  ??
                       $item['quantityVended'] ??
                       $item['quantity']       ?? 0;

            $qty = is_array($soldRaw)
                ? (float) ($soldRaw['value'] ?? $soldRaw['quantity'] ?? $soldRaw['total'] ?? array_values($soldRaw)[0] ?? 0)
                : (float) $soldRaw;

            if ($qty <= 0) continue;

            // ── Código do item ──────────────────────────────────────────────
            $rawId = $item['itemId']    ?? $item['mlbId']     ?? $item['item_id']   ??
                     $item['id']        ?? $item['itemCode']   ?? $item['productId'] ??
                     $item['sku']       ?? $item['code']       ?? '';

            $raw = strtoupper(trim((string) $rawId));

            if ($raw === '') {
                $semIdCount++;
                if ($semIdCount <= 3) {
                    Log::warning('[MLB SyncVendas] Item sem ID. Chaves: ' . implode(', ', array_keys($item)));
                }
                continue;
            }

            $raw = preg_replace('/^MLB[\s\-_]+/', 'MLB', $raw);
            $mlb = str_starts_with($raw, 'MLB') ? $raw : 'MLB' . $raw;

            if (!preg_match('/^MLB\d{7,}$/', $mlb)) continue;

            // ── Preço unitário e faturamento líquido ────────────────────────
            $preco = isset($item['price']) ? (float) $item['price'] : null;

            $netRaw = $item['netBilling'] ?? null;
            $net    = is_array($netRaw) ? (float) ($netRaw['value'] ?? 0) : (float) ($netRaw ?? 0);

            // Mesmo MLB pode aparecer em múltiplos itens (ex: orgânico + ADS).
            // Usa max em vez de soma para evitar duplicação de contagem.
            if (!isset($result[$mlb])) {
                $result[$mlb] = ['qty' => 0, 'preco' => $preco, 'net_billing' => 0];
            }
            $result[$mlb]['qty']         = max($result[$mlb]['qty'], $qty);
            $result[$mlb]['net_billing'] = max($result[$mlb]['net_billing'], $net);
            // Mantém o preço mais recente (não-nulo)
            if ($preco !== null) $result[$mlb]['preco'] = $preco;
        }

        if ($semIdCount > 0) {
            Log::warning("[MLB SyncVendas] {$semIdCount} item(ns) descartados por falta de ID.");
        }

        return $result;
    }

    // =========================================================================
    // COLETA DE DADOS ML (Inteligência de Anúncios) — Phase 17
    // =========================================================================

    /**
     * Lista todas as coletas (visibilidade compartilhada dentro do módulo Publicação —
     * RESEARCH Q2: colaborativo é mais útil; admin vê tudo).
     */
    public function coletaIndex(Request $request): Response
    {
        $this->checkPubAccess('coleta');

        return Inertia::render('Mlb/Coleta', [
            'coletas' => $this->listarColetas(),
            'coleta'  => null,
        ]);
    }

    /**
     * Valida a entrada, cria a coleta pendente e dispara o Job assíncrono (D-06).
     */
    public function coletaStore(Request $request): RedirectResponse
    {
        $this->checkPubAccess('coleta');

        // Validação de entrada (T-17-11): keyword obrigatória; condição restrita ao enum ML
        $request->validate([
            'keyword'      => 'required|string|max:255',
            'categoria_id' => 'nullable|string|max:50',
            'faixa_preco'  => 'nullable|string|max:20',
            'condicao'     => 'nullable|in:new,used',
        ]);

        $coleta = MlbColeta::create([
            'user_id'      => auth()->id(),
            'keyword'      => $request->keyword,
            'categoria_id' => $request->categoria_id,
            'faixa_preco'  => $request->faixa_preco,
            'condicao'     => $request->condicao,
            'status'       => MlbColeta::STATUS_PENDENTE,
        ]);

        MlbColetaJob::dispatch($coleta->id);

        return redirect()->route('mlb.coleta.show', $coleta->id);
    }

    /**
     * Renderiza o relatório de uma coleta específica + a lista de histórico.
     */
    public function coletaShow(Request $request, int $id): Response
    {
        $this->checkPubAccess('coleta');

        $coleta = MlbColeta::findOrFail($id);

        return Inertia::render('Mlb/Coleta', [
            'coletas' => $this->listarColetas(),
            'coleta'  => [
                'id'            => $coleta->id,
                'keyword'       => $coleta->keyword,
                'categoria_id'  => $coleta->categoria_id,
                'status'        => $coleta->status,
                'resultado'     => $coleta->resultado,
                'erro_mensagem' => $coleta->erro_mensagem,
                'created_at'    => $coleta->created_at?->format('d/m/Y H:i'),
            ],
        ]);
    }

    /**
     * Endpoint JSON de status para o polling do frontend.
     * Pitfall 5: status='rodando' por mais de 10 min é tratado como timeout/erro.
     */
    public function coletaStatus(int $id): JsonResponse
    {
        $this->checkPubAccess('coleta');

        $coleta = MlbColeta::findOrFail($id);

        $timedOut = $coleta->status === MlbColeta::STATUS_RODANDO
            && $coleta->started_at
            && $coleta->started_at->lt(now()->subMinutes(10));

        return response()->json([
            'status' => $timedOut ? MlbColeta::STATUS_ERRO : $coleta->status,
        ]);
    }

    /**
     * Helper: lista de coletas mapeada para a UI (compartilhada entre index e show).
     */
    private function listarColetas(): Collection
    {
        return MlbColeta::latest()->get()->map(fn ($c) => [
            'id'           => $c->id,
            'keyword'      => $c->keyword,
            'categoria_id' => $c->categoria_id,
            'status'       => $c->status,
            'created_at'   => $c->created_at?->format('d/m/Y H:i'),
            'duracao'      => $this->duracaoColeta($c),
        ]);
    }

    /**
     * Helper: duração legível da coleta (finished - started), ou null se incompleta.
     */
    private function duracaoColeta(MlbColeta $c): ?string
    {
        if (! $c->started_at || ! $c->finished_at) {
            return null;
        }

        $seg = (int) abs($c->finished_at->diffInSeconds($c->started_at));

        return $seg < 60 ? "{$seg}s" : intdiv($seg, 60) . 'min ' . ($seg % 60) . 's';
    }
}
