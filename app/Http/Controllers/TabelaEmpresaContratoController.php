<?php

namespace App\Http\Controllers;

use App\Http\Requests\SalvarFaixasContratoRequest;
use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\ContratoTabelaProposta;
use App\Models\EmpresaFaixaFaturamento;
use App\Models\GrupoFaixaFaturamento;
use App\Models\Servico;
use App\Models\ServicoFaixaFaturamento;
use App\Services\Fechamento\FechamentoFaixaResolver;
use App\Services\Fechamento\GravarTabelaEmpresaService;
use App\Services\Fechamento\GravarTabelaGrupoService;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;

/**
 * TabelaEmpresaContratoController — a FICHA PERMANENTE da tabela de cobrança de uma empresa (Fase
 * 142 Plano 02, D-03), dentro do módulo de contratos.
 *
 * O usuário pediu que o cadastro da tabela progressiva por empresa ficasse dentro de
 * `/administrativo/contratos/empresa/{id}`, "por se tratar de algo relacionado ao contrato e não
 * ser algo que muda com frequência" — ver 142-CONTEXT.md §D-03. Esta ficha é o destino do botão
 * novo em `ContratoAdminController::show()`.
 *
 * ### Relação com `/administrativo/contratos/tabelas` (Fase 140, `TabelasContratoController`)
 * As duas telas convivem DE PROPÓSITO — não são a mesma coisa e não devem virar a mesma coisa
 * (`<decisao_de_projeto>` do 142-01-PLAN.md, leitura obrigatória antes de mexer aqui). A da Fase
 * 140 é uma CAIXA DE ENTRADA (fila do que foi lido do Clicksign, organizada por contrato, esvazia
 * com o tempo, grava `origem = contrato`). Esta é uma FICHA (permanente, organizada por empresa,
 * consultada e ajustada quantas vezes precisar, grava `origem = manual`). O que impede a
 * divergência entre as duas é a porta única de escrita (`GravarTabelaEmpresaService`, 142-01) — as
 * duas passam por ela — mais o link cruzado: `leitura_pendente` abaixo manda desta ficha para a
 * caixa de entrada quando há uma leitura aguardando conferência daquela empresa.
 *
 * ### Armadilha de autorização (razão de existir de `SalvarFaixasContratoRequest`)
 * O grupo de rotas de destino (`admin.contratos`) está FORA do `role:admin` de propósito —
 * permissão de setor, sem deploy. `SalvarFaixasFaturamentoRequest` (a validação de faixas já
 * existente, usada pelo Fechamento) autoriza só `isAdmin()`. Por isso esta ficha tem rotas e um
 * FormRequest PRÓPRIOS: `SalvarFaixasContratoRequest` herda a validação inteira e troca só a
 * autorização — nunca duas cópias da regra de sobreposição/buraco de faixa.
 *
 * @see app/Services/Fechamento/GravarTabelaEmpresaService.php (porta única de escrita)
 * @see app/Http/Requests/SalvarFaixasContratoRequest.php (autorização própria, validação herdada)
 * @see .planning/phases/142-cadastro-da-tabela-progressiva-no-contrato/142-01-PLAN.md (decisão de projeto)
 * @see .planning/phases/142-cadastro-da-tabela-progressiva-no-contrato/142-02-PLAN.md
 */
