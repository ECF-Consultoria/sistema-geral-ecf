<?php

namespace App\Services\FluxoEntrada;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Setor;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * DistribuicaoService — a fila da Coordenação e o ato de distribuir
 * (Fase 154, DISTRIB-01..04).
 *
 * **Esta fase não tem migration.** As duas funções já existem no enum de
 * `company_users.role`, e "quem distribuiu e quando" já é gravado pela linha de
 * transição 5→6 em `company_etapa_transicoes` (D-C). Nada é acrescentado a
 * `companies`, que tem ~500 linhas em produção.
 */
class DistribuicaoService
{
    public const ROLE_ANALISTA     = 'analista';
    public const ROLE_ESTRATEGISTA = 'estrategista';

    /** Janela em que uma empresa recém-distribuída ainda conta como "novo cliente" (RESP-02). */
    public const DIAS_NOVO_CLIENTE = 14;

    public function __construct(private EtapaTransicaoService $etapas)
    {
    }

    /**
     * Slug do setor cujo LÍDER distribui (Fase 157, D-B). Casa com
     * `servicos.setor = 'performance'`.
     */
    public const SETOR_DA_LIDERANCA = 'performance';

    /**
     * Quem pode distribuir — **fonte única** das duas portas (Fase 157).
     *
     * Existe aqui, e não em cada controller, porque a Fase 157 criou um segundo
     * caminho para o mesmo ato (a aba de `/companies`, além da tela da
     * Coordenação). Duas checagens divergiriam, e a divergência apareceria como
     * "vejo a fila mas o botão dá 403" — que foi exatamente o que o teste pegou
     * antes de isto existir.
     *
     * Três caminhos, nesta ordem: admin; líder do setor Performance (o dono do
     * ato desde a Fase 157); e quem tem a chave `coordenacao.distribuir` da
     * Fase 154, preservada para não tirar acesso de quem já a tinha.
     */
    public function podeDistribuir(?User $usuario): bool
    {
        if ($usuario === null) {
            return false;
        }

        if ($usuario->isAdmin()) {
            return true;
        }

        $setorId = Setor::where('slug', self::SETOR_DA_LIDERANCA)->value('id');

        if ($setorId !== null && $usuario->isLiderDe($setorId)) {
            return true;
        }

        return $usuario->hasPermission(Permissions::COORDENACAO_DISTRIBUIR);
    }

    /**
     * A fila da Coordenação (DISTRIB-01).
     *
     * **Deriva da ETAPA, não reconfere contrato** (D-D). A empresa só chega à
     * etapa 5 passando pelo FINALIZAR da Fase 152, que já exigiu o checklist
     * completo e o contrato assinado. Reconferir "contrato assinado" ao vivo
     * faria um contrato cancelado depois sumir a empresa da fila **no meio da
     * distribuição**, sem ninguém entender por quê. A etapa é a palavra da
     * máquina de estados; o resto é derivação.
     *
     * @return Collection<int, Company>
     */
    public function fila(): Collection
    {
        return Company::query()
            ->where('active', true)
            ->where('etapa', Company::ETAPA_AGUARDANDO_DISTRIBUICAO)
            // "ainda não têm responsáveis operacionais" — nenhum dos dois papéis.
            ->whereDoesntHave('users', function ($q) {
                $q->whereIn('company_users.role', [self::ROLE_ANALISTA, self::ROLE_ESTRATEGISTA]);
            })
            // ⚠️ Só entra na fila quem TEM serviço ativo. O vínculo de
            // responsável é por serviço (D-A), então empresa sem nenhum não tem
            // onde ser vinculada — `distribuir()` a recusa. Sem esta condição
            // ela aparecia na fila só para falhar no clique, com a mensagem
            // "não tem serviço ativo" depois de o usuário escolher as duas
            // pessoas. Listar o que não dá para fazer é pior que não listar.
            ->whereHas('contratosServico', fn ($q) => $q->where('ativo', true))
            ->with('contratosServico.servico')
            ->orderBy('name')
            ->get();
    }

