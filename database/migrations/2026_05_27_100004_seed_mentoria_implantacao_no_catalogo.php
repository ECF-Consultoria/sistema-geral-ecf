<?php

use App\Models\Servico;
use Illuminate\Database\Migrations\Migration;

/**
 * Pós-Phase 14 / merge origin/main — Migration 4: seed dos tipos
 * `Mentoria` e `Implantação` no catálogo `servicos`.
 *
 * Preserva a INTENÇÃO do commit b10041f (`feat(comercial): adiciona tipos
 * Mentoria, Implantação e Incubadora`) do outro dev, adaptada ao modelo
 * pós-Phase 14:
 *
 *  - b10041f adicionava os tipos ao enum `service_type` (coluna legacy
 *    dropada pelo Plan 14-06) + checkboxes hardcoded em JSX (substituídos
 *    pelo seletor multi do catálogo no Plan 14-04).
 *  - Pós-Phase 14, a forma correta de adicionar tipo novo é registrar
 *    como row no catálogo `servicos`. A partir daí o seletor multi de
 *    `NovaEmpresa.jsx` o exibe automaticamente, sem mudança em JSX.
 *
 *  - `Incubadora` já estava no catálogo (seed Plan 14-02 / Migration 1) —
 *    não tocada aqui, idempotente via firstOrCreate.
 *
 * Idempotente: `firstOrCreate` por `nome`. Roda 2× sem duplicar.
 *
 * Após esta migration, os usuários do Comercial poderão escolher Mentoria
 * e Implantação no cadastro de empresa via /comercial/empresas/novo
 * (seletor do catálogo) sem alteração de código.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Mesmos defaults do seed canônico (Migration 1): valor_padrao=0
        // e tipo_cobranca=mensal. Admin ajusta via UI /servicos depois.
        $novosNomes = [
            'Mentoria',
            'Implantação',
        ];

        foreach ($novosNomes as $nome) {
            // firstOrCreate (NÃO updateOrCreate): se já existir, preserva
            // ajustes manuais. Garante idempotência em deploy re-run.
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
        // Espelha o down() da Migration 1: não deletar do catálogo —
        // contratos vinculados via restrictOnDelete impediriam o drop.
        // Em dev, re-seed é trivial; em prod, contratos preservados.
    }
};
