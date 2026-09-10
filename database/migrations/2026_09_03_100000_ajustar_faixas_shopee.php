<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 137 — checkpoint fechado em 2026-09-03: ajuste na tabela de faixas
 * de `Gestão de ADS Shopee` (id 9 em produção), decisão do usuário após
 * conferir os valores semeados em `2026_09_02_100003_seed_faixas_faturamento_iniciais`
 * contra os contratos publicados.
 *
 * NÃO edita a migration 100003 — ela é idempotente por
 * `updateOrInsert(['servico_id', 'ordem'])`, então quem já rodou o seed
 * antigo não re-executaria uma versão editada e ficaria parado nos valores
 * velhos. Uma migration nova garante que todo ambiente converge.
 *
 * Duas mudanças, as duas só em Shopee — `Gestão` (id 6) e `Brigada` (id 10)
 * não mudam em nada:
 *
 * 1. Três faixas novas no topo (ordens 9, 10, 11), fechando o gap real do
 *    contrato: hoje a tabela termina em "Até R$ 3.000.000 → R$ 5.000" e não
 *    tem faixa aberta — empresa Shopee acima de R$ 3 milhões não casava em
 *    faixa nenhuma e caía em "A DEFINIR". O desenho copia Gestão/Brigada
 *    (dois degraus fechados + um aberto/piso), mantendo o passo de R$ 500
 *    que a tabela de Shopee já usa.
 *
 * 2. Todos os tetos das ordens 1 a 8 passam de teto cheio (`50_000.00`) para
 *    a convenção `,99` (`49_999.99`), que é a que Gestão/Brigada já usam —
 *    decisão de unificar convenção. ATENÇÃO: isso muda cobrança — empresa
 *    Shopee que fatura EXATAMENTE R$ 50.000,00 deixava de cair na faixa 1
 *    (R$ 1.500) e passa a cair na faixa 2 (R$ 2.000). O usuário está ciente
 *    e decidiu assim conscientemente — não é bug a corrigir depois.
 *
 * Resolução por NOME exato (não id hardcoded — id é dado de produção, pode
 * divergir em outros ambientes). Se o serviço não existir neste ambiente,
 * esta migration pula em silêncio — mesma disciplina da 100003.
 *
 * Idempotência por `updateOrInsert(['servico_id', 'ordem'])` — rodar duas
 * vezes não duplica linha nem altera contagem/valor.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('servico_faixas_faturamento') || ! Schema::hasTable('servicos')) {
            return;
        }

        $now = Carbon::now();

        $servicoId = DB::table('servicos')->where('nome', 'Gestão de ADS Shopee')->value('id');

        // Serviço não existe neste ambiente — pula em silêncio, nunca cria
        // serviço aqui.
        if ($servicoId === null) {
            return;
        }

        // Ordens 1 a 8: teto cheio → convenção ",99" (decisão 2, unificação
        // com Gestão/Brigada). Valor e valor_e_piso não mudam.
        $novosLimites = [
            1 => 49_999.99,
            2 => 149_999.99,
            3 => 249_999.99,
            4 => 499_999.99,
            5 => 999_999.99,
            6 => 1_499_999.99,
            7 => 1_999_999.99,
            8 => 2_999_999.99,
        ];

        // Ordens 9, 10, 11 novas: fecham o gap acima de R$ 3.000.000
        // (decisão 1). Ordem 11 é a única aberta/piso, mesmo padrão de
        // Gestão/Brigada (ordem 7 daquela tabela).
        $novasFaixas = [
            ['ordem' => 9,  'limite_superior' => 3_999_999.99, 'valor' => 5_500.00, 'valor_e_piso' => false],
            ['ordem' => 10, 'limite_superior' => 4_999_999.99, 'valor' => 6_000.00, 'valor_e_piso' => false],
            ['ordem' => 11, 'limite_superior' => null,         'valor' => 6_500.00, 'valor_e_piso' => true],
        ];

        DB::transaction(function () use ($servicoId, $novosLimites, $novasFaixas, $now) {
            foreach ($novosLimites as $ordem => $limiteSuperior) {
                DB::table('servico_faixas_faturamento')
                    ->where('servico_id', $servicoId)
                    ->where('ordem', $ordem)
                    ->update([
                        'limite_superior' => $limiteSuperior,
                        'updated_at'      => $now,
                    ]);
            }

            foreach ($novasFaixas as $faixa) {
                DB::table('servico_faixas_faturamento')->updateOrInsert(
                    ['servico_id' => $servicoId, 'ordem' => $faixa['ordem']],
                    [
                        'limite_superior' => $faixa['limite_superior'],
                        'valor'           => $faixa['valor'],
                        'valor_e_piso'    => $faixa['valor_e_piso'],
                        'created_at'      => $now,
                        'updated_at'      => $now,
                    ]
                );
            }
        });
    }

    public function down(): void
    {
        // Não reverte — mesma disciplina de `seed_faixas_faturamento_iniciais.php`
        // (D-11): fechamento já congelado referencia a faixa aplicada na
        // época, desfazer aqui quebraria esse registro histórico.
    }
};