    /**
     * Quem pode ser escolhido para cada função (DISTRIB-02, D-B).
     *
     * Régua em três passos:
     *   1. resolve o setor pelo `slug` == `servicos.setor` dos serviços ativos;
     *   2. se resolveu E há colaborador ativo com o cargo ali, devolve só esses;
     *   3. senão, devolve todo colaborador ativo com o cargo, em qualquer setor,
     *      e diz no retorno por que abriu.
     *
     * O passo 3 não é conveniência: foi **medido** que `servicos.setor` e
     * `setores.slug` não casam para `performance` (o setor de mais empresas) nem
     * para `outros`, e que os setores que casam quase não têm ninguém com o
     * cargo. Filtro estrito deixaria o seletor vazio para praticamente toda
     * empresa — a fase nasceria inutilizável. Ver `154-DECISOES.md` D-B.
     *
     * `motivo_abertura` é não-nulo exatamente quando a lista foi aberta, e a tela
     * o exibe: select que muda de universo em silêncio faz o coordenador achar
     * que aquele é o time daquele serviço.
     *
     * @return array{
     *   analistas: array<int, array{id:int, name:string}>,
     *   estrategistas: array<int, array{id:int, name:string}>,
     *   motivo_abertura: ?string,
     * }
     */
    public function elegiveis(Company $company): array
    {
        $setorIds = $this->setorIdsDaEmpresa($company);

        $analistas     = $this->porCargo(self::ROLE_ANALISTA, $setorIds);
        $estrategistas = $this->porCargo(self::ROLE_ESTRATEGISTA, $setorIds);

        $motivo = null;

        if ($setorIds === []) {
            $motivo = 'Os serviços desta empresa não têm setor correspondente cadastrado — mostrando todos os colaboradores habilitados.';
        } elseif ($analistas === [] || $estrategistas === []) {
            $motivo = 'Nenhum colaborador habilitado nos setores dos serviços desta empresa — mostrando todos os habilitados.';
        }

        if ($motivo !== null) {
            $analistas     = $this->porCargo(self::ROLE_ANALISTA, []);
            $estrategistas = $this->porCargo(self::ROLE_ESTRATEGISTA, []);
        }

        return [
            'analistas'       => $analistas,
            'estrategistas'   => $estrategistas,
            'motivo_abertura' => $motivo,
        ];
    }

    /**
     * Distribui: grava os dois vínculos e move a empresa 5→6 (DISTRIB-03/04).
     *
     * **Ordem deliberada** (D-E): pivot primeiro, transição depois. Se a
     * transição for recusada, os vínculos ficam gravados sem a etapa ter mudado
     * — a empresa continua na fila e distribuir de novo é idempotente. O inverso
     * (etapa movida sem responsável) seria pior: a empresa sumiria da fila sem
     * ter sido distribuída.
     *
     * ⚠️ A transação envolve **só** a escrita da pivot. `transicionar()` já é
     * transacional com `lockForUpdate()` por dentro, e envolvê-lo numa transação
     * externa é proibido desde a Fase 152.
     *
     * @return array{status: string, requisito_faltante: ?string, servicos_vinculados: int}
     */
    public function distribuir(Company $company, int $analistaId, int $estrategistaId, User $por): array
    {
        if ($company->etapa !== Company::ETAPA_AGUARDANDO_DISTRIBUICAO) {
            return [
                'status'              => 'recusado',
                'requisito_faltante'  => 'Esta empresa não está aguardando distribuição.',
                'servicos_vinculados' => 0,
            ];
        }

        $servicoIds = ContratoServico::query()
            ->where('company_id', $company->id)
            ->where('ativo', true)
            ->pluck('servico_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($servicoIds === []) {
            // Sem serviço ativo não há onde gravar o vínculo — a pivot é por
            // serviço (D-A). Recusar é melhor que gravar `servico_id` nulo e
            // divergir de 100% do acervo.
            return [
                'status'              => 'recusado',
                'requisito_faltante'  => 'A empresa não tem serviço ativo — não há onde vincular os responsáveis.',
                'servicos_vinculados' => 0,
            ];
        }

        DB::transaction(function () use ($company, $servicoIds, $analistaId, $estrategistaId) {
            foreach ($servicoIds as $servicoId) {
                $this->vincular($company->id, $analistaId, self::ROLE_ANALISTA, $servicoId);
                $this->vincular($company->id, $estrategistaId, self::ROLE_ESTRATEGISTA, $servicoId);
            }
        });

        $resultado = $this->etapas->transicionar($company, Company::ETAPA_AGUARDANDO_ONBOARDING, $por);

        if ($resultado['status'] !== 'transicionado') {
            return [
                'status'              => $resultado['status'],
                'requisito_faltante'  => $resultado['requisito_faltante'],
                'servicos_vinculados' => count($servicoIds),
            ];
        }

        return [
            'status'              => 'distribuido',
            'requisito_faltante'  => null,
            'servicos_vinculados' => count($servicoIds),
        ];
    }

