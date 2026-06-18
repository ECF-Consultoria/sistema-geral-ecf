<?php

use App\Models\Configuracao;
use Illuminate\Database\Migrations\Migration;

// Migration de seed idempotente das 3 chaves de limiar de meta por estágio (Phase 38).
// Decisões travadas: D-07 (limiares M2/M3/M4) e D-08 (globais via Configuracao).
//
// D-07: Meta de faturamento por empresa = limiar do estágio:
//   M2 ≥ R$1.000 · M3 ≥ R$4.000 · M4 ≥ R$8.000
//
// D-08: Limiares globais e configuráveis via Configuracao (sem deploy).
//   Override por polo NÃO nesta fase (deferido).
//
// D-09: A chave antiga "polo_meta_valor_empresa" (R$3.000 — verba ADS, não meta)
//   está descartada do cálculo. Esta migration NÃO toca essa chave.
//
// Idempotência: insere SOMENTE se a chave ainda não existir em `configuracoes`,
// preservando overrides feitos manualmente via UI do painel.
return new class extends Migration
{
    /**
     * Limiares de meta por estágio (D-07 do CONTEXT.md).
     * Só são inseridos se a chave não existir — overrides via UI são preservados.
     */
    private const LIMIARES = [
        'polo_limiar_m2' => 1000,  // M2 ≥ R$1.000
        'polo_limiar_m3' => 4000,  // M3 ≥ R$4.000
        'polo_limiar_m4' => 8000,  // M4 ≥ R$8.000
    ];

    public function up(): void
    {
        foreach (self::LIMIARES as $chave => $valorDefault) {
            // Insere apenas se a chave não existir — idempotente e preserva overrides.
            if (!Configuracao::where('chave', $chave)->exists()) {
                Configuracao::set($chave, (string) $valorDefault);
            }
        }
    }

    public function down(): void
    {
        // Remove apenas as 3 chaves novas criadas por esta migration.
        // Não afeta "polo_meta_valor_empresa" (chave do modelo antigo, descartada).
        foreach (array_keys(self::LIMIARES) as $chave) {
            Configuracao::where('chave', $chave)->delete();
        }
    }
};
