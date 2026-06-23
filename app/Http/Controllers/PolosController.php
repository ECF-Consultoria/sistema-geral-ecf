<?php

// Phase 38 (Plano 03, re-escopo) — Refatoração do PolosController para o novo modelo.
// Substitui a agregação antiga ("roster inteiro × R$3.000") pelo join ECF×CSV por cust_id:
//   - "ativo" = MlbEmpresa em Fase M2/M3/M4 (projeto=POLOS) — fonte ECF (D-01, D-02)
//   - faturamento = TGMV_LC do CSV por cust_id normalizado (D-10)
//   - meta = soma dos limiares por estágio configuráveis (D-07, D-08)
//   - status por empresa: Problema > Não > Sim > Em progresso (D-11)
//   - M1 excluído dos ativos (D-01)
//   - duas visões: grade por polo + distribuição de status (D-13, D-14)

namespace App\Http\Controllers;

use App\Jobs\SyncPolosFaturamentoJob;
use App\Models\Configuracao;
use App\Models\MlbEmpresa;
use App\Models\PoloFaturamentoSnapshot;
use App\Services\AdmanService;
use App\Services\EcfDriveService;
use App\Support\CustId;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

/**
 * Controller da página /polos — Faturamento por Polo vs Meta (Phase 38, re-escopo).
 *
 * Modelo novo (D-01..D-15 de 38-CONTEXT.md):
 *   - Ativos = MlbEmpresa whereIn('fase', ['M2','M3','M4']) where('projeto','POLOS')
 *   - Faturamento = TGMV_LC do CSV POLOS MENSAL do ECF Drive, casado por cust_id normalizado
 *   - Meta por polo = soma dos limiares por estágio dos seus ativos (M2=1k, M3=4k, M4=8k — configuráveis)
 *   - Status por empresa: Problema (flag) > Não (fat=0) > Sim (fat≥limiar) > Em progresso
 *   - M1 excluído (onboarding sem meta de faturamento)
 *
 * Props emitidas para Polos/Index:
 *   polos          → Array<{ polo, ativos, faturamento, meta, pct, status }> (por polo)
 *   statusDist     → { Sim, Em progresso, Não, Problema, total } (distribuição entre ativos)
 *   meses          → Array<{ value: 'YYYYMM', label: 'Junho/2026', parcial: bool }> (desc)
 *   mesSelecionado → string|null (YYYYMM exibido)
 *   mesRefLabel    → string|null (label pt-BR do mês exibido)
 *   parcial          → bool (mês ainda enchendo — COMPARATIVO != FECHADO)
 *   fonteFaturamento → 'adman' (mês corrente ao vivo) | 'csv' (mês fechado/oficial)
 *   erro             → string|null
 *
 * Acesso restrito a admin via middleware role:admin na rota.
 */
class PolosController extends Controller
{
    public function __construct(
        private EcfDriveService $ecf,
        private AdmanService $adman,
    ) {
        // O /polos processa o CSV POLOS MENSAL (até 5000 linhas) + Adman ao vivo
        // dos ativos no mês corrente; o pico (~157MB) excede o memory_limit de
        // 128M do PHP-FPM e derrubava a página com 500 (memory exhausted). Eleva
        // o teto só para esta área (admin-only, baixa concorrência) — não afeta
        // a config global do servidor.
        @ini_set('memory_limit', '512M');
    }

