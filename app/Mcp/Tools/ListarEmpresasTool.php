<?php

namespace App\Mcp\Tools;

use App\Mcp\ErroDaFerramenta;
use App\Models\Company;
use App\Models\User;
use App\Services\Empresas\EmpresasVisiveisService;
use App\Support\Permissions;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

/**
 * `listar_empresas` — a lista de `/companies` (Cadastro de empresas).
 *
 * O universo vem de {@see EmpresasVisiveisService::consulta()}, a MESMA
 * consulta da tela: empresas de Performance (Gestão + Mentoria) sem
 * MlbEmpresa, com o recorte da Fase 157 (analista/estrategista só veem as
 * próprias). Os filtros que na tela rodam no navegador (busca, serviço) aqui
 * rodam no servidor, sobre esse mesmo conjunto.
 *
 * Padrão = a aba "Empresas" da tela: só empresas EM OPERAÇÃO (com analista ou
 * estrategista) e ativas. É esse o número do rótulo "Empresas (N)".
 *
 * Fora de propósito: e-mail, telefone e CNPJ do cliente (dado pessoal que a
 * pergunta "quais empresas…" não precisa).
 */
#[Name('listar_empresas')]
#[Title('Listar empresas (Cadastro)')]
#[Description(<<<'TXT'
Lista as empresas da carteira de Performance (Gestão e Mentoria) — a mesma lista da tela /companies do ECF Admin, com o mesmo recorte do seu perfil. Use para responder "quais empresas", "de quem é a empresa X", "qual o CUST de…", "quantas empresas o analista Y tem", "quais estão sem grant ativo".
Por padrão devolve o que a aba "Empresas" mostra: empresas ativas e em operação (com analista ou estrategista). O campo `total` é o mesmo número do rótulo "Empresas (N)" da tela.
Não inclui empresas dos Polos (use painel_polos/onboarding_polos) nem contas só de Publicação.
TXT)]
class ListarEmpresasTool extends FerramentaEcf
{
    private const PENDENCIAS = ['sem_responsavel', 'sem_cust_id', 'sem_email_colaborador', 'sem_grant_ativo', 'empresa_nova'];

