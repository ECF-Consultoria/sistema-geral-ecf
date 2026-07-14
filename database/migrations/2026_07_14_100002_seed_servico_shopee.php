<?php

use App\Models\Servico;
use Illuminate\Database\Migrations\Migration;

/**
 * Phase 75 / Plan 75-01 (DEC-1) — seed idempotente do serviço "Shopee".
 *
 * Semeia a entrada "Shopee" (setor='shopee', ativo=true) no catálogo `servicos`
 * para que ela apareça automaticamente no wizard do Comercial
 * (`servicos_disponiveis` já lê `Servico::ativo` em `ComercialController::create()`).
 *
 * Timestamp ESTRITAMENTE MAIOR que o da migração de enum
 * (2026_07_14_100001_add_shopee_to_servicos_setor_enum) — Pitfall 2: em MySQL o
 * INSERT com setor='shopee' falha (`Data truncated`) se o ALTER ainda não rodou.
 *
 * Idempotente via `firstOrCreate` por `nome` (NÃO `updateOrCreate`): re-runs
 * preservam `valor_padrao` editado manualmente via UI (/servicos).
 *
 * Nome EXATO "Shopee" — não casa nenhum prefixo ML de
 * `ComercialController::servicoDisparaImplementacao()` (Polos/Assessoria/
 * Incubadora), logo NÃO dispara criação indevida de MlbEmpresa (Pitfall 3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Servico::firstOrCreate(
            ['nome' => 'Shopee'],
            [
                'valor_padrao'  => 0,
                'tipo_cobranca' => Servico::TIPO_MENSAL,
                'ativo'         => true,
                'setor'         => Servico::SETOR_SHOPEE, // 'shopee'
            ],
        );
    }

    public function down(): void
    {
        // Não deletar — contratos vinculados via restrictOnDelete impediriam o
        // drop (mesmo padrão do seed do catálogo Phase 14). Em dev o re-seed é
        // trivial; em prod o serviço permanece preservado.
    }
};
