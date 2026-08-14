<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Services\Contratos\ContratosPresosService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;

/**
 * ContratoAdminController — Fase 131 (UI-01/UI-05/UI-06, D-01, D-04, D-09,
 * D-10).
 *
 * A porta de entrada da tela administrativa de contratos: onde o
 * Administrativo enxerga o estado real de cada contrato sem abrir o banco,
 * sem depender do alerta chegar em alguém, e sem precisar reconstruir a
 * situação a partir de logs.
 *
 * D-01 — esta fase trava DUAS telas separadas (não painel lateral, não
 * edição inline na linha): esta lista (`index()`) e o detalhe da empresa
 * (`admin.contratos.show`, plano 131-04), alcançado clicando na linha.
 *
 * D-10 — este controller vai ABSORVER a liberação manual da Fase 130
 * (`ContratoLiberacaoManualController`) como uma ação dentro da tela de
 * detalhe, no plano 131-06. Até lá, a rota antiga
 * (`contratos.liberacao-manual.*`) continua no ar — removê-la agora
 * deixaria a liberação manual sem nenhuma superfície.
 */
class ContratoAdminController extends Controller
{
    /** Estado só-de-exibição — empresa sem nenhum ContratoAssinatura criado ainda. */
    private const SEM_CONTRATO = 'aguardando_administrativo';

