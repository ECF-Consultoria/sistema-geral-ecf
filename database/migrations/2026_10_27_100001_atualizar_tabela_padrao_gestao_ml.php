<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quick 261027-m1y — nova tabela progressiva PADRÃO do serviço `Gestão`
 * (Gestão de ADS Mercado Livre): 6 faixas no lugar das 7 antigas.
 *
 * Os limites (500000.00, 1000000.00, ...) são valores travados pelo usuário;
 * NÃO converter para a convenção ",99" das outras tabelas. Só afeta contrato
 * NOVO — o texto impresso lê "A partir de R$ 500.000,00" a partir do limite
 * da faixa anterior.
 *
 * NÃO toca `empresa_faixas_faturamento` (decisão travada; learning
 * fechamento-tabela-por-empresa §1: a tabela do serviço é só semente/modelo
 * de partida, e com a flag `fechamento_tabela_por_empresa_ativa` ligada em
 * produção ela não é régua de cobrança). NÃO toca Brigada (tabela idêntica
 * hoje, mas fora de escopo) nem Shopee.
 *
 * Antes de trocar, congela a tabela ANTIGA nos contratos de Gestão já
 * gerados (com envelope) e ainda não assinados: eles saíram com o .docx v5 de
 * tabela fixa; ao serem assinados depois desta migration, sem o
 * congelamento, `TabelaDeContratoAssinadoService` gravaria a tabela NOVA como
 * "confirmada por contrato" — uma tabela que o cliente não assinou.
 *
 * Resolução por NOME exato; idempotente (updateOrInsert por servico_id+ordem,
 * congelamento só enquanto a tabela atual ainda não é a nova).
 */
return new class extends Migration
{
    private const NOVAS = [
        ['ordem' => 1, 'limite_superior' => 500000.00,  'valor' => 3700.00,  'valor_e_piso' => false],
        ['ordem' => 2, 'limite_superior' => 1000000.00, 'valor' => 5500.00,  'valor_e_piso' => false],
        ['ordem' => 3, 'limite_superior' => 2000000.00, 'valor' => 7400.00,  'valor_e_piso' => false],
        ['ordem' => 4, 'limite_superior' => 3000000.00, 'valor' => 9250.00,  'valor_e_piso' => false],
        ['ordem' => 5, 'limite_superior' => 5000000.00, 'valor' => 11100.00, 'valor_e_piso' => false],
        ['ordem' => 6, 'limite_superior' => null,       'valor' => 13500.00, 'valor_e_piso' => true],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('servicos')
            || ! Schema::hasTable('servico_faixas_faturamento')
            || ! Schema::hasTable('contrato_assinaturas')
            || ! Schema::hasColumn('contrato_assinaturas', 'tabela_progressiva_snapshot')) {
            return;
        }

        $servicoId = DB::table('servicos')->where('nome', 'Gestão')->value('id');

        if ($servicoId === null) {
            return;
        }

        $now = Carbon::now();

        DB::transaction(function () use ($servicoId, $now) {
            $atuais = DB::table('servico_faixas_faturamento')
                ->where('servico_id', $servicoId)
                ->orderBy('ordem')
                ->get();

            $assinatura = fn (iterable $linhas): array => collect($linhas)->map(fn ($l) => [
                (int) (is_array($l) ? $l['ordem'] : $l->ordem),
                (is_array($l) ? $l['limite_superior'] : $l->limite_superior) === null
                    ? null
                    : round((float) (is_array($l) ? $l['limite_superior'] : $l->limite_superior), 2),
                round((float) (is_array($l) ? $l['valor'] : $l->valor), 2),
                (bool) (is_array($l) ? $l['valor_e_piso'] : $l->valor_e_piso),
            ])->values()->all();

            $jaENova = $assinatura($atuais) === $assinatura(self::NOVAS);

            // (a) congela a tabela antiga nos contratos em voo.
            if (! $jaENova && $atuais->isNotEmpty()) {
                $snapshot = json_encode([
                    'origem'       => 'servico',
                    'servico_id'   => (int) $servicoId,
                    'congelada_em' => $now->format('Y-m-d H:i:s'),
                    'faixas'       => $atuais->map(fn ($f) => [
                        'ordem'           => (int) $f->ordem,
                        'limite_superior' => $f->limite_superior === null ? null : (float) $f->limite_superior,
                        'valor'           => (float) $f->valor,
                        'valor_e_piso'    => (bool) $f->valor_e_piso,
                    ])->values()->all(),
                ]);

                DB::table('contrato_assinaturas')
                    ->where('servico_id', $servicoId)
                    ->whereNotNull('clicksign_envelope_id')
                    ->whereIn('status', ['rascunho', 'aguardando_assinaturas'])
                    ->whereNull('tabela_progressiva_snapshot')
                    ->update(['tabela_progressiva_snapshot' => $snapshot]);
            }

            // (b) tabela nova.
            foreach (self::NOVAS as $faixa) {
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

            // (c) a ordem 7 antiga deixa de existir.
            DB::table('servico_faixas_faturamento')
                ->where('servico_id', $servicoId)
                ->where('ordem', '>', 6)
                ->delete();
        });
    }

    public function down(): void
    {
        // Não reverte — mesma disciplina das migrations de seed/Shopee:
        // contrato já gerado referencia a tabela da época.
    }
};
