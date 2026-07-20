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
use App\Models\PoloFaturamentoSnapshot;
use App\Models\PoloMetaEntrada;
use App\Models\User;
use App\Services\AdmanService;
use App\Services\EcfDriveService;
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
     *                fonteFaturamento, adsLimites, m1, erro
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

            return [
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
            $prob   = (bool) ($a['problema'] ?? false);
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

        // Lista operacional ancorada em MlbEmpresa projeto=POLOS (só DB — sem ECF Drive).
        // ATIVAS apenas: empresas arquivadas (ausentes na planilha) saem daqui e NÃO
        // contam em nada — vão para a aba "Arquivados" (prop `arquivadas`, abaixo).
        $empresas = MlbEmpresa::ativas()
            ->with([
                'responsavel:id,name',
                'implementacao',
                'implementacao.responsavel:id,name',
                'implementacao.linkEnviadoPor:id,name',
            ])
            ->orderBy('nome')
            ->get()
            // Projeto canônico: coluna `projeto`, com fallback por fase (empresas antigas).
            ->filter(fn ($e) => (($e->getAttributes()['projeto'] ?? null) ?: (MlbEmpresa::FASE_PARA_PROJETO[$e->fase ?? ''] ?? null)) === 'POLOS')
            ->map(function ($e) {
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
                    'gmail_colaborador'        => $impl?->gmail_colaborador,
                    'grupo_whatsapp'           => $impl ? (bool) $impl->grupo_whatsapp : null,
                    'planilha_produtos'        => $impl?->planilha_produtos,
                    'listagem'                 => $impl?->listagem,
                    'publicacao'               => $impl?->publicacao,
                    'decola'                   => $impl ? (bool) $impl->decola : null,
                    'campanha_criada'          => $impl ? (bool) $impl->campanha_criada : null,
                    'contextos_logistica'      => $impl?->contextos_logistica,
                    'me1'                      => $impl?->me1,
                    'integradora'              => $impl?->integradora,
                    'places'                   => $impl?->places,
                    'erp'                      => $impl?->erp,
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
                    'contextos_logistica', 'me1', 'integradora', 'places', 'erp',
                    // Restauração literal do envio (só o "Desfazer" envia estes — snapshot bruto).
                    'link_enviado_em', 'link_enviado_por'];
        $BOOL      = ['grupo_whatsapp', 'decola', 'campanha_criada'];
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
    public function todasEmpresas(): \Inertia\Response
    {
        $this->checkFaturamentoAccess();

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
            'adsLimites'       => ['teto' => 3000, 'alerta1' => 1000, 'alerta2' => 2000],
            'm1'               => ['total' => 0, 'faturando' => 0, 'nao' => 0, 'faturamento' => 0, 'empresas' => [], 'polos' => []],
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

    /**
     * Faturamento POR CATEGORIA "Casa, Móveis e Decoração" (raiz ML MLB1574) do mês,
     * por cust_id normalizado. SUBSTITUI o gross total da conta — o /polos passa a
     * contar SÓ Móveis em todo o painel.
     *
     * Fonte: coluna `faturamento_moveis` do PoloFaturamentoSnapshot, computada pelo
     * SyncPolosFaturamentoJob a partir do netBilling por item do /performance (Adman
     * entrega o categoryId; a raiz vem da API pública do ML). Diferente do gross, o
     * valor por categoria NÃO tem cache ao vivo — a frescura é a do último warm (cron
     * 13:00 + botão "Sincronizar"), igual ao ADS. Cust_id sem snapshot → ausência no
     * mapa (o chamador trata como R$0). NUNCA quebra o /polos.
     *
     * @param  array<array<string,mixed>>  $ativos  Ativos (toArray)
     * @param  string  $mesSel  TIM_MONTH_ID 'YYYYMM' do mês exibido
     * @return array<string,float>  [cust_id normalizado => faturamento Móveis (net)]
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

            // Faturamento de Móveis vive SÓ no snapshot (sem cache ao vivo por categoria).
            $snaps = PoloFaturamentoSnapshot::where('mes', $mesSel)
                ->whereIn('cust_id', $custIds)
                ->pluck('faturamento_moveis', 'cust_id');

            $out = [];
            foreach ($custIds as $id) {
                if (isset($snaps[$id]) && $snaps[$id] !== null) {
                    $out[$id] = (float) $snaps[$id];
                }
            }

            return $out;
        } catch (\Throwable $e) {
            // Defensiva: falha de leitura NÃO quebra /polos.
            Log::warning('[Polos] Falha ao ler faturamento (Móveis) do snapshot: ' . $e->getMessage());
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
            // D-16: coorte M1 (onboarding) = fase M1 + M0. M0 são empresas em
            // implantação (Estágio 1/2/3); as sem cust_id (lixo/não-linkadas) são
            // descartadas logo abaixo pelo guard `$id === ''`, então não inflam a conta.
            foreach (
                MlbEmpresa::whereIn('fase', ['M1', 'M0'])->where('projeto', 'POLOS')
                    ->whereNull('arquivado_em') // arquivadas não contam na coorte M1
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
