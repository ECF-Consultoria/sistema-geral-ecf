<?php

namespace App\Http\Controllers;

use App\Exceptions\ClicksignException;
use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Models\ContratoAssinaturaSignatario;
use App\Models\ContratoLiberacao;
use App\Models\ContratoServico;
use App\Models\Servico;
use App\Models\User;
use App\Services\Clicksign\ClicksignClient;
use App\Services\Clicksign\CongelamentoEmissaoService;
use App\Services\Clicksign\ContratoClicksignService;
use App\Services\Contratos\ContratoDadosMinimosService;
use App\Services\Contratos\ContratosPresosService;
use App\Services\Contratos\GatilhoContratoAdministrativoService;
use App\Services\ContratoPdfService;
use App\Services\Operacional\EmpresaOperacionalRouter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
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
 * D-10 — este controller ABSORVEU a liberação manual da Fase 130
 * (`ContratoLiberacaoManualController::store()`, agora `liberarManual()`
 * abaixo) como ação dentro da tela de detalhe (plano 131-06). A rota antiga
 * (`contratos.liberacao-manual.*`) foi REMOVIDA (404, não redirect) — o
 * controller e a tela antigos não existem mais.
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
    public function index(Request $request, ContratosPresosService $presos, EmpresaOperacionalRouter $router): \Inertia\Response
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
        //
        // Quick 260824-kbk — desde o quick 260824-bte, pagamento escalonado
        // cria um `ContratoServico` por FASE (mesmo `servico_id`), e o laço
        // antigo emitia uma linha por FASE, não por serviço — quebrando a
        // invariante do comentário acima e duplicando a mesma empresa (as
        // duas fases resolvem a MESMA chave em $contratosPorPar, então
        // apontavam para o MESMO ContratoAssinatura). Agrupamos por
        // `servico_id` ANTES do loop, nos dois ramos (com contrato e
        // SEM_CONTRATO) — a consolidação em si (`show()`, "Datas por
        // serviço") continua mostrando as fases separadas, que é onde a
        // pessoa preenche as datas de cada uma.
        $linhas = collect();
        foreach ($companies as $company) {
            $gruposPorServico = $company->contratosServico
                ->filter(fn (ContratoServico $cs) => $cs->servico?->exigeContrato())
                ->groupBy('servico_id');

            foreach ($gruposPorServico as $servicoId => $grupoContratosServico) {
                $contratoServico = $grupoContratosServico->first();
                // Término do SERVIÇO (não da fase): o maior `data_vencimento`
                // não-nulo do grupo — o serviço termina quando a última fase
                // termina. Todas nulas (ou grupo de uma fase só, sem prazo)
                // → null, o mesmo "Sem prazo" legítimo de sempre.
                $dataVencimentoGrupo = $grupoContratosServico->pluck('data_vencimento')->filter()->max();

                $chave = $company->id.':'.$servicoId;
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
                        // Quick 260816-d72 (UI-06/D-05) — sub-estado de exibição
                        // de 'rascunho', não entra no resumo de 7 chaves (D-04).
                        'preparando'                   => $contrato->estaPreparando(),
                        // Quick 260820-my3 (Tarefa 2) — sub-estado irmão de
                        // 'preparando', mutuamente exclusivo por construção:
                        // rascunho + envelope vazio que passou da janela sem
                        // terminar a montagem. Também não entra no resumo de 7
                        // chaves (D-04) — a mesma disciplina de 'preparando'.
                        'montagem_travada'             => $contrato->estaMontagemTravada(),
                        // Quick 260819-o4x — término do contrato do SERVIÇO
                        // desta linha (`contratos_servico.data_vencimento`),
                        // não do ContratoAssinatura. Alimenta a coluna
                        // "Término" e a ordenação `vencimento`. `null` é
                        // prazo indeterminado, caso legítimo — a tela mostra
                        // "Sem prazo" e a ordenação joga para o fim. Com
                        // fases (Quick 260824-kbk), é o maior valor
                        // não-nulo do GRUPO, não da fase da vez.
                        'data_vencimento'              => optional($dataVencimentoGrupo)->format('Y-m-d'),
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
                        // Quick 260816-d72 — par sem contrato nunca está
                        // "preparando"; chave sempre presente (nunca undefined
                        // no front).
                        'preparando'                   => false,
                        // Quick 260820-my3 (Tarefa 2) — mesma disciplina:
                        // par sem contrato nunca está "travado na montagem"
                        // (não existe ContratoAssinatura para travar).
                        'montagem_travada'              => false,
                        // Quick 260819-o4x — o término vem do ContratoServico,
                        // que EXISTE mesmo sem ContratoAssinatura: empresa que
                        // ainda aguarda o Administrativo já tem prazo contratado.
                        // Quick 260824-kbk — maior não-nulo do GRUPO, mesma
                        // disciplina do ramo com contrato acima.
                        'data_vencimento'              => optional($dataVencimentoGrupo)->format('Y-m-d'),
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

        // (6b) Filtro por serviço adquirido — whitelist pelos ids que o
        // próprio universo da tela admite (serviços com `exige_contrato`),
        // mesma disciplina do `situacao`: id fora da lista vira null e nunca
        // chega a filtrar nada.
        //
        // ⚠️ Aplicado EM MEMÓRIA, sobre as LINHAS — nunca na query de
        // companies do passo (1). É requisito de correção, não preferência:
        // a linha é o par (empresa, serviço), então empresa com dois serviços
        // precisa manter só a linha do serviço escolhido. Filtrar na query
        // faria a empresa inteira sumir (se o `whereHas` não casasse) ou
        // aparecer inteira, com as duas linhas — os dois resultados errados.
        //
        // ⚠️ Roda DEPOIS do resumo (passo 5), de propósito: as 7 contagens
        // seguem ABSOLUTAS, iguais ao que o filtro de situação já faz. O
        // resumo é a régua fixa contra a qual a pessoa compara o recorte que
        // escolheu — se ele encolhesse junto, os cartões marcariam sempre
        // 100% do que está na tela e deixariam de informar. (A busca `q` é a
        // exceção histórica: mora na query e por isso encolhe o resumo.)
        $servicosDoUniverso = Servico::where('exige_contrato', true)
            ->orderBy('nome')
            ->get(['id', 'nome']);

        $servicoInput = $request->input('servico');
        $servico = $servicosDoUniverso->contains('id', (int) $servicoInput) ? (int) $servicoInput : null;

        if ($servico !== null) {
            $linhas = $linhas->filter(fn (array $l) => $l['servico_id'] === $servico)->values();
        }

        // (7) Ordenação — whitelist em PHP, valor fora da lista cai no
        // default (`recente`) e nunca chega a ordenar nada.
        //
        // `recente` (DEFAULT): EMPRESA MAIS RECENTE PRIMEIRO.
        //
        // Substituiu "mais tempo parado primeiro" (`sortByDesc('dias_parado')`,
        // decisão do 131-UI-SPEC) a pedido do usuário em 2026-08-19. A coluna
        // "Parado há" continua na tela e o filtro de situação continua
        // funcionando — o que mudou é só qual linha aparece no topo por padrão.
        //
        // ⚠️ O desempate por `company_id` NÃO é capricho. `companies.created_at`
        // tem um bloco grande de empresas empatadas em 2026-05-25 por causa de
        // um reimport em massa (a coluna é artefato de importação, não data real
        // de entrada da empresa — ver `.planning/learnings/`). Como a paginação
        // do passo (8) é MANUAL, via `LengthAwarePaginator` sobre esta coleção já
        // ordenada, um empate sem desempate determinístico faz linhas trocarem de
        // página entre requisições — a pessoa pagina e vê a mesma empresa duas
        // vezes, ou nenhuma.
        //
        // O mapa é montado FORA do loop de linhas de propósito: a forma de cada
        // linha é uma whitelist deliberada (T-131-03-*/T-131-04-* — nenhum dado
        // de signatário atravessa para o browser), então a chave de ordenação
        // não entra no payload.
        // `vencimento`: TÉRMINO MAIS PRÓXIMO PRIMEIRO
        // (`contratos_servico.data_vencimento` crescente).
        //
        // ⚠️ Término VAZIO não é dado faltando — é contrato por prazo
        // indeterminado, caso legítimo que a regra 5 do
        // `ContratoDadosMinimosService` registra explicitamente ("data_vencimento
        // vazia NÃO reprova"). Por isso essas linhas vão para o FIM da lista, e
        // não para o topo: `null` ordena antes de qualquer data em PHP, e o
        // resultado seria a tela apresentar como "mais urgente" justamente quem
        // não tem prazo nenhum. A flag booleana no primeiro critério empurra os
        // sem-prazo para baixo antes de a data ser comparada.
        $criadoEmPorEmpresa = $companies->pluck('created_at', 'id');

        $ordenacoes = ['recente', 'vencimento'];
        $ordenarInput = $request->input('ordenar');
        $ordenar = in_array($ordenarInput, $ordenacoes, true) ? $ordenarInput : 'recente';

        $criterios = $ordenar === 'vencimento'
            ? [
                fn (array $a, array $b) => ($a['data_vencimento'] === null) <=> ($b['data_vencimento'] === null),
                fn (array $a, array $b) => $a['data_vencimento'] <=> $b['data_vencimento'],
                fn (array $a, array $b) => $b['company_id'] <=> $a['company_id'],
            ]
            : [
                fn (array $a, array $b) => ($criadoEmPorEmpresa[$b['company_id']] ?? null)
                    <=> ($criadoEmPorEmpresa[$a['company_id']] ?? null),
                fn (array $a, array $b) => $b['company_id'] <=> $a['company_id'],
            ];

        $linhas = $linhas->sortBy($criterios)->values();

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
                // Quick 260819-o4x — devolvidos JÁ SANEADOS pela whitelist,
                // nunca o que veio na URL: a tela ecoa `filters` de volta nos
                // selects, e devolver o valor cru faria um `?ordenar=xxx`
                // aparecer selecionado enquanto o backend ordenou por outro
                // critério.
                'servico'  => $servico,
                'ordenar'  => $ordenar,
            ],
            // Lista para o select de serviço — os mesmos que definem o
            // universo da tela (`exige_contrato`), ordenados por nome.
            'servicos'           => $servicosDoUniverso->map(fn (Servico $s) => [
                'id'   => $s->id,
                'nome' => $s->nome,
            ])->values(),
            'resumo'             => $resumo,
            'sem_contrato_count' => $semContratoCount,
            // Fase 133 (D-04) — leitura pelo ÚNICO ponto autorizado
            // (EmpresaOperacionalRouter::bloqueioAtivo()), nunca
            // Configuracao::get() direto aqui. Não muda o universo da
            // listagem (Fase 131); só diz à tela se deve contar a
            // consequência do bloqueio na faixa.
            'bloqueio_ativo'     => $router->bloqueioAtivo(),
        ]);
    }

    /**
     * Detalhe da empresa (D-01, plano 131-04): onde o Administrativo completa
     * o que o Comercial deixou pela metade (ADM-01/ADM-02) e vê explicitamente
     * o que falta para gerar o contrato (D-03).
     *
     * A tela NÃO recalcula elegibilidade — `pode_gerar_contrato` e `faltantes`
     * vêm prontos do backend, únicas fontes: `ContratoDadosMinimosService` e
     * `GatilhoContratoAdministrativoService`.
     */
    public function show(
        Company $company,
        ContratoDadosMinimosService $dados,
        GatilhoContratoAdministrativoService $gatilho,
        ContratoClicksignService $clicksign,
        ContratosPresosService $presos,
        CongelamentoEmissaoService $congelamento,
        // Quick 260824-bte (Tarefa 3c) — só para `ContratoPdfService::planoParcelas()`,
        // que mostra na tela o texto EFETIVO atual (override ou composto)
        // do campo editável de {{plano_parcelas}}.
        ContratoPdfService $pdfDados,
    ): \Inertia\Response {
        $company->loadMissing('contratosServico.servico');

        $faltantes = $dados->faltantes($company);
        $avaliacao = $gatilho->avaliar($company);
        // Quick 260821-l8n — mesmo serviço lançado em mais de um
        // ContratoServico ativo (ex.: pagamento escalonado do HubSpot com 2
        // itens de linha). `avaliar()` não enxerga isso (não é dado
        // faltando nem pendência comercial); sem esta checagem a tela
        // mostrava o botão "Gerar contrato" HABILITADO para uma empresa que
        // `iniciarParaEmpresa()` ia recusar sem avisar por quê.
        $temServicoDuplicado = $clicksign->servicosDuplicados($company) !== [];
        $podeGerarContrato = $dados->estaPronta($company) && $avaliacao['status'] === 'elegivel' && ! $temServicoDuplicado;

        // Escolhe qual bloco a tela mostra quando o botão está desabilitado
        // (D-03). Dados mínimos vem primeiro: se a empresa está incompleta, é
        // essa a razão que o Administrativo precisa ver, mesmo que a empresa
        // também esteja, por exemplo, em 'aguardando_comercial' por outro
        // motivo (sem_valor/sem_setor, que faltantes() não cobre). Serviço
        // duplicado vem logo depois — mesma família de "dado errado no
        // cadastro", antes dos motivos de fluxo (ja_em_andamento/
        // aguardando_comercial/isento).
        $motivoBloqueio = null;
        if (! $podeGerarContrato) {
            $motivoBloqueio = match (true) {
                $faltantes !== []                                => 'dados_minimos',
                $temServicoDuplicado                              => 'servicos_duplicados',
                $avaliacao['status'] === 'ja_em_andamento'        => 'ja_em_andamento',
                $avaliacao['status'] === 'aguardando_comercial'   => 'aguardando_comercial',
                $avaliacao['status'] === 'isento'                 => 'isento',
                default                                           => null,
            };
        }

        // Fase 132 Plano 01 (D-07) — com a emissão congelada, ninguém gera
        // contrato para empresa NENHUMA. Precedência sobre o `match` acima:
        // ele só decide quando a emissão não está congelada. Mostrar "falta
        // completar tal dado" mandaria a pessoa consertar algo que não
        // destravaria nada.
        if ($congelamento->ativo()) {
            $podeGerarContrato = false;
            $motivoBloqueio = 'emissao_congelada';
        }

        $contratosServicoAtivos = $company->contratosServico->where('ativo', true)->values();

        // Contratos de assinatura da empresa (todos os estados), com os
        // signatários — array ACHATADO, nunca o model inteiro nem dado de
        // signatário além de id/nome/papel/situacao (T-131-04-04).
        $contratos = ContratoAssinatura::where('company_id', $company->id)
            ->with('signatarios')
            ->orderByDesc('id')
            ->get();

        // Quick 260819-guy — Tarefa 7 item 1: "já tentou antes" DERIVADO
        // aqui, no backend, em vez do `useState({})` que zerava a cada
        // reload em `ContratoDetalhe.jsx`. Cada nova tentativa de gerar
        // contrato nasce como uma linha NOVA de `ContratoAssinatura` para o
        // mesmo (empresa, serviço) — `GatilhoContratoAdministrativoService`
        // não reaproveita a linha `erro` antiga (o slot já foi liberado por
        // `GerarContratoAssinaturaJob::failed()`). Então: a linha mais
        // ANTIGA por serviço é a primeira tentativa; qualquer outra linha do
        // mesmo serviço já é, por definição, uma tentativa seguinte.
        $idMaisAntigoPorServico = $contratos->groupBy('servico_id')->map(fn ($grupo) => $grupo->min('id'));

        return Inertia::render('Admin/ContratoDetalhe', [
            'company' => [
                'id'                => $company->id,
                'name'              => $company->name,
                'cnpj'              => $company->cnpj,
                'email_cliente'     => $company->email_cliente,
                'nome_contato'      => $company->nome_contato,
                // Quick 260819-guy.
                'razao_social'      => $company->razao_social,
                // Quick 260821-cq0 — endereço volta a ser 5 campos
                // separados (endereco aqui é só o logradouro).
                'endereco'          => $company->endereco,
                'bairro'            => $company->bairro,
                'cidade'            => $company->cidade,
                'estado'            => $company->estado,
                'cep'               => $company->cep,
            ],
            'contratos_servico' => $contratosServicoAtivos->map(fn (ContratoServico $cs) => [
                'id'                => $cs->id,
                'servico_id'        => $cs->servico_id,
                'servico_nome'      => $cs->servico?->nome,
                'exige_contrato'    => (bool) $cs->servico?->exigeContrato(),
                'valor_contratado'  => (float) $cs->valor_contratado,
                'data_contratacao'  => optional($cs->data_contratacao)->format('Y-m-d'),
                'data_vencimento'   => optional($cs->data_vencimento)->format('Y-m-d'),
                // Quick 260819-guy.
                'data_primeira_parcela' => optional($cs->data_primeira_parcela)->format('Y-m-d'),
                'dia_vencimento'        => $cs->dia_vencimento,
                // Quick 260824-r4k — quantidade de parcelas desta fase, pra
                // "Datas por serviço" identificar cada bloco quando o mesmo
                // serviço tem mais de um `ContratoServico` (pagamento
                // escalonado). `ContratoServico::parcelas()` é o mesmo
                // parser de `hubspot_billing_period` que o snapshot
                // congelado do contrato usa (quick 260824-bte) — nunca
                // reimplementar.
                'parcelas'              => $cs->parcelas(),
            ])->values(),
            // Retorno CRU de faltantes() — a tela exibe `rotulo`, não recalcula
            // nada no client (contrato de retorno é PÚBLICO, ver docblock do
            // service).
            'faltantes' => $faltantes,
            // Prop PRÓPRIA — é .env da ECF, não dado da empresa (nunca
            // misturar com `faltantes`, ver docblock de
            // faltantesDaConfiguracaoEcf()).
            'configuracao_ecf_faltante' => $dados->faltantesDaConfiguracaoEcf(),
            'pode_gerar_contrato'       => $podeGerarContrato,
            'motivo_bloqueio'           => $motivoBloqueio,
            // Plano 131-05 (CLICK-10/D-13) — URL do PAINEL da Clicksign (não a
            // API), derivada de CLICKSIGN_ENV (plano 131-01). É o destino real
            // do CTA "Registrar e ir para a Clicksign" — nunca hardcodar no
            // JSX. Pode vir vazia se `CLICKSIGN_PAINEL_URL`/`CLICKSIGN_ENV`
            // não estiverem configuradas; a tela degrada o rótulo nesse caso.
            'painel_clicksign_url'     => config('services.clicksign.painel_url'),
            // Plano 131-06 (D-10) — o mesmo array de rótulos que a tela
            // antiga já consumia (`MOTIVOS_MANUAIS_LABELS`), agora prop
            // desta tela para alimentar o select do modal "Liberar
            // manualmente".
            'motivos_manuais' => ContratoLiberacao::MOTIVOS_MANUAIS_LABELS,
            'contratos' => $contratos->map(function (ContratoAssinatura $c) use ($presos, $idMaisAntigoPorServico, $pdfDados) {
                return [
                    'id'                                => $c->id,
                    'servico_id'                        => $c->servico_id,
                    'servico_nome'                       => $c->servico?->nome,
                    'status'                             => $c->status,
                    'dias_parado'                        => $presos->diasParado($c),
                    'causa'                              => $presos->causa($c),
                    // Quick 260824-bte (Tarefa 3c) — texto de {{plano_parcelas}}:
                    // `plano_parcelas_texto` é o override CRU (nulo quando
                    // não preenchido — é o que o formulário edita e
                    // `atualizarCadastro()` grava); `plano_parcelas_efetivo`
                    // é o valor que REALMENTE vai para o contrato agora
                    // (override ou composto pelas fases do snapshot) — é o
                    // que o campo mostra por padrão antes de qualquer edição.
                    'plano_parcelas_texto'               => $c->plano_parcelas_texto,
                    'plano_parcelas_efetivo'              => $pdfDados->planoParcelas($c),
                    // Quick 260819-guy (Tarefa 7 item 1) — texto exato da
                    // recusa. Já passou por `podarPii()` (WR-11) antes de
                    // gravar, em `GerarContratoAssinaturaJob::failed()`; a
                    // tela exibe cru, sem reprocessar.
                    'erro_mensagem'                     => $c->erro_mensagem,
                    'ja_tentou_antes'                   => $c->id !== ($idMaisAntigoPorServico[$c->servico_id] ?? $c->id),
                    'enviado_em'                         => $c->enviado_em?->toIso8601String(),
                    'assinado_em'                        => $c->assinado_em?->toIso8601String(),
                    'liberado_em'                        => $c->liberado_em?->toIso8601String(),
                    // D-10 — a UI usa este booleano para decidir se ainda
                    // oferece "Liberar manualmente" nesta linha.
                    'ja_liberado'                        => $c->liberado_em !== null,
                    'cancelamento_solicitado_em'         => $c->cancelamento_solicitado_em?->toIso8601String(),
                    'cancelamento_motivo'                => $c->cancelamento_motivo,
                    'cancelamento_solicitado_por_nome'   => $c->cancelamento_solicitado_por_user_id
                        ? User::find($c->cancelamento_solicitado_por_user_id)?->name
                        : null,
                    // Quick 260816-d72 (UI-06/D-05).
                    'preparando'                         => $c->estaPreparando(),
                    // Quick 260820-my3 (Tarefa 2) — irmão de 'preparando',
                    // mutuamente exclusivo por construção (ver docblock de
                    // estaMontagemTravada()).
                    'montagem_travada'                   => $c->estaMontagemTravada(),
                    // Array achatado — NUNCA email/cpf/clicksign_signer_key/
                    // auths/evidencia_signer (T-131-04-04). Consumido também
                    // pelos planos 131-05/06.
                    'signatarios' => $c->signatarios->map(fn (ContratoAssinaturaSignatario $s) => [
                        'id'       => $s->id,
                        'nome'     => $s->nome,
                        'papel'    => $s->papel,
                        'situacao' => $s->situacao,
                    ])->values(),
                ];
            })->values(),
        ]);
    }

    /**
     * ADM-01 — o Administrativo completa aqui o que o Comercial deixou pela
     * metade: CNPJ, e-mail do cliente, nome de quem assina, razão social e
     * endereço da empresa (Quick 260819-guy, endereço em 5 campos separados
     * desde a Quick 260821-cq0), e — por serviço — as datas de início/
     * término, a data da 1ª parcela e o dia do mês do vencimento das demais
     * (Quick 260819-guy). E-mail do colaborador (acesso à conta do Mercado
     * Livre) fica fora desta tela — Quick 260817-d6h — e é completado em
     * `/companies` (`CompanyController`).
     *
     * D8 travada da milestone: nada aqui volta para o Comercial — a cobrança
     * do dado termina nesta tela.
     */
    public function atualizarCadastro(Request $request, Company $company): RedirectResponse
    {
        $data = $request->validate([
            // Quick 260821-odj — CnpjValido() SAIU daqui (incidente em
            // produção, empresa 430 Mons Bike: `nome_contato` de uma palavra
            // só reprovava a REQUISIÇÃO INTEIRA, e razão social/endereço sem
            // problema nenhum não eram gravados junto). O dígito verificador
            // já é checado por `ContratoDadosMinimosService::faltantes()`
            // (o gate da GERAÇÃO, não do salvar) — nada chega à Clicksign
            // sem essa validação. Aqui só formato estrutural (tamanho/tipo).
            'cnpj'                                 => ['nullable', 'string', 'max:20'],
            'email_cliente'                        => ['nullable', 'email'],
            // Quick 260821-odj — NomeCompletoValido() SAIU pelo mesmo
            // motivo do `cnpj` acima: era a causa raiz do incidente medido
            // (nome_contato de uma palavra só, vindo do HubSpot, bloqueava o
            // salvar de TODOS os outros campos do formulário). A exigência
            // de nome completo continua valendo — só migrou para o gate da
            // geração (`ContratoDadosMinimosService::faltantes()`), que roda
            // antes de qualquer chamada à Clicksign.
            'nome_contato'                          => ['nullable', 'string', 'max:255'],
            // Quick 260819-guy — razão social/endereço são POR EMPRESA (mesmo
            // bloco de cnpj/email/nome_contato); campos por serviço vão em
            // contratos_servico.*.
            //
            // Quick 260821-cq0 — endereço volta a ser 5 campos separados
            // (endereco aqui é só o logradouro).
            'razao_social'                          => ['nullable', 'string', 'max:255'],
            'endereco'                               => ['nullable', 'string', 'max:255'],
            'bairro'                                 => ['nullable', 'string', 'max:255'],
            'cidade'                                  => ['nullable', 'string', 'max:255'],
            'estado'                                  => ['nullable', 'string', 'max:255'],
            'cep'                                     => ['nullable', 'string', 'max:20'],
            'contratos_servico'                     => ['array'],
            'contratos_servico.*.id'                => ['required', 'integer', 'exists:contratos_servico,id'],
            'contratos_servico.*.data_contratacao'  => ['nullable', 'date'],
            'contratos_servico.*.data_vencimento'   => ['nullable', 'date'],
            // Quick 260819-guy — data da 1ª parcela (data única) e dia do mês
            // (1 a 31, NÃO uma data — ver docblock da migration) em que
            // vencem as parcelas seguintes.
            'contratos_servico.*.data_primeira_parcela' => ['nullable', 'date'],
            'contratos_servico.*.dia_vencimento'        => ['nullable', 'integer', 'min:1', 'max:31'],
            // Quick 260824-bte (Tarefa 3c) — override de {{plano_parcelas}}
            // por CONTRATO (`ContratoAssinatura`, não `ContratoServico`: a
            // coluna vive lá — Tarefa 3a). String vazia é tratada como "sem
            // override" (volta a usar o composto) no save abaixo, mesma
            // disciplina de `?? null` dos demais campos opcionais desta rota.
            'contratos'                            => ['array'],
            'contratos.*.id'                       => ['required', 'integer', 'exists:contrato_assinaturas,id'],
            'contratos.*.plano_parcelas_texto'      => ['nullable', 'string'],
        ]);

        $itensServico = $data['contratos_servico'] ?? [];
        $itensContrato = $data['contratos'] ?? [];

        // T-131-04-01 (IDOR) — molde literal do
        // ContratoLiberacaoManualController::store(): valida o PERTENCIMENTO
        // de cada item ANTES de gravar qualquer coisa. Se validasse e
        // gravasse item a item, um primeiro item válido já teria gravado
        // antes de um segundo item de outra empresa abortar — quebraria a
        // garantia de "não grava nada" em caso de IDOR.
        $paresParaAtualizar = [];
        foreach ($itensServico as $item) {
            $contratoServico = ContratoServico::findOrFail($item['id']);

            if ($contratoServico->company_id !== $company->id) {
                abort(422, 'O serviço informado não pertence a esta empresa.');
            }

            $paresParaAtualizar[] = [$contratoServico, $item];
        }

        // Mesma disciplina de IDOR acima, agora para `contrato_assinaturas`
        // (Quick 260824-bte, Tarefa 3c).
        $paresContratoParaAtualizar = [];
        foreach ($itensContrato as $item) {
            $contratoAssinatura = ContratoAssinatura::findOrFail($item['id']);

            if ($contratoAssinatura->company_id !== $company->id) {
                abort(422, 'O contrato informado não pertence a esta empresa.');
            }

            $paresContratoParaAtualizar[] = [$contratoAssinatura, $item];
        }

        // Mass assignment sobre os $fillable já existentes de Company — nunca
        // $guarded = [].
        $company->fill(collect($data)->only([
            'cnpj', 'email_cliente', 'nome_contato', 'razao_social',
            // Quick 260821-cq0 — endereço volta a ser 5 campos separados.
            'endereco', 'bairro', 'cidade', 'estado', 'cep',
        ])->all());
        $company->save();

        foreach ($paresParaAtualizar as [$contratoServico, $item]) {
            $contratoServico->update([
                'data_contratacao'       => $item['data_contratacao'] ?? null,
                'data_vencimento'        => $item['data_vencimento'] ?? null,
                // Quick 260819-guy.
                'data_primeira_parcela'  => $item['data_primeira_parcela'] ?? null,
                'dia_vencimento'         => $item['dia_vencimento'] ?? null,
            ]);
        }

        foreach ($paresContratoParaAtualizar as [$contratoAssinatura, $item]) {
            // Quick 260824-bte — string vazia grava `null` (volta a usar o
            // texto composto pelas fases do snapshot); nunca guarda string
            // vazia como se fosse um override de propósito.
            $textoOverride = trim((string) ($item['plano_parcelas_texto'] ?? ''));

            $contratoAssinatura->update([
                'plano_parcelas_texto' => $textoOverride !== '' ? $textoOverride : null,
            ]);
        }

        return back()->with('success', 'Cadastro atualizado.');
    }

    /**
     * UI-02 — dispara a geração do contrato quando está tudo pronto.
     *
     * Revalida no SERVIDOR, sem confiar no botão do client (o `disabled` do
     * client não é controle, T-131-04-03). Delega o disparo inteiro a
     * `GatilhoContratoAdministrativoService::dispararSeElegivel()` — nunca
     * reimplementa o gate nem chama a Clicksign direto.
     *
     * ⚠️ `status === 'disparado'` NÃO prova que nasceu contrato — ver o
     * docblock de `GatilhoContratoAdministrativoService::dispararSeElegivel()`
     * e de `ContratoClicksignService::iniciarParaEmpresa()`. A ÚNICA prova de
     * sucesso é `resultado.ok === true`; a mera presença da chave `resultado`
     * NUNCA é tratada como sucesso.
     */
    public function gerarContrato(
        Company $company,
        ContratoDadosMinimosService $dados,
        GatilhoContratoAdministrativoService $gatilho,
        CongelamentoEmissaoService $congelamento,
    ): RedirectResponse {
        // Fase 132 Plano 01 (D-07) — PRIMEIRA coisa do método, antes de
        // qualquer avaliação ou I/O. Fecha a janela entre a troca de
        // credenciais (plano 132-02) e a aprovação final (plano 132-04):
        // enquanto congelada, nenhum contrato nasce para empresa nenhuma,
        // nem para quem já está com o cadastro completo e elegível. O
        // `disabled` do botão no client não é controle (T-131-04-03) — esta
        // checagem no servidor é.
        if ($congelamento->ativo()) {
            Log::warning('[Administrativo] Tentativa de gerar contrato com a emissão congelada', [
                'company_id' => $company->id,
                'user_id'    => auth()->id(),
            ]);

            return back()->with('error', 'A emissão de contratos está pausada no momento. É temporário e proposital — vale para todas as empresas, não é problema desta empresa nem do que foi preenchido. Fale com o time técnico e tente de novo quando for avisado. Nada do que já foi preenchido se perde.');
        }

        $avaliacao = $gatilho->avaliar($company);

        if (! $dados->estaPronta($company) || $avaliacao['status'] !== 'elegivel') {
            // Quick 260819-guy — Tarefa 7 item 2: antes era `abort(422, ...)`,
            // que renderiza a página branca do Symfony ("Oops! An Error
            // Occurred"), fora da aplicação. Mesmo tratamento do ramo de
            // emissão congelada, dez linhas acima — a checagem no servidor
            // está certa (o `disabled` do client não é controle,
            // T-131-04-03), só a apresentação estava errada.
            return back()->with('error', 'Ainda falta completar algum dado, ou já existe um contrato em andamento para esta empresa.');
        }

        $retorno = $gatilho->dispararSeElegivel($company);

        if ($retorno['status'] === 'disparado' && data_get($retorno, 'resultado.ok') === true) {
            // Quick 260816-d72 (D-05/UI-06): a redação antiga ("Contrato
            // gerado") afirmava um fato que ainda não tinha acontecido — o
            // job só monta o envelope depois, sujeito ao bucket de 1/min
            // (ver ContratoAssinatura::estaPreparando()). Continua no canal
            // 'success': o PEDIDO foi de fato aceito, só a promessa muda.
            return back()->with('success', 'Contrato solicitado. Pode levar até um minuto para ficar pronto — atualize a página em instantes.');
        }

        $configuracaoFaltante = data_get($retorno, 'resultado.configuracao', []);
        if ($retorno['status'] === 'disparado' && $configuracaoFaltante !== []) {
            // A falha é NOSSA (configuração da ECF), não do cliente — UI-06:
            // sem jargão, e a orientação é o próximo passo concreto.
            return back()->with('error', 'Não deu para gerar o contrato: falta uma configuração interna da ECF — quem assina pela ECF ainda não está cadastrado. Avise o time técnico; não é nada que o cliente precise fazer.');
        }

        if ($retorno['status'] === 'disparado') {
            return back()->with('error', 'Não deu para gerar o contrato agora. Confira se falta algum dado acima e tente de novo.');
        }

        if ($retorno['status'] === 'erro') {
            // Mensagem genérica — nunca ecoar a resposta crua da Clicksign
            // nem a mensagem da exceção na tela (T-131-04-05); o detalhe vai
            // pro log dentro do próprio Gatilho.
            return back()->with('error', 'Não deu para gerar este contrato agora. Tente de novo em alguns minutos.');
        }

        return back()->with('error', 'Não é possível gerar o contrato agora: verifique se ainda há alguma pendência.');
    }

    /**
     * CLICK-07 — reenvia o aviso de assinatura para quem ainda não assinou.
     *
     * ⚠️ O 429 deste endpoint é resposta ESPERADA da Clicksign (rate limit
     * anti-spam próprio, medido em `CLICKSIGN-SANDBOX-EMPIRICO.md` §14) —
     * nunca tratado como erro. Uma tentativa por clique: sem retry próprio
     * (o `ClicksignClient::reenviarNotificacao()` já não retenta).
     */
    public function reenviar(
        ContratoAssinatura $contratoAssinatura,
        ContratoAssinaturaSignatario $signatario,
        ClicksignClient $client,
    ): RedirectResponse {
        // T-131-05-01 (IDOR) — o signatário informado precisa pertencer ao
        // contrato informado, checado ANTES de qualquer I/O.
        if ($signatario->contrato_assinatura_id !== $contratoAssinatura->id) {
            abort(422, 'O signatário informado não pertence a este contrato.');
        }

        // T-131-05-02 — o reenvio só faz sentido com o contrato aguardando
        // assinaturas e a pessoa ainda pendente (UI-SPEC só oferece a ação
        // nesse estado).
        if ($contratoAssinatura->status !== ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS) {
            abort(422, 'Este contrato não está aguardando assinaturas.');
        }

        if ($signatario->situacao !== ContratoAssinaturaSignatario::SITUACAO_PENDENTE) {
            abort(422, 'Esta pessoa já respondeu — não há aviso para reenviar.');
        }

        if (blank($contratoAssinatura->clicksign_envelope_id) || blank($signatario->clicksign_signer_key)) {
            abort(422, 'Este contrato ainda não foi enviado.');
        }

        try {
            $client->reenviarNotificacao($contratoAssinatura->clicksign_envelope_id, $signatario->clicksign_signer_key);
        } catch (ClicksignException $e) {
            if ($e->httpStatus === 429) {
                // Resposta ESPERADA — canal `aviso` (âmbar/neutro), nunca
                // `error`. UI-SPEC: "Aguarde um pouco antes de reenviar".
                return back()->with('aviso', 'Você reenviou recentemente. Espere alguns minutos e tente de novo — isso evita marcar o convite como spam.');
            }

            // Nunca ecoar a mensagem crua da API na tela — o detalhe já foi
            // logado dentro do próprio ClicksignClient.
            return back()->with('error', 'Não deu para reenviar o aviso agora. Tente de novo em alguns minutos.');
        }

        return back()->with('success', 'Aviso reenviado.');
    }

    /**
     * CLICK-10/D-13 — registra a INTENÇÃO de cancelamento (autor + motivo +
     * data). Não cancela nada de verdade: medido que a API v3 não permite
     * cancelar envelope em `running` (`CLICKSIGN-SANDBOX-EMPIRICO.md` §15.2).
     * O cancelamento real é feito no painel da Clicksign; quando o webhook
     * `cancel` chegar (Fase 129), o estado fecha sozinho.
     *
     * ⛔ NÃO chama o método de cancelamento do `ClicksignClient` (medido:
     * 403 em envelope `running`) nem qualquer outro método do client —
     * nenhuma requisição HTTP sai desta action.
     */
    public function registrarCancelamento(Request $request, ContratoAssinatura $contratoAssinatura): RedirectResponse
    {
        $data = $request->validate([
            'motivo' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        // Só faz sentido registrar cancelamento de contrato vivo.
        if (! in_array($contratoAssinatura->status, ContratoAssinatura::STATUS_EM_ANDAMENTO, true)) {
            abort(422, 'Este contrato não está em andamento.');
        }

        if (filled($contratoAssinatura->cancelamento_solicitado_em)) {
            abort(422, 'Este contrato já tem um cancelamento registrado.');
        }

        // fill()+save() no model — nunca updateQuietly()/query builder, o
        // hook `saving` é quem alimenta a trava composta
        // ca_empresa_servico_andamento_uniq. NÃO alterar `status`: quem
        // fecha o estado é o webhook `cancel` (Fase 129).
        $contratoAssinatura->fill([
            'cancelamento_motivo'                => $data['motivo'],
            'cancelamento_solicitado_por_user_id' => $request->user()->id,
            'cancelamento_solicitado_em'          => now(),
        ]);
        $contratoAssinatura->save();

        return back()->with('success', 'Cancelamento registrado. Agora conclua no painel da Clicksign.');
    }

    /**
     * Quick 260825-dap — "Refazer contrato": o Administrativo corrige um
     * contrato com dado errado (e-mail, razão social, endereço, valor) sem
     * precisar apagar a empresa no banco (era o que o desenvolvedor vinha
     * fazendo à mão, onze vezes em 24-25/08). Cancela o envelope atual na
     * Clicksign e gera um novo, pelo MESMO funil de sempre
     * (`GatilhoContratoAdministrativoService::dispararSeElegivel()`), com os
     * dados que estão no cadastro AGORA.
     *
     * Motivo do incidente: os dados de um contrato ficam CONGELADOS no
     * envelope assim que ele nasce — a Clicksign não deixa editar
     * signatário de envelope criado por API (medido em 2026-08-24), e
     * salvar o cadastro nesta tela não "empurra" nada para lá.
     *
     * A sequência, NESTA ordem:
     * 1. Cancela o envelope na Clicksign (se houver um — rascunho que nunca
     *    chegou a montar envelope não tem o que cancelar).
     *    ⚠️ Se `cancelarEnvelope()` devolver `false`, PARA aqui — nenhum
     *    contrato novo nasce. Um envelope antigo ainda vivo significa que o
     *    cliente pode assinar o contrato ERRADO: dois contratos válidos da
     *    mesma empresa circulando ao mesmo tempo. O Administrativo precisa
     *    cancelar pelo painel da Clicksign antes de tentar de novo.
     * 2. Fecha o contrato antigo como `cancelado`.
     * 3. Gera o novo pelo funil único — mesmo caminho do botão "Gerar
     *    contrato" e do gatilho automático (`gerarContrato()` acima nunca é
     *    chamado daqui, mas o service por trás é o mesmo).
     *
     * ⚠️ CONTRASTE DE PROPÓSITO com `registrarCancelamento()` (leia o
     * docblock de lá antes de "corrigir" isto — a diferença é intencional,
     * não incoerência): lá, o cancelamento registrado é só INTENÇÃO — quem
     * cancela de verdade é um humano no painel da Clicksign, e por isso o
     * `status` fica intocado até o webhook `cancel` confirmar (ver Fase
     * 129). AQUI é diferente: nós mesmos chamamos `cancelarEnvelope()` pela
     * API e SÓ avançamos quando ela confirma (`true`) — a confirmação já
     * aconteceu de forma síncrona. Fechar o `status` agora, sem esperar o
     * webhook, é o que libera a trava composta
     * `ca_empresa_servico_andamento_uniq` para o contrato novo poder nascer
     * no passo 3 acima — se esperássemos o webhook (como `registrarCancelamento()`
     * faz), o passo 3 bateria de novo em "já em andamento" e nada seria
     * gerado. Os dois métodos protegem premissas diferentes: um cancela por
     * FORA (painel humano, sem confirmação síncrona), o outro cancela por
     * DENTRO (API, com confirmação síncrona) — por isso um espera o webhook
     * e o outro não.
     *
     * `fill()` + `save()` no model — nunca `updateQuietly()`/query builder,
     * mesma disciplina de `registrarCancelamento()`: é o hook `saving` de
     * `ContratoAssinatura` quem alimenta a coluna sombra da trava composta.
     */
    public function refazer(
        Request $request,
        ContratoAssinatura $contratoAssinatura,
        ClicksignClient $client,
        GatilhoContratoAdministrativoService $gatilho,
    ): RedirectResponse {
        $data = $request->validate([
            'motivo' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        // ⚠️ Contrato assinado é documento jurídico — NUNCA se refaz. É o
        // item mais caro de errar deste quick.
        if ($contratoAssinatura->status === ContratoAssinatura::STATUS_ASSINADO) {
            return back()->with('error', 'Este contrato já foi assinado. Contrato assinado é documento jurídico — não pode ser refeito.');
        }

        // Só faz sentido refazer um contrato vivo (em andamento) ou que
        // falhou tecnicamente ao ser criado (erro). Recusado/expirado/
        // cancelado ficam de fora — não é o caso que este quick resolve
        // (o dado errado já não vai virar um contrato válido de qualquer
        // forma nesses estados; quem precisar de um contrato novo usa
        // "Gerar contrato"/"Tentar novamente" normalmente).
        $statusPermiteRefazer = [...ContratoAssinatura::STATUS_EM_ANDAMENTO, ContratoAssinatura::STATUS_ERRO];
        if (! in_array($contratoAssinatura->status, $statusPermiteRefazer, true)) {
            return back()->with('error', 'Este contrato não está em andamento nem com erro — não há o que refazer.');
        }

        $company = $contratoAssinatura->company;

        // 1. Cancela o envelope atual na Clicksign.
        if (filled($contratoAssinatura->clicksign_envelope_id)) {
            $cancelou = $client->cancelarEnvelope($contratoAssinatura->clicksign_envelope_id);

            if (! $cancelou) {
                Log::warning('[Administrativo] Refazer contrato: falha ao cancelar envelope na Clicksign — abortado antes de gerar o novo', [
                    'contrato_id' => $contratoAssinatura->id,
                    'company_id'  => $company->id,
                ]);

                // ⚠️ PARA aqui — nenhum contrato novo nasce, o contrato
                // antigo continua intacto (nem status nem mais nada foi
                // alterado). Um envelope antigo ainda vivo permitiria dois
                // contratos válidos da mesma empresa circulando.
                return back()->with('error', 'Não deu para cancelar o contrato atual na Clicksign. Para não deixar dois contratos válidos circulando ao mesmo tempo, cancele manualmente pelo painel da Clicksign e tente refazer de novo depois.');
            }
        }

        // 2. Fecha o contrato antigo — ver o CONTRASTE explicado no
        // docblock acima: aqui SIM alteramos o status, de propósito.
        $contratoAssinatura->fill([
            'status'                               => ContratoAssinatura::STATUS_CANCELADO,
            'cancelamento_motivo'                  => $data['motivo'],
            'cancelamento_solicitado_por_user_id'  => $request->user()->id,
            'cancelamento_solicitado_em'           => now(),
        ]);
        $contratoAssinatura->save();

        // 3. Gera o novo pelo funil único — nunca reimplementar a criação
        // aqui (é o mesmo `GatilhoContratoAdministrativoService` que
        // `gerarContrato()` usa e que o Observer automático da Fase 128
        // dispara).
        $retorno = $gatilho->dispararSeElegivel($company);

        if ($retorno['status'] === 'disparado' && data_get($retorno, 'resultado.ok') === true) {
            $contratosNovosIds = data_get($retorno, 'resultado.criados', []);

            // Quick 260825-ixp — transporta `plano_parcelas_texto` (a frase
            // do parcelamento que a pessoa editou na tela) do contrato
            // ANTIGO para o NOVO. Sem isto, o override ficava preso no
            // contrato cancelado e o novo nascia com a coluna `null`
            // (voltava ao texto composto) — o único caminho de "editar → o
            // texto valer" é justamente o refazer, que era onde ele se
            // perdia (ver PLAN.md do quick).
            //
            // ⚠️ `dispararSeElegivel()` pode criar MAIS de um contrato (um
            // por serviço, se a empresa tiver outros serviços elegíveis no
            // mesmo disparo) — `criados` é só uma lista de ids, sem chave
            // por serviço. Filtramos pelo `servico_id` do contrato ANTIGO
            // para herdar o texto só no contrato certo, nunca em todos.
            //
            // ⚠️ Transporte LITERAL — nunca recompor. Se o texto composto
            // mudou (fases diferentes), quem manda é o que a pessoa
            // escreveu; ela vê o campo na tela e corrige antes de enviar.
            //
            // ⚠️ Se o antigo não tinha override (`null`), não faz nada — o
            // novo continua `null` e usa o composto, sem inventar valor.
            $planoParcelasTransportado = false;
            if ($contratoAssinatura->plano_parcelas_texto !== null) {
                $contratoNovoMesmoServico = ContratoAssinatura::whereIn('id', $contratosNovosIds)
                    ->where('servico_id', $contratoAssinatura->servico_id)
                    ->first();

                if (! $contratoNovoMesmoServico) {
                    // Fallback (não deveria ocorrer no fluxo normal, ver
                    // docblock de `dispararSeElegivel()`): busca pelo par
                    // (company_id, servico_id) em andamento depois da
                    // criação — a trava composta garante no máximo um.
                    $contratoNovoMesmoServico = ContratoAssinatura::where('company_id', $company->id)
                        ->where('servico_id', $contratoAssinatura->servico_id)
                        ->whereIn('status', ContratoAssinatura::STATUS_EM_ANDAMENTO)
                        ->latest('id')
                        ->first();
                }

                if ($contratoNovoMesmoServico) {
                    $contratoNovoMesmoServico->update([
                        'plano_parcelas_texto' => $contratoAssinatura->plano_parcelas_texto,
                    ]);
                    $planoParcelasTransportado = true;
                }
            }

            // Rastro (Tarefa 3) — quem refez, quando, o motivo, e a ligação
            // entre o contrato antigo e o(s) novo(s). O trait LogsActivity
            // do model já registra a MUDANÇA de status isolada (passo 2
            // acima); este registro MANUAL é o único lugar que amarra as
            // duas pontas — sem ele ninguém reconstrói depois por que
            // existem dois contratos do mesmo serviço.
            activity('administrativo')
                ->causedBy($request->user())
                ->withProperties([
                    'empresa'                      => $company->name,
                    'servico_id'                   => $contratoAssinatura->servico_id,
                    'contrato_antigo_id'           => $contratoAssinatura->id,
                    'contratos_novos_ids'          => $contratosNovosIds,
                    'motivo'                       => $data['motivo'],
                    // Quick 260825-ixp.
                    'plano_parcelas_transportado'  => $planoParcelasTransportado,
                ])
                ->log('Contrato refeito: "' . $company->name . '"');

            return back()->with('success', 'Contrato refeito. O novo contrato foi gerado com os dados atuais do cadastro — pode levar até um minuto para ficar pronto.');
        }

        // O contrato antigo já foi cancelado — é irreversível, o envelope
        // não existe mais na Clicksign. O que resta é explicar que o novo
        // não nasceu agora, sem prometer nada que a tela normal de "Gerar
        // contrato" (com os motivos de bloqueio já visíveis acima) não
        // resolva.
        return back()->with('error', 'O contrato anterior foi cancelado, mas não deu para gerar o novo agora. Confira a situação da empresa nesta tela e gere manualmente quando estiver tudo certo.');
    }

    /**
     * D-10 — absorve `ContratoLiberacaoManualController::store()` (Fase 130
     * Plano 04, REDE-03/DADOS-05, D-10/D-11/D-12) como ação da tela de
     * detalhe. O corpo é copiado VERBATIM, incluindo os comentários que
     * nomeiam as ameaças (T-130-04-03) — nenhuma mitigação foi afrouxada.
     *
     * D-11 — este método NÃO chama `GateLiberacaoOperacionalService::avaliar()`.
     * A liberação manual existe PORQUE o gate automático (e a reconciliação)
     * não liberaram; passar pelo mesmo gate aqui tornaria a ação inútil no
     * exato cenário em que ela precisa funcionar (Clicksign fora do ar,
     * contrato recusado pelo cliente, envelope apagado). O admin libera em
     * QUALQUER estado — a tela mostra o estado real em destaque antes de
     * confirmar, e a prestação de contas é o motivo obrigatório + o autor
     * registrado.
     */
    public function liberarManual(Request $request, EmpresaOperacionalRouter $router): RedirectResponse
    {
        $data = $request->validate([
            'company_id'             => ['required', 'integer', 'exists:companies,id'],
            'servico_id'             => ['required', 'integer', 'exists:servicos,id'],
            'contrato_assinatura_id' => ['nullable', 'integer', 'exists:contrato_assinaturas,id'],
            // D-12 — slug é lista fechada, nunca string livre.
            'motivo_slug'            => ['required', Rule::in(ContratoLiberacao::MOTIVOS_MANUAIS)],
            // D-12 — o detalhe é obrigatório MESMO com o slug preenchido.
            'motivo_detalhe'         => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $company = Company::findOrFail($data['company_id']);
        $servico = Servico::findOrFail($data['servico_id']);

        $contrato = null;
        if (! empty($data['contrato_assinatura_id'])) {
            $contrato = ContratoAssinatura::findOrFail($data['contrato_assinatura_id']);

            // T-130-04-03 (IDOR) — o contrato informado tem que pertencer à
            // MESMA empresa/serviço do POST, senão a liberação de uma
            // empresa ficaria amarrada à evidência de outra.
            if ($contrato->company_id !== $company->id || $contrato->servico_id !== $servico->id) {
                abort(422, 'O contrato informado não pertence a esta empresa/serviço.');
            }
        }

        // D-11 — NÃO chamar GateLiberacaoOperacionalService::avaliar() aqui.
        // A liberação manual existe porque o gate automático (webhook +
        // reconciliação) NÃO liberou; passar pelo mesmo gate tornaria esta
        // ação inútil no exato cenário em que ela é necessária.
        $router->liberarEmpresa(
            $company,
            $servico,
            ContratoLiberacao::VIA_MANUAL,
            contrato: $contrato,
            liberadoPorUserId: $request->user()->id,
            motivo: $data['motivo_detalhe'],
            motivoSlug: $data['motivo_slug'],
        );

        return back()->with('success', 'Empresa liberada para o operacional.');
    }
}