    /**
     * Carrega os ativos do ECF, cruza com o CSV POLOS MENSAL do ECF Drive por cust_id,
     * calcula faturamento, meta por estágio e status por empresa. Exibe UM mês por vez.
     *
     * Estratégia defensiva: try/catch Throwable global — se o ECF Drive
     * estiver offline, a página abre com props vazias + mensagem pt-BR.
     */
    public function index(): \Inertia\Response
    {
        try {
            // ─── 1. Descobrir o arquivo POLOS MENSAL ──────────────────────────
            $files = $this->ecf->listFiles(['search' => 'POLOS_MENSAL']);

            $cands = collect($files['data'] ?? []);

            // Preferir etlStatus=done se existir, senão cair no mais recente
            $done    = $cands->where('etlStatus', 'done')->sortByDesc('downloadedAt');
            $arquivo = $done->first() ?? $cands->sortByDesc('downloadedAt')->first();

            if (! $arquivo) {
                return $this->semDados('Arquivo CSV POLOS MENSAL não encontrado no ECF Drive.');
            }

            // ─── 2. Buscar linhas do CSV (envelope usa 'rows', não 'data') ────
            $resp   = $this->ecf->fileJson($arquivo['id'], ['limit' => 5000]);
            $linhas = $resp['rows'] ?? [];

            // Aviso de truncamento: limited=true significa que o CSV excede 5000 linhas
            if (($resp['limited'] ?? false) === true) {
                Log::warning('[Polos] CSV POLOS MENSAL truncado em 5000 linhas — limited=true');
            }

            // ─── 3. Resolver o mês exibido (default: mais recente) ────────────
            $meses     = $this->listarMeses($linhas);
            $valores   = array_column($meses, 'value');
            $mesPedido = trim((string) request('mes', ''));
            $mesSel    = in_array($mesPedido, $valores, true)
                ? $mesPedido
                : ($valores[0] ?? null); // listarMeses retorna desc → [0] = mais recente

            // Filtra só as linhas do mês selecionado antes de agregar
            $linhasMes = $mesSel === null ? [] : array_values(array_filter(
                $linhas,
                fn ($r) => (string) ($r['TIM_MONTH_ID'] ?? $r['tim_month_id'] ?? '') === $mesSel,
            ));

            // ─── 4. Carregar limiares por estágio (configuráveis via Configuracao) ─
            // D-07: M2=R$1.000 · M3=R$4.000 · M4=R$8.000 — defaults da planilha
            // D-08: sem override por polo nesta fase
            $limiares = [
                'M2' => (float) Configuracao::get('polo_limiar_m2', 1000),
                'M3' => (float) Configuracao::get('polo_limiar_m3', 4000),
                'M4' => (float) Configuracao::get('polo_limiar_m4', 8000),
            ];

            // ─── 5. Determinar o mês (parcial/corrente vs fechado) ────────────
            // Necessário ANTES de montar os ativos: mês fechado reconstrói o
            // roster histórico do CSV; mês corrente usa o estado ao vivo do ECF.
            $mesAtual = collect($meses)->firstWhere('value', $mesSel);
            $parcial  = (bool) ($mesAtual['parcial'] ?? false);

            // ─── 6. Montar ativos (M2/M3/M4) do mês ───────────────────────────
            // D-01: M1 excluído. Mês corrente = MlbEmpresa ao vivo (D-02); mês
            // fechado = reconstrução por MESES_NO_PROGRAMA do CSV (ver método).
            $ativos = $this->montarAtivosDoMes($mesSel, $parcial, $linhasMes);

            // ─── 7. Faturamento: mês corrente = Adman ao vivo; mês fechado = CSV ──
            // Mês corrente/parcial → Adman (gross_billing, mais fresco). Mês fechado
            // → TGMV_LC oficial do CSV: mesma métrica da planilha e cobre empresas
            // que já saíram do programa (a Adman não guarda histórico delas, daria R$0).
            [$fatMes, $fonteFaturamento] = $this->faturamentoDoMes($mesSel, $parcial, $ativos, $linhasMes);

            // ─── 7b. Gasto de ADS (investment Adman) do mês corrente, por cust_id ──
            // SÓ-CACHE (sem HTTP). Mês fechado → cache frio → [] (ADS=R$0; sem fonte
            // de ADS para meses fechados — limitação documentada). Alimenta o saldo
            // de ADS (teto × ativos) e o gasto por polo/estágio no Cockpit.
            $adsMes = ($mesSel !== null && $parcial) ? $this->adsAdmanDoMes($ativos, $mesSel) : [];

            // Teto de ADS por empresa (configurável; default R$3.000) — base do "disponível".
            $adsLimites = [
                'teto'    => (float) Configuracao::get('polo_ads_teto', 3000),
                'alerta1' => (float) Configuracao::get('polo_ads_alerta1', 1000),
                'alerta2' => (float) Configuracao::get('polo_ads_alerta2', 2000),
            ];

            // ─── 8. Agregar por polo (com ADS) e calcular distribuição de status ──
            $polos      = $this->agregarPorPolo($ativos, $linhasMes, $limiares, $fatMes, $adsMes);
            $statusDist = $this->distribuicaoStatus($ativos, $linhasMes, $limiares, $fatMes);

            // ─── 9. Empresas M1 (onboarding) — FORA da meta; visão própria ────
            // M1 é excluído dos ativos (D-01). Aqui montamos uma coorte separada com
            // status binário (faturando vs não) para o gráfico dedicado de M1.
            $m1 = $this->montarM1($mesSel, $parcial, $linhasMes);

            return Inertia::render('Polos/Index', [
                'polos'            => $polos,
                'statusDist'       => $statusDist,
                'meses'            => $meses,
                'mesSelecionado'   => $mesSel,
                'mesRefLabel'      => $mesAtual['label'] ?? null,
                'parcial'          => $parcial,
                'fonteFaturamento' => $fonteFaturamento,
                'adsLimites'       => $adsLimites,
                'm1'               => $m1,
                'erro'             => null,
            ]);
        } catch (\Throwable $e) {
            // Defensiva: ECF Drive offline NÃO quebra a aba
            report($e);
            return $this->semDados('Não foi possível buscar dados do ECF Drive. Tente em alguns segundos.');
        }
    }

    /**
     * Página "Todas as empresas" (/polos/empresas) — visão completa em tabela:
     * lista plana de TODAS as empresas ativas (todos os polos) do mês, com status,
     * faturamento vs meta, ads e problema. Abre numa aba própria (mais espaço).
     */
    public function todasEmpresas(): \Inertia\Response
    {
        $vazio = [
            'empresas'       => [],
            'statusDist'     => ['Sim' => 0, 'Em progresso' => 0, 'Não' => 0, 'Problema' => 0, 'total' => 0],
            'meses'          => [],
            'mesSelecionado' => null,
            'mesRefLabel'    => null,
            'parcial'        => false,
            'totais'         => ['faturamento' => 0, 'meta' => 0, 'pct' => 0, 'ativos' => 0],
            // Limites de ADS defensivos: garante shape consistente no frontend mesmo sem dados.
            'adsLimites'     => ['teto' => 3000, 'alerta1' => 1000, 'alerta2' => 2000],
            'erro'           => null,
        ];

        try {
            $d = $this->montarPolos();
            if ($d === null) {
                return Inertia::render('Polos/Empresas', array_merge($vazio, [
                    'erro' => 'Arquivo CSV POLOS MENSAL não encontrado no ECF Drive.',
                ]));
            }

            // Lista plana (com o polo de cada empresa), ordenada por faturamento desc.
            $empresas = [];
            foreach ($d['polos'] as $p) {
                foreach (($p['empresas'] ?? []) as $e) {
                    $empresas[] = $e + ['polo' => $p['polo']];
                }
            }
            usort($empresas, fn ($a, $b) => $b['faturamento'] <=> $a['faturamento']);

            $totFat  = array_sum(array_column($empresas, 'faturamento'));
            $totMeta = array_sum(array_column($empresas, 'meta'));

            return Inertia::render('Polos/Empresas', [
                'empresas'       => $empresas,
                'statusDist'     => $d['statusDist'],
                'meses'          => $d['meses'],
                'mesSelecionado' => $d['mesSel'],
                'mesRefLabel'    => $d['mesAtual']['label'] ?? null,
                'parcial'        => $d['parcial'],
                'totais'         => [
                    'faturamento' => $totFat,
                    'meta'        => $totMeta,
                    'pct'         => $totMeta > 0 ? round($totFat / $totMeta * 100, 1) : 0,
                    'ativos'      => count($empresas),
                ],
                'adsLimites'     => $d['adsLimites'],
                'erro'           => null,
            ]);
        } catch (\Throwable $e) {
            report($e);
            return Inertia::render('Polos/Empresas', array_merge($vazio, [
                'erro' => 'Não foi possível buscar dados do ECF Drive. Tente em alguns segundos.',
            ]));
        }
    }

