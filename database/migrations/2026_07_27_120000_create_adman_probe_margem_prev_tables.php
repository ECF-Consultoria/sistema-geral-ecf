<?php

// pt-BR: Migration do probe de estabilidade de `percentageMargin.prev` da Adman
// (Fase 117, Plano 02 — gate MPP-04). A decisão D1 da milestone v21.0 amarra a
// nota de margem do bônus em `value - prev`, num campo que NUNCA foi validado
// quanto a estabilidade e que já produziu dois reverts em 4 dias (memória do
// projeto: `project_adman_margem_diff_instavel_bonus.md`). Este arquivo cria
// as DUAS tabelas do instrumento de medição:
//
//   - adman_probe_margem_prev_leituras: um FATO durável por leitura × empresa
//     (o comando `adman:probe-margem-prev` grava uma linha aqui a cada rodada,
//     lendo direto da Adman com `forceRefresh: true` — nunca do cache);
//   - adman_probe_margem_prev_vereditos: a AGREGAÇÃO dessas leituras num
//     veredito reconsultável (aprovado | reprovado | instrumentacao_suspeita),
//     gerado pelo modo `--relatorio` do mesmo comando.
//
// Nenhuma das duas colunas guarda token/credencial — só métricas numéricas,
// rótulos e o hash do payload cru (ver `leitura_hash` abaixo).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('adman_probe_margem_prev_leituras', function (Blueprint $table) {
            $table->id();

            // Empresa lida. NOT NULL com cascade — deliberadamente NÃO usa o
            // modificador de FK que zera a coluna no delete (esse modificador
            // exigiria ->nullable() na coluna, senão quebra no MariaDB de
            // produção com erro 1830 — armadilha já registrada na memória do
            // projeto). Se uma empresa for removida, suas leituras do probe
            // (um experimento de vida curta) somem junto — não há valor em
            // preservá-las órfãs.
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            // Âncora de reprodutibilidade — a MESMA string YYYY-MM em todas
            // as leituras da rodada, nunca 'last_closed_month' (D-05 do PLAN:
            // leituras se espalham por 24-48h e podem cruzar a virada de mês).
            $table->string('periodo_key');

            // Momento REAL da leitura (não confundir com created_at, que é
            // quando a linha foi persistida — na prática os dois coincidem,
            // mas lida_em é o campo semanticamente correto para a análise de
            // estabilidade ao longo do tempo).
            $table->timestamp('lida_em');

            // Rótulo humano da janela desta leitura (D-02): madrugada |
            // contencao_11h | pico_tarde | repeticao_24h | manual. STRING,
            // nunca coluna de tipo restrito por lista fixa — armadilha já
            // registrada na memória do projeto (esse tipo de coluna cria
            // CHECK enforçado no SQLite dos testes).
            $table->string('janela_esperada')->nullable();

            // ── Payload cru da Adman (percentageMargin), preservado sem
            //    arredondamento ──────────────────────────────────────────
            // decimal(14,6) e NÃO (10,2): guardar com 2 casas (parece razoável
            // porque "é percentual") destruiria a capacidade de detectar
            // variação sub-0,01 entre leituras — e FABRICARIA identidade
            // bit-a-bit, disparando falso-positivo na checagem de sanidade
            // anti-cache (invariante 2 do plano). 6 casas preserva o dado
            // exatamente como a Adman devolveu.
            $table->decimal('value', 14, 6)->nullable();       // percentageMargin.value
            $table->decimal('prev', 14, 6)->nullable();         // percentageMargin.prev
            $table->decimal('diff_nativo', 14, 6)->nullable();  // percentageMargin.diff (contexto; NÃO entra no cálculo de pp)
            $table->decimal('margem_var_pp', 14, 6)->nullable(); // value - prev, calculado no momento da leitura

            // Régua de margem (cópia de DesempenhoScoreService::reguaMargem(),
            // D-12) aplicada sobre margem_var_pp no momento da leitura — é
            // essa nota, comparada entre leituras da mesma empresa, que
            // decide o veredito de flip (D-01).
            $table->unsignedTinyInteger('nota_regua')->nullable();

            // md5 do JSON do sub-array percentageMargin cru — usado SÓ pela
            // verificação de sanidade anti-cache (invariante 2): se todas as
            // leituras de uma empresa tiverem o MESMO hash, é sinal de que o
            // probe está lendo cache, não a Adman ao vivo.
            $table->string('leitura_hash', 32)->nullable();

            // true quando a leitura HTTP falhou (fail-open por item — uma
            // exceção numa empresa não aborta as demais). Falha é DADO, não
            // erro: fica registrada, não descartada.
            $table->boolean('http_falhou')->default(false);

            $table->timestamps();

            $table->index(['company_id', 'lida_em']);
            $table->index(['periodo_key', 'lida_em']);
        });

        Schema::create('adman_probe_margem_prev_vereditos', function (Blueprint $table) {
            $table->id();

            $table->string('periodo_key');
            $table->timestamp('gerado_em');

            // Contadores agregados da rodada de leituras que geraram este veredito.
            $table->unsignedInteger('total_leituras');
            $table->unsignedInteger('total_empresas');
            $table->unsignedInteger('total_rodadas'); // nº de lida_em distintos

            // Fração de leituras com prev não-nulo — comparado contra
            // AdmanMetricDiffService::MARGEM_COBERTURA_MINIMA (D-03), nunca
            // hardcodeado aqui.
            $table->decimal('cobertura_prev', 5, 4)->nullable();

            // Quantas empresas da amostra tiveram nota_regua diferente entre
            // duas leituras quaisquer (D-01 — zero flip é obrigatório para aprovar).
            $table->unsignedInteger('empresas_com_flip_count')->default(0);
            $table->json('empresas_com_flip')->nullable(); // [{company_id, company_name, notas: [..]}]

            // aprovado | reprovado | instrumentacao_suspeita. STRING, nunca
            // coluna de tipo restrito por lista fixa — mesma razão da tabela
            // de leituras acima.
            $table->string('veredito');

            // Lista de motivos legíveis em pt-BR que levaram ao veredito —
            // parte da disciplina "conferir por reconsulta ao banco, nunca
            // por stdout" (D-10, mesmo padrão do gate FIXMARG-03).
            $table->json('motivos')->nullable();

            $table->timestamps();

            $table->index(['periodo_key', 'gerado_em']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adman_probe_margem_prev_vereditos');
        Schema::dropIfExists('adman_probe_margem_prev_leituras');
    }
};
