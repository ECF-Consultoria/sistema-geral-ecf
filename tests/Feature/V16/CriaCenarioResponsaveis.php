<?php

namespace Tests\Feature\V16;

use App\Models\Company;
use App\Models\Servico;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Trait de fixture da milestone v16.0 (Phase 76).
 *
 * Monta, via `DB::table` puro, o cenário mínimo de responsáveis por-serviço
 * reutilizado pelos testes de `tests/Feature/V16/`. Usa DB::table (não Eloquent)
 * nas inserções de `servicos`, `contratos_servico` e `company_users` porque:
 *  - não existem ServicoFactory/ContratoServicoFactory no projeto (evita criá-las);
 *  - precisamos controlar `servico_id` explicitamente na pivot `company_users`
 *    (o attach do Eloquent não é conveniente para o slot NULL/consolidado).
 *
 * Comentários e nomes de helpers em pt-BR, conforme convenção do projeto.
 */
trait CriaCenarioResponsaveis
{
    /**
     * Insere um serviço no catálogo e retorna o id.
     *
     * @param string $setor  Um dos Servico::SETOR_* (ex.: 'performance', 'shopee').
     * @param bool   $ativo  Serviço ativo no catálogo (default true).
     */
    protected function criarServico(string $setor, bool $ativo = true): int
    {
        return (int) DB::table('servicos')->insertGetId([
            'nome'          => 'Serviço ' . ucfirst($setor),
            'valor_padrao'  => 0,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => $ativo,
            'setor'         => $setor,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    /**
     * Insere um contrato de serviço para a empresa e retorna o id.
     * `ativo` controla se o contrato entra (ou não) na data-migration performance.
     */
    protected function criarContrato(int $companyId, int $servicoId, bool $ativo = true): int
    {
        return (int) DB::table('contratos_servico')->insertGetId([
            'company_id'       => $companyId,
            'servico_id'       => $servicoId,
            'valor_contratado' => 0,
            'data_contratacao' => now()->toDateString(),
            'ativo'            => $ativo,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    /**
     * Insere uma linha na pivot `company_users` com `servico_id` explícito
     * (pode ser null = slot consolidado/legado) e retorna o id da linha.
     */
    protected function inserirPivot(int $companyId, int $userId, string $role, ?int $servicoId): int
    {
        return (int) DB::table('company_users')->insertGetId([
            'company_id'  => $companyId,
            'user_id'     => $userId,
            'role'        => $role,
            'servico_id'  => $servicoId,
            'assigned_at' => now()->toDateString(),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    /**
     * Cenário base ML com responsáveis por-serviço já preenchidos:
     *  - 1 Company (via CompanyFactory);
     *  - 1 serviço performance ativo + contrato performance ativo;
     *  - 1 user "analista" (role 'consultor') e 1 user "estrategista" (role 'estrategista');
     *  - as 2 linhas em `company_users` já com o `servico_id` do performance.
     *
     * @return array{company: Company, servicoPerf: int, analista: User, estrategista: User}
     */
    protected function criarCenarioMlComResponsaveis(): array
    {
        $company     = Company::factory()->create();
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($company->id, $servicoPerf, true);

        $analista     = User::factory()->create();
        $estrategista = User::factory()->create();

        // 'consultor' é o slot do Analista de Performance; 'estrategista' o do Estrategista.
        $this->inserirPivot($company->id, $analista->id, 'consultor', $servicoPerf);
        $this->inserirPivot($company->id, $estrategista->id, 'estrategista', $servicoPerf);

        return [
            'company'      => $company,
            'servicoPerf'  => $servicoPerf,
            'analista'     => $analista,
            'estrategista' => $estrategista,
        ];
    }

    /**
     * Adiciona à empresa o slot Shopee: serviço shopee ativo + contrato shopee ativo
     * (criados se ainda não existirem) e a linha `company_users` com esse `servico_id`.
     * Simula a duplicação por-serviço que a Phase 78 vai introduzir.
     *
     * @return array{servicoShopee: int, row: int}
     */
    protected function inserirLinhaShopee(int $companyId, int $userId, string $role): array
    {
        // Reaproveita o serviço shopee ativo se já foi criado nesta suite.
        $servicoShopee = DB::table('servicos')
            ->where('setor', Servico::SETOR_SHOPEE)
            ->where('ativo', true)
            ->value('id');

        if ($servicoShopee === null) {
            $servicoShopee = $this->criarServico(Servico::SETOR_SHOPEE, true);
        }

        // Garante contrato shopee ativo para a empresa (idempotente por par).
        $temContrato = DB::table('contratos_servico')
            ->where('company_id', $companyId)
            ->where('servico_id', $servicoShopee)
            ->where('ativo', true)
            ->exists();

        if (! $temContrato) {
            $this->criarContrato($companyId, (int) $servicoShopee, true);
        }

        $row = $this->inserirPivot($companyId, $userId, $role, (int) $servicoShopee);

        return [
            'servicoShopee' => (int) $servicoShopee,
            'row'           => $row,
        ];
    }
}
