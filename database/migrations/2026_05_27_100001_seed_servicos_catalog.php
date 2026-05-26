<?php

use App\Models\Servico;
use Illuminate\Database\Migrations\Migration;

/**
 * Phase 14 / Plan 14-02 — Migration 1: seed do catálogo `servicos`.
 *
 * Popula a tabela `servicos` com os 6 tipos canônicos legacy (mapeados
 * em CONTEXT.md D-01). Idempotente via `firstOrCreate` por `nome` — em
 * re-runs, edições manuais de `valor_padrao` feitas via UI (`/servicos`)
 * são preservadas (não usamos `updateOrCreate` por isso).
 *
 * Esta migration é a base do Plan 14-02: a Migration 2 (data)
 * depende destes registros para criar `contratos_servico` derivados
 * dos campos legacy de `companies` (service_type + additional_service).
 *
 * Sistema entra em COEXISTÊNCIA após Plan 14-02:
 *  - Campos legacy de `companies` PERMANECEM populados
 *  - Tabela `contratos_servico` PASSA a ser populada
 *  - Runtime AINDA lê dos legacy até o refator dos consumers (Plan 14-03)
 *  - Drop das colunas legacy só no Plan 14-06, após `phase14:verificar-cobranca` exit 0
 *
 * Roda 2× sem duplicar (verificado em `Phase14MigrationTest`).
 *
 * Per D-01, D-06.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Mapeamento D-01 — 6 nomes canônicos pt-BR (verbatim do CONTEXT.md).
        // Não inclui o slug legacy (publicacao, polos, etc.) — esta migration só
        // popula o catálogo; o lookup slug→nome fica na Migration 2.
        $nomesCanonicos = [
            'Publicação',
            'Polos',
            'Assessoria',
            'Incubadora',
            'Publicidade',
            'Gestão',
        ];

        foreach ($nomesCanonicos as $nome) {
            // firstOrCreate (NÃO updateOrCreate): preserva ajustes manuais em
            // valor_padrao feitos pelo usuário via UI pós-migration.
            Servico::firstOrCreate(
                ['nome' => $nome],
                [
                    'valor_padrao'  => 0,
                    'tipo_cobranca' => Servico::TIPO_MENSAL,
                    'ativo'         => true,
                ],
            );
        }
    }

    public function down(): void
    {
        // Não deletar — contratos vinculados via restrictOnDelete impediriam o
        // drop. Em dev, re-seed é trivial; em prod, contratos preservados.
        // Reverter este seed exigiria também reverter a Migration 2 (data) e
        // remover os contratos derivados — escopo fora do down().
    }
};
