<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// pt-BR: Migration da D-06 (Fase 127-01, DADOS-06) — a trava de unicidade de
// `contrato_assinaturas` em andamento deixa de ser por EMPRESA e passa a ser
// por (EMPRESA + SERVIÇO).
//
// A CONTRADIÇÃO que motivou esta migration (ver 127-CONTEXT.md, D-06): a
// Fase 125 criou `unique('company_id_em_andamento', 'ca_company_andamento_uniq')`
// — uma coluna só, no máximo UM contrato em andamento por empresa. Um dia
// depois, a D-21 (Fase 126) estabeleceu um modelo `.docx` por serviço, o que
// exige N contratos por empresa. Somado à D-01 (gerar os N pela fila,
// juntos), o segundo serviço bateria na constraint SEMPRE — não é corrida,
// é determinístico. As duas tabelas já estão em produção mas VAZIAS
// (verificado no CONTEXT): não há dado para migrar, e esta é a janela
// barata para a correção.
//
// Armadilhas de MariaDB que esta migration respeita (o SQLite dos testes
// NÃO pega nenhuma das três abaixo):
//   1. `enum` + SQLite — nenhuma coluna `$table->enum(` aqui.
//   2. `nullOnDelete()` sem `->nullable()` (erro 1830) — não se aplica: a FK
//      `servico_id` usa `restrictOnDelete()`, não `nullOnDelete()`. Um
//      contrato não pode sobreviver à exclusão do serviço que representa.
//   3. Nome de índice acima de 64 caracteres (erro 1059) — falha SILENCIOSA
//      (cria sem o índice, migration fica `Pending`). Todo índice aqui é
//      nomeado à mão, curto.
//   4. ARMADILHA ESPECÍFICA DESTA MIGRATION — dropar índice usado por FK
//      falha com erro 1553 no MariaDB. O SQLite dos testes passa verde nas
//      DUAS ordens (criar-depois-dropar OU dropar-depois-criar) — é
//      regressão que só aparece no deploy. Por isso o índice novo
//      (`ca_empresa_servico_andamento_uniq`) é criado ANTES de dropar o
//      antigo (`ca_company_andamento_uniq`), sempre.
//
// Por que `servico_id` nasce `nullable()`: o SQLite dos testes recusa
// `ADD COLUMN ... NOT NULL` sem default ("Cannot add a NOT NULL column with
// default value NULL"), mesmo em tabela vazia. A obrigatoriedade REAL não
// vem do schema — vem do hook `saving` do model (Task 3), que lança
// `\RuntimeException` se `servico_id` estiver vazio ao salvar.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contrato_assinaturas', function (Blueprint $table) {
            // Relação real com o catálogo de serviços — ao contrário da
            // coluna espelho `company_id_em_andamento` (sem FK própria por
            // desenho da Fase 125), esta FK existe porque `servico_id` é a
            // FONTE, não um derivado. `restrictOnDelete()` porque um
            // contrato não pode sobreviver à exclusão do serviço que ele
            // representa — não é `nullOnDelete()`, então a armadilha 1830
            // não se aplica aqui.
            $table->foreignId('servico_id')
                ->nullable()
                ->after('company_id')
                ->constrained('servicos')
                ->restrictOnDelete();

            // Espelho derivado, igual a `company_id_em_andamento` da Fase
            // 125: SEM FK própria de propósito (a integridade já vem da FK
            // de `servico_id` acima). Preenchido pelo hook `saving` do
            // model (Task 3), nunca escrito à mão.
            $table->unsignedBigInteger('servico_id_em_andamento')
                ->nullable()
                ->after('company_id_em_andamento');

            // DADOS-06: o prazo escolhido na criação do envelope (D-03)
            // fica auditável no NOSSO banco, não só dentro do envelope da
            // Clicksign. `null` significa "usou o padrão da configuração" —
            // resolvido no acessor (plano 127-04). A Fase 130 (alerta de
            // contrato preso) precisa do prazo real para calcular "há
            // quanto tempo é aceitável esperar".
            $table->unsignedSmallInteger('prazo_dias')->nullable();
            $table->unsignedSmallInteger('lembrete_dias')->nullable();
        });

        // Segundo `Schema::table(...)` de propósito: criar o índice novo
        // ANTES de dropar o antigo (armadilha 1553 — ver comentário no topo
        // do arquivo). As duas operações precisam estar em statements ALTER
        // TABLE separados e nesta ordem exata no texto do arquivo, porque a
        // guarda estática (MigrationsFase127ConvencoesTest) confere a
        // ordem por `strpos()` no código-fonte.
        Schema::table('contrato_assinaturas', function (Blueprint $table) {
            // Garantia REAL da D-06: um contrato em andamento por (empresa +
            // serviço). MariaDB e SQLite tratam múltiplos NULL como
            // distintos, então contratos encerrados (colunas NULL) nunca
            // colidem entre si.
            $table->unique(['company_id_em_andamento', 'servico_id_em_andamento'], 'ca_empresa_servico_andamento_uniq');
        });

        Schema::table('contrato_assinaturas', function (Blueprint $table) {
            // Índice de UMA coluna só da Fase 125 — substituído pelo composto
            // acima. Dropar DEPOIS de criar o novo, nunca antes (erro 1553).
            $table->dropUnique('ca_company_andamento_uniq');
        });
    }

    public function down(): void
    {
        // Inverso simétrico: recriar o índice antigo ANTES de dropar o
        // novo (mesma disciplina do erro 1553, na direção contrária).
        Schema::table('contrato_assinaturas', function (Blueprint $table) {
            $table->unique('company_id_em_andamento', 'ca_company_andamento_uniq');
        });

        Schema::table('contrato_assinaturas', function (Blueprint $table) {
            $table->dropUnique('ca_empresa_servico_andamento_uniq');
        });

        Schema::table('contrato_assinaturas', function (Blueprint $table) {
            // Dropar a FK antes da coluna.
            $table->dropForeign(['servico_id']);
        });

        Schema::table('contrato_assinaturas', function (Blueprint $table) {
            $table->dropColumn(['servico_id', 'servico_id_em_andamento', 'prazo_dias', 'lembrete_dias']);
        });
    }
};