    /**
     * Prepara os dados base dos polos (arquivo CSV → mês → ativos → faturamento
     * Adman → agregação por polo + distribuição de status). Compartilhado pela
     * visão completa. Retorna null quando o POLOS MENSAL não existe.
     *
     * @return array{polos:array,statusDist:array,meses:array,mesSel:?string,mesAtual:?array,parcial:bool,adsLimites:array}|null
     */
    private function montarPolos(): ?array
    {
        $files   = $this->ecf->listFiles(['search' => 'POLOS_MENSAL']);
        $cands   = collect($files['data'] ?? []);
        $done    = $cands->where('etlStatus', 'done')->sortByDesc('downloadedAt');
        $arquivo = $done->first() ?? $cands->sortByDesc('downloadedAt')->first();
        if (! $arquivo) {
            return null;
        }

        $resp   = $this->ecf->fileJson($arquivo['id'], ['limit' => 5000]);
        $linhas = $resp['rows'] ?? [];

        $meses     = $this->listarMeses($linhas);
        $valores   = array_column($meses, 'value');
        $mesPedido = trim((string) request('mes', ''));
        $mesSel    = in_array($mesPedido, $valores, true) ? $mesPedido : ($valores[0] ?? null);

        $linhasMes = $mesSel === null ? [] : array_values(array_filter(
            $linhas,
            fn ($r) => (string) ($r['TIM_MONTH_ID'] ?? $r['tim_month_id'] ?? '') === $mesSel,
        ));

        $limiares = [
            'M2' => (float) Configuracao::get('polo_limiar_m2', 1000),
            'M3' => (float) Configuracao::get('polo_limiar_m3', 4000),
            'M4' => (float) Configuracao::get('polo_limiar_m4', 8000),
        ];

        $mesAtual = collect($meses)->firstWhere('value', $mesSel);
        $parcial  = (bool) ($mesAtual['parcial'] ?? false);

        // Mês corrente = MlbEmpresa ao vivo; mês fechado = reconstrução do CSV.
        $ativos = $this->montarAtivosDoMes($mesSel, $parcial, $linhasMes);
        // Faturamento: Adman no corrente, TGMV_LC do CSV no mês fechado.
        [$fatMes] = $this->faturamentoDoMes($mesSel, $parcial, $ativos, $linhasMes);

        // ADS do mês corrente via Adman (SÓ-CACHE). Mês fechado → cache frio → [].
        // ADS = R$0 em mês fechado — sem fonte CSV para ADS (limitação documentada).
        $adsMes = $mesSel !== null ? $this->adsAdmanDoMes($ativos, $mesSel) : [];

        // Limiares de ADS configuráveis via Configuracao (defaults: teto=3000, alerta1=1000, alerta2=2000).
        $adsLimites = [
            'teto'    => (float) Configuracao::get('polo_ads_teto', 3000),
            'alerta1' => (float) Configuracao::get('polo_ads_alerta1', 1000),
            'alerta2' => (float) Configuracao::get('polo_ads_alerta2', 2000),
        ];

        $polos      = $this->agregarPorPolo($ativos, $linhasMes, $limiares, $fatMes, $adsMes);
        $statusDist = $this->distribuicaoStatus($ativos, $linhasMes, $limiares, $fatMes);

        return compact('polos', 'statusDist', 'meses', 'mesSel', 'mesAtual', 'parcial', 'adsLimites');
    }

    /**
     * Botão "Sincronizar" — aquece o cache de gross_billing da Adman para os polos
     * ativos do mês selecionado (ou corrente). Despacha em background (o warm leva
     * ~12 min para ~85 polos pelo throttle da Adman). Após processar, o /polos passa
     * a ler os valores do dia direto da Adman.
     *
     * Requer worker de fila ativo (`php artisan queue:work`) — na VPS o Supervisor
     * já roda; no localhost o dev precisa subir o worker.
     */
    public function sync(Request $request): \Illuminate\Http\RedirectResponse
    {
        // Mês alvo: o selecionado (YYYYMM) ou o corrente como default.
        $mes = trim((string) $request->input('mes', ''));
        if (! preg_match('/^\d{6}$/', $mes)) {
            $mes = now()->format('Ym');
        }

        $de  = substr($mes, 0, 4) . '-' . substr($mes, 4, 2) . '-01';
        $ate = date('Y-m-t', strtotime($de));

        SyncPolosFaturamentoJob::dispatch($de, $ate);

        return back()->with(
            'success',
            "Sincronização do faturamento ({$this->mesLabel($mes)}) iniciada — os valores atualizam em alguns minutos."
        );
    }

    /**
     * Faturamento semanal (Semana 1–4) de UMA empresa via Adman — alimenta o
     * detalhe da empresa no painel do /polos. AJAX (JSON), carregado sob demanda
     * ao clicar numa empresa (4 chamadas Adman; cacheadas após a 1ª vez).
     *
     * Semanas do mês: 1–7, 8–14, 15–21, 22–fim.
     */
    public function semanal(Request $request, string $cust): \Illuminate\Http\JsonResponse
    {
        $custId = CustId::normaliza($cust);
        $mes    = trim((string) $request->query('mes', ''));
        if (! preg_match('/^\d{6}$/', $mes)) {
            $mes = now()->format('Ym');
        }

        $ano = (int) substr($mes, 0, 4);
        $m   = (int) substr($mes, 4, 2);
        $ult = (int) date('t', mktime(0, 0, 0, $m, 1, $ano));

        $cortes   = [[1, 7], [8, 14], [15, 21], [22, $ult]];
        $semanas  = [];
        $total    = 0.0;
        $totalAds = 0.0;
        foreach ($cortes as $i => [$d1, $d2]) {
            $de  = sprintf('%04d-%02d-%02d', $ano, $m, $d1);
            $ate = sprintf('%04d-%02d-%02d', $ano, $m, $d2);
            $fat = 0.0;
            $ads = 0.0;
            try {
                $v   = $this->adman->fetchGrossBilling($custId, $de, $ate, 1440, false);
                $fat = $v !== null ? (float) $v : 0.0;
            } catch (\Throwable $e) {
                Log::warning("[Polos] semanal cust={$custId} S" . ($i + 1) . ': ' . $e->getMessage());
            }
            // ADS semanal: investment da mesma janela via /performance (cacheado após o 1º clique).
            try {
                $v   = $this->adman->fetchInvestment($custId, $de, $ate, 1440, false);
                $ads = $v !== null ? (float) $v : 0.0;
            } catch (\Throwable $e) {
                Log::warning("[Polos] semanal ADS cust={$custId} S" . ($i + 1) . ': ' . $e->getMessage());
            }
            $total    += $fat;
            $totalAds += $ads;
            $semanas[] = [
                'semana'      => $i + 1,
                'de'          => $de,
                'ate'         => $ate,
                'faturamento' => $fat,
                'ads'         => $ads,
            ];
        }

        return response()->json([
            'cust_id'  => $custId,
            'mes'      => $mes,
            'semanas'  => $semanas,
            'total'    => $total,
            'totalAds' => $totalAds,
        ]);
    }