class TabelaEmpresaContratoController extends Controller
{
    /**
     * GET /administrativo/contratos/empresa/{company}/tabela — a ficha.
     *
     * Props ACHATADAS (mesma disciplina de `TabelasContratoController`/`ContratoAdminController`)
     * — nunca o model inteiro.
     */
    public function show(Company $company, FechamentoFaixaResolver $resolver): \Inertia\Response
    {
        $company->loadMissing('grupo');

        // As linhas GRAVADAS da tabela própria — nunca uma reconstrução (é a dívida que o 142-01
        // pagou expondo `tabela_faixas` no fechamento; esta ficha lê o mesmo dado cru).
        $tabelaEmpresa = EmpresaFaixaFaturamento::where('company_id', $company->id)
            ->ordenadas()
            ->get();

        // Todas as linhas de uma empresa compartilham a mesma origem (mesma disciplina do
        // GravarTabelaEmpresaService e do FechamentoFaixaResolver::paraEmpresa()).
        $procedenciaEmpresa = $tabelaEmpresa->first()->origem ?? null;

        $tabelaGrupo = $company->company_group_id !== null
            ? GrupoFaixaFaturamento::where('company_group_id', $company->company_group_id)->ordenadas()->get()
            : null;

        // "Qual tabela está cobrando esta empresa HOJE" — não é necessariamente a da empresa (a
        // do grupo vence, Fase 138).
        $resolvido = $resolver->paraEmpresa($company);

        // Link cruzado (decisão de projeto do 142-01) para a caixa de entrada da Fase 140.
        // `company_id` da proposta é PALPITE da leitura automática, nunca vínculo confirmado — a
        // tela (plano 03) precisa dizer isso; aqui só entrega o dado.
        $leituraPendente = ContratoTabelaProposta::query()
            ->where('company_id', $company->id)
            ->where('situacao', ContratoTabelaProposta::SITUACAO_PENDENTE)
            ->first();

        return Inertia::render('Admin/TabelaEmpresa', [
            'company' => [
                'id'                => $company->id,
                'name'              => $company->name,
                'company_group_id'  => $company->company_group_id,
                'grupo_nome'        => $company->grupo?->name,
            ],
            'tabela_empresa'       => $this->achatarFaixas($tabelaEmpresa),
            'procedencia_empresa'  => $procedenciaEmpresa,
            'tabela_grupo'         => $tabelaGrupo === null ? null : $this->achatarFaixas($tabelaGrupo),
            'tabela_aplicada'      => $resolvido === null ? null : [
                'origem'                   => $resolvido['origem'],
                'servico_nome'             => $resolvido['servico_nome'],
                'grupo_nome'               => $resolvido['grupo_nome'],
                'herdada_de_company_name'  => $resolvido['herdada_de_company_name'],
                'faixas'                   => $this->achatarFaixas($resolvido['faixas']),
            ],
            'modelos_de_partida'  => $this->modelosDePartida(),
            'leitura_pendente'    => $leituraPendente === null ? null : [
                'id'            => $leituraPendente->id,
                'nome_envelope' => $leituraPendente->nome_envelope,
                'tem_tabela'    => $leituraPendente->tipo_cobranca === ContratoTabelaProposta::TIPO_TABELA,
            ],
        ]);
    }

    /**
     * POST /administrativo/contratos/empresa/{company}/tabela — grava a tabela própria da
     * empresa (`origem = manual`), pela porta única.
     */
    public function salvar(SalvarFaixasContratoRequest $request, Company $company, GravarTabelaEmpresaService $servico): RedirectResponse
    {
        $resultado = $servico->gravar(
            $company,
            $request->validated('faixas'),
            EmpresaFaixaFaturamento::ORIGEM_MANUAL,
            null,
            $request->user(),
            'contrato_ficha',
        );

        $response = back()->with('success', 'Tabela de cobrança salva.');

        // Aviso neutro — não é erro, a pessoa pode ter substituído de propósito uma tabela que
        // já tinha sido confirmada por outro caminho.
        if ($resultado['substituiu_confirmada'] === true) {
            $textoOrigemAnterior = $resultado['origem_anterior'] === EmpresaFaixaFaturamento::ORIGEM_CONTRATO
                ? 'tinha vindo do contrato assinado'
                : 'já tinha sido cadastrada à mão antes';

            $response = $response->with(
                'aviso',
                "A tabela que estava aqui {$textoOrigemAnterior} e foi substituída pelo cadastro feito agora."
            );
        }

        return $response;
    }

    /**
     * DELETE /administrativo/contratos/empresa/{company}/tabela — apaga a tabela própria da
     * empresa. Sem FormRequest (não há payload a validar) — guard próprio via `abort_unless`,
     * mesma forma de `FechamentoController::removerFaixasEmpresa` (mas com a permissão do módulo
     * de contratos, não só `isAdmin()`).
     */
    public function remover(Request $request, Company $company, GravarTabelaEmpresaService $servico): RedirectResponse
    {
        abort_unless($this->podeMexerNaTabela($request), 403);

        $servico->remover($company, $request->user(), 'contrato_ficha');

        return back()->with('success', 'Tabela de cobrança removida.');
    }