    /**
     * Lista de contratos administrativos (UI-01/D-04): os 7 estados SEMPRE
     * (nunca o recorte estreito de contratos "presos" do serviço de
     * contratos presos, que esconderia `assinado`/`cancelado` e todo
     * contrato dentro do limiar), com resumo de 7 contagens, filtro por
     * situação e busca por empresa.
     */
    public function index(Request $request, ContratosPresosService $presos): \Inertia\Response
    {
        // (1) Universo (D9 — isenção, ver Servico::exigeContrato()): empresas
        // ATIVAS com ao menos um ContratoServico ATIVO cujo serviço exige
        // contrato. Filtrado NO BACKEND, antes de paginar — nunca escondido
        // no client. Empresa cujo único serviço é isento (Polos) nunca
        // entra aqui.
        $companiesQuery = Company::query()
            ->where('active', true)
            ->whereHas('contratosServico', fn ($q) => $q->where('ativo', true)
                ->whereHas('servico', fn ($s) => $s->where('exige_contrato', true)))
            ->with(['contratosServico' => fn ($q) => $q->where('ativo', true)->with('servico')]);

        // (2) Busca por empresa — SQL, com binding (nunca concatenado).
        $q = $request->input('q');
        if (filled($q)) {
            $qLike = '%'.trim((string) $q).'%';
            $companiesQuery->where(function ($w) use ($qLike) {
                $w->where('name', 'like', $qLike)->orWhere('cnpj', 'like', $qLike);
            });
        }

        $companies = $companiesQuery->get();

        // (3) Contratos de TODAS as empresas do universo, numa única query,
        // indexados por "company_id:servico_id", pegando o de maior id
        // (mais recente) por par.
        $contratosPorPar = ContratoAssinatura::whereIn('company_id', $companies->pluck('id'))
            ->orderByDesc('id')
            ->get()
            ->groupBy(fn (ContratoAssinatura $c) => $c->company_id.':'.$c->servico_id)
            ->map(fn ($grupo) => $grupo->first());

        // (4) Linhas — uma por par (empresa, serviço que exige contrato).
        // Array ACHATADO por linha — nunca o model inteiro, nunca dado de
        // signatário (nome/e-mail/CPF) atravessando para o browser.
        $linhas = collect();
        foreach ($companies as $company) {
            foreach ($company->contratosServico as $contratoServico) {
                if (! $contratoServico->servico?->exigeContrato()) {
                    continue;
                }

                $chave = $company->id.':'.$contratoServico->servico_id;
                $contrato = $contratosPorPar->get($chave);

                if ($contrato) {
                    $linhas->push([
                        'contrato_id'                => $contrato->id,
                        'company_id'                 => $company->id,
                        'company_nome'                => $company->name,
                        'servico_id'                  => $contratoServico->servico_id,
                        'servico_nome'                => $contratoServico->servico?->nome,
                        'status'                       => $contrato->status,
                        'dias_parado'                  => $presos->diasParado($contrato),
                        'causa'                        => $presos->causa($contrato),
                        'enviado_em'                   => $contrato->enviado_em?->toIso8601String(),
                        'assinado_em'                  => $contrato->assinado_em?->toIso8601String(),
                        'liberado_em'                  => $contrato->liberado_em?->toIso8601String(),
                        'cancelamento_solicitado_em'   => $contrato->cancelamento_solicitado_em?->toIso8601String(),
                    ]);
                } else {
                    // Par sem contrato ainda — estado só-de-exibição, base
                    // de tempo é companies.created_at (mesma base do badge
                    // do Comercial, D-08 do plano 131-02).
                    $linhas->push([
                        'contrato_id'                => null,
                        'company_id'                 => $company->id,
                        'company_nome'                => $company->name,
                        'servico_id'                  => $contratoServico->servico_id,
                        'servico_nome'                => $contratoServico->servico?->nome,
                        'status'                       => self::SEM_CONTRATO,
                        'dias_parado'                  => (int) $company->created_at->diffInDays(now()),
                        'causa'                        => null,
                        'enviado_em'                   => null,
                        'assinado_em'                  => null,
                        'liberado_em'                  => null,
                        'cancelamento_solicitado_em'   => null,
                    ]);
                }
            }
        }

        // (5) Resumo (D-04) — EXATAMENTE 7 chaves, uma por
        // ContratoAssinatura::STATUS_TODOS, todas inicializadas em zero,
        // incrementadas sobre a coleção COMPLETA — antes do filtro de
        // situação (contagens absolutas, mesmo padrão do
        // pendencia_counts do ComercialController). O estado
        // "aguardando_administrativo" NÃO entra aqui — vai para a prop
        // escalar separada sem_contrato_count, porque o resumo trava em 7
        // contagens (D-04) e o grid do UI-SPEC trava em 7 colunas.
        $resumo = array_fill_keys(ContratoAssinatura::STATUS_TODOS, 0);
        $semContratoCount = 0;
        foreach ($linhas as $linha) {
            if ($linha['status'] === self::SEM_CONTRATO) {
                $semContratoCount++;
            } elseif (array_key_exists($linha['status'], $resumo)) {
                $resumo[$linha['status']]++;
            }
        }

        // (6) Filtro de situação — whitelist em PHP (T-131-03-03), qualquer
        // valor fora da lista vira null e nunca chega a filtrar nada.
        // Aplicado em memória: depende do estado derivado (par sem
        // contrato vira 'aguardando_administrativo', que não existe na
        // coluna do banco).
        $situacaoWhitelist = [...ContratoAssinatura::STATUS_TODOS, self::SEM_CONTRATO];
        $situacaoInput = $request->input('situacao');
        $situacao = in_array($situacaoInput, $situacaoWhitelist, true) ? $situacaoInput : null;

        if ($situacao !== null) {
            $linhas = $linhas->filter(fn (array $l) => $l['status'] === $situacao)->values();
        }

        // (7) Ordenação padrão: "mais tempo parado primeiro" (UI-SPEC).
        $linhas = $linhas->sortByDesc('dias_parado')->values();

        // (8) Paginação manual via LengthAwarePaginator, preservando path e
        // query — mesmo padrão de ComercialController::listagem().
        $perPage = 50;
        $page = max(1, (int) $request->input('page', 1));
        $paginator = new LengthAwarePaginator(
            $linhas->forPage($page, $perPage)->values(),
            $linhas->count(),
            $perPage,
            $page,
            [
                'path'  => $request->url(),
                'query' => $request->query(),
            ],
        );

        return Inertia::render('Admin/Contratos', [
            'linhas'             => $paginator,
            'filters'            => [
                'situacao' => $situacao,
                'q'        => $q,
            ],
            'resumo'             => $resumo,
            'sem_contrato_count' => $semContratoCount,
        ]);
    }
}