    /**
     * Marcadores de chegada da empresa na carteira do responsável
     * (RESP-02): "novo cliente" e "onboarding pendente".
     *
     * **Os dois DERIVAM, nenhum vira coluna** (D-F):
     *
     * - `novo_cliente` sai da data da transição 5→6 — a distribuição — dentro da
     *   janela de {@see self::DIAS_NOVO_CLIENTE} dias. Uma coluna `is_novo`
     *   precisaria de alguém para desligar, e ficaria acesa para sempre no dia
     *   em que esse alguém esquecesse.
     * - `onboarding_pendente` sai da própria `etapa`. A Fase 155 é dona do
     *   onboarding; aqui só se lê o estado.
     *
     * Uma consulta só para todas as empresas — nada de N+1 no dashboard.
     *
     * @param  array<int, int>  $companyIds
     * @return array<int, array{novo_cliente: bool, onboarding_pendente: bool}>
     */
    public function marcadores(array $companyIds): array
    {
        if ($companyIds === []) {
            return [];
        }

        $distribuidasEm = DB::table('company_etapa_transicoes')
            ->whereIn('company_id', $companyIds)
            ->where('etapa_nova', Company::ETAPA_AGUARDANDO_ONBOARDING)
            ->groupBy('company_id')
            ->pluck(DB::raw('MAX(created_at) as em'), 'company_id');

        $etapas = Company::whereIn('id', $companyIds)->pluck('etapa', 'id');

        $limite = now()->subDays(self::DIAS_NOVO_CLIENTE);
        $saida  = [];

        foreach ($companyIds as $id) {
            $em = $distribuidasEm[$id] ?? null;

            $saida[$id] = [
                'novo_cliente' => $em !== null && \Illuminate\Support\Carbon::parse($em)->greaterThanOrEqualTo($limite),
                'onboarding_pendente' => in_array(
                    $etapas[$id] ?? null,
                    [Company::ETAPA_AGUARDANDO_ONBOARDING, Company::ETAPA_ONBOARDING_ANDAMENTO],
                    true
                ),
            ];
        }

        return $saida;
    }

    /**
     * Idempotente por (empresa, serviço, função): distribuir de novo troca o
     * responsável em vez de acumular linha.
     */
    private function vincular(int $companyId, int $userId, string $role, int $servicoId): void
    {
        DB::table('company_users')->updateOrInsert(
            ['company_id' => $companyId, 'servico_id' => $servicoId, 'role' => $role],
            ['user_id' => $userId, 'assigned_at' => now()->toDateString(), 'updated_at' => now()]
        );
    }

    /**
     * Ids de `setores` cujos `slug` batem com o `servicos.setor` dos serviços
     * ativos da empresa. Devolve vazio quando nenhum casa — que é o caso real de
     * `performance` e `outros`, medido no banco.
     *
     * @return array<int, int>
     */
    private function setorIdsDaEmpresa(Company $company): array
    {
        $slugs = ContratoServico::query()
            ->where('contratos_servico.company_id', $company->id)
            ->where('contratos_servico.ativo', true)
            ->join('servicos', 'servicos.id', '=', 'contratos_servico.servico_id')
            ->pluck('servicos.setor')
            ->filter()
            ->unique()
            ->all();

        if ($slugs === []) {
            return [];
        }

        return Setor::whereIn('slug', $slugs)->where('active', true)->pluck('id')->all();
    }

    /**
     * Colaboradores ativos com o cargo pedido. `$setorIds` vazio significa
     * "qualquer setor".
     *
     * @param  array<int, int>  $setorIds
     * @return array<int, array{id:int, name:string}>
     */
    private function porCargo(string $cargoSlug, array $setorIds): array
    {
        $q = User::query()
            ->where('users.active', true)
            ->whereExists(function ($sub) use ($cargoSlug, $setorIds) {
                $sub->select(DB::raw(1))
                    ->from('user_setores as us')
                    ->join('cargos as c', 'c.id', '=', 'us.cargo_id')
                    ->whereColumn('us.user_id', 'users.id')
                    ->where('c.slug', $cargoSlug);

                if ($setorIds !== []) {
                    $sub->whereIn('us.setor_id', $setorIds);
                }
            })
            ->orderBy('users.name');

        return $q->get(['users.id', 'users.name'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])
            ->values()
            ->all();
    }
}