    protected function podeUsar(User $usuario): bool
    {
        // Mesma régua da rota GET /companies (permission:core.empresas).
        return $usuario->hasPermission(Permissions::CORE_EMPRESAS);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'busca' => $schema->string()
                ->description('Parte do nome da empresa, do segmento ou do CUST (Cust ID da Adman / ID da loja no ML). Igual à caixa "Buscar empresa" da tela.'),
            'analista' => $schema->string()
                ->description('Nome (ou parte) ou id do analista responsável (slot de Performance).'),
            'estrategista' => $schema->string()
                ->description('Nome (ou parte) ou id do estrategista responsável (slot de Performance).'),
            'servico' => $schema->string()
                ->description('Nome (ou parte) ou id do serviço contratado ativo, ex.: "Gestão", "Mentoria".'),
            'status' => $schema->string()
                ->enum(['ativa', 'inativa', 'todas'])
                ->description('Situação do cadastro. Padrão: "ativa".'),
            'etapa' => $schema->string()
                ->enum([...Company::ETAPAS, 'sem_etapa'])
                ->description('Etapa da empresa no fluxo de entrada (máquina de estados). "sem_etapa" = cadastro legado sem etapa.'),
            'pendencia' => $schema->string()
                ->enum(self::PENDENCIAS)
                ->description('Só empresas com esta pendência de cadastro.'),
            'incluir_fora_de_operacao' => $schema->boolean()
                ->description('Inclui empresas SEM analista e SEM estrategista (as que a tela mostra na aba Distribuição, não na aba Empresas). Padrão: false.'),
            ...$this->schemaPaginacao($schema),
        ];
    }

    protected function consultar(Request $request, User $usuario): array
    {
        $etapa = $this->texto($request, 'etapa');
        if ($etapa !== null && ! in_array($etapa, [...Company::ETAPAS, 'sem_etapa'], true)) {
            throw new ErroDaFerramenta('Etapa desconhecida: "'.$etapa.'". Valores aceitos: '.implode(', ', [...Company::ETAPAS, 'sem_etapa']).'.');
        }

        $status       = $this->texto($request, 'status') ?? 'ativa';
        $pendencia    = $this->texto($request, 'pendencia');
        $busca        = $this->texto($request, 'busca');
        $analista     = $this->texto($request, 'analista');
        $estrategista = $this->texto($request, 'estrategista');
        $servico      = $this->texto($request, 'servico');
        $foraDeOperacao = $this->booleano($request, 'incluir_fora_de_operacao');

        $empresas = app(EmpresasVisiveisService::class)->consulta($usuario)
            ->with([
                // Sem projeção de colunas: são belongsToMany pela pivot
                // company_users, e `id` ficaria ambíguo — o controller também
                // carrega inteiras.
                'analistaPerformance',
                'estrategistaPerformance',
                'contratosServico' => fn ($q) => $q->where('ativo', true)->with('servico:id,nome'),
                'mlToken',
                'grupo:id,name',
            ])
            ->withCount(['grants as grants_ativos_count' => fn ($q) => $q->where('status', 'active')])
            ->when($etapa === 'sem_etapa', fn ($q) => $q->whereNull('etapa'))
            ->when($etapa !== null && $etapa !== 'sem_etapa', fn ($q) => $q->where('etapa', $etapa))
            ->orderBy('name')
            ->get()
            ->map(fn (Company $c) => $this->linha($c));

        // Contagem do rótulo "Empresas (N)" da tela, ANTES dos filtros desta
        // chamada — é o número que se confere contra a tela.
        $totalAbaEmpresas = $empresas->filter(fn ($e) => $e['em_operacao'] && $e['ativa'])->count();

        $filtradas = $empresas
            ->when(! $foraDeOperacao, fn ($c) => $c->filter(fn ($e) => $e['em_operacao']))
            ->when($status === 'ativa', fn ($c) => $c->filter(fn ($e) => $e['ativa']))
            ->when($status === 'inativa', fn ($c) => $c->reject(fn ($e) => $e['ativa']))
            ->when($busca, fn ($c) => $c->filter(fn ($e) => $this->casaBusca($e, $busca)))
            ->when($analista, fn ($c) => $c->filter(fn ($e) => $this->casaPessoa($e['analista'], $analista)))
            ->when($estrategista, fn ($c) => $c->filter(fn ($e) => $this->casaPessoa($e['estrategista'], $estrategista)))
            ->when($servico, fn ($c) => $c->filter(fn ($e) => $this->casaServico($e['_servicos'], $servico)))
            ->when($pendencia, fn ($c) => $c->filter(fn ($e) => in_array($pendencia, $e['pendencias'], true)))
            ->map(function (array $e) {
                unset($e['_servicos']);

                return $e;
            });

        return [
            ...$this->paginar($filtradas, $request),
            'total_aba_empresas_da_tela' => $totalAbaEmpresas,
            'fonte' => 'Banco do ECF Admin, ao vivo (mesma lista de /companies).',
        ];
    }

    /** @return array<string, mixed> */
    private function linha(Company $c): array
    {
        $analista     = $c->analistaPerformance->first();
        $estrategista = $c->estrategistaPerformance->first();

        return [
            'id'           => $c->id,
            'empresa'      => $c->name,
            'cust_id'      => $c->cust_id,
            'ml_store_id'  => $c->ml_store_id,
            'segmento'     => $c->segment,
            'ativa'        => (bool) $c->active,
            'etapa'        => $c->etapa,
            // Mesma régua do CompanyController: "em operação" = tem analista OU
            // estrategista (a empresa sem os dois vai para a aba Distribuição).
            'em_operacao'  => $analista !== null || $estrategista !== null,
            'analista'     => $analista ? ['id' => $analista->id, 'nome' => $analista->name] : null,
            'estrategista' => $estrategista ? ['id' => $estrategista->id, 'nome' => $estrategista->name] : null,
            'servicos'     => $c->contratosServico->map(fn ($ct) => $ct->servico?->nome)->filter()->values()->all(),
            'grant_ativo'  => $c->grants_ativos_count > 0,
            'ml_token'     => $c->mlToken?->status,
            'grupo'        => $c->grupo?->name,
            // Mesmas pendências e mesma ordem do CompanyController::index().
            'pendencias'   => array_values(array_filter([
                ($analista === null || $estrategista === null) ? 'sem_responsavel' : null,
                (! $c->adman_account_id && ! $c->ml_store_id)   ? 'sem_cust_id' : null,
                (! $c->email_colaborador)                       ? 'sem_email_colaborador' : null,
                ($c->grants_ativos_count < 1)                  ? 'sem_grant_ativo' : null,
                $c->empresa_nova                                ? 'empresa_nova' : null,
            ])),
            // Só para o filtro de serviço (id + nome); sai da resposta.
            '_servicos'    => $c->contratosServico->map(fn ($ct) => ['id' => $ct->servico?->id, 'nome' => $ct->servico?->nome])->all(),
        ];
    }

    /** Mesma busca da caixa "Buscar empresa": nome, segmento ou CUST. */
    private function casaBusca(array $e, string $busca): bool
    {
        $q = Str::lower($busca);

        return Str::contains(Str::lower((string) $e['empresa']), $q)
            || Str::contains(Str::lower((string) $e['segmento']), $q)
            || Str::contains((string) $e['cust_id'], $busca)
            || Str::contains((string) $e['ml_store_id'], $busca);
    }

    private function casaPessoa(?array $pessoa, string $filtro): bool
    {
        if ($pessoa === null) {
            return false;
        }

        return ctype_digit($filtro)
            ? (int) $filtro === $pessoa['id']
            : Str::contains(Str::lower(Str::ascii($pessoa['nome'])), Str::lower(Str::ascii($filtro)));
    }

    /** @param array<int, array{id:?int, nome:?string}> $servicos */
    private function casaServico(array $servicos, string $filtro): bool
    {
        foreach ($servicos as $s) {
            if (ctype_digit($filtro) ? (int) $filtro === $s['id'] : Str::contains(Str::lower(Str::ascii((string) $s['nome'])), Str::lower(Str::ascii($filtro)))) {
                return true;
            }
        }

        return false;
    }
}
