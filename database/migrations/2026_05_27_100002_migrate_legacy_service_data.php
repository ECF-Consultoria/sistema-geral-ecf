<?php

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Servico;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Phase 14 / Plan 14-02 — Migration 2: data migration legacy → contratos_servico.
 *
 * Itera todas as empresas existentes e cria os `contratos_servico` derivados
 * dos campos legacy:
 *   (1) Para cada item em `companies.service_type` (JSON array): 1 contrato
 *       com servico_id mapeado via D-01 e valor_contratado=0 (classificação).
 *   (2) Se `companies.additional_service` preenchido: 1 contrato adicional
 *       com servico_id resolvido via normalização Title Case (D-02) e
 *       valor_contratado=additional_service_price.
 *   (3) Empresas com ambos vazios: NENHUM contrato (estado neutro — D-04 item 3).
 *
 * Datas (D-04):
 *   - data_contratacao = contract_start ?? company.created_at
 *   - data_vencimento  = contract_end (nullable)
 *
 * Idempotência (RESEARCH §1 + Pitfall 3):
 *   - Catálogo: firstOrCreate por nome
 *   - Contratos: guard explícito `where(company_id, servico_id, valor_contratado)->exists()`
 *     antes de criar. Re-run NÃO duplica.
 *
 * Atomicidade: TODO o trabalho dentro de DB::transaction — se falhar no meio,
 * nenhum contrato fica criado (`php artisan migrate` falha loud).
 *
 * Performance (Pitfall 2): chunk(100) para evitar OOM com muitas empresas.
 *
 * Sistema entra em COEXISTÊNCIA após esta migration:
 *   - Campos legacy de `companies` PERMANECEM populados
 *   - `contratos_servico` PASSA a ser populada
 *   - Runtime AINDA lê dos legacy (refator dos consumers só no Plan 14-03)
 *   - Drop das colunas legacy só no Plan 14-06
 *
 * Per D-01, D-02, D-04, D-06.
 */
return new class extends Migration
{
    /**
     * Mapeamento D-01 (verbatim do CONTEXT.md) — slug legacy → nome canônico no catálogo.
     *
     * @var array<string, string>
     */
    private array $mapaLegacy = [
        'publicacao'  => 'Publicação',
        'polos'       => 'Polos',
        'assessoria'  => 'Assessoria',
        'incubadora'  => 'Incubadora',
        'publicidade' => 'Publicidade',
        'gestao'      => 'Gestão',
    ];

    public function up(): void
    {
        DB::transaction(function () {
            // Cache nome → id para evitar N queries de resolução (RESEARCH §1).
            // Recalculado a cada chunk seria desnecessário — o catálogo é estático
            // dentro de uma migração (Migration 1 já populou tudo).
            $servicosByNome = Servico::pluck('id', 'nome');

            Company::chunk(100, function ($companies) use ($servicosByNome) {
                foreach ($companies as $company) {
                    $this->migrarContratosLegacy($company, $servicosByNome);
                }
            });
        });
    }

    public function down(): void
    {
        // Down não reverte dados criados — contratos_servico permanecem.
        // Para reverter completamente, restaurar backup do banco.
        // Migration de schema (drop) faz própria reversão via recriação de
        // colunas no Plan 14-06 (migration _100003).
    }

    /**
     * Cria contratos_servico para uma empresa, derivando dos campos legacy.
     *
     * Regra cumulativa (D-04):
     *   - 1 contrato por slug em service_type (valor_contratado=0)
     *   - 1 contrato adicional se additional_service preenchido
     *
     * @param  Company                     $company        Empresa fonte dos dados legacy.
     * @param  Collection<string, int>     $servicosByNome Mapeamento nome → id (cache).
     */
    private function migrarContratosLegacy(Company $company, Collection $servicosByNome): void
    {
        // data_contratacao: contract_start ou created_at como fallback (D-04).
        // contract_start vem como Carbon (cast date:Y-m-d em Company.php); usamos
        // toDateString() para sempre normalizar para 'Y-m-d' (Pitfall 8 — SQLite).
        $dataContratacao = $company->contract_start
            ? $company->contract_start->toDateString()
            : $company->created_at->toDateString();

        $dataVencimento = $company->contract_end?->toDateString();

        // ─── (1) Contratos derivados de service_type (JSON array) ────────────
        // (array) cast: se for null vira []; se for array fica igual.
        foreach ((array) $company->service_type as $slug) {
            $nome = $this->mapaLegacy[$slug] ?? null;

            // Slug desconhecido (ex: dado sujo histórico) → ignora.
            if (! $nome || ! isset($servicosByNome[$nome])) {
                continue;
            }

            $servicoId = (int) $servicosByNome[$nome];

            // Guard de idempotência: combinação exata (company + servico + valor=0).
            // Pitfall 3: empresa com service_type=['polos'] já migrada não duplica
            // contratos no segundo run.
            $jaExiste = ContratoServico::where('company_id', $company->id)
                ->where('servico_id', $servicoId)
                ->where('valor_contratado', 0)
                ->exists();

            if ($jaExiste) {
                continue;
            }

            ContratoServico::create([
                'company_id'       => $company->id,
                'servico_id'       => $servicoId,
                'valor_contratado' => 0,
                'data_contratacao' => $dataContratacao,
                'data_vencimento'  => $dataVencimento,
                'ativo'            => true,
            ]);
        }

        // ─── (2) Contrato adicional derivado de additional_service ──────────
        $additionalRaw = (string) ($company->additional_service ?? '');

        if (trim($additionalRaw) === '') {
            // Sem additional_service → nada a fazer aqui (D-04 itens 3 e 4).
            return;
        }

        // D-02: normalização agressiva (trim + Title Case UTF-8) garante dedupe:
        // "consultoria", "Consultoria", "  CONSULTORIA  " viram todos "Consultoria".
        $nomeAdicional = mb_convert_case(trim($additionalRaw), MB_CASE_TITLE, 'UTF-8');

        // Pitfall 4 (cast decimal:2 retorna string em SQLite): (float) explícito
        // antes de operações aritméticas e comparações.
        $valorAdicional = (float) ($company->additional_service_price ?? 0);

        // find-or-create no catálogo para o nome adicional. valor_padrao do
        // catálogo recebe o valor real (heurística — usuário pode ajustar via UI).
        $servicoAdicional = Servico::firstOrCreate(
            ['nome' => $nomeAdicional],
            [
                'valor_padrao'  => $valorAdicional,
                'tipo_cobranca' => Servico::TIPO_MENSAL,
                'ativo'         => true,
            ],
        );

        // Atualiza cache local para futuros lookups dentro da mesma transaction.
        $servicosByNome->put($nomeAdicional, $servicoAdicional->id);

        // Guard de idempotência por (company + servico + valor_contratado exato).
        $jaExisteAdicional = ContratoServico::where('company_id', $company->id)
            ->where('servico_id', $servicoAdicional->id)
            ->where('valor_contratado', $valorAdicional)
            ->exists();

        if ($jaExisteAdicional) {
            return;
        }

        ContratoServico::create([
            'company_id'       => $company->id,
            'servico_id'       => $servicoAdicional->id,
            'valor_contratado' => $valorAdicional,
            'data_contratacao' => $dataContratacao,
            'data_vencimento'  => $dataVencimento,
            'ativo'            => true,
        ]);
    }
};