    // ─── Helpers privados ─────────────────────────────────────────────────────

    /**
     * Render padrão "sem dados / erro" — props vazias + mensagem pt-BR.
     * Centraliza a forma das props para os caminhos de saída defensivos.
     * statusDist zerado: shape consistente mesmo sem dados (D-12).
     */
    private function semDados(string $mensagem): \Inertia\Response
    {
        return Inertia::render('Polos/Index', [
            'polos'            => [],
            'statusDist'       => ['Sim' => 0, 'Em progresso' => 0, 'Não' => 0, 'Problema' => 0, 'total' => 0],
            'meses'            => [],
            'mesSelecionado'   => null,
            'mesRefLabel'      => null,
            'parcial'          => false,
            'fonteFaturamento' => 'csv',
            'adsLimites'       => ['teto' => 3000, 'alerta1' => 1000, 'alerta2' => 2000],
            'm1'               => ['total' => 0, 'faturando' => 0, 'nao' => 0, 'faturamento' => 0, 'empresas' => [], 'polos' => []],
            'erro'             => $mensagem,
        ]);
    }

    /**
     * Gasto de ADS REAL do mês por cust_id normalizado, lido do snapshot durável
     * (PoloFaturamentoSnapshot.ads).
     *
     * A fonte do ADS é a SOMA do `investment` dos adgroups da Adman
     * (/ads/{cust}/adgroups/metrics), gravada pelo SyncPolosFaturamentoJob via
     * AdmanService::fetchAdsInvestmentTotal — NÃO o summarizedData.investment do
     * /performance, que vem 0 para a maioria dos polos (e fazia o ADS do /polos vir
     * muito menor que a planilha). Empresa sem snapshot → sai sem ADS (R$0) até o
     * próximo sync. Lê só do banco (sem HTTP): a request nunca chama a Adman.
     *
     * @param  array<array<string,mixed>>  $ativos  Ativos M2–M4 (toArray)
     * @param  string  $mesSel  TIM_MONTH_ID 'YYYYMM' do mês exibido
     * @return array<string,float>  [cust_id normalizado => ADS]
     */
    private function adsAdmanDoMes(array $ativos, string $mesSel): array
    {
        try {
            $custIds = collect($ativos)
                ->map(fn ($a) => CustId::normaliza((string) ($a['cust_id'] ?? '')))
                ->filter(fn ($id) => $id !== '')
                ->unique()
                ->values()
                ->all();

            if (empty($custIds)) {
                return [];
            }

            return PoloFaturamentoSnapshot::where('mes', $mesSel)
                ->whereIn('cust_id', $custIds)
                ->pluck('ads', 'cust_id')
                ->map(fn ($v) => (float) $v)
                ->all();
        } catch (\Throwable $e) {
            // Defensiva: erro de banco NÃO quebra /polos — ADS vira R$0.
            Log::warning('[Polos] Falha ao ler ADS do snapshot: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Faturamento ao vivo via Adman para o mês CORRENTE/parcial, por cust_id
     * normalizado. Usado em vez do TGMV_LC do CSV (que atrasa no mês corrente).
     *
     * Estratégia (spike 2026-06-15, alinhada com DashboardController):
     *   1. SOMENTE cache (getCachedGrossBillingsMany) — instantâneo, sem HTTP. A
     *      request NUNCA busca da Adman de forma síncrona: o throttle (~7s/cust_id
     *      em cache frio) inviabiliza N grande (ex.: 77 polos → ~9min → timeout).
     *      O cache é aquecido FORA da request (comando/queue), 24h de TTL.
     *   2. 100% defensivo: cust_id sem cache → ausência no mapa, e o chamador cai
     *      de volta no TGMV_LC do CSV. A página nunca quebra nem bloqueia.
     *
     * @param  array<array<string,mixed>>  $ativos  Ativos M2–M4 (toArray)
     * @param  string  $mesSel  TIM_MONTH_ID 'YYYYMM' do mês exibido
     * @return array<string,float>  [cust_id normalizado => gross_billing]
     */
    private function faturamentoAdmanDoMes(array $ativos, string $mesSel): array
    {
        try {
            $custIds = collect($ativos)
                ->map(fn ($a) => CustId::normaliza((string) ($a['cust_id'] ?? '')))
                ->filter(fn ($id) => $id !== '')
                ->unique()
                ->values()
                ->all();

            if (empty($custIds)) {
                return [];
            }

            // Janela do mês: primeiro ao último dia (mesma do spike).
            $de  = substr($mesSel, 0, 4) . '-' . substr($mesSel, 4, 2) . '-01';
            $ate = date('Y-m-t', strtotime($de));

            // SOMENTE cache (sem HTTP) — round-trip único, igual ao DashboardController.
            // Cust_id sem cache NÃO é buscado aqui (evita ~7s/cust_id no render); cai
            // no TGMV_LC do CSV no chamador até o cache aquecer fora da request.
            $cache     = $this->adman->getCachedGrossBillingsMany($custIds, $de, $ate);
            $out       = [];
            $faltantes = [];
            foreach ($custIds as $id) {
                if (! empty($cache[$id]['hasEntry']) && $cache[$id]['value'] !== null) {
                    $out[$id] = (float) $cache[$id]['value'];
                } else {
                    $faltantes[] = $id;
                }
            }

            // Fallback durável: cust_ids sem cache do dia (chave fria — logo após a
            // meia-noite BRT, quando o cacheDay rotaciona, ou após flush/restart do Redis)
            // são preenchidos com o último faturamento sincronizado e persistido no
            // snapshot. Sem isto a página zerava para R$0 ao virar o dia. O cache do dia
            // (mais fresco) continua sendo a fonte preferencial — só preenche o que faltou.
            $doSnapshot = 0;
            if (! empty($faltantes)) {
                $snaps = PoloFaturamentoSnapshot::where('mes', $mesSel)
                    ->whereIn('cust_id', $faltantes)
                    ->pluck('faturamento', 'cust_id');
                foreach ($faltantes as $id) {
                    if (isset($snaps[$id])) {
                        $out[$id] = (float) $snaps[$id];
                        $doSnapshot++;
                    }
                }
            }

            $semDado = count($faltantes) - $doSnapshot;
            if ($semDado > 0) {
                Log::info("[Polos] Adman: {$semDado} cust_ids sem cache nem snapshot no mês corrente — R\$0 até o próximo sync.");
            }

            return $out;
        } catch (\Throwable $e) {
            // Defensiva: Adman fora do ar NÃO quebra /polos — cai no CSV.
            Log::warning('[Polos] Falha ao buscar faturamento Adman do mês corrente: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Lista os meses distintos presentes no CSV (coluna TIM_MONTH_ID), em ordem
     * decrescente (mais recente primeiro). Marca como `parcial` o mês que tiver
     * qualquer linha com COMPARATIVO != FECHADO (mês corrente ainda enchendo).
     *
     * @param  array<array<string,mixed>>  $linhas
     * @return array<array{value:string,label:string,parcial:bool}>
     */
    private function listarMeses(array $linhas): array
    {
        $mapa = []; // value(YYYYMM) => parcial(bool)
        foreach ($linhas as $row) {
            $mes = trim((string) ($row['TIM_MONTH_ID'] ?? $row['tim_month_id'] ?? ''));
            if ($mes === '') {
                continue;
            }
            $comp    = strtoupper(trim((string) ($row['COMPARATIVO'] ?? $row['comparativo'] ?? '')));
            $parcial = $comp !== '' && $comp !== 'FECHADO';
            $mapa[$mes] = ($mapa[$mes] ?? false) || $parcial;
        }

        krsort($mapa); // desc → mês mais recente primeiro

        $out = [];
        foreach ($mapa as $value => $parcial) {
            $value = (string) $value; // PHP converte chave numérica em int; normaliza
            $out[] = [
                'value'   => $value,
                'label'   => $this->mesLabel($value),
                'parcial' => $parcial,
            ];
        }

        return $out;
    }

    /**
     * Converte TIM_MONTH_ID 'YYYYMM' no rótulo pt-BR 'Mês/Ano' (ex: '202606' → 'Junho/2026').
     */
    private function mesLabel(string $mes): string
    {
        if (strlen($mes) < 6) {
            return $mes;
        }

        $nomes = [
            1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março',    4 => 'Abril',
            5 => 'Maio',    6 => 'Junho',     7 => 'Julho',    8 => 'Agosto',
            9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro',
        ];

        $ano = substr($mes, 0, 4);
        $num = (int) substr($mes, 4, 2);

        return ($nomes[$num] ?? $mes) . '/' . $ano;
    }

    /**
     * Monta o array de "ativos" (M2–M4) do mês selecionado, escolhendo a fonte
     * conforme o mês seja corrente/parcial ou já fechado.
     *
     * Mês PARCIAL/corrente → estado AO VIVO do ECF (MlbEmpresa), curado pela equipe.
     * Mês FECHADO → reconstrói o roster HISTÓRICO daquele mês a partir do CSV. O
     *   MlbEmpresa só guarda o estado de hoje (fase atual); usá-lo para um mês
     *   passado mostraria o roster de hoje (ex.: 85 empresas) em vez do real
     *   daquele mês (ex.: ~45 em abril), porque empresas entram e saem do programa.
     *
     * Regra de reconstrução (validada empiricamente contra o mês corrente, onde os
     * 85 ativos do ECF batem com o MESES_NO_PROGRAMA do CSV):
     *   Fase = MESES_NO_PROGRAMA + 1 → meses=1 vira M2, meses=2 vira M3, meses=3 vira M4.
     *   Exclui meses=0 (M1, onboarding sem meta) e meses>=4 (já saiu do programa de 4 meses).
     *   O flag `problema` é histórico-indisponível no CSV → assume false.
     *
     * @param  ?string                       $mesSel     Mês exibido (YYYYMM) ou null
     * @param  bool                          $parcial    true = mês ainda enchendo (corrente)
     * @param  array<array<string,mixed>>    $linhasMes  Linhas do CSV do mês selecionado
     * @return array<array<string,mixed>>                Ativos com a mesma shape do MlbEmpresa::toArray()
     */
    private function montarAtivosDoMes(?string $mesSel, bool $parcial, array $linhasMes): array
    {
        // Mês corrente/parcial (ou indefinido): estado ao vivo do ECF, curado (D-02).
        if ($parcial || $mesSel === null) {
            return MlbEmpresa::whereIn('fase', ['M2', 'M3', 'M4'])
                ->where('projeto', 'POLOS')
                ->get(['id', 'nome', 'cust_id', 'polo', 'fase', 'problema', 'problema_nota', 'ads_desligado'])
                ->toArray();
        }

        // Mês fechado: reconstrói o roster histórico a partir do CSV daquele mês.
        $faseDeMeses = static fn (int $m): ?string => match ($m) {
            1       => 'M2',
            2       => 'M3',
            3       => 'M4',
            default => null, // 0 = M1 (excluído); >=4 = já saiu do programa
        };

        $ativos = [];
        foreach ($linhasMes as $row) {
            $meses = (int) ($row['MESES_NO_PROGRAMA'] ?? $row['meses_no_programa'] ?? -1);
            $fase  = $faseDeMeses($meses);
            if ($fase === null) {
                continue;
            }

            $id = CustId::normaliza((string) ($row['CUS_CUST_ID_SEL'] ?? $row['cus_cust_id_sel'] ?? ''));
            if ($id === '' || isset($ativos[$id])) {
                continue; // ignora linha sem cust_id e deduplica por empresa
            }

            $nome = trim((string) ($row['CUS_NICKNAME'] ?? $row['cus_nickname'] ?? ''));

            $ativos[$id] = [
                'cust_id'       => $id,
                'nome'          => $nome !== '' ? $nome : "Empresa {$id}",
                'polo'          => trim((string) ($row['LOCALIDADE'] ?? $row['localidade'] ?? '')),
                'fase'          => $fase,
                'problema'      => false, // flag histórico indisponível no CSV
                'problema_nota' => null,
                'ads_desligado' => null,
            ];
        }

        return array_values($ativos);
    }

    /**
     * Coorte de empresas M1 (onboarding, MESES_NO_PROGRAMA=0) do mês — FORA da meta
     * de faturamento (D-01 exclui M1 dos ativos). Visão própria com status binário:
     * "faturando" (TGMV_LC > 0) vs "não".
     *
     * Roster: MlbEmpresa ao vivo (fase=M1) no mês corrente; reconstrução do CSV
     * (MESES_NO_PROGRAMA=0) no mês fechado — mesma regra dos ativos M2–M4.
     * Faturamento: SEMPRE TGMV_LC do CSV (a Adman não aquece cache p/ M1; o TGMV é a
     * métrica oficial da planilha e existe tanto no mês corrente quanto no fechado).
     *
     * @param  ?string                       $mesSel     Mês exibido (YYYYMM) ou null
     * @param  bool                          $parcial    true = mês corrente
     * @param  array<array<string,mixed>>    $linhasMes  Linhas do CSV do mês
     * @return array{total:int,faturando:int,nao:int,faturamento:float,empresas:array,polos:array}
     */
    private function montarM1(?string $mesSel, bool $parcial, array $linhasMes): array
    {
        $lookup = $this->montarLookup($linhasMes); // cust_id => { tgmv, localidade }

        // ── Roster M1 (cust_id => nome/polo) ──
        $roster = [];
        if ($parcial || $mesSel === null) {
            // Mês corrente: estado ao vivo do ECF.
            foreach (
                MlbEmpresa::where('fase', 'M1')->where('projeto', 'POLOS')
                    ->get(['nome', 'cust_id', 'polo']) as $e
            ) {
                $id = CustId::normaliza((string) $e->cust_id);
                if ($id === '' || isset($roster[$id])) {
                    continue;
                }
                $nome = trim((string) $e->nome);
                $roster[$id] = ['nome' => $nome !== '' ? $nome : "Empresa {$id}", 'polo' => trim((string) $e->polo)];
            }
        } else {
            // Mês fechado: reconstrói pelo CSV (MESES_NO_PROGRAMA = 0 → M1).
            foreach ($linhasMes as $row) {
                $meses = (int) ($row['MESES_NO_PROGRAMA'] ?? $row['meses_no_programa'] ?? -1);
                if ($meses !== 0) {
                    continue;
                }
                $id = CustId::normaliza((string) ($row['CUS_CUST_ID_SEL'] ?? $row['cus_cust_id_sel'] ?? ''));
                if ($id === '' || isset($roster[$id])) {
                    continue;
                }
                $nome = trim((string) ($row['CUS_NICKNAME'] ?? $row['cus_nickname'] ?? ''));
                $roster[$id] = [
                    'nome' => $nome !== '' ? $nome : "Empresa {$id}",
                    'polo' => trim((string) ($row['LOCALIDADE'] ?? $row['localidade'] ?? '')),
                ];
            }
        }

        // Faturamento M1 via Adman (gross_billing, só-cache + snapshot) — igual aos
        // ativos M2–M4; NÃO usa TGMV do CSV. O CSV serve só p/ localidade (abaixo).
        $m1Ativos = array_map(fn ($id) => ['cust_id' => $id], array_keys($roster));
        $fatMap   = $mesSel !== null ? $this->faturamentoAdmanDoMes($m1Ativos, $mesSel) : [];

        // ── Agregação: faturando = gross_billing (Adman) > 0 ──
        $empresas  = [];
        $porPolo   = [];
        $faturando = 0;
        $totalFat  = 0.0;
        foreach ($roster as $id => $r) {
            $csv   = $lookup[$id] ?? null;
            $fat   = $fatMap[$id] ?? 0.0;
            $polo  = ($csv !== null && $csv['localidade'] !== '') ? $csv['localidade'] : ($r['polo'] ?: 'Sem polo');
            $isFat = $fat > 0;
            if ($isFat) {
                $faturando++;
            }
            $totalFat += $fat;

            $empresas[] = [
                'cust_id'     => $id,
                'nome'        => $r['nome'],
                'polo'        => $polo,
                'faturamento' => $fat,
                'faturando'   => $isFat,
            ];

            if (! isset($porPolo[$polo])) {
                $porPolo[$polo] = ['polo' => $polo, 'total' => 0, 'faturando' => 0, 'faturamento' => 0.0];
            }
            $porPolo[$polo]['total']++;
            if ($isFat) {
                $porPolo[$polo]['faturando']++;
            }
            $porPolo[$polo]['faturamento'] += $fat;
        }

        usort($empresas, fn ($a, $b) => $b['faturamento'] <=> $a['faturamento']);
        $polos = array_values($porPolo);
        usort($polos, fn ($a, $b) => $b['faturamento'] <=> $a['faturamento']);

        $total = count($roster);

        return [
            'total'       => $total,
            'faturando'   => $faturando,
            'nao'         => $total - $faturando,
            'faturamento' => $totalFat,
            'empresas'    => $empresas,
            'polos'       => $polos,
        ];
    }

    /**
     * Monta o mapa [cust_id => faturamento] do mês, escolhendo a fonte conforme o mês:
     *   - corrente/parcial → Adman ao vivo (gross_billing), mais fresco que o CSV;
     *   - fechado → TGMV_LC oficial do CSV — mesma métrica da planilha e cobre as
     *     empresas que já saíram do programa (a Adman não guarda histórico delas,
     *     o que zerava ~2/3 do faturamento do mês no roster reconstruído).
     *
     * @param  ?string                       $mesSel     Mês exibido (YYYYMM) ou null
     * @param  bool                          $parcial    true = mês ainda enchendo (corrente)
     * @param  array<array<string,mixed>>    $ativos     Ativos do mês (p/ a busca Adman)
     * @param  array<array<string,mixed>>    $linhasMes  Linhas do CSV do mês selecionado
     * @return array{0: array<string,float>, 1: string}  [mapa cust_id=>fat, fonte('adman'|'csv')]
     */
    private function faturamentoDoMes(?string $mesSel, bool $parcial, array $ativos, array $linhasMes): array
    {
        // Faturamento 100% da Adman (gross_billing) — SÓ-CACHE + fallback no snapshot
        // mensal. NUNCA do CSV (decisão de produto: faturamento só via API). O mês
        // fechado lê do snapshot capturado quando aquele mês era corrente; cust_id sem
        // cache nem snapshot → R$0. O CSV segue apenas para lista de meses e localidade
        // ($parcial/$linhasMes mantidos na assinatura por compatibilidade dos callers).
        $fat = $mesSel !== null ? $this->faturamentoAdmanDoMes($ativos, $mesSel) : [];

        return [$fat, 'adman'];
    }

    /**
     * Converte número no formato pt-BR do CSV ("129402,86" / "1.234,56") para float.
     * Remove separador de milhar "." e troca a vírgula decimal por ".". O cast direto
     * `(float)` truncava em "129402,86" → 129402 (perdia os centavos).
     */
    private function parseNumeroBr(string $raw): float
    {
        $raw = trim($raw);
        if ($raw === '') {
            return 0.0;
        }

        return (float) str_replace(['.', ','], ['', '.'], $raw);
    }

    /**
     * Indexa as linhas do CSV por cust_id normalizado para o join com os ativos.
     * Pitfall 1 (RESEARCH.md): CUS_CUST_ID_SEL está no formato "2425054445,0" —
     * CustId::normaliza() converte para "2425054445" (mesmo formato de MlbEmpresa.cust_id).
     *
     * @param  array<array<string,mixed>>  $linhasMes  Linhas do mês selecionado
     * @return array<string, array{tgmv: float, localidade: string}>
     */
    private function montarLookup(array $linhasMes): array
    {
        $lookup = [];

        foreach ($linhasMes as $row) {
            // Acesso dual-format defensivo: colunas UPPER_CASE confirmadas no spike
            $rawId = (string) ($row['CUS_CUST_ID_SEL'] ?? $row['cus_cust_id_sel'] ?? '');
            $id    = CustId::normaliza($rawId);

            // Ignora linhas sem cust_id válido
            if ($id === '') {
                continue;
            }

            $lookup[$id] = [
                'tgmv'       => $this->parseNumeroBr((string) ($row['TGMV_LC'] ?? $row['tgmv_lc'] ?? '')),
                'localidade' => trim((string) ($row['LOCALIDADE'] ?? $row['localidade'] ?? '')),
            ];
        }

        return $lookup;
    }

    /**
     * Calcula o status de uma empresa com base na precedência D-11 (CONTEXT.md):
     *   Problema (flag MlbEmpresa.problema=true) → maior precedência
     *   Não     (faturamento <= 0) → ativo sem dado ou zerado (D-12)
     *   Sim     (faturamento >= limiar do estágio) → bateu a meta
     *   Em progresso (0 < faturamento < limiar) → menor precedência
     *
     * @param  bool   $problema  Flag da empresa (MlbEmpresa.problema)
     * @param  float  $fat       Faturamento TGMV_LC (0 quando ausente no CSV)
     * @param  float  $limiar    Meta do estágio (M2=1k, M3=4k, M4=8k)
     * @return string            Status: 'Problema' | 'Não' | 'Sim' | 'Em progresso'
     */
    private function calcularStatus(bool $problema, float $fat, float $limiar): string
    {
        if ($problema) {
            return 'Problema';
        }

        if ($fat <= 0) {
            return 'Não';
        }

        if ($fat >= $limiar) {
            return 'Sim';
        }

        return 'Em progresso';
    }

    /**
     * Determina o pior status agregado de um polo (pior caso entre os seus ativos).
     * Prioridade: Problema > Não > Em progresso > Sim.
     *
     * @param  string[]  $statuses  Status de cada empresa do polo
     * @return string               Status com maior prioridade
     */
    private function statusAgregado(array $statuses): string
    {
        $prioridade = ['Problema' => 4, 'Não' => 3, 'Em progresso' => 2, 'Sim' => 1];

        $melhor = 'Sim';
        foreach ($statuses as $s) {
            if (($prioridade[$s] ?? 0) > ($prioridade[$melhor] ?? 0)) {
                $melhor = $s;
            }
        }

        return $melhor;
    }

    /**
     * Agrega os ativos M2–M4 por polo, cruzando com o lookup do CSV por cust_id.
     *
     * Pitfall 3 (RESEARCH.md): itera sobre os ATIVOS (não sobre o CSV).
     * Ativo ausente no CSV → faturamento R$0, status 'Não' — NÃO descartado (D-12).
     *
     * Polo: usa LOCALIDADE do CSV quando disponível; fallback MlbEmpresa.polo (D-15).
     * Meta por polo: soma dos limiares dos ativos (D-13) — NUNCA ativos × 3.000 (D-09).
     *
     * @param  array<array<string,mixed>>  $ativos     Ativos M2–M4 do ECF (toArray)
     * @param  array<array<string,mixed>>  $linhasMes  Linhas do CSV do mês selecionado
     * @param  array<string,float>         $limiares   ['M2'=>1000, 'M3'=>4000, 'M4'=>8000]
     * @param  array<string,float>         $fatAdman   [cust_id => gross_billing] do mês corrente (vazio em mês fechado)
     * @param  array<string,float>         $adsAdman   [cust_id => investment ADS] do mês corrente (vazio quando sem cache)
     * @return array<array<string,mixed>>              Polos agregados, ordenados por nome
     */
    private function agregarPorPolo(array $ativos, array $linhasMes, array $limiares, array $fatAdman = [], array $adsAdman = []): array
    {
        $lookup = $this->montarLookup($linhasMes);
        $grupos = []; // polo → { fat[], limiar[], statuses[] }

        foreach ($ativos as $ativo) {
            // Normaliza o cust_id do ativo para o mesmo formato do lookup
            $id  = CustId::normaliza((string) ($ativo['cust_id'] ?? ''));
            $csv = $id !== '' ? ($lookup[$id] ?? null) : null;

            // Faturamento: SEMPRE da Adman (gross_billing). cust_id sem dado na
            // Adman → R$0 (sem fallback CSV). O CSV aqui serve só p/ LOCALIDADE.
            $tgmv = $fatAdman[$id] ?? 0.0;

            // Gasto de ADS do mês corrente (investment Adman). Sem cache → R$0.
            $ads = $adsAdman[$id] ?? 0.0;

            // D-15: polo vem de LOCALIDADE do CSV quando disponível; fallback MlbEmpresa.polo
            $localidade = ($csv !== null && $csv['localidade'] !== '')
                ? $csv['localidade']
                : ($ativo['polo'] ?: 'Sem polo');

            $limiar = (float) ($limiares[$ativo['fase']] ?? 0);
            $status = $this->calcularStatus((bool) $ativo['problema'], $tgmv, $limiar);

            if (! isset($grupos[$localidade])) {
                $grupos[$localidade] = ['faturamentos' => [], 'limiares' => [], 'statuses' => [], 'empresas' => []];
            }

            $grupos[$localidade]['faturamentos'][] = $tgmv;
            $grupos[$localidade]['limiares'][]      = $limiar;
            $grupos[$localidade]['statuses'][]      = $status;

            // Detalhe por empresa (para o painel de detalhe ao clicar no polo).
            $grupos[$localidade]['empresas'][] = [
                'cust_id'       => $id,
                'nome'          => $ativo['nome'] ?? "Empresa {$id}",
                'fase'          => $ativo['fase'],
                'faturamento'   => $tgmv,
                'meta'          => $limiar,
                'pct'           => $limiar > 0 ? round($tgmv / $limiar * 100, 1) : 0.0,
                'status'        => $status,
                'ads'           => $ads,
                'problema'      => (bool) ($ativo['problema'] ?? false),
                'problema_nota' => $ativo['problema_nota'] ?? null,
                'ads_desligado' => isset($ativo['ads_desligado']) ? (bool) $ativo['ads_desligado'] : null,
            ];
        }

        $resultado = [];
        foreach ($grupos as $polo => $dados) {
            $faturamento = array_sum($dados['faturamentos']);
            // D-13: meta = soma dos limiares individuais dos ativos do polo
            $meta        = array_sum($dados['limiares']);
            $pct         = $meta > 0 ? round($faturamento / $meta * 100, 1) : 0.0;
            $ativos_n    = count($dados['faturamentos']);
            $status      = $this->statusAgregado($dados['statuses']);

            // Empresas ordenadas por faturamento desc (maior primeiro).
            $empresas = $dados['empresas'];
            usort($empresas, fn ($a, $b) => ($b['faturamento'] <=> $a['faturamento']));

            $resultado[] = [
                'polo'        => $polo,
                'ativos'      => $ativos_n,
                'faturamento' => $faturamento,
                'meta'        => $meta,
                'pct'         => $pct,
                'status'      => $status,
                'empresas'    => $empresas,
            ];
        }

        // Ordenar alfabeticamente por nome do polo (usort com strcmp)
        usort($resultado, fn ($a, $b) => strcmp($a['polo'], $b['polo']));

        return $resultado;
    }

    /**
     * Calcula a distribuição de status entre todos os ativos M2–M4 (D-14).
     *
     * Retorna os 4 contadores Sim/Em progresso/Não/Problema + total,
     * para alimentar a vista de distribuição (replica "Gráfico Junho" da planilha).
     *
     * @param  array<array<string,mixed>>  $ativos     Ativos M2–M4 do ECF (toArray)
     * @param  array<array<string,mixed>>  $linhasMes  Linhas do CSV do mês selecionado
     * @param  array<string,float>         $limiares   ['M2'=>1000, 'M3'=>4000, 'M4'=>8000]
     * @param  array<string,float>         $fatAdman   [cust_id => gross_billing] do mês corrente (vazio em mês fechado)
     * @return array{Sim:int,'Em progresso':int,'Não':int,Problema:int,total:int}
     */
    private function distribuicaoStatus(array $ativos, array $linhasMes, array $limiares, array $fatAdman = []): array
    {
        $lookup = $this->montarLookup($linhasMes);

        $contadores = ['Sim' => 0, 'Em progresso' => 0, 'Não' => 0, 'Problema' => 0];

        foreach ($ativos as $ativo) {
            $id   = CustId::normaliza((string) ($ativo['cust_id'] ?? ''));
            // Faturamento sempre da Adman (gross_billing); sem dado → R$0.
            $tgmv = $fatAdman[$id] ?? 0.0;

            $limiar = (float) ($limiares[$ativo['fase']] ?? 0);
            $status = $this->calcularStatus((bool) $ativo['problema'], $tgmv, $limiar);

            $contadores[$status]++;
        }

        $contadores['total'] = count($ativos);

        return $contadores;
    }
}
