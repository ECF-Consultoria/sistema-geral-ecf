<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 140 Plano 04 (TAB-07) — Cria `contrato_tabela_propostas`: o que a leitura automática do
 * Clicksign (140-01/140-02/140-03) descobriu sobre cada contrato, guardado para conferência
 * humana depois. Proposta ≠ cobrança: `company_id` aqui é o PALPITE (D-05 do CONTEXT — zero
 * casamentos com segurança na varredura real, sempre pendente de confirmação humana), nunca uma
 * empresa confirmada. Quem grava tabela de cobrança de verdade é o plano 140-05, só depois da
 * confirmação — esta migration NUNCA toca em `empresa_faixas_faturamento`,
 * `grupo_faixas_faturamento` ou `companies`.
 *
 * Cobre os dois formatos de cobrança medidos na varredura real (85 contratos fechados): 29 de
 * valor fixo (`valor_fixo` preenchido, `faixas` nulo) e 49 de tabela progressiva (`faixas`
 * preenchido, `valor_fixo` nulo) — nunca os dois ao mesmo tempo. Pagamento escalonado (parcelas de
 * valores diferentes) grava `valor_fixo` nulo E o(s) valor(es) encontrados só no `motivo`/aviso —
 * o parser nunca inventa uma média nem escolhe um dos valores em silêncio.
 *
 * ⚠️ Três armadilhas de MariaDB já pagas neste projeto (SQLite dos testes NÃO pega nenhuma):
 *
 * 1. **Nome de índice acima de 64 caracteres quebra no MariaDB (erro 1059)** e passa no SQLite —
 *    a migration fica `Pending` em produção com a tabela criada SEM o índice. Por isso os dois
 *    índices abaixo são nomeados à mão, curtos: `ctp_envelope_unq`, `ctp_situacao_idx`.
 * 2. **`nullOnDelete()` exige `nullable()` ANTES** (erro 1830 no MariaDB, invisível no SQLite).
 *    `company_id` e `confirmado_por` são nullable DE PROPÓSITO aqui — a proposta nasce sem
 *    vínculo confirmado (D-05), então as duas FKs passam por este caminho.
 * 3. **A coluna de tipo enumerado do MySQL quebra o SQLite dos testes.** `envelope_situacao`,
 *    `confianca`, `tipo_cobranca` e `situacao` são `string()` + constantes `public const` no
 *    model, como o resto do projeto — nunca essa coluna de tipo fechado do MariaDB/MySQL.
 *
 * Migration idempotente: guard `Schema::hasTable` evita recriação em rerun (mesmo padrão de
 * `empresa_faixas_faturamento`).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('contrato_tabela_propostas')) {
            return;
        }

        Schema::create('contrato_tabela_propostas', function (Blueprint $table) {
            $table->id();

            // Identificador do envelope na Clicksign — único: o mesmo contrato nunca vira duas
            // propostas, mesmo rodando a varredura de novo (T-140-15).
            $table->string('clicksign_envelope_id', 64);
            $table->string('nome_envelope', 255);
            $table->string('envelope_situacao', 32);
            $table->date('envelope_data')->nullable();

            // O palpite de empresa (EmpresaPalpiteService), NÃO uma confirmação — armadilha 2:
            // nullable() ANTES de constrained()->nullOnDelete(). Apagar a empresa não apaga a
            // proposta nem o histórico da leitura, só solta o vínculo.
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();

            $table->string('confianca', 16);
            $table->decimal('pontuacao', 5, 2)->nullable();
            $table->boolean('ambiguo')->default(false);
            $table->json('candidatos')->nullable();

            $table->string('tipo_cobranca', 16);
            $table->decimal('valor_fixo', 10, 2)->nullable();

            // Shape de EmpresaFaixaFaturamento: [{"ordem","limite_superior","valor","valor_e_piso"}, ...].
            $table->json('faixas')->nullable();

            $table->string('cnpj_lido', 14)->nullable();
            $table->string('razao_social_lida', 255)->nullable();
            $table->string('motivo', 255)->nullable();

            $table->string('situacao', 16)->default('pendente');

            // Armadilha 2 de novo: quem confirmou/descartou — nullable ANTES de nullOnDelete.
            $table->foreignId('confirmado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmado_em')->nullable();

            $table->timestamps();

            $table->unique('clicksign_envelope_id', 'ctp_envelope_unq');
            $table->index('situacao', 'ctp_situacao_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contrato_tabela_propostas');
    }
};