    /**
     * GET /administrativo/contratos/grupo/{grupo}/tabela — a ficha da tabela de cobrança de um
     * GRUPO (Fase 143 Plano 03, T2).
     *
     * ⚠️ **Por que esta página precisou existir.** Até aqui, a tabela de um grupo só era editável
     * de dentro da ficha de uma empresa-membro (`Admin/TabelaEmpresa.jsx`, bloco 5). Isso não
     * alcança o caso que abriu a Fase 143: o grupo que fica POR CIMA dos outros pode não ter
     * empresa nenhuma pendurada direto nele — e é justamente a tabela dele que governa a cobrança
     * de todas as empresas abaixo. Sem esta página, essa tabela era inalcançável pela tela.
     *
     * Props ACHATADAS, mesma disciplina de `show()` — nunca o model inteiro.
     */
    public function showGrupo(CompanyGroup $grupo, FechamentoFaixaResolver $resolver): \Inertia\Response
    {
        $grupo->loadMissing('pai');

        // As linhas GRAVADAS deste grupo — nunca uma reconstrução.
        $tabelaGrupo = GrupoFaixaFaturamento::where('company_group_id', $grupo->id)
            ->ordenadas()
            ->get();

        $subgrupos = CompanyGroup::where('parent_id', $grupo->id)
            ->orderBy('name')
            ->get(['id', 'name']);

        // As empresas que esta tabela alcança: as do próprio grupo MAIS as dos grupos pendurados
        // nele. É o número que a pessoa precisa ver antes de salvar — 10 empresas, não 2.
        $idsDoConjunto = $subgrupos->pluck('id')->push($grupo->id)->all();

        $empresas = Company::whereIn('company_group_id', $idsDoConjunto)
            ->orderBy('name')
            ->get(['id', 'name', 'company_group_id', 'active']);

        $nomePorGrupoId = $subgrupos->pluck('name', 'id')->put($grupo->id, $grupo->name);

        // Qual tabela de fato governa este conjunto hoje. Sem âncora de propósito: a pergunta
        // aqui é "existe tabela cadastrada neste grupo ou no grupo em que ele está?", não "o que
        // a empresa que mais faturou emprestaria" — essa herança é assunto da ficha de empresa.
        $queVale = $resolver->paraGrupo($grupo, null);

        return Inertia::render('Admin/TabelaGrupo', [
            'grupo' => [
                'id'   => $grupo->id,
                'name' => $grupo->name,
                'pai'  => $grupo->pai === null ? null : [
                    'id'   => $grupo->pai->id,
                    'name' => $grupo->pai->name,
                ],
            ],
            'tabela_grupo'   => $this->achatarFaixas($tabelaGrupo),
            'tabela_que_vale' => $queVale === null ? null : [
                'grupo_id'   => $queVale['grupo_id'],
                'grupo_nome' => $queVale['grupo_nome'],
                'faixas'     => $this->achatarFaixas($queVale['faixas']),
            ],
            'subgrupos' => $subgrupos
                ->map(fn (CompanyGroup $g) => [
                    'id'             => $g->id,
                    'name'           => $g->name,
                    'empresas_count' => $empresas->where('company_group_id', $g->id)->count(),
                ])
                ->values()
                ->all(),
            'empresas' => $empresas
                ->map(fn (Company $c) => [
                    'id'         => $c->id,
                    'name'       => $c->name,
                    'grupo_nome' => $nomePorGrupoId[$c->company_group_id] ?? null,
                    'ativa'      => (bool) $c->active,
                ])
                ->values()
                ->all(),
            'modelos_de_partida' => $this->modelosDePartida(),
        ]);
    }

