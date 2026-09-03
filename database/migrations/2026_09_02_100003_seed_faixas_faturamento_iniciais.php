<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 137 (D-01, D-02b) — Seed idempotente das TRÊS tabelas progressivas de
 * faturamento MEDIDAS nos modelos publicados na Clicksign em 2026-09-02.
 * Fonte autoritativa: o modelo PUBLICADO, não os `.docx` locais da raiz do
 * repositório (desatualizados — ver 137-CONTEXT.md D-02).
 *
 * `Gestão` e `Brigada` têm a MESMA tabela de 7 faixas (fato medido, não
 * coincidência de digitação — elas são idênticas nos modelos publicados e
 * batem com a constante `AdminController::FAIXAS` já vigente). A última
 * faixa (ordem 7) é a única com `limite_superior` nulo e `valor_e_piso`
 * verdadeiro: "acima de R$ 5.000.000 → a partir de R$ 12.000", piso, não
 * preço fechado.
 *
 * `Gestão de ADS Shopee` tem tabela PRÓPRIA de 8 faixas, todas em "Até" —
 * NENHUMA delas é faixa aberta. Empresa Shopee acima de R$ 3.000.000 não
 * casa em faixa nenhuma; isso é o dado real do modelo publicado, não uma
 * omissão a corrigir aqui.
 *
 * Resolução por NOME exato, nunca por id hardcoded (id é dado de produção,
 * pode divergir em outros ambientes). Se um serviço não existir no
 * ambiente, esta migration pula APENAS esse serviço, em silêncio — nunca
 * cria serviço (não é responsabilidade desta migration popular o catálogo).
 *
 * Idempotência por `updateOrInsert(['servico_id', 'ordem'])` — rodar duas
 * vezes não duplica linha nem altera contagem.
 *
 * Não semeia nada em `empresa_faixas_faturamento`: exceção por empresa é
 * cadastro manual (D-04), para tabelas antigas ou fora do padrão — inventar
 * exceção aqui mudaria cobrança de empresa real.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('servico_faixas_faturamento') || ! Schema::hasTable('servicos')) {
            return;
        }

        $now = Carbon::now();

        // Gestão (id 6 em produção) e Brigada (id 10 em produção) — tabela
        // IDÊNTICA entre os dois serviços (D-02b). Ordem 7 é a faixa-piso.
        $faixasGestaoBrigada = [
            ['ordem' => 1, 'limite_superior' => 499_999.99,   'valor' => 3_000.00,  'valor_e_piso' => false],
            ['ordem' => 2, 'limite_superior' => 999_999.99,   'valor' => 4_500.00,  'valor_e_piso' => false],
            ['ordem' => 3, 'limite_superior' => 1_999_999.99, 'valor' => 6_000.00,  'valor_e_piso' => false],
            ['ordem' => 4, 'limite_superior' => 2_999_999.99, 'valor' => 7_500.00,  'valor_e_piso' => false],
            ['ordem' => 5, 'limite_superior' => 3_999_999.99, 'valor' => 9_000.00,  'valor_e_piso' => false],
            ['ordem' => 6, 'limite_superior' => 4_999_999.99, 'valor' => 10_500.00, 'valor_e_piso' => false],
            ['ordem' => 7, 'limite_superior' => null,         'valor' => 12_000.00, 'valor_e_piso' => true],
        ];

        // Gestão de ADS Shopee — tabela PRÓPRIA de 8 faixas, todas "Até",
        // nenhuma aberta (D-02b, particularidade 2).
        $faixasShopee = [
            ['ordem' => 1, 'limite_superior' => 50_000.00,    'valor' => 1_500.00, 'valor_e_piso' => false],
            ['ordem' => 2, 'limite_superior' => 150_000.00,   'valor' => 2_000.00, 'valor_e_piso' => false],
            ['ordem' => 3, 'limite_superior' => 250_000.00,   'valor' => 2_500.00, 'valor_e_piso' => false],
            ['ordem' => 4, 'limite_superior' => 500_000.00,   'valor' => 3_000.00, 'valor_e_piso' => false],
            ['ordem' => 5, 'limite_superior' => 1_000_000.00, 'valor' => 3_500.00, 'valor_e_piso' => false],
            ['ordem' => 6, 'limite_superior' => 1_500_000.00, 'valor' => 4_000.00, 'valor_e_piso' => false],
            ['ordem' => 7, 'limite_superior' => 2_000_000.00, 'valor' => 4_500.00, 'valor_e_piso' => false],
            ['ordem' => 8, 'limite_superior' => 3_000_000.00, 'valor' => 5_000.00, 'valor_e_piso' => false],
        ];

        $servicosEFaixas = [
            'Gestão'                => $faixasGestaoBrigada,
            'Brigada'                => $faixasGestaoBrigada,
            'Gestão de ADS Shopee'  => $faixasShopee,
        ];

        DB::transaction(function () use ($servicosEFaixas, $now) {
            foreach ($servicosEFaixas as $nomeServico => $faixas) {
                $servicoId = DB::table('servicos')->where('nome', $nomeServico)->value('id');

                // Serviço não existe neste ambiente — pula em silêncio, nunca
                // cria serviço aqui.
                if ($servicoId === null) {
                    continue;
                }

                foreach ($faixas as $faixa) {
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
            }
        });
    }

    public function down(): void
    {
        // Não apaga nada — mesma disciplina de `seed_servicos_catalog.php`.
        // Remover faixas quebraria fechamento já congelado (D-11): o
        // registro do fechamento referencia a faixa aplicada na época.
    }
};
