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
use App\Models\MlbConfiguracao;
use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
use App\Models\PoloFaturamentoManual;
use App\Models\PoloFaturamentoSnapshot;
use App\Models\PoloAdsBonificacao;
use App\Models\PoloAdsStatus;
use App\Models\PoloMetaEntrada;
use App\Models\PoloRosterSnapshot;
use App\Models\PoloTarefaMeta;
use App\Models\PolosComentario;
use App\Models\Ppa;
use App\Models\PolosReuniaoMes;
use App\Models\User;
use App\Services\AdmanService;
use App\Services\EcfDriveService;
use App\Services\Polos\VinculoAdmanPolos;
use App\Support\CustId;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
 * Acesso: admin OU permissão mlb.faturamento_polos (setor Polos). Gate inline
 * via checkFaturamentoAccess() — as rotas polos.* NÃO estão mais no grupo role:admin.
 */
class PolosController extends Controller
{
    /**
     * Status das linhas M1 em /polos/empresas. M1 é onboarding e não tem meta (D-01): a linha
     * existe para acompanhar ADS e faturamento, e NUNCA cai em No alvo / Em progresso / Não —
     * esses chips têm de bater com a Distribuição de status, que é só M2–M4.
     */
    private const STATUS_SEM_META = 'Sem meta';

    /**
     * Gate das telas de Faturamento Polos (index/todasEmpresas/sync/semanal).
     * Admin sempre; demais precisam da permissão dedicada mlb.faturamento_polos,
     * concedida ao setor Polos. NÃO reusa mlb.projetos p/ não vazar o financeiro
     * a quem tem projetos de Publicação.
     */
    private function checkFaturamentoAccess(): void
    {
        $user = auth()->user();
        abort_unless(
            $user && ($user->isAdmin() || $user->hasPermission('mlb.faturamento_polos')),
            403
        );
    }

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
        $this->checkFaturamentoAccess();