    /**
     * POST /administrativo/contratos/grupo/{grupo}/tabela — substitui a tabela INTEIRA do grupo,
     * pela porta única `GravarTabelaGrupoService` (Fase 143 Plano 03, T1).
     *
     * ⚠️ Até a Fase 143 isto era um `delete()` de query builder seguido de `create()` em laço,
     * sem uma única chamada a `activity()` no arquivo inteiro: **a tabela anterior evaporava sem
     * registro**. Com a árvore de grupos, a tabela de um grupo-pai governa a cobrança de todas as
     * empresas abaixo dele (10, no caso que abriu a fase) — trocar isso sem rastro é trocar a
     * mensalidade de dez clientes sem ninguém saber quem fez nem qual era o valor anterior.
     *
     * `GrupoFaixaFaturamento` não tem coluna de origem (não ganha uma nesta fase) — por isso a
     * porta é a do grupo, e não `GravarTabelaEmpresaService` (que é a de
     * `empresa_faixas_faturamento`, com a trava de precedência das três origens).
     */
    public function salvarGrupo(SalvarFaixasContratoRequest $request, CompanyGroup $grupo, GravarTabelaGrupoService $servico): RedirectResponse
    {
        $resultado = $servico->gravar(
            $grupo,
            $request->validated('faixas'),
            $request->user(),
            'contrato_ficha',
        );

        $response = back()->with('success', 'Tabela do grupo salva.');

        // Aviso neutro — não é erro; a pessoa pode ter substituído de propósito. O que ela
        // precisa ver é o TAMANHO do que acabou de mudar.
        if ($resultado['substituiu'] === true && $resultado['empresas_governadas'] > 0) {
            $response = $response->with(
                'aviso',
                $resultado['empresas_governadas'] === 1
                    ? 'A tabela que estava aqui foi substituída. Ela vale para 1 empresa.'
                    : "A tabela que estava aqui foi substituída. Ela vale para {$resultado['empresas_governadas']} empresas."
            );
        }

        return $response;
    }

    /**
     * DELETE /administrativo/contratos/grupo/{grupo}/tabela — apaga a tabela própria do grupo,
     * pela mesma porta única (que registra a trilha com `depois = []`).
     */
    public function removerGrupo(Request $request, CompanyGroup $grupo, GravarTabelaGrupoService $servico): RedirectResponse
    {
        abort_unless($this->podeMexerNaTabela($request), 403);

        $servico->remover($grupo, $request->user(), 'contrato_ficha');

        return back()->with('success', 'Grupo voltou a usar a tabela da empresa.');
    }

    /**
     * Guard duplo repetido nos dois endpoints sem FormRequest (`remover`/`removerGrupo`) — mesma
     * dupla checagem de `SalvarFaixasContratoRequest::authorize()`.
     */
    private function podeMexerNaTabela(Request $request): bool
    {
        return $request->user()?->isAdmin() === true
            || $request->user()?->hasPermission(Permissions::ADMIN_CONTRATOS) === true;
    }

    /**
     * Catálogo de serviços com tabela cadastrada, para o botão "começar a partir da tabela do
     * serviço X" — mesma consulta e mesma regra de "quais serviços entram" de
     * `AdminController::fechamentoFaixasPorServico()` (Fase 137), replicada aqui (não extraída
     * para um service compartilhado: o controller de origem é grande demais para mexer só por
     * isto). Nunca duas regras diferentes de universo de serviços — qualquer mudança na regra de
     * lá precisa ser espelhada aqui.
     *
     * @return array<int, array{id:int, nome:string, faixas:array}>
     */
    private function modelosDePartida(): array
    {
        return Servico::query()
            ->where('ativo', true)
            ->where(function ($q) {
                $q->whereIn('setor', Servico::SETORES_FINANCEIROS)
                    ->orWhereNotNull('plataforma');
            })
            ->orderBy('nome')
            ->get(['id', 'nome'])
            ->map(fn (Servico $s) => [
                'id'     => $s->id,
                'nome'   => $s->nome,
                'faixas' => $this->achatarFaixas(
                    ServicoFaixaFaturamento::where('servico_id', $s->id)->ordenadas()->get()
                ),
            ])
            // Só entra no catálogo quem TEM tabela cadastrada — "modelo de partida" pressupõe
            // ter algo para copiar.
            ->filter(fn (array $item) => $item['faixas'] !== [])
            ->values()
            ->all();
    }

    /**
     * Achata uma coleção de faixas (empresa, grupo ou serviço — todas têm o mesmo shape de
     * colunas) para o formato que a tela consome. Nunca o model inteiro.
     *
     * @return array<int, array{ordem:int, limite_superior:?float, valor:float, valor_e_piso:bool}>
     */
    private function achatarFaixas(iterable $faixas): array
    {
        return Collection::make($faixas)
            ->map(fn ($f) => [
                'ordem'           => $f->ordem,
                'limite_superior' => $f->limite_superior !== null ? (float) $f->limite_superior : null,
                'valor'           => (float) $f->valor,
                'valor_e_piso'    => (bool) $f->valor_e_piso,
            ])
            ->values()
            ->all();
    }
}