        // Wrapper fino: toda a montagem vive em montarCockpit() para ser reusada
        // pela aba unificada (painel()) sem duplicar lógica nem divergir de shape.
        return Inertia::render('Polos/Index', $this->montarCockpit(request('mes')));
    }

    /**
     * Monta o pacote completo de props do Cockpit financeiro de Polos — o MESMO que
     * a página /polos consome. Extraído de index() para ser reusado pelo Painel
     * unificado (painel()), garantindo cockpit IDÊNTICO ao /polos.
     *
     * @param  string|null $mesPedido  YYYYMM solicitado (?mes); null/inválido → mês mais recente.
     * @return array  polos, statusDist, meses, mesSelecionado, mesRefLabel, parcial,
     *                fonteFaturamento, metricaFaturamento, adsLimites, m1, faturamentoPorFase, erro
     */
    public function montarCockpit(?string $mesPedido = null): array
    {
        try {
            // ─── 1. Descobrir o arquivo POLOS MENSAL ──────────────────────────
            $files = $this->ecf->listFiles(['search' => 'POLOS_MENSAL']);

            $cands = collect($files['data'] ?? []);

            // Preferir etlStatus=done se existir, senão cair no mais recente
            $done    = $cands->where('etlStatus', 'done')->sortByDesc('downloadedAt');
            $arquivo = $done->first() ?? $cands->sortByDesc('downloadedAt')->first();

            if (! $arquivo) {
                return $this->cockpitVazio('Arquivo CSV POLOS MENSAL não encontrado no ECF Drive.');
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
            $mesPedido = trim((string) ($mesPedido ?? ''));
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
            // $manuais = lançamentos à mão do mês (só valem onde a Adman não tem dado).
            [$fatMes, $fonteFaturamento, $manuais] = $this->faturamentoDoMes($mesSel, $parcial, $ativos, $linhasMes);

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
            $polos      = $this->agregarPorPolo($ativos, $linhasMes, $limiares, $fatMes, $adsMes, $manuais);
            $statusDist = $this->distribuicaoStatus($ativos, $linhasMes, $limiares, $fatMes);

            // ─── 9. Empresas M1 (onboarding) — FORA da meta; visão própria ────
            // M1 é excluído dos ativos (D-01). Aqui montamos uma coorte separada com
            // status binário (faturando vs não) para o gráfico dedicado de M1.
            $m1 = $this->montarM1($mesSel, $parcial, $linhasMes);

            // ─── 10. Faturamento por fase (M1–M4) — o TOTAL do projeto ────────
            // A meta segue só sobre M2–M4 (polos[].faturamento); o total soma M1 também.
            $porFase = $this->faturamentoPorFase($ativos, $fatMes, $m1);

            return [
                'polos'            => $polos,
                'statusDist'       => $statusDist,
                'meses'            => $meses,
                'mesSelecionado'   => $mesSel,
                'mesRefLabel'      => $mesAtual['label'] ?? null,
                'parcial'          => $parcial,
                'fonteFaturamento' => $fonteFaturamento,
                // 'moveis' | 'gross' — a tela ROTULA o número por aqui. O painel já mostrou
                // móveis sob o título 'Faturamento total' por 2 meses; não repetir.
                'metricaFaturamento' => $this->metricaFaturamento(),
                'adsLimites'       => $adsLimites,
                // Meta ÚNICA de faturamento do projeto Polos (R$), editável no painel.
                // NÃO é a soma das metas por empresa (limiar×ativos) — é um alvo global.
                'metaFaturamento'  => (float) Configuracao::get('polo_meta_faturamento', 3200000),
                'm1'               => $m1,
                'faturamentoPorFase' => $porFase,
                // Libera o lápis de faturamento manual na tela (ver podeLancarFaturamentoManual).
                'podeLancarFaturamento' => $this->podeLancarFaturamentoManual(auth()->user()),
                'erro'             => null,
            ];
        } catch (\Throwable $e) {
            // Defensiva: ECF Drive offline NÃO quebra a aba
            report($e);
            return $this->cockpitVazio('Não foi possível buscar dados do ECF Drive. Tente em alguns segundos.');
        }
    }

    /**
     * DIAGNÓSTICO read-only (não altera nada): reconcilia empresa-a-empresa o faturamento que o
     * painel mostra (Adman gross_billing) contra o TGMV_LC do CSV (métrica da planilha), p/ medir
     * o gap e a causa dominante — métrica (gross×TGMV), inclusão de Fechamento no roster, ou
     * empresas zeradas no CSV mas com venda na Adman. Reusa o MESMO pipeline do cockpit.
     * Consumido por `php artisan polos:audit-faturamento`.
     *
     * @return array{mes:?string,mesLabel:?string,parcial:bool,nAtivos:int,nFechamento:int,
     *   sumAdman:float,sumCsv:float,diffTotal:float,sumFechamentoAdman:float,sumFechamentoCsv:float,
     *   distAdman:array,distCsv:array,nZeradasCsvComGross:int,sumZeradasCsvComGross:float,linhas:array}
     */
    public function auditarFaturamento(?string $mesPedido = null): array
    {
        $files   = $this->ecf->listFiles(['search' => 'POLOS_MENSAL']);
        $cands   = collect($files['data'] ?? []);
        $done    = $cands->where('etlStatus', 'done')->sortByDesc('downloadedAt');
        $arquivo = $done->first() ?? $cands->sortByDesc('downloadedAt')->first();
        if (! $arquivo) {
            throw new \RuntimeException('Arquivo CSV POLOS MENSAL não encontrado no ECF Drive.');
        }

        $resp   = $this->ecf->fileJson($arquivo['id'], ['limit' => 5000]);
        $linhas = $resp['rows'] ?? [];

        $meses     = $this->listarMeses($linhas);
        $valores   = array_column($meses, 'value');
        $mesPedido = trim((string) ($mesPedido ?? ''));
        $mesSel    = in_array($mesPedido, $valores, true) ? $mesPedido : ($valores[0] ?? null);

        $linhasMes = $mesSel === null ? [] : array_values(array_filter(
            $linhas,
            fn ($r) => (string) ($r['TIM_MONTH_ID'] ?? $r['tim_month_id'] ?? '') === $mesSel,
        ));

        $mesAtual = collect($meses)->firstWhere('value', $mesSel);
        $parcial  = (bool) ($mesAtual['parcial'] ?? false);

        $limiares = [
            'M2' => (float) Configuracao::get('polo_limiar_m2', 1000),
            'M3' => (float) Configuracao::get('polo_limiar_m3', 4000),
            'M4' => (float) Configuracao::get('polo_limiar_m4', 8000),
        ];

        $ativos = $this->montarAtivosDoMes($mesSel, $parcial, $linhasMes);
        $adman  = $mesSel !== null ? $this->faturamentoAdmanDoMes($ativos, $mesSel) : [];
        $lookup = $this->montarLookup($linhasMes);

        $linhasOut = [];
        foreach ($ativos as $a) {
            $id     = CustId::normaliza((string) ($a['cust_id'] ?? ''));
            $gross  = (float) ($adman[$id] ?? 0.0);
            $tgmv   = (float) ($lookup[$id]['tgmv'] ?? 0.0);
            $limiar = (float) ($limiares[$a['fase']] ?? 0);
            // Só o problema marcado como "fora da meta" muda o status (quick 260805-dzu).
            $prob   = $this->desconsideraDaMeta($a);
            $linhasOut[] = [
                'cust_id'      => $id,
                'nome'         => (string) ($a['nome'] ?? ''),
                'polo'         => (($lookup[$id]['localidade'] ?? '') ?: (string) ($a['polo'] ?? '')),
                'fase'         => (string) ($a['fase'] ?? ''),
                'adman'        => $gross,
                'csv'          => $tgmv,
                'diff'         => $gross - $tgmv,
                'no_csv'       => isset($lookup[$id]),
                'status_adman' => $this->calcularStatus($prob, $gross, $limiar),
                'status_csv'   => $this->calcularStatus($prob, $tgmv, $limiar),
            ];
        }

        $fech       = array_values(array_filter($linhasOut, fn ($l) => $l['fase'] === 'Fechamento'));
        $zeradasCsv = array_values(array_filter($linhasOut, fn ($l) => $l['csv'] <= 0 && $l['adman'] > 0));

        $distAdman = ['Sim' => 0, 'Em progresso' => 0, 'Não' => 0, 'Problema' => 0];
        $distCsv   = ['Sim' => 0, 'Em progresso' => 0, 'Não' => 0, 'Problema' => 0];
        foreach ($linhasOut as $l) {
            $distAdman[$l['status_adman']]++;
            $distCsv[$l['status_csv']]++;
        }

        return [
            'mes'                   => $mesSel,
            'mesLabel'              => $mesAtual['label'] ?? null,
            'parcial'               => $parcial,
            'nAtivos'               => count($linhasOut),
            'nFechamento'           => count($fech),
            'sumAdman'              => array_sum(array_column($linhasOut, 'adman')),
            'sumCsv'                => array_sum(array_column($linhasOut, 'csv')),
            'diffTotal'             => array_sum(array_column($linhasOut, 'diff')),
            'sumFechamentoAdman'    => array_sum(array_column($fech, 'adman')),
            'sumFechamentoCsv'      => array_sum(array_column($fech, 'csv')),
            'distAdman'             => $distAdman,
            'distCsv'               => $distCsv,
            'nZeradasCsvComGross'   => count($zeradasCsv),
            'sumZeradasCsvComGross' => array_sum(array_column($zeradasCsv, 'adman')),
            'linhas'                => $linhasOut,
        ];
    }

    /**
     * Painel unificado de Polos (aba NOVA e ADITIVA — /polos, /mlb/polos-empresas e
     * /mlb/implementacao seguem intactas). Modelo "planilha": tabela plana ancorada
     * em MlbEmpresa projeto=POLOS, com TODOS os campos do onboarding (Acessos/Produtos/
     * Logística) editáveis INLINE — sem abrir a ficha. O front organiza em "lentes"
     * (Geral/Acessos/Produtos/Logística/Financeiro) que reusam os blocos da ficha.
     *
     * Este método monta SÓ o payload OPERACIONAL (só DB — rápido), para a edição inline
     * ser instantânea (cada célula salva via a rota do bloco e recarrega só o operacional,
     * sem tocar o ECF Drive). A camada FINANCEIRA vem separada e sob demanda em
     * painelFinanceiro() (JSON admin-only) — anti-vazamento + edição inline rápida.
     *
     * Gate (RF-8): rota no grupo mlb.* (NÃO no role:admin). Acesso operacional espelha
     * checkPubAccess('projetos') (admin OU permissão mlb.projetos). NÃO usa
     * isGestor/isLiderPub/isPublicador (bug whereHas('cargos') os torna over-permissive).
     */
    public function painel(Request $request): \Inertia\Response
    {
        $user = $request->user();

        // ── Gate operacional (mesma régua de /mlb/polos-empresas) ──
        abort_unless(
            $user->isAdmin() || $user->hasPermission('mlb.projetos'),
            403,
            'Acesso restrito ao módulo de Polos.'
        );

        // Vínculo com a Adman (TKT-0014): da última resposta da Adman por conta, que o sync
        // já grava — uma consulta para a lista toda, nenhuma chamada nova à Adman.
        $vinculoAdman = VinculoAdmanPolos::carregar();

        // Tarefas do time (TKT-0017): o "feito" de Campanha e de PPA sai destas duas leituras,
        // uma consulta cada para a lista inteira. Campanha usa a mesma regra do filtro de
        // /polos/empresas (DEV-43); PPA = a empresa tem ao menos um plano.
        $leiturasAds = PoloAdsStatus::frescos()->get()->keyBy(fn (PoloAdsStatus $l) => (string) $l->cust_id);
        $comPpa      = Ppa::whereNotNull('mlb_empresa_id')->distinct()->pluck('mlb_empresa_id')->flip();

        // Lista operacional ancorada em MlbEmpresa projeto=POLOS (só DB — sem ECF Drive).
        // ATIVAS apenas: empresas arquivadas (ausentes na planilha) saem daqui e NÃO
        // contam em nada — vão para a aba "Arquivados" (prop `arquivadas`, abaixo).
        $empresas = MlbEmpresa::ativas()
            ->with([
                'responsavel:id,name',
                'decolaMl',
                'implementacao',
                'implementacao.responsavel:id,name',
                'implementacao.linkEnviadoPor:id,name',
            ])
            ->orderBy('nome')
            ->get()
            // Projeto canônico: coluna `projeto`, com fallback por fase (empresas antigas).
            ->filter(fn ($e) => (($e->getAttributes()['projeto'] ?? null) ?: (MlbEmpresa::FASE_PARA_PROJETO[$e->fase ?? ''] ?? null)) === 'POLOS')
            ->map(function ($e) use ($vinculoAdman, $leiturasAds, $comPpa) {
                $impl  = $e->implementacao;
                $prazo = $impl?->infoPrazo();

                // "Novo" = ninguém mexeu ainda (nem na empresa, nem na ficha). O selo some
                // assim que houver a 1ª edição — updated_at passa a divergir de created_at
                // (2s de tolerância p/ o próprio insert, que grava os dois no mesmo instante).
                $mexeuEmpresa = $e->created_at && $e->updated_at && $e->created_at->diffInSeconds($e->updated_at) > 2;
                $mexeuFicha   = $impl && $impl->created_at && $impl->updated_at && $impl->created_at->diffInSeconds($impl->updated_at) > 2;
                $ehNovo       = (bool) ($e->created_at && ! $mexeuEmpresa && ! $mexeuFicha);

                $row = [
                    'id'                       => $e->id,
                    'nome'                     => $e->nome,
                    'fase'                     => $e->fase,
                    'estagio'                  => $e->estagio,
                    'polo'                     => $e->polo,
                    'prioridade'               => $e->prioridade,
                    'cust_id'                  => $e->cust_id,
                    // cust normalizado p/ casar com o mapa financeiro (mesmo normaliza do PHP).
                    'cust_norm'                => CustId::normaliza((string) ($e->cust_id ?? '')),
                    'contexto'                 => $e->contexto,
                    'problema'                 => (bool) $e->problema,
                    'problema_nota'            => $e->problema_nota,
                    // true = o problema tira a empresa da meta (status 'Problema' na
                    // Distribuição de status); false = ela segue contando (quick 260805-dzu).
                    'problema_desconsidera_meta' => (bool) $e->problema_desconsidera_meta,
                    'ads_desligado'            => (bool) $e->ads_desligado,
                    'progresso_skus'           => $e->progresso(),
                    'empresa_responsavel_nome' => $e->responsavel?->name,
                    // ── Onboarding / ficha (implementacao é HasOne nullable: pode não existir) ──
                    'impl_id'                  => $impl?->id,
                    'token'                    => $impl?->token,
                    'onboarding_progresso'     => $impl?->progresso(),
                    // ── Extras p/ o modal "Ver" (mesma forma que a tela de Onboarding espera) ──
                    'progresso'                => $impl?->progresso(),
                    'dados'                    => $impl?->dados,
                    'ultimo_acesso'            => $impl?->ultimo_acesso?->diffForHumans(),
                    'status_envio'             => $impl?->statusEnvio(),
                    // Autorização do ML pelo link do Onboarding ({link_oauth}).
                    'ml_oauth'                 => $impl?->oauthMl(),
                    'link_enviado_em'          => $impl?->link_enviado_em?->format('d/m/Y'),
                    'link_enviado_por'         => $impl?->linkEnviadoPor?->name,
                    'responsavel_id'           => $impl?->responsavel_id,
                    'responsavel_nome'         => $impl?->responsavel?->name,
                    'fora_do_prazo'            => $prazo['fora_do_prazo'] ?? null,
                    'dias_restantes'           => $prazo['dias_restantes'] ?? null,
                    'dias_decorridos'          => $prazo['dias_decorridos'] ?? null,
                    // Caminho de edição de Fase/Polo: com ficha → bloco.identificacao (parcial);
                    // sem ficha → empresas.update (exige payload completo, anexado abaixo).
                    'fase_endpoint'            => $impl ? 'bloco' : 'empresa',
                    // ── Entrada no projeto (planilha V2; null sem ficha) ──
                    'status_entrada'           => $impl?->status_entrada,
                    'chance_entrada'           => $impl?->chance_entrada,
                    'reuniao_onboarding'       => $impl?->reuniao_onboarding,
                    // ── Valores do onboarding (edição inline tipo planilha; null sem ficha) ──
                    'acesso_colaborador'       => $impl?->acesso_colaborador,
                    // Dois e-mails distintos e ambos buscáveis na barra do painel: `gmail` é a
                    // conta do ML cadastrada NA EMPRESA (existe sem ficha) e `gmail_colaborador`
                    // é o acesso que a ECF usa (mora na ficha). Quem procura pelo endereço do
                    // cliente não sabe em qual dos dois ele foi digitado.
                    'gmail'                    => $e->gmail,
                    'gmail_colaborador'        => $impl?->gmail_colaborador,
                    // Coluna "Vínculo Adman" (TKT-0014): automática e só leitura, existe sem
                    // ficha. 'Vinculado' | 'Não vinculado' | null (fase que o sync não lê ou
                    // sem Cust ID). Regra em VinculoAdmanPolos.
                    'adman_vinculo'            => $vinculoAdman->status($e->fase, $e->cust_id),
                    'adman_lido_em'            => $vinculoAdman->lidoEm($e->cust_id),
                    // Tarefas do time (TKT-0017), só leitura: situação das campanhas ('ativa',
                    // 'desligada', 'sem_campanha' ou null = não verificado) e se há PPA.
                    'ads_campanha'             => PoloAdsStatus::situacaoDaEmpresa(
                        $leiturasAds[CustId::normaliza((string) ($e->cust_id ?? ''))] ?? null,
                        $e->ads_desligado === null ? null : (bool) $e->ads_desligado,
                    ),
                    'tem_ppa'                  => isset($comPpa[$e->id]),
                    'grupo_whatsapp'           => $impl ? (bool) $impl->grupo_whatsapp : null,
                    // Link do grupo de WhatsApp (coluna "Link do Whats"; quick 260810-dv6).
                    'link_whatsapp'            => $impl?->link_whatsapp,
                    'planilha_produtos'        => $impl?->planilha_produtos,
                    'listagem'                 => $impl?->listagem,
                    'publicacao'               => $impl?->publicacao,
                    // decola é texto desde 2026-08-03 (Sim/Não/Mensagem Enviada/valor criado).
                    'decola'                   => $impl?->decola,
                    // true = o Mercado Livre confirma o Decola ativo (leitura fresca do
                    // polos:decola-status, TKT-0013): a tela mostra o selo "automático".
                    'decola_ml_ativo'          => (bool) $e->decolaMl?->ativoEFresco(),
                    'campanha_criada'          => $impl ? (bool) $impl->campanha_criada : null,
                    'central_promocao'         => $impl?->central_promocao,
                    'contextos_logistica'      => $impl?->contextos_logistica,
                    'me1'                      => $impl?->me1,
                    'integradora'              => $impl?->integradora,
                    'places'                   => $impl?->places,
                    'erp'                      => $impl?->erp,
                    // ── Respostas do CLIENTE no link do Onboarding (moram no JSON, não em
                    // coluna). Somente leitura no Painel: quem responde é o cliente, a
                    // equipe só vê e filtra. Ver MlbImplementacao::respostaChecklist().
                    'produtos_perfil'          => $impl?->respostaChecklist('produtos_perfil'),
                    // Canal + faixa: o item virou duas perguntas em 02/09/2026 e
                    // respostaChecklist() só devolveria a faixa.
                    'canais_faturamento'       => $impl?->respostaCanaisVenda(),
                    // Texto livre do item "Observações sobre publicação" (mora em
                    // `observacao`, não em `valor` — ver observacaoPublicacao()).
                    'obs_publicacao'           => $impl?->observacaoPublicacao(),
                    // Opção "quer jardinagem/otimização de anúncios" marcada abaixo da
                    // Planilha de Produtos (TKT-0010): 'Sim' ou null (desmarcado/sem ficha).
                    'jardinagem'               => $impl?->querJardinagem() ? 'Sim' : null,
                    'data_solicitacao'         => $impl?->data_solicitacao?->format('Y-m-d'),
                    // Data de cadastro/entrada da empresa no sistema (automática; existe sem ficha).
                    'data_cadastro'            => $e->created_at?->format('Y-m-d'),
                    // Selo "novo": some depois que alguém editar a empresa ou a ficha.
                    'novo'                     => $ehNovo,
                ];

                // Sem ficha: anexa o payload COMPLETO que updateEmpresa espera, para a UI
                // reenviar tudo ao mudar só a Fase/Polo (updateEmpresa zera campos omitidos).
                if (! $impl) {
                    $row['payload_empresa'] = [
                        'nome'           => $e->nome,
                        'cust_id'        => $e->cust_id,
                        'polo'           => $e->polo,
                        'gmail'          => $e->gmail,
                        'estagio'        => $e->estagio,
                        'fase'           => $e->fase,
                        'projeto'        => $e->getAttributes()['projeto'] ?? null,
                        'prioridade'     => $e->prioridade,
                        'responsavel_id' => $e->responsavel_id,
                        'contexto'       => $e->contexto,
                        'skus_estagio1'  => $e->skus_estagio1,
                        'skus_estagio2'  => $e->skus_estagio2,
                        'skus_estagio3'  => $e->skus_estagio3,
                        'prazo_estagio1' => $e->prazo_estagio1?->format('Y-m-d'),
                        'prazo_estagio2' => $e->prazo_estagio2?->format('Y-m-d'),
                        'prazo_estagio3' => $e->prazo_estagio3?->format('Y-m-d'),
                        'encerramento'   => $e->encerramento?->format('Y-m-d'),
                    ];
                }

                return $row;
            })
            ->values();

        // ── Empresas ARQUIVADAS (aba "Arquivados") — ausentes na planilha, fora do projeto.
        // Shape enxuto (só o necessário p/ listar + desarquivar); não entram em nenhuma conta.
        $arquivadas = MlbEmpresa::arquivadas()
            ->with('arquivadoPor:id,name')
            ->orderByDesc('arquivado_em')
            ->get()
            ->filter(fn ($e) => (($e->getAttributes()['projeto'] ?? null) ?: (MlbEmpresa::FASE_PARA_PROJETO[$e->fase ?? ''] ?? null)) === 'POLOS')
            ->map(fn ($e) => [
                'id'               => $e->id,
                'nome'             => $e->nome,
                'cust_id'          => $e->cust_id,
                'fase'             => $e->fase,
                'polo'             => $e->polo,
                'arquivado_em'     => $e->arquivado_em?->format('d/m/Y'),
                'arquivado_por'    => $e->arquivadoPor?->name,
                'arquivado_motivo' => $e->arquivado_motivo,
            ])
            ->values();

        return Inertia::render('Polos/Painel', [
            'isAdmin'    => $user->isAdmin(),
            'empresas'   => $empresas,
            'arquivadas' => $arquivadas,
            'usuarios'   => User::where('active', true)->orderBy('name')->get(['id', 'name']),
            // Metas de entrantes por região × mês (aba Metas). Alvos cadastrados; realizado
            // é derivado no front a partir de `empresas` (cust_id + acesso + grupo whatsapp).
            'metasEntrada' => PoloMetaEntrada::all(['polo', 'mes', 'meta']),
            // Tarefas do time com meta editável (TKT-0017): cartões da tela "Tarefas" do Modo TV
            // e da sub-aba "Tarefas do time". O "feito" é calculado no front (lib/polosTarefas).
            'tarefasMeta' => PoloTarefaMeta::with('responsavel:id,name')->orderBy('id')->get()
                ->map(fn (PoloTarefaMeta $t) => $t->paraTela())->values(),
            // "Próximos passos" do rodapé da tela Tarefas da TV (lista curta, editada na sub-aba).
            'proximosPassos' => PoloTarefaMeta::proximosPassos(),
            // Props do modal "Ver" (mesma fonte da tela de Onboarding — MlbImplementacaoController@index).
            'checklist'         => MlbImplementacao::CHECKLIST,
            'erp_opcoes'        => MlbImplementacao::ERP_OPCOES,
            'integrador_opcoes' => MlbImplementacao::INTEGRADOR_OPCOES,
            'global_padroes'    => MlbConfiguracao::implementacaoPadroes(),
            // Opções dos selects inline (espelha as opções da ficha — fonte única no model).
            'opcoes'   => [
                'polo'               => MlbImplementacao::ONB_POLO_OPCOES,
                'fase'               => MlbImplementacao::ONB_FASE_OPCOES,
                'status_entrada'     => MlbImplementacao::ONB_STATUS_ENTRADA_OPCOES,
                'chance_entrada'     => MlbImplementacao::ONB_CHANCE_ENTRADA_OPCOES,
                'reuniao_onboarding' => MlbImplementacao::ONB_REUNIAO_ONBOARDING_OPCOES,
                'acesso_colaborador' => MlbImplementacao::ONB_ACESSO_COLABORADOR_OPCOES,
                'planilha_produtos'  => MlbImplementacao::ONB_PLANILHA_PRODUTOS_OPCOES,
                'listagem'           => MlbImplementacao::ONB_LISTAGEM_OPCOES,
                'publicacao'         => MlbImplementacao::ONB_PUBLICACAO_OPCOES,
                'decola'             => MlbImplementacao::ONB_DECOLA_OPCOES,
                'central_promocao'   => MlbImplementacao::ONB_CENTRAL_PROMOCAO_OPCOES,
                'me1'                => MlbImplementacao::ONB_ME1_OPCOES,
                'integradora'        => MlbImplementacao::ONB_INTEGRADORA_OPCOES,
                'places'             => MlbImplementacao::ONB_PLACES_OPCOES,
                'erp'                => MlbImplementacao::ONB_ERP_OPCOES,
            ],
        ]);
    }

    /**
     * Camada financeira do Painel Polos (ADMIN-ONLY) — JSON assíncrono.
     *
     * Carregada pelo front após montar e ao trocar de mês, SEPARADA do payload
     * operacional para: (a) não vazar financeiro a não-admin (nem entra no HTML/props);
     * (b) manter a edição inline instantânea (operacional recarrega só DB; o ECF Drive
     * — caro — só é tocado aqui, sob demanda). Mesmo padrão dos cards async do app.
     *
     * Retorna o cockpit (idêntico ao /polos) + um mapa cust_norm → financeiro por empresa.
     */
    public function painelFinanceiro(Request $request): \Illuminate\Http\JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        $cockpit = $this->montarCockpit($request->query('mes'));

        // Mapa cust_norm → financeiro: ativos (M2-M4, do agregado por polo) + M1 (binário).
        $fin = [];
        foreach ($cockpit['polos'] as $p) {
            foreach (($p['empresas'] ?? []) as $emp) {
                $k = CustId::normaliza((string) ($emp['cust_id'] ?? ''));
                if ($k === '') {
                    continue;
                }
                $fin[$k] = [
                    'faturamento' => $emp['faturamento'],
                    // Origem do número (Adman / manual / sem dado) — a célula marca o manual.
                    'faturamento_fonte'  => $emp['faturamento_fonte'] ?? null,
                    'faturamento_manual' => $emp['faturamento_manual'] ?? null,
                    'meta'        => $emp['meta'],
                    'pct'         => $emp['pct'],
                    'status'      => $emp['status'],
                    'ads'         => $emp['ads'],
                    'tipo'        => 'ativo',
                ];
            }
        }
        foreach (($cockpit['m1']['empresas'] ?? []) as $emp) {
            $k = CustId::normaliza((string) ($emp['cust_id'] ?? ''));
            if ($k === '' || isset($fin[$k])) {
                continue;
            }
            $fin[$k] = [
                'faturamento' => $emp['faturamento'],
                'faturando'   => $emp['faturando'],
                'tipo'        => 'm1',
            ];
        }

        return response()->json(['cockpit' => $cockpit, 'financeiro' => $fin]);
    }

    /**
     * Lista ENXUTA dos sellers do Painel Polos, em JSON (TKT-0022).
     *
     * Para quem lê o Admin por máquina (o `ler_tela` do MCP, o artefato de gestão do time):
     * o prop `empresas` do painel passa de 7 MB porque cada linha leva a ficha inteira
     * (`dados`, com a planilha de produtos e o checklist) e o `payload_empresa`, e uma
     * empresa sozinha estoura o tamanho máximo de resposta do MCP. Aqui vai uma linha curta
     * por empresa, do MESMO universo do painel e com as MESMAS regras (responsável, campanha,
     * Decola automático, vínculo Adman, PPA) — inclusive o que a régua das tarefas do time
     * (lib/polosTarefas.js) precisa para contar o "feito".
     *
     * Financeiro (faturamento, meta, status da meta, ADS do mês) sai do MESMO montarPolos()
     * do /polos/empresas, aceita `?mes=AAAAMM` como ele e só vem para quem abre aquela tela
     * (admin ou mlb.faturamento_polos): os demais recebem esses campos nulos. É nulo também
     * para quem está fora da meta do mês (M0, M1 e fases terminais). Os campos operacionais
     * são o estado de HOJE, qualquer que seja o mês.
     *
     * Região: a do cadastro (a coluna Polo do painel) e, sem ela, a LOCALIDADE do CSV,
     * sempre como um dos 4 nomes oficiais (MlbImplementacao::poloOficial). Não grava nada.
     */
    public function painelSellers(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $request->user();

        // Mesmo gate operacional do painel.
        abort_unless(
            $user->isAdmin() || $user->hasPermission('mlb.projetos'),
            403,
            'Acesso restrito ao módulo de Polos.'
        );

        // Mesma régua de checkFaturamentoAccess() (/polos/empresas).
        $verFinanceiro = $user->isAdmin() || $user->hasPermission('mlb.faturamento_polos');

        $d              = null;
        $erroFinanceiro = null;
        if ($verFinanceiro) {
            try {
                $d = $this->montarPolos();
                if ($d === null) {
                    $erroFinanceiro = 'Arquivo CSV POLOS MENSAL não encontrado no ECF Drive.';
                }
            } catch (\Throwable $e) {
                report($e);
                $erroFinanceiro = 'Não foi possível buscar dados do ECF Drive. Tente em alguns segundos.';
            }
        }

        // Linhas do /polos/empresas (só quem está na meta do mês). Liga pelo id do cadastro,
        // que o mês corrente e o roster congelado trazem; só a reconstrução antiga pelo CSV
        // vem sem id e liga pelo cust. Ligar só pelo cust dava a uma empresa FORA da meta o
        // faturamento de outra com o mesmo cust (Moveis absoluto × Natture, 19/10/2026).
        $linhasFin  = collect($d ? $this->linhasDaVisaoCompleta($d, comM1: false) : []);
        $finPorId   = $linhasFin->whereNotNull('mlb_empresa_id')->keyBy('mlb_empresa_id');
        $finPorCust = $linhasFin->whereNull('mlb_empresa_id')->keyBy('cust_id');

        $vinculoAdman = VinculoAdmanPolos::carregar();
        $leiturasAds  = PoloAdsStatus::frescos()->get()->keyBy(fn (PoloAdsStatus $l) => (string) $l->cust_id);
        $comPpa       = Ppa::whereNotNull('mlb_empresa_id')->distinct()->pluck('mlb_empresa_id')->flip();

        $semRegiao = [];

        $sellers = MlbEmpresa::ativas()
            ->with([
                'responsavel:id,name',
                'decolaMl',
                // Só as colunas usadas: `dados` (planilha + checklist) é o que pesa no painel.
                'implementacao:id,empresa_id,responsavel_id,acesso_colaborador,places,decola,central_promocao,me1,listagem,publicacao',
                'implementacao.responsavel:id,name',
            ])
            ->orderBy('nome')
            ->get()
            ->filter(fn ($e) => (($e->getAttributes()['projeto'] ?? null) ?: (MlbEmpresa::FASE_PARA_PROJETO[$e->fase ?? ''] ?? null)) === 'POLOS')
            ->map(function (MlbEmpresa $e) use ($finPorId, $finPorCust, $vinculoAdman, $leiturasAds, $comPpa, &$semRegiao) {
                $impl = $e->implementacao;
                $cust = CustId::normaliza((string) ($e->cust_id ?? ''));
                $fin  = $finPorId->get($e->id) ?? ($cust !== '' ? $finPorCust->get($cust) : null);

                $regiao = MlbImplementacao::poloOficial($e->polo) ?? MlbImplementacao::poloOficial($fin['polo'] ?? null);
                if ($regiao === null && trim((string) $e->polo) !== '') {
                    $semRegiao[$e->polo] = true;
                }

                return [
                    'id'                    => $e->id,
                    'nome'                  => $e->nome,
                    'cust_id'               => $e->cust_id,
                    'fase'                  => $e->fase,
                    'estagio'               => $e->estagio,
                    'regiao'                => $regiao,
                    // Mesma regra da coluna Responsável do painel: com ficha, o da ficha; sem, o da empresa.
                    'responsavel'           => $impl ? $impl->responsavel?->name : $e->responsavel?->name,
                    'faturamento'           => $fin['faturamento'] ?? null,
                    'meta'                  => $fin['meta'] ?? null,
                    'status_meta'           => $fin['status'] ?? null,
                    'ads'                   => $fin['ads'] ?? null,
                    'ads_desligado'         => (bool) $e->ads_desligado,
                    'ads_campanha'          => PoloAdsStatus::situacaoDaEmpresa(
                        $leiturasAds[$cust] ?? null,
                        $e->ads_desligado === null ? null : (bool) $e->ads_desligado,
                    ),
                    'problema'              => (bool) $e->problema,
                    'problema_nota'         => $e->problema_nota,
                    'problema_fora_da_meta' => $this->desconsideraDaMeta([
                        'problema'                   => $e->problema,
                        'problema_desconsidera_meta' => $e->problema_desconsidera_meta,
                    ]),
                    'places'                => $impl?->places,
                    'decola'                => $impl?->decola,
                    'decola_ml_ativo'       => (bool) $e->decolaMl?->ativoEFresco(),
                    'central_promocao'      => $impl?->central_promocao,
                    'me1'                   => $impl?->me1,
                    'listagem'              => $impl?->listagem,
                    'publicacao'            => $impl?->publicacao,
                    'acesso_colaborador'    => $impl?->acesso_colaborador,
                    'adman_vinculo'         => $vinculoAdman->status($e->fase, $e->cust_id),
                    'tem_ppa'               => isset($comPpa[$e->id]),
                ];
            })
            ->values();

        return response()->json([
            'mes'                      => $d['mesSel'] ?? null,
            'mes_label'                => $d['mesAtual']['label'] ?? null,
            'parcial'                  => $d['parcial'] ?? null,
            'meses'                    => $d['meses'] ?? [],
            'financeiro'               => $d !== null,
            'erro_financeiro'          => $erroFinanceiro,
            'regioes'                  => MlbImplementacao::ONB_POLO_OPCOES,
            // Grafias do cadastro que não viraram nenhuma das 4 regiões (a linha sai sem região).
            'regioes_nao_reconhecidas' => array_keys($semRegiao),
            'total'                    => $sellers->count(),
            'sellers'                  => $sellers,
        ]);
    }

    /**
     * Colunas da planilha exportada — MESMA ordem e MESMOS rótulos da lente "Geral"
     * do Painel (`COLS_POR_LENTE.geral` em Polos/Painel.jsx), precedidas da
     * identidade (Empresa / Cust ID / Situação).
     *
     * `tipo` governa a formatação da célula no XLSX:
     *   texto | data (dd/mm/aaaa) | dinheiro (R$) | percentual (0-100)
     *
     * As `fin_*` só entram para admin — o Painel também só as mostra para admin.
     *
     * @return array<int,array{key:string,label:string,tipo:string}>
     */
    private function colunasExportacao(bool $isAdmin): array
    {
        $cols = [
            ['key' => 'nome',                'label' => 'Empresa',             'tipo' => 'texto'],
            ['key' => 'cust_id',             'label' => 'Cust ID',             'tipo' => 'texto'],
            ['key' => 'situacao',            'label' => 'Situação',            'tipo' => 'texto'],
            ['key' => 'problema_nota',       'label' => 'Nota do problema',    'tipo' => 'texto'],
            // ── Identidade / andamento (lente Geral) ──
            ['key' => 'data_cadastro',       'label' => 'Cadastro',            'tipo' => 'data'],
            ['key' => 'fase',                'label' => 'Fase',                'tipo' => 'texto'],
            ['key' => 'estagio',             'label' => 'Estágio',             'tipo' => 'texto'],
            ['key' => 'polo',                'label' => 'Polo',                'tipo' => 'texto'],
            ['key' => 'responsavel',         'label' => 'Responsável',         'tipo' => 'texto'],
            ['key' => 'onboarding',          'label' => 'Onboarding',          'tipo' => 'percentual'],
            ['key' => 'envio',               'label' => 'Envio',               'tipo' => 'texto'],
            ['key' => 'status_entrada',      'label' => 'Status entrada',      'tipo' => 'texto'],
            ['key' => 'chance_entrada',      'label' => 'Chance entrada',      'tipo' => 'texto'],
            // ── Acessos ──
            ['key' => 'acesso_colaborador',  'label' => 'Acesso colaborador',  'tipo' => 'texto'],
            ['key' => 'gmail_colaborador',   'label' => 'Gmail colaborador',   'tipo' => 'texto'],
            // Automático, da última resposta da Adman (TKT-0014) — ver VinculoAdmanPolos.
            ['key' => 'adman_vinculo',       'label' => 'Vínculo Adman',       'tipo' => 'texto'],
            ['key' => 'grupo_whatsapp',      'label' => 'Grupo WhatsApp',      'tipo' => 'texto'],
            ['key' => 'link_whatsapp',       'label' => 'Link do Whats',       'tipo' => 'texto'],
            ['key' => 'reuniao_onboarding',  'label' => 'Reunião onboarding',  'tipo' => 'texto'],
            // Resposta do cliente no link do Onboarding (JSON, não coluna).
            ['key' => 'canais_faturamento',  'label' => 'Outros canais',       'tipo' => 'texto'],
            ['key' => 'data_solicitacao',    'label' => 'Data solicitação',    'tipo' => 'data'],
            // ── Produtos ──
            ['key' => 'planilha_produtos',   'label' => 'Planilha produtos',   'tipo' => 'texto'],
            ['key' => 'listagem',            'label' => 'Listagem',            'tipo' => 'texto'],
            ['key' => 'publicacao',          'label' => 'Publicação',          'tipo' => 'texto'],
            ['key' => 'decola',              'label' => 'Decola',              'tipo' => 'texto'],
            ['key' => 'campanha_criada',     'label' => 'Campanha',            'tipo' => 'texto'],
            ['key' => 'central_promocao',    'label' => 'Central de Promoção', 'tipo' => 'texto'],
            // Resposta do cliente no link do Onboarding (JSON, não coluna).
            ['key' => 'obs_publicacao',      'label' => 'Obs. publicação',     'tipo' => 'texto'],
            ['key' => 'jardinagem',          'label' => 'Jardinagem',          'tipo' => 'texto'],
            // ── Logística ──
            ['key' => 'contextos_logistica', 'label' => 'Contextos logística', 'tipo' => 'texto'],
            ['key' => 'me1',                 'label' => 'ME1',                 'tipo' => 'texto'],
            ['key' => 'integradora',         'label' => 'Integradora',         'tipo' => 'texto'],
            // Resposta do cliente no link do Onboarding — é o que decide o ME1.
            ['key' => 'produtos_perfil',     'label' => 'Perfil produtos',     'tipo' => 'texto'],
            ['key' => 'places',              'label' => 'Places',              'tipo' => 'texto'],
            ['key' => 'erp',                 'label' => 'ERP',                 'tipo' => 'texto'],
        ];

        if ($isAdmin) {
            $cols[] = ['key' => 'fin_faturamento', 'label' => 'Faturamento', 'tipo' => 'dinheiro'];
            $cols[] = ['key' => 'fin_meta',        'label' => 'Meta',        'tipo' => 'dinheiro'];
            $cols[] = ['key' => 'fin_pct',         'label' => '% da meta',   'tipo' => 'percentual'];
            $cols[] = ['key' => 'fin_ads',         'label' => 'ADS',         'tipo' => 'dinheiro'];
            // Fatia de Casa/Móveis no gross — sinaliza quem não vende móvel num polo
            // moveleiro. NÃO entra em meta/status: é insumo de curadoria de roster.
            $cols[] = ['key' => 'fin_pct_moveis', 'label' => '% móveis',    'tipo' => 'percentual'];
            $cols[] = ['key' => 'fin_status',      'label' => 'Status',      'tipo' => 'texto'];
        }

        return $cols;
    }

    /** Rótulos espelhados do Painel (STATUS_ENVIO_LABELS de Polos/Painel.jsx). */
    private const EXPORT_ENVIO_LABEL = [
        'falta_enviar' => 'Pendente',
        'enviado'      => 'Enviado',
        'concluido'    => 'Concluído',
    ];

    /** Rótulos espelhados do Painel (SITUACAO_LABEL de Polos/Painel.jsx). */
    private const EXPORT_SITUACAO_LABEL = [
        'problema'       => 'Com problema',
        'fora_meta'      => 'Desconsiderada da meta',
        'fora_prazo'     => 'Fora do prazo',
        'pendente_envio' => 'Pendente de envio',
        'sem_ficha'      => 'Sem ficha',
        'ads_off'        => 'ADS desligado',
        'ok'             => 'Sem pendências',
    ];

    /** Rótulos espelhados do Painel (STATUS_META de Polos/components/statusMeta.js). */
    private const EXPORT_STATUS_META_LABEL = [
        'Sim'          => 'No alvo',
        'Em progresso' => 'Em progresso',
        'Não'          => 'Não',
        'Problema'     => 'Problema',
    ];

    /**
     * Coluna "Situação" da planilha — espelha `situacaoDe()` de Polos/Painel.jsx.
     * Uma empresa pode acumular vários flags; sem nenhum, é "Sem pendências".
     */
    private function situacaoExport(MlbEmpresa $e, ?MlbImplementacao $impl, ?array $prazo): string
    {
        $flags = [];
        if ($e->problema) {
            $flags[] = 'problema';
        }
        if ($e->problema_desconsidera_meta) {
            $flags[] = 'fora_meta';
        }
        if ($prazo['fora_do_prazo'] ?? false) {
            $flags[] = 'fora_prazo';
        }
        if ($impl && $impl->statusEnvio() === 'falta_enviar') {
            $flags[] = 'pendente_envio';
        }
        if (! $impl) {
            $flags[] = 'sem_ficha';
        }
        if ($e->ads_desligado) {
            $flags[] = 'ads_off';
        }

        if (empty($flags)) {
            $flags[] = 'ok';
        }

        return implode(', ', array_map(fn ($f) => self::EXPORT_SITUACAO_LABEL[$f] ?? $f, $flags));
    }

    /**
     * Baixa o Painel Polos como planilha (.xlsx) — uma coluna por campo, cabeçalho
     * congelado e AutoFiltro já ligado (abre filtrável no Excel, no LibreOffice e no
     * Google Sheets ao subir o arquivo pro Drive).
     *
     * POST e não GET porque o front manda os `ids` das linhas VISÍVEIS: a planilha sai
     * com exatamente o que está na tela depois dos funis, e uma lista de 500+ ids não
     * caberia numa query string. Sem `ids`, exporta o painel inteiro.
     *
     * O bloco financeiro (admin) reusa `montarCockpit()` — o MESMO que a camada
     * financeira da tela já carregou, então o cache costuma estar quente. Se falhar,
     * a planilha sai sem os números em vez de derrubar o download.
     */
    public function exportarPlanilha(Request $request)
    {
        $user = $request->user();
        abort_unless(
            $user->isAdmin() || $user->hasPermission('mlb.projetos'),
            403,
            'Acesso restrito ao módulo de Polos.'
        );

        $data = $request->validate([
            'ids'   => ['sometimes', 'array', 'max:5000'],
            'ids.*' => ['integer'],
            'mes'   => ['sometimes', 'nullable', 'string', 'max:7'],
        ]);

        $isAdmin = (bool) $user->isAdmin();
        $colunas = $this->colunasExportacao($isAdmin);

        // ── Linhas: mesmo escopo do painel (ativas + projeto POLOS), ordem da tela ──
        $ids = array_values(array_filter(array_map('intval', $data['ids'] ?? [])));

        $query = MlbEmpresa::ativas()
            ->with(['responsavel:id,name', 'implementacao', 'implementacao.responsavel:id,name'])
            ->orderBy('nome');

        if (! empty($ids)) {
            $query->whereIn('id', $ids);
        }

        $empresas = $query->get()->filter(
            fn ($e) => (($e->getAttributes()['projeto'] ?? null) ?: (MlbEmpresa::FASE_PARA_PROJETO[$e->fase ?? ''] ?? null)) === 'POLOS'
        )->values();

        // ── Financeiro (admin): cust_norm → números do cockpit ──
        $fin = [];
        // cust_norm → fatia de Casa/Móveis (MLB1574) sobre o GROSS da conta, em %. É a
        // visão de "essa empresa vende móvel mesmo?" — pergunta de ROSTER, e é aqui na
        // planilha que o time decide quem fica no programa. JHOLP MIX MAGAZINE sai com
        // 0,8% (99% Pet Shop). A coluna continua útil mesmo com a métrica já em móveis:
        // a meta responde "vendeu quanto de móvel", esta responde "é moveleira?".
        $pctMoveis = [];
        if ($isAdmin) {
            try {
                $cockpit = $this->montarCockpit($data['mes'] ?? null);
                foreach ($cockpit['polos'] as $p) {
                    foreach (($p['empresas'] ?? []) as $emp) {
                        $k = CustId::normaliza((string) ($emp['cust_id'] ?? ''));
                        if ($k !== '') {
                            $fin[$k] = $emp;
                        }
                    }
                }
                foreach (($cockpit['m1']['empresas'] ?? []) as $emp) {
                    $k = CustId::normaliza((string) ($emp['cust_id'] ?? ''));
                    if ($k !== '' && ! isset($fin[$k])) {
                        $fin[$k] = $emp;
                    }
                }

                $mesFin = (string) ($cockpit['mesSelecionado'] ?? '');
                if ($mesFin !== '' && $fin !== []) {
                    $ativosFin = array_map(fn ($k) => ['cust_id' => $k], array_keys($fin));
                    $moveis    = $this->faturamentoMoveisDoMes($ativosFin, $mesFin);
                    // Denominador SEMPRE o gross da conta — nunca `$emp['faturamento']`,
                    // que segue a métrica vigente (metricaFaturamento). Com a métrica em
                    // 'moveis' aquilo seria móveis/móveis = 100% para todo mundo, apagando
                    // justamente o sinal de curadoria que esta coluna existe para dar.
                    $grossMes = $this->colunaDoSnapshot($ativosFin, $mesFin, 'faturamento');
                    foreach ($fin as $k => $emp) {
                        $gross = (float) ($grossMes[$k] ?? 0);
                        // Sem gross não há fração possível — coluna vazia em vez de 0%,
                        // que se leria como "não vende móvel".
                        $pctMoveis[$k] = $gross > 0 ? round(100 * ((float) ($moveis[$k] ?? 0)) / $gross, 1) : null;
                    }
                }
            } catch (\Throwable $ex) {
                // Planilha sem financeiro > download quebrado: o operacional é o essencial.
                Log::warning('[Polos] Exportação sem bloco financeiro: ' . $ex->getMessage());
                $fin       = [];
                $pctMoveis = [];
            }
        }

        $vinculoAdman = VinculoAdmanPolos::carregar();

        $linhas = $empresas->map(function ($e) use ($fin, $pctMoveis, $vinculoAdman) {
            $impl  = $e->implementacao;
            $prazo = $impl?->infoPrazo();
            $f     = $fin[CustId::normaliza((string) ($e->cust_id ?? ''))] ?? [];

            // Boolean da ficha vira Sim/Não; null (sem ficha) fica vazio — célula em branco
            // diz "não se aplica", que é diferente de "respondeu Não".
            $simNao = fn ($v) => $v === null ? null : ($v ? 'Sim' : 'Não');

            return [
                'nome'                => $e->nome,
                'cust_id'             => $e->cust_id,
                'situacao'            => $this->situacaoExport($e, $impl, $prazo),
                'problema_nota'       => $e->problema ? $e->problema_nota : null,
                'data_cadastro'       => $e->created_at?->format('Y-m-d'),
                'fase'                => $e->fase,
                'estagio'             => $e->estagio,
                'polo'                => $e->polo,
                'responsavel'         => $impl ? $impl->responsavel?->name : $e->responsavel?->name,
                'onboarding'          => $impl?->progresso()['pct'],
                'envio'               => $impl ? (self::EXPORT_ENVIO_LABEL[$impl->statusEnvio()] ?? $impl->statusEnvio()) : null,
                'status_entrada'      => $impl?->status_entrada,
                'chance_entrada'      => $impl?->chance_entrada,
                'acesso_colaborador'  => $impl?->acesso_colaborador,
                'gmail_colaborador'   => $impl?->gmail_colaborador,
                'adman_vinculo'       => $vinculoAdman->status($e->fase, $e->cust_id),
                'grupo_whatsapp'      => $simNao($impl ? (bool) $impl->grupo_whatsapp : null),
                'link_whatsapp'       => $impl?->link_whatsapp,
                'reuniao_onboarding'  => $impl?->reuniao_onboarding,
                'canais_faturamento'  => $impl?->respostaCanaisVenda(),
                'data_solicitacao'    => $impl?->data_solicitacao?->format('Y-m-d'),
                'planilha_produtos'   => $impl?->planilha_produtos,
                'listagem'            => $impl?->listagem,
                'publicacao'          => $impl?->publicacao,
                'decola'              => $impl?->decola,
                'campanha_criada'     => $simNao($impl ? (bool) $impl->campanha_criada : null),
                'central_promocao'    => $impl?->central_promocao,
                'obs_publicacao'      => $impl?->observacaoPublicacao(),
                'jardinagem'          => $impl?->querJardinagem() ? 'Sim' : null,
                'contextos_logistica' => $impl?->contextos_logistica,
                'me1'                 => $impl?->me1,
                'integradora'         => $impl?->integradora,
                'produtos_perfil'     => $impl?->respostaChecklist('produtos_perfil'),
                'places'              => $impl?->places,
                'erp'                 => $impl?->erp,
                'fin_faturamento'     => $f['faturamento'] ?? null,
                'fin_meta'            => $f['meta'] ?? null,
                'fin_pct'             => $f['pct'] ?? null,
                'fin_ads'             => $f['ads'] ?? null,
                'fin_pct_moveis'      => $pctMoveis[CustId::normaliza((string) ($e->cust_id ?? ''))] ?? null,
                'fin_status'          => isset($f['status']) ? (self::EXPORT_STATUS_META_LABEL[$f['status']] ?? $f['status']) : null,
            ];
        })->all();

        activity('polos')
            ->causedBy($user)
            ->withProperties(['linhas' => count($linhas), 'financeiro' => $isAdmin && ! empty($fin)])
            ->log('[Polos] Painel exportado em planilha (' . count($linhas) . ' empresa(s))');

        return $this->streamXlsx($colunas, $linhas, 'painel-polos-' . now()->format('Y-m-d') . '.xlsx', 'Painel Polos');
    }

    /**
     * Escreve o .xlsx e devolve como download em streaming.
     *
     * Streaming (e não `save()` num arquivo temporário) porque a planilha do painel passa
     * de 500 linhas × 35 colunas e não há motivo para encostar no disco.
     *
     * @param array<int,array{key:string,label:string,tipo:string,largura?:int,quebrar?:bool}> $colunas
     * @param array<int,array<string,mixed>>                        $linhas
     */
    private function streamXlsx(array $colunas, array $linhas, string $nomeArquivo, string $nomeAba): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $planilha = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $folha    = $planilha->getActiveSheet();
        $folha->setTitle(mb_substr($nomeAba, 0, 31));

        $letraDe = fn (int $i) => \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);

        // ── Cabeçalho ──
        foreach ($colunas as $i => $col) {
            $folha->setCellValue($letraDe($i) . '1', $col['label']);
        }

        $ultimaLetra = $letraDe(count($colunas) - 1);
        $ultimaLinha = count($linhas) + 1;

        $folha->getStyle("A1:{$ultimaLetra}1")->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F2430']],
            'alignment' => ['vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
        ]);
        $folha->getRowDimension(1)->setRowHeight(22);

        // ── Corpo ──
        foreach ($linhas as $l => $linha) {
            $r = $l + 2;
            foreach ($colunas as $i => $col) {
                $valor = $linha[$col['key']] ?? null;
                if ($valor === null || $valor === '') {
                    continue; // célula vazia filtra melhor que um "—" no Excel/Sheets
                }

                $celula = $letraDe($i) . $r;

                if ($col['tipo'] === 'data') {
                    // Data como número de série do Excel: só assim o filtro de data e a
                    // ordenação funcionam. Como texto, "10/01" ordenaria antes de "09/12".
                    $folha->setCellValue($celula, \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(\Carbon\Carbon::parse($valor)));
                } elseif (in_array($col['tipo'], ['dinheiro', 'percentual'], true)) {
                    $folha->setCellValueExplicit($celula, (float) $valor, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
                } else {
                    // STRING explícito: cust_id é numérico e viraria 2,42505E+09 sem isto.
                    $folha->setCellValueExplicit($celula, (string) $valor, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                }
            }
        }

        // ── Formato por coluna (faixa inteira, não célula a célula) ──
        if ($ultimaLinha > 1) {
            foreach ($colunas as $i => $col) {
                $faixa = $letraDe($i) . '2:' . $letraDe($i) . $ultimaLinha;
                if ($col['tipo'] === 'data') {
                    $folha->getStyle($faixa)->getNumberFormat()->setFormatCode('dd/mm/yyyy');
                } elseif ($col['tipo'] === 'dinheiro') {
                    $folha->getStyle($faixa)->getNumberFormat()->setFormatCode('R$ #,##0.00');
                } elseif ($col['tipo'] === 'percentual') {
                    // Guardamos 0-100 (é o que o painel mostra), então o "%" é literal — não
                    // o formato percentual do Excel, que multiplicaria por 100.
                    $folha->getStyle($faixa)->getNumberFormat()->setFormatCode('0"%"');
                }
                // Texto longo em várias linhas (comentários): quebra dentro da célula e
                // alinha no topo, senão o Excel mostra só a primeira linha cortada.
                if (! empty($col['quebrar'])) {
                    $folha->getStyle($faixa)->getAlignment()
                        ->setWrapText(true)
                        ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP);
                }
            }
        }

        // ── Cabeçalho + coluna Empresa congelados, e AutoFiltro ligado ──
        $folha->freezePane('B2');
        $folha->setAutoFilter("A1:{$ultimaLetra}{$ultimaLinha}");

        // Largura fixa por tipo, NÃO setAutoSize: o autosize obriga o writer a medir cada
        // célula (35 colunas × 500+ empresas) e é de longe a parte mais cara da geração.
        foreach ($colunas as $i => $col) {
            // `largura` na definição da coluna vence a regra geral abaixo.
            $largura = $col['largura'] ?? match (true) {
                $col['key'] === 'nome'                       => 34,
                $col['key'] === 'situacao'                   => 30,
                in_array($col['key'], ['problema_nota', 'link_whatsapp'], true) => 32,
                $col['tipo'] === 'data'                      => 12,
                $col['tipo'] === 'percentual'                => 12,
                $col['tipo'] === 'dinheiro'                  => 15,
                default                                      => 22,
            };
            $folha->getColumnDimension($letraDe($i))->setWidth($largura);
        }

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($planilha);

        return response()->streamDownload(function () use ($writer, $planilha) {
            $writer->save('php://output');
            $planilha->disconnectWorksheets(); // libera as ~500×35 células
        }, $nomeArquivo, [
            'Content-Type'  => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store, no-cache',
        ]);
    }


    /**
     * Edição em MASSA do Painel Polos — aplica mudanças a N empresas de uma vez.
     *
     * Aceita `items:[{id, changes:{campo:valor}}]` (por-id — usado no "Desfazer") OU
     * `ids:[int]` + `changes:{campo:valor}` (aplicação uniforme). Reproduz as MESMAS
     * regras da edição inline: `fase`/`polo` → mlb_empresas; demais campos → ficha
     * (mlb_implementacoes) — empresas SEM ficha são IGNORADAS p/ esses campos. Tudo numa
     * transação. Devolve resumo + snapshot ("undo") p/ o front desfazer chamando o mesmo endpoint.
     */
    public function painelBulk(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        abort_unless(
            $user->isAdmin() || $user->hasPermission('mlb.projetos'),
            403,
            'Acesso restrito ao módulo de Polos.'
        );

        $data = $request->validate([
            'items'           => ['sometimes', 'array', 'max:1000'],
            'items.*.id'      => ['required', 'integer'],
            'items.*.changes' => ['required', 'array'],
            'ids'             => ['sometimes', 'array', 'max:1000'],
            'ids.*'           => ['integer'],
            'changes'         => ['sometimes', 'array'],
        ]);

        // Normaliza p/ a forma canônica: lista de {id, changes}.
        $items = [];
        if (! empty($data['items'])) {
            $items = $data['items'];
        } elseif (! empty($data['ids']) && ! empty($data['changes'])) {
            foreach ($data['ids'] as $id) {
                $items[] = ['id' => (int) $id, 'changes' => $data['changes']];
            }
        }
        if (empty($items)) {
            return response()->json(['message' => 'Nada para aplicar.'], 422);
        }

        // Campos permitidos e onde cada um mora.
        $EMPRESA = ['fase', 'polo'];
        $IMPL    = ['responsavel_id', 'data_solicitacao', 'acesso_colaborador', 'gmail_colaborador',
                    'grupo_whatsapp', 'planilha_produtos', 'listagem', 'publicacao', 'decola', 'campanha_criada',
                    'central_promocao', 'contextos_logistica', 'me1', 'integradora', 'places', 'erp',
                    // Restauração literal do envio (só o "Desfazer" envia estes — snapshot bruto).
                    'link_enviado_em', 'link_enviado_por'];
        // decola saiu daqui em 2026-08-03: virou texto (ONB_DECOLA_OPCOES).
        $BOOL      = ['grupo_whatsapp', 'campanha_criada'];
        $ENVIO     = 'status_envio'; // ação especial (enviado/falta_enviar)
        $permitidos = array_merge($EMPRESA, $IMPL, [$ENVIO]);

        $aplicadas = 0;
        $ignoradas = []; // [{id, nome, campos:[], motivo}]
        $undoItems = []; // [{id, changes:{campo:de}}]

        DB::transaction(function () use ($items, $EMPRESA, $IMPL, $BOOL, $ENVIO, $permitidos, $user, &$aplicadas, &$ignoradas, &$undoItems) {
            $empresas = MlbEmpresa::with('implementacao')
                ->whereIn('id', array_column($items, 'id'))
                ->get()->keyBy('id');

            foreach ($items as $it) {
                $e = $empresas->get($it['id']);
                if (! $e) {
                    $ignoradas[] = ['id' => $it['id'], 'nome' => null, 'campos' => array_keys($it['changes'] ?? []), 'motivo' => 'não encontrada'];
                    continue;
                }
                // Escopo: só edita empresas do projeto POLOS (mesmo predicado do painel()) —
                // o front só manda ids de POLOS, mas o servidor não confia no cliente.
                $proj = ($e->getAttributes()['projeto'] ?? null) ?: (MlbEmpresa::FASE_PARA_PROJETO[$e->fase ?? ''] ?? null);
                if ($proj !== 'POLOS') {
                    $ignoradas[] = ['id' => $e->id, 'nome' => $e->nome, 'campos' => array_keys($it['changes'] ?? []), 'motivo' => 'fora do escopo Polos'];
                    continue;
                }
                if ($e->arquivado_em !== null) {
                    $ignoradas[] = ['id' => $e->id, 'nome' => $e->nome, 'campos' => array_keys($it['changes'] ?? []), 'motivo' => 'empresa arquivada'];
                    continue;
                }
                $impl = $e->implementacao;

                $mudEmpresa = [];
                $mudImpl    = [];
                $undo       = [];
                $semFicha   = [];

                foreach (($it['changes'] ?? []) as $campo => $valor) {
                    if (! in_array($campo, $permitidos, true)) {
                        continue;
                    }
                    // Vazio → null (exceto booleans).
                    if (! in_array($campo, $BOOL, true) && $valor === '') {
                        $valor = null;
                    }

                    if (in_array($campo, $EMPRESA, true)) {
                        $undo[$campo]       = $e->{$campo};
                        $mudEmpresa[$campo] = $valor;
                    } elseif ($campo === $ENVIO) {
                        if (! $impl) { $semFicha[] = $campo; continue; }
                        $atual = $impl->statusEnvio();
                        if ($atual === 'concluido') {
                            $ignoradas[] = ['id' => $e->id, 'nome' => $e->nome, 'campos' => [$campo], 'motivo' => 'envio concluído'];
                            continue;
                        }
                        // Só 'enviado'/'falta_enviar' escrevem; outros valores são no-op (sem undo, sem contar).
                        // Snapshot de undo = valores BRUTOS (restaura quem/quando enviou fielmente).
                        if ($valor === 'enviado' || $valor === 'falta_enviar') {
                            $undo['link_enviado_em']  = optional($impl->link_enviado_em)->format('Y-m-d H:i:s');
                            $undo['link_enviado_por'] = $impl->link_enviado_por;
                            if ($valor === 'enviado') {
                                $impl->update(['link_enviado_em' => now(), 'link_enviado_por' => $user->id]);
                            } else {
                                $impl->update(['link_enviado_em' => null, 'link_enviado_por' => null]);
                            }
                        }
                    } else { // demais campos → ficha
                        if (! $impl) { $semFicha[] = $campo; continue; }
                        if (in_array($campo, $BOOL, true)) {
                            $valor = filter_var($valor, FILTER_VALIDATE_BOOLEAN);
                        } elseif ($campo === 'responsavel_id') {
                            $valor = $valor ? (int) $valor : null;
                        }
                        $de = $impl->{$campo};
                        if ($de instanceof \Carbon\CarbonInterface) {
                            $de = $de->format('Y-m-d');
                        }
                        $undo[$campo]    = $de;
                        $mudImpl[$campo] = $valor;

                        // Trava manual do ME1 (quick 260722-nwc): mudar o me1 na mão para
                        // um valor concreto trava a regra automática do Mercado Envios;
                        // limpar destrava. Server-side (me1_manual não vem do cliente).
                        if ($campo === 'me1' && $valor !== $de) {
                            $mudImpl['me1_manual'] = ($valor !== null && $valor !== '');
                        }
                    }
                }

                if (! empty($mudEmpresa)) { $e->update($mudEmpresa); }
                if (! empty($mudImpl) && $impl) { $impl->update($mudImpl); }

                if (! empty($semFicha)) {
                    $ignoradas[] = ['id' => $e->id, 'nome' => $e->nome, 'campos' => $semFicha, 'motivo' => 'sem ficha'];
                }
                if (! empty($undo)) {
                    $undoItems[] = ['id' => $e->id, 'changes' => $undo];
                    $aplicadas++;
                }
            }
        });

        activity('polos')
            ->causedBy($user)
            ->withProperties(['itens' => count($items), 'aplicadas' => $aplicadas])
            ->log("[Polos] Edição em massa: {$aplicadas} empresa(s) alterada(s)");

        return response()->json([
            'aplicadas' => $aplicadas,
            'ignoradas' => $ignoradas,
            'undo'      => ['items' => $undoItems],
        ]);
    }

    /**
     * Grava (upsert) a meta de ENTRANTES de uma região num mês (aba Metas).
     * Mesmo gate operacional do painel; `mes` no formato 'YYYY-MM'.
     */
    public function salvarMetaEntrada(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        abort_unless(
            $user->isAdmin() || $user->hasPermission('mlb.projetos'),
            403,
            'Acesso restrito ao módulo de Polos.'
        );

        $data = $request->validate([
            'polo' => ['required', 'string', \Illuminate\Validation\Rule::in(MlbImplementacao::ONB_POLO_OPCOES)],
            'mes'  => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'meta' => ['required', 'integer', 'min:0', 'max:100000'],
        ]);

        PoloMetaEntrada::updateOrCreate(
            ['polo' => $data['polo'], 'mes' => $data['mes']],
            ['meta' => $data['meta']],
        );

        return response()->json(['ok' => true]);
    }

    /**
     * Grava a META ÚNICA de faturamento do projeto Polos (R$) — o alvo global
     * usado no card "% Geral da meta". NÃO é a soma das metas por empresa; é um
     * número editável (default R$ 3.200.000), persistido em Configuracao.
     *
     * Admin-only: a camada financeira do painel é exclusiva de admin (mesmo gate
     * de painelFinanceiro()).
     */
    public function salvarMetaFaturamento(Request $request): \Illuminate\Http\JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        $data = $request->validate([
            'meta' => ['required', 'numeric', 'min:0', 'max:1000000000'],
        ]);

        $meta = (int) round($data['meta']);
        Configuracao::set('polo_meta_faturamento', $meta);

        return response()->json(['ok' => true, 'meta' => $meta]);
    }

    // ═══ TAREFAS DO TIME (TKT-0017) ═══
    // Cartões da tela "Tarefas" do Modo TV: indicador de uma lista fixa, meta, responsável e
    // prazo, editados pela gestão. Mesmo gate operacional do painel e do salvarMetaEntrada —
    // quem vê o Painel acompanha e ajusta a meta do time. JSON, como as outras metas do painel.

    public function tarefaStore(Request $request): \Illuminate\Http\JsonResponse
    {
        $this->checkPainelOperacional($request);

        $tarefa = PoloTarefaMeta::create($this->dadosDaTarefa($request) + [
            'criado_por'     => $request->user()->id,
            'atualizado_por' => $request->user()->id,
        ]);

        return response()->json(['ok' => true, 'tarefa' => $tarefa->load('responsavel:id,name')->paraTela()]);
    }

    public function tarefaUpdate(Request $request, PoloTarefaMeta $tarefa): \Illuminate\Http\JsonResponse
    {
        $this->checkPainelOperacional($request);

        $tarefa->update($this->dadosDaTarefa($request) + ['atualizado_por' => $request->user()->id]);

        return response()->json(['ok' => true, 'tarefa' => $tarefa->load('responsavel:id,name')->paraTela()]);
    }

    /** Remove o cartão da tela (soft delete: o histórico da meta fica no activity_log). */
    public function tarefaDestroy(Request $request, PoloTarefaMeta $tarefa): \Illuminate\Http\JsonResponse
    {
        $this->checkPainelOperacional($request);

        $tarefa->update(['atualizado_por' => $request->user()->id]);
        $tarefa->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Grava os "Próximos passos" da tela Tarefas da TV: a lista inteira de uma vez (a tela manda
     * todos os itens, na ordem). Linha vazia sai; lista vazia apaga o bloco da TV.
     */
    public function salvarProximosPassos(Request $request): \Illuminate\Http\JsonResponse
    {
        $this->checkPainelOperacional($request);

        $data = $request->validate([
            'itens'   => ['present', 'array', 'max:'.PoloTarefaMeta::MAX_PROXIMOS_PASSOS],
            'itens.*' => ['nullable', 'string', 'max:120'],
        ]);

        $itens = array_values(array_filter(array_map(fn ($i) => trim((string) $i), $data['itens']), fn ($i) => $i !== ''));
        $antes = PoloTarefaMeta::proximosPassos();

        Configuracao::set(PoloTarefaMeta::CHAVE_PROXIMOS_PASSOS, json_encode($itens, JSON_UNESCAPED_UNICODE));

        activity('polos')
            ->causedBy($request->user())
            ->withProperties(['antes' => $antes, 'depois' => $itens])
            ->log('Próximos passos da TV (Polos) atualizados');

        return response()->json(['ok' => true, 'itens' => $itens]);
    }

    /** Gate operacional do Painel Polos: admin OU permissão mlb.projetos (mesma régua de painel()). */
    private function checkPainelOperacional(Request $request): void
    {
        $user = $request->user();
        abort_unless(
            $user->isAdmin() || $user->hasPermission('mlb.projetos'),
            403,
            'Acesso restrito ao módulo de Polos.'
        );
    }

    /**
     * Valida e normaliza o formulário do cartão. Estágio-alvo só existe na Publicação (e cai no
     * padrão "Estágio 1" = saiu de Não Listado quando vem vazio); nos outros indicadores é limpo,
     * para um cartão que trocou de indicador não carregar um estágio que não usa. O mesmo vale
     * para o feito digitado, que só a contagem manual tem.
     */
    private function dadosDaTarefa(Request $request): array
    {
        $data = $request->validate([
            'indicador'      => ['required', 'string', \Illuminate\Validation\Rule::in(array_keys(PoloTarefaMeta::INDICADORES))],
            'estagio_alvo'   => ['nullable', 'string', \Illuminate\Validation\Rule::in(PoloTarefaMeta::ESTAGIOS_ALVO)],
            'titulo'         => ['required', 'string', 'max:120'],
            'descricao'      => ['nullable', 'string', 'max:500'],
            'meta'           => ['required', 'integer', 'min:0', 'max:100000'],
            'feito_manual'   => ['nullable', 'integer', 'min:0', 'max:100000'],
            'responsavel_id' => ['nullable', 'integer', 'exists:users,id'],
            'prazo'          => ['nullable', 'date'],
        ]);

        $data['estagio_alvo'] = $data['indicador'] === 'publicacao'
            ? ($data['estagio_alvo'] ?? PoloTarefaMeta::ESTAGIO_ALVO_PADRAO)
            : null;
        $data['descricao'] = ($data['descricao'] ?? null) ?: null;
        // Feito digitado só existe na contagem manual (zero quando vem vazio); os automáticos
        // são calculados ao vivo e não guardam número nenhum.
        $data['feito_manual'] = $data['indicador'] === PoloTarefaMeta::INDICADOR_MANUAL
            ? (int) ($data['feito_manual'] ?? 0)
            : null;

        return $data;
    }

    /**
     * Arquiva UMA empresa Polos (RF-Arquivamento): sai do Painel e NÃO conta mais em
     * metas/faturamento/cockpit. Reversível (nada é apagado — só marca `arquivado_em`).
     * Usado tanto pelo botão manual do Painel quanto (via command) pelo sync da planilha.
     *
     * Gate operacional (mesma régua do painel): admin OU permissão mlb.projetos.
     */
    public function arquivar(Request $request, MlbEmpresa $empresa): \Illuminate\Http\RedirectResponse
    {
        $user = $request->user();
        abort_unless(
            $user->isAdmin() || $user->hasPermission('mlb.projetos'),
            403,
            'Acesso restrito ao módulo de Polos.'
        );

        // Escopo: só empresas do projeto POLOS (não deixa arquivar publicador/assessoria).
        $proj = ($empresa->getAttributes()['projeto'] ?? null) ?: (MlbEmpresa::FASE_PARA_PROJETO[$empresa->fase ?? ''] ?? null);
        abort_unless($proj === 'POLOS', 403, 'Só é possível arquivar empresas do projeto Polos.');

        if ($empresa->arquivado_em === null) {
            $motivo = trim((string) $request->input('motivo', '')) ?: 'Arquivada manualmente';
            $empresa->update([
                'arquivado_em'     => now(),
                'arquivado_por'    => $user->id,
                'arquivado_motivo' => $motivo,
            ]);

            activity('polos')
                ->causedBy($user)
                ->performedOn($empresa)
                ->withProperties(['motivo' => $motivo])
                ->log("[Polos] Empresa arquivada: {$empresa->nome}");
        }

        return back()->with('success', "\"{$empresa->nome}\" arquivada. Não conta mais em metas/faturamento.");
    }

    /**
     * Desarquiva UMA empresa Polos — volta ao Painel e às contas. Limpa os campos de
     * arquivamento. Mesmo gate/escopo do arquivar().
     */
    public function desarquivar(Request $request, MlbEmpresa $empresa): \Illuminate\Http\RedirectResponse
    {
        $user = $request->user();
        abort_unless(
            $user->isAdmin() || $user->hasPermission('mlb.projetos'),
            403,
            'Acesso restrito ao módulo de Polos.'
        );

        $proj = ($empresa->getAttributes()['projeto'] ?? null) ?: (MlbEmpresa::FASE_PARA_PROJETO[$empresa->fase ?? ''] ?? null);
        abort_unless($proj === 'POLOS', 403, 'Só é possível desarquivar empresas do projeto Polos.');

        if ($empresa->arquivado_em !== null) {
            $empresa->update([
                'arquivado_em'     => null,
                'arquivado_por'    => null,
                'arquivado_motivo' => null,
            ]);

            activity('polos')
                ->causedBy($user)
                ->performedOn($empresa)
                ->log("[Polos] Empresa desarquivada: {$empresa->nome}");
        }

        return back()->with('success', "\"{$empresa->nome}\" desarquivada. Voltou ao Painel Polos.");
    }

    /**
     * Página "Todas as empresas" (/polos/empresas) — visão completa em tabela:
     * lista plana de TODAS as empresas ativas (todos os polos) do mês, com status,
     * faturamento vs meta, ads e problema. Abre numa aba própria (mais espaço).
     */
    public function todasEmpresas(Request $request): \Inertia\Response
    {
        $this->checkFaturamentoAccess();

        // Status vindo do clique numa fatia da "Distribuição de status" (/polos e Cockpit).
        // Só chaves conhecidas: query string é entrada do usuário, e um valor livre viraria
        // um chip que não filtra nada — a tela diria "0 empresas" sem explicar por quê.
        $statusInicial = $request->query('status');
        // 'Sem meta' (chip M1) não vem do donut, mas viaja na troca de mês como os demais.
        if (! in_array($statusInicial, ['Sim', 'Em progresso', 'Não', 'Problema', self::STATUS_SEM_META, 'ads'], true)) {
            $statusInicial = null;
        }

        $vazio = [
            'empresas'       => [],
            'statusDist'     => ['Sim' => 0, 'Em progresso' => 0, 'Não' => 0, 'Problema' => 0, 'total' => 0],
            'meses'          => [],
            'mesSelecionado' => null,
            'mesRefLabel'    => null,
            'parcial'        => false,
            'totais'         => ['faturamento' => 0, 'meta' => 0, 'pct' => 0, 'ativos' => 0, 'm1' => 0, 'faturamento_m1' => 0],
            // Limites de ADS defensivos: garante shape consistente no frontend mesmo sem dados.
            'adsLimites'     => ['teto' => 3000, 'alerta1' => 1000, 'alerta2' => 2000],
            'comentarios'    => (object) [],
            // Check "reunião do mês feita" por cust_id (TKT-0004).
            'reunioes'       => (object) [],
            'statusInicial'  => $statusInicial,
            'podeLancarFaturamento' => $this->podeLancarFaturamentoManual($request->user()),
            'erro'           => null,
        ];

        try {
            $d = $this->montarPolos();
            if ($d === null) {
                return Inertia::render('Polos/Empresas', array_merge($vazio, [
                    'erro' => 'Arquivo CSV POLOS MENSAL não encontrado no ECF Drive.',
                ]));
            }

            $empresas = $this->linhasDaVisaoCompleta($d, comM1: true);

            // M1 está na lista, não na meta: o faturamento soma tudo (M1–M4, como o card do
            // Painel) e ativos/meta/% ficam só no roster da meta. Mesma regra do Painel —
            // ver learnings painel-polos §14: não divida o total pela meta.
            $m1     = array_values(array_filter($empresas, fn ($e) => $e['status'] === self::STATUS_SEM_META));
            $naMeta = array_values(array_filter($empresas, fn ($e) => $e['status'] !== self::STATUS_SEM_META));

            $totFat     = array_sum(array_column($empresas, 'faturamento'));
            $totFatMeta = array_sum(array_column($naMeta, 'faturamento'));
            $totMeta    = array_sum(array_column($naMeta, 'meta'));

            return Inertia::render('Polos/Empresas', [
                'empresas'       => $empresas,
                'statusDist'     => $d['statusDist'],
                'meses'          => $d['meses'],
                'mesSelecionado' => $d['mesSel'],
                'mesRefLabel'    => $d['mesAtual']['label'] ?? null,
                'parcial'        => $d['parcial'],
                'totais'         => [
                    'faturamento'    => $totFat,
                    'meta'           => $totMeta,
                    'pct'            => $totMeta > 0 ? round($totFatMeta / $totMeta * 100, 1) : 0,
                    'ativos'         => count($naMeta),
                    'm1'             => count($m1),
                    'faturamento_m1' => array_sum(array_column($m1, 'faturamento')),
                ],
                'adsLimites'     => $d['adsLimites'],
                'metricaFaturamento' => $d['metricaFaturamento'],
                // Comentarios das empresas da lista, de TODOS os meses (TKT-0007), agrupados
                // por cust_id. Vem junto com a pagina (volume pequeno) em vez de um fetch por
                // linha aberta: sem isso, abrir 20 empresas seriam 20 idas ao servidor para
                // mostrar 3 frases.
                'comentarios'    => $this->comentariosDasEmpresas(array_column($empresas, 'cust_id'), $request->user()),
                'statusInicial'  => $statusInicial,
                'erro'           => null,
                // Check "reunião do mês feita" do mês selecionado, por cust_id (TKT-0004).
                'reunioes'       => (object) $this->reunioesDoMes($d['mesSel']),
                // Libera o lançamento de faturamento manual (só Dev, conta Admin e Débora).
                'podeLancarFaturamento' => $this->podeLancarFaturamentoManual($request->user()),
            ]);
        } catch (\Throwable $e) {
            report($e);
            return Inertia::render('Polos/Empresas', array_merge($vazio, [
                'erro' => 'Não foi possível buscar dados do ECF Drive. Tente em alguns segundos.',
            ]));
        }
    }

    /**
     * Linhas da visão completa (/polos/empresas): lista plana com o polo de cada empresa,
     * ordenada por faturamento desc. Compartilhada pela tela e pela planilha exportada,
     * para as duas nunca divergirem.
     *
     * `$comM1` (27/10/2026): a tela e a planilha listam também as M1, com status
     * STATUS_SEM_META — "tem empresa gastando ADS no M1 e não estamos vendo". O JSON enxuto do
     * Painel (painelSellers) passa false: o contrato dele é financeiro nulo fora da meta.
     *
     * @param  array  $d  Saída de montarPolos()
     * @return array<int, array<string,mixed>>
     */
    private function linhasDaVisaoCompleta(array $d, bool $comM1 = true): array
    {
        $empresas = [];
        foreach ($d['polos'] as $p) {
            foreach (($p['empresas'] ?? []) as $e) {
                $empresas[] = $e + ['polo' => $p['polo']];
            }
        }
        if ($comM1) {
            array_push($empresas, ...($d['m1'] ?? []));
        }
        usort($empresas, fn ($a, $b) => $b['faturamento'] <=> $a['faturamento']);

        // Status das campanhas e bônus de ADS são estado de AGORA: só o mês corrente os mostra.
        // Mês fechado segue com o `ads_desligado` congelado no roster daquele mês.
        return $d['parcial'] ? $this->comBonusAds($this->comAdsAutomatico($empresas)) : $empresas;
    }

    // ═══ Planilha do Faturamento Polos (/polos/empresas — TKT-0018) ═══

    /** Rótulos espelhados de STATUS_META de Polos/Empresas.jsx (aqui "Não" é "Não faturou"). */
    private const EMPRESAS_STATUS_LABEL = [
        'Sim'          => 'No alvo',
        'Em progresso' => 'Em progresso',
        'Não'          => 'Não faturou',
        'Problema'     => 'Problema',
        self::STATUS_SEM_META => 'Sem meta (M1)',
    ];

    /** Rótulos espelhados de CAMPANHAS de Polos/Empresas.jsx; null = não verificado. */
    private const EMPRESAS_CAMPANHA_LABEL = [
        PoloAdsStatus::CAMPANHA_ATIVA        => 'Ativa',
        PoloAdsStatus::CAMPANHA_DESLIGADA    => 'Desligada',
        PoloAdsStatus::CAMPANHA_SEM_CAMPANHA => 'Sem campanha',
    ];

    /**
     * Baixa a visão completa do Faturamento Polos (/polos/empresas) como planilha .xlsx:
     * as colunas da tabela, mais Cust ID, meta, a reunião do mês e os comentários da
     * empresa (de todos os meses, como a tela mostra).
     *
     * Mesmo padrão da planilha do Painel Polos (`exportarPlanilha`): POST com as linhas
     * VISÍVEIS (`custs`, já filtradas pelos chips e pela busca, na ordem da tela) e o mês
     * selecionado; sem `custs`, sai o mês inteiro. A lista é remontada aqui pelo mesmo
     * `montarPolos()` da tela — o front só diz QUAIS linhas, nunca os números.
     *
     * "% da meta" sai com o valor REAL: a tela trava a barra em 100 (learnings §9) e mostra
     * o real no hover; numa planilha o número cru é o que serve para filtrar e ordenar.
     */
    public function exportarEmpresas(Request $request)
    {
        $this->checkFaturamentoAccess();

        $dados = $request->validate([
            'mes'     => ['sometimes', 'nullable', 'string', 'max:6'],
            'custs'   => ['sometimes', 'array', 'max:5000'],
            'custs.*' => ['nullable', 'string', 'max:50'],
        ]);

        try {
            $d = $this->montarPolos();
        } catch (\Throwable $e) {
            report($e);
            $d = null;
        }
        if ($d === null) {
            return back()->with('error', 'Não foi possível montar a planilha agora: o ECF Drive não respondeu. Tente em alguns segundos.');
        }

        $empresas = $this->linhasDaVisaoCompleta($d, comM1: true);

        // Recorte e ordem da tela. Empresa sem Cust ID chega como '' (o middleware a
        // transforma em null) e casa com as demais sem Cust ID, como na própria tabela.
        if (array_key_exists('custs', $dados)) {
            $posicao = [];
            foreach ($dados['custs'] as $i => $c) {
                $posicao[CustId::normaliza((string) $c)] ??= $i;
            }
            $empresas = array_values(array_filter($empresas, fn ($e) => isset($posicao[(string) $e['cust_id']])));
            usort($empresas, fn ($a, $b) => $posicao[(string) $a['cust_id']] <=> $posicao[(string) $b['cust_id']]);
        }

        $parcial     = (bool) $d['parcial'];
        $comentarios = $this->comentariosDasEmpresas(array_column($empresas, 'cust_id'), $request->user());
        $reunioes    = $this->reunioesDoMes($d['mesSel']);

        $colunas = [
            ['key' => 'nome',          'label' => 'Empresa',     'tipo' => 'texto'],
            ['key' => 'cust_id',       'label' => 'Cust ID',     'tipo' => 'texto'],
            ['key' => 'polo',          'label' => 'Polo',        'tipo' => 'texto'],
            ['key' => 'fase',          'label' => 'Fase',        'tipo' => 'texto'],
            ['key' => 'status',        'label' => 'Status',      'tipo' => 'texto'],
            ['key' => 'faturamento',   'label' => $d['metricaFaturamento'] === self::METRICA_GROSS ? 'Faturamento' : 'Faturamento (móveis)', 'tipo' => 'dinheiro'],
            ['key' => 'faturamento_fonte', 'label' => 'Fonte do faturamento', 'tipo' => 'texto', 'largura' => 34],
            ['key' => 'meta',          'label' => 'Meta',        'tipo' => 'dinheiro'],
            ['key' => 'pct',           'label' => '% da meta',   'tipo' => 'percentual'],
            ['key' => 'ads',           'label' => 'ADS',         'tipo' => 'dinheiro'],
            ['key' => 'ads_situacao',  'label' => 'ADS ligado/desligado', 'tipo' => 'texto'],
        ];
        // O filtro "Campanhas" só existe no mês corrente (é estado de agora) — a coluna também.
        if ($parcial) {
            $colunas[] = ['key' => 'campanhas', 'label' => 'Campanhas', 'tipo' => 'texto'];
        }
        array_push($colunas,
            ['key' => 'problema',        'label' => 'Problema',            'tipo' => 'texto'],
            ['key' => 'problema_nota',   'label' => 'Nota do problema',    'tipo' => 'texto', 'largura' => 32],
            ['key' => 'reuniao',         'label' => 'Reunião do mês',      'tipo' => 'texto'],
            ['key' => 'reuniao_por',     'label' => 'Reunião marcada por', 'tipo' => 'texto', 'largura' => 32],
            ['key' => 'comentarios',     'label' => 'Comentários',         'tipo' => 'texto', 'largura' => 70, 'quebrar' => true],
        );

        $linhas = array_map(function (array $e) use ($comentarios, $reunioes, $parcial) {
            $reuniao = $reunioes[$e['cust_id']] ?? null;

            // Como a coluna Sinais: true = desligado, false = ligado, null = não informado.
            $adsSituacao = match ($e['ads_desligado'] ?? null) {
                true    => 'Desligado',
                false   => 'Ligado',
                default => null,
            };

            // M1 não tem meta: célula vazia, não "R$ 0,00 / 0%" (que leria como meta não batida).
            $semMeta = $e['status'] === self::STATUS_SEM_META;

            return [
                'nome'          => $e['nome'],
                'cust_id'       => $e['cust_id'],
                'polo'          => $e['polo'],
                'fase'          => $e['fase'],
                'status'        => self::EMPRESAS_STATUS_LABEL[$e['status']] ?? $e['status'],
                'faturamento'   => $e['faturamento'],
                'faturamento_fonte' => $this->fonteFaturamentoParaCelula($e),
                'meta'          => $semMeta ? null : $e['meta'],
                'pct'           => $semMeta ? null : $e['pct'],
                'ads'           => $e['ads'],
                'ads_situacao'  => $adsSituacao,
                'campanhas'     => $parcial ? (self::EMPRESAS_CAMPANHA_LABEL[$e['ads_campanha'] ?? ''] ?? 'Não verificado') : null,
                'problema'      => ! empty($e['problema']) ? 'Sim' : null,
                'problema_nota' => ! empty($e['problema']) ? $e['problema_nota'] : null,
                // Sem marcação = não feita, como a tela mostra.
                'reuniao'       => ($reuniao['feita'] ?? false) ? 'Feita' : 'Não feita',
                'reuniao_por'   => $reuniao ? trim($reuniao['por'] . ($reuniao['em'] ? " em {$reuniao['em']}" : '')) : null,
                'comentarios'   => $this->comentariosParaCelula($comentarios[$e['cust_id']] ?? []),
            ];
        }, $empresas);

        activity('polos')
            ->causedBy($request->user())
            ->withProperties(['linhas' => count($linhas), 'mes' => $d['mesSel']])
            ->log('[Polos] Faturamento Polos exportado em planilha (' . count($linhas) . ' empresa(s))');

        return $this->streamXlsx(
            $colunas,
            $linhas,
            'faturamento-polos-' . ($d['mesSel'] ?? 'sem-mes') . '-' . now()->format('Y-m-d') . '.xlsx',
            'Faturamento Polos'
        );
    }

    /** "Adman", "Manual (Fulano, 22/10/2026 14:30)" ou "Sem dado da Adman" — coluna da planilha. */
    private function fonteFaturamentoParaCelula(array $e): string
    {
        return match ($e['faturamento_fonte'] ?? null) {
            'adman'  => 'Adman',
            'manual' => 'Manual (' . implode(', ', array_filter([
                $e['faturamento_manual']['por'] ?? null,
                $e['faturamento_manual']['em'] ?? null,
            ])) . ')',
            default  => 'Sem dado da Adman',
        };
    }

    /**
     * Comentários de UMA empresa numa célula só, do mais recente para o mais antigo, cada
     * um com o mês de referência, o autor e a data — o que a gaveta da tela mostra.
     *
     * @param  array<int, array<string,mixed>>  $lista  Saída de comentariosDasEmpresas() da empresa
     */
    private function comentariosParaCelula(array $lista): ?string
    {
        if ($lista === []) {
            return null;
        }

        $texto = implode("\n\n", array_map(function (array $c) {
            $cabecalho = implode(' · ', array_filter([$c['mes_label'] ?? null, $c['autor'] ?? null, $c['criado_em'] ?? null]));

            return $cabecalho . ($c['editado_em'] ? ' (editado)' : '') . "\n" . $c['texto'];
        }, $lista));

        // Limite de uma célula do Excel: 32.767 caracteres. Acima disso o arquivo abre com erro.
        return mb_strlen($texto) > 32000 ? mb_substr($texto, 0, 32000) . '…' : $texto;
    }

    // ═══ Comentários de performance (/polos/empresas) ═══
    // Ver o docblock da migration polos_comentarios para as decisões de schema.

    /**
     * Comentários das empresas informadas, de TODOS os meses, agrupados por cust_id, no
     * shape que a tela consome — o mais recente primeiro.
     *
     * Permanentes desde o TKT-0007: até então só apareciam no mês em que foram escritos, e
     * o time reescrevia todo mês a mesma anotação ("foi para outra consultoria", "seller
     * cuida das campanhas"). A coluna `mes` continua sendo gravada e vira só a referência
     * (`mes_label`) de quando a anotação foi feita — nada muda no schema.
     *
     * `pode_editar` é resolvido AQUI e não no front: a regra (autor ou admin) é a mesma
     * que os endpoints aplicam, e derivar isso no JSX abriria caminho para a tela mostrar
     * um lápis que o servidor recusa.
     *
     * @param  array<int, string|null> $custIds  cust_id normalizado das linhas da tela
     * @return array<string, array<int, array<string,mixed>>>
     */
    private function comentariosDasEmpresas(array $custIds, ?User $user): array
    {
        $custIds = array_values(array_unique(array_filter(array_map('strval', $custIds), fn ($c) => $c !== '')));
        if ($custIds === []) {
            return [];
        }

        return PolosComentario::with('autor:id,name')
            ->whereIn('cust_id', $custIds)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('cust_id')
            ->map(fn ($grupo) => $grupo->map(fn (PolosComentario $c) => [
                'id'          => $c->id,
                'texto'       => $c->texto,
                'autor'       => $c->autorNome(),
                'mes'         => $c->mes,
                'mes_label'   => $this->mesLabel((string) $c->mes),
                'criado_em'   => $c->created_at?->format('d/m/Y H:i'),
                'editado_em'  => $c->editado_em?->format('d/m/Y H:i'),
                'pode_editar' => $this->podeMexerNoComentario($user, $c),
            ])->values()->all())
            ->all();
    }

    /** Só o autor mexe no próprio comentário; admin mexe em qualquer um (moderação). */
    private function podeMexerNoComentario(?User $user, PolosComentario $comentario): bool
    {
        if ($user === null) {
            return false;
        }

        return $user->isAdmin() || $comentario->user_id === $user->id;
    }

    public function comentarioStore(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->checkFaturamentoAccess();

        $dados = $request->validate([
            'cust_id' => ['required', 'string', 'max:50'],
            'mes'     => ['required', 'string', 'regex:/^[0-9]{6}$/'],
            'texto'   => ['required', 'string', 'max:5000'],
        ]);

        $user = $request->user();

        PolosComentario::create([
            // Normaliza na escrita: a lista da tela é montada por cust normalizado, e um
            // comentário gravado com o cru nunca mais reencontraria a própria empresa.
            'cust_id'    => CustId::normaliza($dados['cust_id']),
            'mes'        => $dados['mes'],
            'user_id'    => $user->id,
            'autor_nome' => $user->name,
            'texto'      => trim($dados['texto']),
        ]);

        return back()->with('success', 'Comentário adicionado.');
    }

    public function comentarioUpdate(Request $request, PolosComentario $comentario): \Illuminate\Http\RedirectResponse
    {
        $this->checkFaturamentoAccess();
        abort_unless($this->podeMexerNoComentario($request->user(), $comentario), 403, 'Só o autor pode editar este comentário.');

        $dados = $request->validate([
            'texto' => ['required', 'string', 'max:5000'],
        ]);

        $comentario->update([
            'texto'      => trim($dados['texto']),
            'editado_em' => now(),
        ]);

        return back()->with('success', 'Comentário atualizado.');
    }

    public function comentarioDestroy(Request $request, PolosComentario $comentario): \Illuminate\Http\RedirectResponse
    {
        $this->checkFaturamentoAccess();
        abort_unless($this->podeMexerNoComentario($request->user(), $comentario), 403, 'Só o autor pode apagar este comentário.');

        $comentario->delete();

        return back()->with('success', 'Comentário removido.');
    }

    // ═══ Reunião do mês (/polos/empresas — TKT-0004) ═══
    // Check manual "a reunião do mês foi feita?", por cust_id + mês. Manual porque o
    // sistema não registra reunião mensal das empresas dos Polos (ver o docblock da
    // migration polos_reunioes_mes).

    /**
     * Marcações do mês, no shape que a tela consome:
     * `[cust_id => ['feita' => bool, 'por' => nome, 'em' => 'd/m/Y H:i']]`.
     *
     * @return array<string, array{feita: bool, por: string, em: ?string}>
     */
    private function reunioesDoMes(?string $mes): array
    {
        if ($mes === null || $mes === '') {
            return [];
        }

        return PolosReuniaoMes::with('marcadoPor:id,name')
            ->where('mes', $mes)
            ->get()
            ->mapWithKeys(fn (PolosReuniaoMes $r) => [$r->cust_id => [
                'feita' => (bool) $r->feita,
                'por'   => $r->marcadoPorNome(),
                'em'    => $r->marcado_em?->format('d/m/Y H:i'),
            ]])
            ->all();
    }

    public function reuniaoMarcar(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->checkFaturamentoAccess();

        $dados = $request->validate([
            'cust_id' => ['required', 'string', 'max:50'],
            'mes'     => ['required', 'string', 'regex:/^[0-9]{6}$/'],
            'feita'   => ['required', 'boolean'],
        ]);

        // Normaliza na escrita: a lista da tela é montada por cust normalizado.
        $cust = CustId::normaliza($dados['cust_id']);
        if ($cust === '') {
            return back()->withErrors(['cust_id' => 'Empresa sem cust_id.']);
        }

        $user  = $request->user();
        $feita = $request->boolean('feita');

        $registro = PolosReuniaoMes::firstOrNew(['cust_id' => $cust, 'mes' => $dados['mes']]);
        $mudou    = ! $registro->exists || (bool) $registro->feita !== $feita;

        if ($mudou) {
            $registro->fill([
                'feita'            => $feita,
                'user_id'          => $user->id,
                'marcado_por_nome' => $user->name,
                'marcado_em'       => now(),
            ])->save();

            activity('polos')
                ->causedBy($user)
                ->performedOn($registro)
                ->withProperties(['cust_id' => $cust, 'mes' => $dados['mes'], 'feita' => $feita])
                ->log("[Polos] Reunião do mês {$dados['mes']} (cust {$cust}): " . ($feita ? 'feita' : 'não feita'));
        }

        return back()->with('success', $feita ? 'Reunião do mês marcada como feita.' : 'Reunião do mês marcada como não feita.');
    }

    // ═══ Faturamento manual (pedido de 22/10/2026) ═══

    /**
     * Quem lança faturamento manual, ALÉM do cargo Dev: a conta "Admin" e a Débora Lima.
     *
     * Por e-mail porque o sistema não tem permissão por pessoa (só por setor) e o pedido foi
     * nominal. NÃO é "qualquer admin": em 22/10/2026 havia 12 admins em produção (a Débora é
     * um deles) e a decisão foi restringir a estas pessoas — os demais veem o valor, mas não
     * editam. Mudou o e-mail de alguém da lista? Atualizar aqui.
     */
    public const LANCADORES_FATURAMENTO_MANUAL = [
        'admin@ecfconsultoria.com.br',        // conta "Admin"
        'consultor.04@ecfconsultoria.com.br', // Débora Lima
    ];

    /** Cargo Dev (users.is_dev) ou um dos e-mails de LANCADORES_FATURAMENTO_MANUAL. */
    private function podeLancarFaturamentoManual(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if ($user->isAdminDev()) {
            return true;
        }

        return in_array(mb_strtolower(trim((string) $user->email)), self::LANCADORES_FATURAMENTO_MANUAL, true);
    }

    /**
     * Lança (ou corrige) o faturamento de UMA empresa dos Polos num mês, à mão.
     *
     * Só para empresa sem dado da Adman no mês (a Adman prevalece — ver
     * faturamentoVigenteDoMes): com dado da Adman, recusa e diz o valor que ela tem.
     * Qualquer mês até o corrente. Histórico de valores no activity_log (log `polos`).
     */
    public function faturamentoManualSalvar(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->checkFaturamentoAccess();

        $user = $request->user();
        abort_unless(
            $this->podeLancarFaturamentoManual($user),
            403,
            'Só o cargo Dev, a conta Admin e a Débora Lima lançam faturamento manual.'
        );

        $dados = $request->validate([
            'cust_id'    => ['required', 'string', 'max:50'],
            'mes'        => ['required', 'string', 'regex:/^[0-9]{4}(0[1-9]|1[0-2])$/'],
            'valor'      => ['required', 'numeric', 'min:0', 'max:999999999.99'],
            'observacao' => ['nullable', 'string', 'max:500'],
        ], [
            'mes.regex'      => 'Mês inválido.',
            'valor.required' => 'Informe o valor.',
            'valor.numeric'  => 'Valor inválido — use só números, ex.: 12.500,00.',
            'valor.min'      => 'O valor não pode ser negativo.',
            'valor.max'      => 'Valor alto demais — confira os dígitos.',
        ]);

        // Normaliza na escrita: o mapa de faturamento é por cust normalizado.
        $cust = CustId::normaliza($dados['cust_id']);
        if ($cust === '') {
            return back()->withErrors(['cust_id' => 'Empresa sem cust_id.']);
        }

        $mes = $dados['mes'];
        if ((int) $mes > (int) now()->format('Ym')) {
            return back()->withErrors(['mes' => 'Não dá para lançar faturamento de um mês que ainda não começou.']);
        }

        $empresa = $this->empresaPolosDoCust($cust);
        if ($empresa === null) {
            return back()->withErrors(['cust_id' => 'Esta empresa não está no projeto Polos.']);
        }

        // A Adman prevalece: com dado dela no mês, o manual não valeria — recusa em vez de
        // gravar um número que ninguém veria.
        $adman = $this->faturamentoAdmanDoMes([['cust_id' => $cust]], $mes);
        if (array_key_exists($cust, $adman)) {
            return back()->withErrors(['valor' => sprintf(
                'A Adman já tem o faturamento de %s em %s (%s). O lançamento manual só vale para empresa sem dado da Adman.',
                $empresa->nome,
                $this->mesLabel($mes),
                $this->brl($adman[$cust]),
            )]);
        }

        $valor      = round((float) $dados['valor'], 2);
        $observacao = trim((string) ($dados['observacao'] ?? '')) ?: null;

        $registro = PoloFaturamentoManual::firstOrNew(['cust_id' => $cust, 'mes' => $mes]);
        $antes    = $registro->exists ? (float) $registro->valor : null;

        $registro->fill([
            'valor'            => $valor,
            'observacao'       => $observacao,
            'user_id'          => $user->id,
            'lancado_por_nome' => $user->name,
        ]);

        if (! $registro->exists || $registro->isDirty()) {
            $registro->save();

            activity('polos')
                ->causedBy($user)
                ->performedOn($registro)
                ->withProperties([
                    'cust_id'    => $cust,
                    'mes'        => $mes,
                    'empresa'    => $empresa->nome,
                    'antes'      => $antes,
                    'depois'     => $valor,
                    'observacao' => $observacao,
                ])
                ->log(sprintf(
                    '[Polos] Faturamento manual de %s (cust %s) em %s: %s%s',
                    $empresa->nome,
                    $cust,
                    $mes,
                    $antes === null ? '' : $this->brl($antes) . ' → ',
                    $this->brl($valor),
                ));
        }

        return back()->with('success', sprintf(
            'Faturamento de %s em %s lançado à mão: %s.',
            $empresa->nome,
            $this->mesLabel($mes),
            $this->brl($valor),
        ));
    }

    /** Remove o lançamento manual: a empresa volta a depender só da Adman (ou R$ 0 sem dado). */
    public function faturamentoManualRemover(Request $request, PoloFaturamentoManual $lancamento): \Illuminate\Http\RedirectResponse
    {
        $this->checkFaturamentoAccess();

        $user = $request->user();
        abort_unless(
            $this->podeLancarFaturamentoManual($user),
            403,
            'Só o cargo Dev, a conta Admin e a Débora Lima lançam faturamento manual.'
        );

        $props = [
            'cust_id' => $lancamento->cust_id,
            'mes'     => $lancamento->mes,
            'antes'   => (float) $lancamento->valor,
            'depois'  => null,
        ];

        $lancamento->delete();

        activity('polos')
            ->causedBy($user)
            ->performedOn($lancamento)
            ->withProperties($props)
            ->log(sprintf(
                '[Polos] Faturamento manual removido (cust %s, %s): era %s',
                $props['cust_id'],
                $props['mes'],
                $this->brl($props['antes']),
            ));

        return back()->with('success', 'Lançamento manual removido — a empresa volta a depender só da Adman.');
    }

    /**
     * Empresa do projeto Polos dona do cust (arquivadas incluídas: mês fechado ainda conta
     * quem foi arquivada depois — mesmo recorte de montarAtivosDoMes).
     */
    private function empresaPolosDoCust(string $cust): ?MlbEmpresa
    {
        return MlbEmpresa::where('projeto', 'POLOS')
            ->whereNotNull('cust_id')
            ->get(['id', 'nome', 'cust_id'])
            ->first(fn (MlbEmpresa $e) => CustId::normaliza((string) $e->cust_id) === $cust);
    }

    /** "R$ 12.345,67" — para mensagens e log. */
    private function brl(float $valor): string
    {
        return 'R$ ' . number_format($valor, 2, ',', '.');
    }

    // ═══ ADS ligado/desligado (/polos/empresas) ═══

    /**
     * Anexa a cada linha a leitura automática das campanhas (TKT-0003), quando a Adman
     * respondeu por aquela conta há menos de PoloAdsStatus::FRESCOR_HORAS. Para essas, a
     * leitura manda no `ads_desligado` da linha e a tela não oferece marcação manual.
     *
     * Também anexa `ads_campanha` (TKT-0015), que alimenta o filtro "Campanhas": 'ativa',
     * 'desligada', 'sem_campanha' ou null (não verificado). Regra em
     * PoloAdsStatus::situacaoDaEmpresa(). Como este método só roda no mês corrente, mês
     * fechado não traz a chave e a tela esconde o filtro.
     *
     * @param  array<int, array<string,mixed>>  $empresas
     * @return array<int, array<string,mixed>>
     */
    private function comAdsAutomatico(array $empresas): array
    {
        $leituras = PoloAdsStatus::frescos()
            ->whereIn('cust_id', array_column($empresas, 'cust_id'))
            ->get()
            ->keyBy('cust_id');

        foreach ($empresas as &$e) {
            $l = $leituras[$e['cust_id']] ?? null;
            $e['ads_auto'] = $l ? [
                'ativas'        => $l->campanhas_ativas,
                'total'         => $l->campanhas_total,
                'verificado_em' => $l->verificado_em?->format('d/m H:i'),
            ] : null;
            if ($l) {
                $e['ads_desligado'] = $l->desligado();
            }
            $manual = $e['ads_desligado'] ?? null;
            $e['ads_campanha'] = PoloAdsStatus::situacaoDaEmpresa($l, $manual === null ? null : (bool) $manual);
        }
        unset($e);

        return $empresas;
    }

    /**
     * Anexa a cada linha o bônus de ADS da conta (TKT-0021): saldo somado, dias do bônus que
     * vence primeiro e a lista completa, ou null quando não há bônus vigente ou leitura
     * fresca (PoloAdsBonificacao::FRESCOR_HORAS). Lido pelo `polos:ads-bonus` (08:15).
     *
     * @param  array<int, array<string,mixed>>  $empresas
     * @return array<int, array<string,mixed>>
     */
    private function comBonusAds(array $empresas): array
    {
        $leituras = PoloAdsBonificacao::frescos()
            ->whereIn('cust_id', array_column($empresas, 'cust_id'))
            ->get()
            ->keyBy('cust_id');

        foreach ($empresas as &$e) {
            $e['ads_bonus'] = ($leituras[$e['cust_id']] ?? null)?->paraTela();
        }
        unset($e);

        return $empresas;
    }

    /**
     * Marca À MÃO o ADS da empresa como ligado, desligado ou "não informado" (TKT-0003).
     *
     * Desde 30/09 o normal é AUTOMÁTICO: o sync lê as campanhas na Adman (13:00 e 17:30) e
     * grava `ads_desligado` (AdsCampanhasPolos). A marcação manual existe só para a conta
     * que a Adman não enxerga ("User is not mentored by agency") — para as demais ela seria
     * sobrescrita na leitura seguinte, então é recusada. Três estados, como a migration
     * 2026_06_15_160000 definiu: null = ninguém informou ainda.
     *
     * Grava no cadastro ao vivo. Mês fechado lê o roster congelado
     * (`polos:congelar-roster`, 23:40), então marcar hoje não reescreve o passado.
     */
    public function marcarAds(Request $request, MlbEmpresa $empresa): \Illuminate\Http\RedirectResponse
    {
        $this->checkFaturamentoAccess();

        $proj = ($empresa->getAttributes()['projeto'] ?? null) ?: (MlbEmpresa::FASE_PARA_PROJETO[$empresa->fase ?? ''] ?? null);
        abort_unless($proj === 'POLOS', 403, 'Só é possível marcar o ADS de empresas do projeto Polos.');

        $cust = CustId::normaliza((string) $empresa->cust_id);
        if ($cust !== '' && PoloAdsStatus::frescos()->where('cust_id', $cust)->exists()) {
            return back()->with('error', "O ADS de \"{$empresa->nome}\" é lido automaticamente da Adman — não dá para marcar à mão.");
        }

        $request->validate([
            // `present`: null é um valor válido (limpar), mas o campo tem de vir.
            'ads_desligado' => ['present', 'nullable', 'boolean'],
        ]);

        $novo    = $request->input('ads_desligado') === null ? null : $request->boolean('ads_desligado');
        $antes   = $empresa->ads_desligado;
        $rotulo  = fn (?bool $v) => match ($v) { true => 'desligado', false => 'ligado', null => 'não informado' };

        if ($antes !== $novo) {
            $empresa->update(['ads_desligado' => $novo]);

            activity('polos')
                ->causedBy($request->user())
                ->performedOn($empresa)
                ->withProperties(['de' => $rotulo($antes), 'para' => $rotulo($novo)])
                ->log("[Polos] ADS de {$empresa->nome}: {$rotulo($antes)} → {$rotulo($novo)}");
        }

        return back()->with('success', $novo === null
            ? "ADS de \"{$empresa->nome}\" voltou para não informado."
            : "ADS de \"{$empresa->nome}\" marcado como {$rotulo($novo)}.");
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
        // Faturamento: Adman (snapshot), completado pelo lançamento manual onde a Adman não tem dado.
        [$fatMes, , $manuais] = $this->faturamentoDoMes($mesSel, $parcial, $ativos, $linhasMes);

        // ADS do mês corrente via Adman (SÓ-CACHE). Mês fechado → cache frio → [].
        // ADS = R$0 em mês fechado — sem fonte CSV para ADS (limitação documentada).
        $adsMes = $mesSel !== null ? $this->adsAdmanDoMes($ativos, $mesSel) : [];

        // Limiares de ADS configuráveis via Configuracao (defaults: teto=3000, alerta1=1000, alerta2=2000).
        $adsLimites = [
            'teto'    => (float) Configuracao::get('polo_ads_teto', 3000),
            'alerta1' => (float) Configuracao::get('polo_ads_alerta1', 1000),
            'alerta2' => (float) Configuracao::get('polo_ads_alerta2', 2000),
        ];

        $polos      = $this->agregarPorPolo($ativos, $linhasMes, $limiares, $fatMes, $adsMes, $manuais);
        $statusDist = $this->distribuicaoStatus($ativos, $linhasMes, $limiares, $fatMes);

        // Linhas M1 da visão completa — fora de polos/statusDist, que são o roster da meta.
        $m1 = $this->linhasM1DoMes($mesSel, $parcial, $linhasMes, $ativos);

        $metricaFaturamento = $this->metricaFaturamento();

        return compact('polos', 'statusDist', 'meses', 'mesSel', 'mesAtual', 'parcial', 'adsLimites', 'metricaFaturamento', 'm1');
    }

    /**
     * Empresas M1 do mês, no MESMO formato das linhas de agregarPorPolo() (+ `polo`), para a
     * visão completa (/polos/empresas). Pedido de 27/10/2026: "tem empresa gastando ADS no M1
     * e não estamos vendo, porque não está lá".
     *
     * M1 continua fora da meta (D-01): meta 0, status STATUS_SEM_META, e nada daqui entra em
     * polos[] nem na Distribuição de status. Faturamento pelo mesmo faturamentoVigenteDoMes()
     * (Adman + manual) e ADS pelo mesmo snapshot dos ativos.
     *
     * Roster:
     *  - mês corrente: MlbEmpresa ao vivo com fase M1 (sem M0 — o pedido foi M1, e o warm nem
     *    aquece M0). Traz id, problema e ads_desligado, então a coluna Sinais edita como nas
     *    demais linhas.
     *  - mês fechado: o roster congelado só guarda M2–M4 + Fechamento, então M1 sai do CSV
     *    (MESES_NO_PROGRAMA = 0), como na coorte de montarM1() — mas só quem existe no cadastro
     *    de Polos: o CSV lista toda a base da Comercial (ver montarAtivosDoMes()).
     *
     * Sem cust_id não há como medir (nem chave para a linha da tela) → fica fora, como na
     * coorte M1. Quem já está no roster da meta do mês não aparece de novo: o CSV atrasa a régua
     * de meses no programa, e a fase do roster da meta vence.
     *
     * @param  array<array<string,mixed>>  $ativos  Roster da meta do mês (para deduplicar)
     * @return array<int, array<string,mixed>>
     */
    private function linhasM1DoMes(?string $mesSel, bool $parcial, array $linhasMes, array $ativos): array
    {
        if ($mesSel === null) {
            return [];
        }

        $naMeta = [];
        foreach ($ativos as $a) {
            $naMeta[CustId::normaliza((string) ($a['cust_id'] ?? ''))] = true;
        }

        $roster = []; // cust normalizado => linha no formato de montarAtivosDoMes()
        if ($parcial) {
            $empresas = MlbEmpresa::where('fase', 'M1')
                ->where('projeto', 'POLOS')
                ->whereNull('arquivado_em')
                ->orderBy('id')
                ->get(['id', 'nome', 'cust_id', 'polo', 'fase', 'problema', 'problema_nota', 'ads_desligado']);

            foreach ($empresas as $e) {
                $id = CustId::normaliza((string) $e->cust_id);
                if ($id === '' || isset($roster[$id]) || isset($naMeta[$id])) {
                    continue;
                }
                $nome = trim((string) $e->nome);
                $roster[$id] = [
                    'id'            => $e->id,
                    'nome'          => $nome !== '' ? $nome : "Empresa {$id}",
                    'polo'          => trim((string) $e->polo),
                    'problema'      => (bool) $e->problema,
                    'problema_nota' => $e->problema_nota,
                    'ads_desligado' => $e->ads_desligado === null ? null : (bool) $e->ads_desligado,
                ];
            }
        } else {
            $curadas = MlbEmpresa::where('projeto', 'POLOS')
                ->pluck('cust_id')
                ->map(fn ($c) => CustId::normaliza((string) $c))
                ->filter(fn ($c) => $c !== '')
                ->flip();

            foreach ($linhasMes as $row) {
                if ((int) ($row['MESES_NO_PROGRAMA'] ?? $row['meses_no_programa'] ?? -1) !== 0) {
                    continue;
                }
                $id = CustId::normaliza((string) ($row['CUS_CUST_ID_SEL'] ?? $row['cus_cust_id_sel'] ?? ''));
                if ($id === '' || isset($roster[$id]) || isset($naMeta[$id]) || ! $curadas->has($id)) {
                    continue;
                }
                $nome = trim((string) ($row['CUS_NICKNAME'] ?? $row['cus_nickname'] ?? ''));
                $roster[$id] = [
                    'id'            => null, // como no roster reconstruído do CSV: sem edição
                    'nome'          => $nome !== '' ? $nome : "Empresa {$id}",
                    'polo'          => trim((string) ($row['LOCALIDADE'] ?? $row['localidade'] ?? '')),
                    'problema'      => false, // flag histórico indisponível no CSV
                    'problema_nota' => null,
                    'ads_desligado' => null,
                ];
            }
        }

        if ($roster === []) {
            return [];
        }

        $custs   = array_map(fn ($id) => ['cust_id' => (string) $id], array_keys($roster));
        $vigente = $this->faturamentoVigenteDoMes($custs, $mesSel);
        $ads     = $this->adsAdmanDoMes($custs, $mesSel);
        $lookup  = $this->montarLookup($linhasMes);

        $linhas = [];
        foreach ($roster as $id => $r) {
            $id     = (string) $id; // chave numérica do array volta como int
            $csv    = $lookup[$id] ?? null;
            $manual = $vigente['manuais'][$id] ?? null;

            $linhas[] = [
                'cust_id'        => $id,
                'mlb_empresa_id' => $r['id'],
                'nome'           => $r['nome'],
                'fase'           => 'M1',
                'faturamento'    => $vigente['valores'][$id] ?? 0.0,
                'faturamento_fonte'  => $this->fonteDoFaturamento($id, $manual, $vigente['valores']),
                'faturamento_manual' => $manual,
                'meta'           => 0.0,
                'pct'            => 0.0,
                'status'         => self::STATUS_SEM_META,
                'ads'            => $ads[$id] ?? 0.0,
                'problema'       => $r['problema'],
                'problema_fora_da_meta' => false, // sem meta, não há de onde sair
                'problema_nota'  => $r['problema_nota'],
                'ads_desligado'  => $r['ads_desligado'],
                // Mesma regra de agregarPorPolo(): LOCALIDADE do CSV, senão o polo do cadastro.
                'polo'           => ($csv !== null && $csv['localidade'] !== '') ? $csv['localidade'] : ($r['polo'] ?: 'Sem polo'),
            ];
        }

        return $linhas;
    }

    /**
     * De onde veio o faturamento da linha: 'manual' (lançamento aplicado), 'adman' (está no
     * mapa da Adman) ou 'sem_dado' (R$ 0 por ausência, não por venda zero — learnings
     * painel-polos §18). Única regra para as linhas da meta e as M1.
     *
     * @param  array<string,mixed>|null  $manual   Lançamento do mês (saída de faturamentoVigenteDoMes)
     * @param  array<string,float>       $valores  Mapa de faturamento usado na linha
     */
    private function fonteDoFaturamento(string $id, ?array $manual, array $valores): string
    {
        if ($manual['aplicado'] ?? false) {
            return 'manual';
        }

        return ($id !== '' && array_key_exists($id, $valores)) ? 'adman' : 'sem_dado';
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
        $this->checkFaturamentoAccess();

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
        $this->checkFaturamentoAccess();

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
    private function cockpitVazio(string $mensagem): array
    {
        return [
            'polos'            => [],
            'statusDist'       => ['Sim' => 0, 'Em progresso' => 0, 'Não' => 0, 'Problema' => 0, 'total' => 0],
            'meses'            => [],
            'mesSelecionado'   => null,
            'mesRefLabel'      => null,
            'parcial'          => false,
            'fonteFaturamento' => 'csv',
            'metricaFaturamento' => $this->metricaFaturamento(),
            'adsLimites'       => ['teto' => 3000, 'alerta1' => 1000, 'alerta2' => 2000],
            'metaFaturamento'  => (float) Configuracao::get('polo_meta_faturamento', 3200000),
            'm1'               => ['total' => 0, 'faturando' => 0, 'nao' => 0, 'faturamento' => 0, 'empresas' => [], 'polos' => []],
            'faturamentoPorFase' => [],
            'podeLancarFaturamento' => $this->podeLancarFaturamentoManual(auth()->user()),
            'erro'             => $mensagem,
        ];
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

    /** Métrica de faturamento do painel: só Casa/Móveis (raiz MLB1574). */
    public const METRICA_MOVEIS = 'moveis';

    /** Métrica de faturamento do painel: gross da conta inteira, todas as categorias. */
    public const METRICA_GROSS = 'gross';

    /**
     * Qual métrica alimenta meta, status e totais do painel.
     *
     * Vive em `Configuracao` (chave `polo_metrica_faturamento`) e NÃO no código porque
     * já virou duas vezes — 260707 gross→móveis, 260902 móveis→gross, 260910 de volta
     * para móveis. Cada giro custou um deploy; agora é toggle. Valor inválido cai no
     * default em vez de quebrar o painel.
     *
     * @return self::METRICA_*
     */
    private function metricaFaturamento(): string
    {
        $m = (string) Configuracao::get('polo_metrica_faturamento', self::METRICA_MOVEIS);

        return $m === self::METRICA_GROSS ? self::METRICA_GROSS : self::METRICA_MOVEIS;
    }

    /**
     * Faturamento que alimenta meta, status e totais do mês, por cust_id normalizado.
     *
     * A coluna sai de metricaFaturamento(). O histórico dessa decisão importa porque os
     * dois lados têm argumento medido e ela vai voltar à mesa:
     *
     * **260902 — móveis → gross** (`00c8e4f3`), por três motivos:
     *  1. Os limiares M2=1.000 / M3=4.000 / M4=8.000 vêm da planilha, que sempre usou
     *     gross ("defaults da planilha", D-07). Ninguém os recalibrou quando o insumo
     *     virou Móveis-net — a meta ficou ~13% mais difícil sem decisão de produto.
     *  2. Fatiar por categoria obriga a trocar de métrica junto: a Adman só entrega
     *     netBilling POR ITEM (o gross existe só no total da conta).
     *  3. A planilha de Evolução, referência do time, NÃO filtra categoria: traz a JHOLP
     *     com R$ 50.818 (a conta inteira), não com os R$ 396 de móveis dela.
     *
     * **260910 — gross → móveis** (pedido do time: "tirar o cross e deixar só móveis").
     * O motivo 2 acima **não se sustentou na medição** de 202608/202609: nas empresas
     * ~100% móveis a razão móveis/gross tem mediana de 98,1% (set) e 91,6% (ago), e
     * NENHUMA das 6 (set) / 7 (ago) que deixam de bater a meta perde por gross→net —
     * todas perdem por categoria mesmo (JHOLP 1–5% móveis, Iaare Decor 0%, MSI 0%,
     * Primus Haus 4–16%). O motivo 1 segue de pé em agregado e o 3 é real e visível:
     * o total do painel cai ~14–18% e volta a divergir da planilha. Foi aceito.
     *
     * Cust_id sem snapshot → ausência no mapa (o chamador trata como R$0, e é por isso
     * que "sem dado" hoje é indistinguível de "não vendeu"). NUNCA quebra o /polos.
     *
     * @param  array<array<string,mixed>>  $ativos  Ativos (toArray)
     * @param  string  $mesSel  TIM_MONTH_ID 'YYYYMM' do mês exibido
     * @return array<string,float>  [cust_id normalizado => faturamento da métrica vigente]
     */
    private function faturamentoAdmanDoMes(array $ativos, string $mesSel): array
    {
        $coluna = $this->metricaFaturamento() === self::METRICA_GROSS
            ? 'faturamento'
            : 'faturamento_moveis';

        return $this->colunaDoSnapshot($ativos, $mesSel, $coluna);
    }

    /**
     * Faturamento que o painel USA: o da Adman, completado pelo lançamento manual
     * (pedido de 22/10/2026) nas empresas em que a Adman não tem dado no mês.
     *
     * É o ÚNICO ponto que decide entre os dois — meta, status, totais por polo e por fase,
     * coorte M1, /polos/empresas, Painel e exportações passam por aqui. Não leia
     * `polos_faturamento_manual` direto em outro lugar, ou o mesmo número sai diferente
     * em duas telas.
     *
     * Regra (decisão do usuário): **a Adman prevalece**. "Não tem dado" é ausência no mapa
     * de faturamentoAdmanDoMes() — sem snapshot no mês, ou snapshot com a coluna da métrica
     * vigente NULL. Snapshot com R$ 0 é dado (a loja não vendeu) e não é sobrescrito. Se a
     * Adman voltar a responder, o lançamento fica registrado com `aplicado = false` e a tela
     * avisa que ele deixou de valer.
     *
     * @param  array<array<string,mixed>>  $ativos
     * @return array{valores: array<string,float>, manuais: array<string, array{id:int, valor:float, aplicado:bool, observacao:?string, por:string, em:?string}>}
     */
    private function faturamentoVigenteDoMes(array $ativos, string $mesSel): array
    {
        $valores = $this->faturamentoAdmanDoMes($ativos, $mesSel);
        $manuais = [];

        foreach ($this->lancamentosManuaisDoMes($ativos, $mesSel) as $cust => $l) {
            $aplicado = ! array_key_exists($cust, $valores);
            if ($aplicado) {
                $valores[$cust] = (float) $l->valor;
            }

            $manuais[$cust] = [
                'id'         => $l->id,
                'valor'      => (float) $l->valor,
                'aplicado'   => $aplicado,
                'observacao' => $l->observacao,
                'por'        => $l->lancadoPorNome(),
                'em'         => $l->updated_at?->format('d/m/Y H:i'),
            ];
        }

        return ['valores' => $valores, 'manuais' => $manuais];
    }

    /**
     * Lançamentos manuais do mês para os cust_ids dos ativos, por cust_id normalizado.
     * Defensiva como colunaDoSnapshot(): falha de leitura (ex.: tabela ainda não migrada
     * na janela do deploy) devolve [] e o painel segue só com a Adman.
     *
     * @param  array<array<string,mixed>>  $ativos
     * @return array<string, PoloFaturamentoManual>
     */
    private function lancamentosManuaisDoMes(array $ativos, string $mesSel): array
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

            return PoloFaturamentoManual::with('lancadoPor:id,name')
                ->where('mes', $mesSel)
                ->whereIn('cust_id', $custIds)
                ->get()
                ->keyBy('cust_id')
                ->all();
        } catch (\Throwable $e) {
            Log::warning('[Polos] Falha ao ler o faturamento manual: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Faturamento só da raiz "Casa, Móveis e Decoração" (MLB1574), em netBilling por item.
     * Alimenta a coluna "% móveis" da exportação, que existe mesmo quando a métrica
     * vigente já é móveis: lá o número é a RAZÃO móveis/gross, útil para curadoria de
     * roster ("essa empresa vende móvel mesmo?"). Ver faturamentoAdmanDoMes().
     *
     * @param  array<array<string,mixed>>  $ativos
     * @return array<string,float>  [cust_id normalizado => faturamento Móveis (net)]
     */
    private function faturamentoMoveisDoMes(array $ativos, string $mesSel): array
    {
        return $this->colunaDoSnapshot($ativos, $mesSel, 'faturamento_moveis');
    }

    /**
     * Lê uma coluna de valor do PoloFaturamentoSnapshot para os ativos do mês.
     * Defensiva: falha de leitura devolve [] em vez de quebrar o /polos.
     *
     * @param  array<array<string,mixed>>  $ativos
     * @return array<string,float>
     */
    private function colunaDoSnapshot(array $ativos, string $mesSel, string $coluna): array
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

            $snaps = PoloFaturamentoSnapshot::where('mes', $mesSel)
                ->whereIn('cust_id', $custIds)
                ->pluck($coluna, 'cust_id');

            $out = [];
            foreach ($custIds as $id) {
                if (isset($snaps[$id]) && $snaps[$id] !== null) {
                    $out[$id] = (float) $snaps[$id];
                }
            }

            return $out;
        } catch (\Throwable $e) {
            Log::warning("[Polos] Falha ao ler '{$coluna}' do snapshot: " . $e->getMessage());

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
        // Um mês é FECHADO assim que aparece QUALQUER linha 'FECHADO' dele — nunca pelo
        // inverso. Na virada do mês a origem publica o mês recém-encerrado DUAS vezes: as
        // linhas definitivas 'FECHADO' e as antigas 'PARCIAL' convivem no mesmo CSV
        // (agosto/2026 chegou com 547 de cada). A regra anterior era `|| $parcial`, então
        // uma única linha parcial vencia todas as fechadas e o mês seguia "corrente".
        //
        // Isso não é cosmético: `montarAtivosDoMes()` usa o roster AO VIVO quando o mês é
        // parcial, e o time avança todas as fases na virada (M0→M1→…→M4→Encerrado, 324
        // mudanças em 01/09/2026). Agosto passou a ser somado com as fases de setembro e
        // as 43 empresas que eram M4 sumiram do total — R$ 4,73 mi viraram R$ 2,74 mi.
        // O mês corrente continua parcial pela injeção logo abaixo, que é o caso legítimo.
        $temFechado = []; // value(YYYYMM) => viu ao menos uma linha FECHADO
        foreach ($linhas as $row) {
            $mes = trim((string) ($row['TIM_MONTH_ID'] ?? $row['tim_month_id'] ?? ''));
            if ($mes === '') {
                continue;
            }
            $comp = strtoupper(trim((string) ($row['COMPARATIVO'] ?? $row['comparativo'] ?? '')));
            $temFechado[$mes] = ($temFechado[$mes] ?? false) || $comp === 'FECHADO';
        }

        $mapa = []; // value(YYYYMM) => parcial(bool)
        foreach ($temFechado as $mes => $fechado) {
            $mapa[$mes] = ! $fechado;
        }

        // O eixo de meses NÃO deve depender do CSV para o mês corrente. A Comercial
        // publica a linha do mês vigente no ECF Drive com alguns dias de atraso, mas o
        // faturamento é 100% Adman (cache/snapshot ao vivo). Injeta o mês atual como
        // parcial para ele aparecer de imediato, alimentado pela Adman, sem esperar o CSV.
        // (Ativos vêm do MlbEmpresa ao vivo no ramo parcial; localidade fica vazia até o
        //  CSV do mês chegar — degradação apenas cosmética.)
        $mapa[now()->format('Ym')] = true; // corrente é sempre parcial (ainda enchendo)

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
        // D-16 (reconciliação com a Comercial): "ativo" = M2/M3/M4 + Fechamento
        // (empresas graduadas/concluídas que seguem vendendo contam como ativas). Churn
        // e M0 (onboarding) NÃO contam aqui — M0 vai na coorte M1 (ver montarM1).
        // Fechamento não tem limiar em $limiares → meta=0 (não infla o denominador do polo;
        // agrega só o faturamento). Roster manual (Kanban); ver memória do painel de polos.
        if ($parcial || $mesSel === null) {
            return MlbEmpresa::whereIn('fase', ['M2', 'M3', 'M4', 'Fechamento'])
                ->where('projeto', 'POLOS')
                ->whereNull('arquivado_em') // arquivadas não contam em meta/faturamento
                ->get(['id', 'nome', 'cust_id', 'polo', 'fase', 'problema', 'problema_nota', 'problema_desconsidera_meta', 'ads_desligado'])
                ->toArray();
        }

        // Mês fechado: o roster CONGELADO é a fonte preferencial — é o único que descreve
        // o mês em vez de inferi-lo. Gravado por `polos:congelar-roster` (diário no mês
        // corrente; --do-log no backfill). Mês sem snapshot cai na reconstrução do CSV
        // abaixo, que continua valendo para o histórico antigo.
        $congelado = PoloRosterSnapshot::where('mes', $mesSel)->get();
        if ($congelado->isNotEmpty()) {
            return $congelado->map(fn ($r) => $r->paraAtivo())->all();
        }

        // Fallback: reconstrói o roster histórico a partir do CSV daquele mês.
        $faseDeMeses = static fn (int $m): ?string => match ($m) {
            1       => 'M2',
            2       => 'M3',
            3       => 'M4',
            default => null, // 0 = M1 (excluído); >=4 = já saiu do programa
        };

        // O CSV da Comercial lista TODO seller da base, não só quem entrou no programa —
        // o roster de Polos é curado à mão no MlbEmpresa (Kanban). Sem este cruzamento a
        // reconstrução inventava ativos: agosto/2026 vinha com 185 empresas contra as 133
        // da planilha, e as 40 excedentes (apelidos crus do ML, tipo 'PISA20240413123113')
        // nunca tiveram snapshot — entravam no gráfico como "Não vendeu" e derrubavam o
        // "no alvo" de 57,7% para 43,8%. Elas não venderam zero: nunca foram medidas.
        //
        // Inclui arquivadas de propósito: arquivar é evento de HOJE e não apaga o fato de
        // a empresa ter sido ativa no mês consultado (a Spinella Decor foi arquivada por
        // engano em 18/07 faturando ~R$ 900 mil/mês — filtrar por arquivado_em aqui
        // reintroduziria o mesmo buraco no histórico).
        $curadas = MlbEmpresa::where('projeto', 'POLOS')
            ->pluck('cust_id')
            ->map(fn ($c) => CustId::normaliza((string) $c))
            ->filter(fn ($c) => $c !== '')
            ->flip();

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

            if (! $curadas->has($id)) {
                continue; // seller da base da Comercial que nunca entrou no programa
            }

            $nome = trim((string) ($row['CUS_NICKNAME'] ?? $row['cus_nickname'] ?? ''));

            $ativos[$id] = [
                'cust_id'       => $id,
                'nome'          => $nome !== '' ? $nome : "Empresa {$id}",
                'polo'          => trim((string) ($row['LOCALIDADE'] ?? $row['localidade'] ?? '')),
                'fase'          => $fase,
                'problema'      => false, // flag histórico indisponível no CSV
                'problema_nota' => null,
                'problema_desconsidera_meta' => false, // idem: não existe no CSV
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
            // D-16: coorte M1 (onboarding) = fase M1 + M0. M0 são empresas em
            // implantação (Estágio 1/2/3); as sem cust_id (lixo/não-linkadas) são
            // descartadas logo abaixo pelo guard `$id === ''`, então não inflam a conta.
            foreach (
                MlbEmpresa::whereIn('fase', ['M1', 'M0'])->where('projeto', 'POLOS')
                    ->whereNull('arquivado_em') // arquivadas não contam na coorte M1
                    ->get(['nome', 'cust_id', 'polo', 'fase']) as $e
            ) {
                $id = CustId::normaliza((string) $e->cust_id);
                if ($id === '' || isset($roster[$id])) {
                    continue;
                }
                $nome = trim((string) $e->nome);
                // `fase` separa M1 de M0 no faturamento por fase (M0 fica fora do total M1–M4).
                $roster[$id] = ['nome' => $nome !== '' ? $nome : "Empresa {$id}", 'polo' => trim((string) $e->polo), 'fase' => (string) $e->fase];
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
                    'fase' => 'M1', // o CSV não distingue M0: MESES_NO_PROGRAMA=0 é M1
                ];
            }
        }

        // Faturamento M1 via Adman (snapshot) + lançamento manual onde a Adman não tem dado —
        // igual aos ativos M2–M4; NÃO usa TGMV do CSV. O CSV serve só p/ localidade (abaixo).
        $m1Ativos = array_map(fn ($id) => ['cust_id' => (string) $id], array_keys($roster));
        $fatMap   = $mesSel !== null ? $this->faturamentoVigenteDoMes($m1Ativos, $mesSel)['valores'] : [];

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
                'fase'        => $r['fase'],
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
     * Faturamento do mês quebrado por fase — alimenta o card de faturamento do Painel e do
     * Modo TV, que mostram o TOTAL do projeto e quanto cada M vende.
     *
     * Pedido de 30/09/2026: o faturamento total passa a somar M1–M4 (antes era só o roster
     * da meta). A META não muda: "% Geral da meta" continua dividindo só `polos[].faturamento`
     * (M2–M4), porque M1 é onboarding e nunca teve meta (D-01). Os dois números convivem na
     * tela de propósito — o total vende mais do que a base da meta. `naMeta` diz qual é qual.
     *
     * - Fases da meta varrem os MESMOS `$ativos` e o MESMO `$fatMes` de agregarPorPolo(), sem
     *   deduplicar — a soma das fases `naMeta` é, por construção, Σ polos[].faturamento, e as
     *   empresas dessas fases somam o "Empresas ativas". Trocar a fonte de um sem o outro faz o
     *   card e o % da meta divergirem sem erro nenhum.
     * - M1 sai da coorte de montarM1() filtrada por `fase === 'M1'`: a coorte inclui M0 (D-16),
     *   que fica FORA — o pedido foi M1–M4. Hoje o polos:warm nem aquece M0 (R$ 0 de qualquer
     *   jeito); o filtro garante que continue fora se um dia aquecer.
     * - M1–M4 saem sempre, mesmo zeradas (a tela tem posição fixa por fase). Outra fase do
     *   roster de ativos (Fechamento) só aparece se tiver empresa, no fim.
     *
     * Medido em produção em 30/09 (setembro parcial, móveis): M1 R$ 403 mil, M2 R$ 1,14 mi,
     * M3 R$ 1,17 mi, M4 R$ 1,03 mi — M1 é ~11% do total.
     *
     * @param  array<array<string,mixed>>  $ativos  Roster da meta (M2–M4 + Fechamento)
     * @param  array<string,float>         $fatMes  [cust_id => faturamento] do mês
     * @param  array{empresas?:array}      $m1      Coorte de montarM1()
     * @return array<int, array{fase:string, empresas:int, faturamento:float, naMeta:bool}>
     */
    private function faturamentoPorFase(array $ativos, array $fatMes, array $m1): array
    {
        $fases = [];
        foreach (['M1', 'M2', 'M3', 'M4'] as $f) {
            $fases[$f] = ['fase' => $f, 'empresas' => 0, 'faturamento' => 0.0, 'naMeta' => $f !== 'M1'];
        }

        foreach (($m1['empresas'] ?? []) as $emp) {
            if (($emp['fase'] ?? '') !== 'M1') {
                continue; // M0 da coorte
            }
            $fases['M1']['empresas']++;
            $fases['M1']['faturamento'] += (float) ($emp['faturamento'] ?? 0);
        }

        foreach ($ativos as $ativo) {
            $fase = (string) ($ativo['fase'] ?? '');
            $id   = CustId::normaliza((string) ($ativo['cust_id'] ?? ''));

            $fases[$fase] ??= ['fase' => $fase, 'empresas' => 0, 'faturamento' => 0.0, 'naMeta' => true];
            $fases[$fase]['empresas']++;
            $fases[$fase]['faturamento'] += (float) ($fatMes[$id] ?? 0.0);
        }

        return array_values($fases);
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
     * @return array{0: array<string,float>, 1: string, 2: array<string,array<string,mixed>>}
     *         [mapa cust_id=>fat, fonte('adman'), lançamentos manuais do mês por cust_id]
     */
    private function faturamentoDoMes(?string $mesSel, bool $parcial, array $ativos, array $linhasMes): array
    {
        // Faturamento da Adman (snapshot mensal), completado pelo lançamento manual onde a
        // Adman não tem dado (ver faturamentoVigenteDoMes). NUNCA do CSV (decisão de produto:
        // faturamento só via API ou à mão). cust_id sem snapshot nem lançamento → R$0. O CSV
        // segue apenas para lista de meses e localidade ($parcial/$linhasMes mantidos na
        // assinatura por compatibilidade dos callers).
        if ($mesSel === null) {
            return [[], 'adman', []];
        }

        $v = $this->faturamentoVigenteDoMes($ativos, $mesSel);

        return [$v['valores'], 'adman', $v['manuais']];
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
     * A empresa sai da meta por causa do problema?
     *
     * Quick 260805-dzu: ter problema deixou de tirar a empresa da meta por si só.
     * Só sai quem foi marcado explicitamente com `problema_desconsidera_meta`
     * (problemas básicos seguem contando em No alvo / Em progresso / Não).
     * Roster histórico reconstruído do CSV não tem nenhum dos dois flags → false.
     *
     * @param  array<string,mixed>  $ativo  Linha do roster (MlbEmpresa::toArray ou CSV)
     */
    private function desconsideraDaMeta(array $ativo): bool
    {
        return (bool) ($ativo['problema'] ?? false)
            && (bool) ($ativo['problema_desconsidera_meta'] ?? false);
    }

    /**
     * Calcula o status de uma empresa com base na precedência D-11 (CONTEXT.md):
     *   Problema (problema marcado como "desconsiderar da meta") → maior precedência
     *   Não     (faturamento <= 0) → ativo sem dado ou zerado (D-12)
     *   Sim     (faturamento >= limiar do estágio) → bateu a meta
     *   Em progresso (0 < faturamento < limiar) → menor precedência
     *
     * @param  bool   $problema  Problema QUE DESCONSIDERA DA META (ver desconsideraDaMeta);
     *                           um problema comum não muda o status desde a quick 260805-dzu
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
     * @param  array<string,array>         $manuais    Lançamentos manuais do mês (saída de faturamentoVigenteDoMes)
     * @return array<array<string,mixed>>              Polos agregados, ordenados por nome
     */
    private function agregarPorPolo(array $ativos, array $linhasMes, array $limiares, array $fatAdman = [], array $adsAdman = [], array $manuais = []): array
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
            $status = $this->calcularStatus($this->desconsideraDaMeta($ativo), $tgmv, $limiar);

            // De onde veio o número: o manual só entra onde a Adman não tem dado, então
            // "está no mapa e não é manual aplicado" = Adman. Fora do mapa = sem dado (R$ 0
            // por ausência, não por venda zero — learnings painel-polos §18).
            $manual = $id !== '' ? ($manuais[$id] ?? null) : null;
            $fonte  = $this->fonteDoFaturamento($id, $manual, $fatAdman);

            if (! isset($grupos[$localidade])) {
                $grupos[$localidade] = ['faturamentos' => [], 'limiares' => [], 'statuses' => [], 'empresas' => []];
            }

            $grupos[$localidade]['faturamentos'][] = $tgmv;
            $grupos[$localidade]['limiares'][]      = $limiar;
            $grupos[$localidade]['statuses'][]      = $status;

            // Detalhe por empresa (para o painel de detalhe ao clicar no polo).
            $grupos[$localidade]['empresas'][] = [
                'cust_id'       => $id,
                // Id do cadastro para editar a empresa na tela (opção de ADS). Nulo no roster
                // reconstruído do CSV (mês fechado antigo), que não tem MlbEmpresa garantida.
                'mlb_empresa_id' => $ativo['id'] ?? null,
                'nome'          => $ativo['nome'] ?? "Empresa {$id}",
                'fase'          => $ativo['fase'],
                'faturamento'   => $tgmv,
                // 'adman' | 'manual' | 'sem_dado' — e o lançamento manual do mês, se houver
                // (inclusive o que deixou de valer porque a Adman voltou a responder).
                'faturamento_fonte'  => $fonte,
                'faturamento_manual' => $manual,
                'meta'          => $limiar,
                'pct'           => $limiar > 0 ? round($tgmv / $limiar * 100, 1) : 0.0,
                'status'        => $status,
                'ads'           => $ads,
                'problema'      => (bool) ($ativo['problema'] ?? false),
                // Distingue "tem problema" de "problema que tira da meta" no detalhe do polo.
                'problema_fora_da_meta' => $this->desconsideraDaMeta($ativo),
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
            $status = $this->calcularStatus($this->desconsideraDaMeta($ativo), $tgmv, $limiar);

            $contadores[$status]++;
        }

        $contadores['total'] = count($ativos);

        return $contadores;
    }
}
