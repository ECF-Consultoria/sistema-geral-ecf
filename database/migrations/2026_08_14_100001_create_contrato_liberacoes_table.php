<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// pt-BR: Migration da tabela `contrato_liberacoes` — Fase 129 Plano 04
// (D-05 do CONTEXT). É o FATO gravado de que uma empresa foi liberada para
// o operacional, POR SERVIÇO (D-01) — quem, quando, por quê e por qual via
// (webhook desta fase ou manual da Fase 130), mesmo quando a liberação não
// gera nenhuma `MlbEmpresa` (D-03: "Gestão" sozinho não gera ficha, mas
// ainda assim precisa constar como liberado, senão o alerta da Fase 130
// acusaria falso positivo eterno em toda empresa só-Gestão/só-Mentoria).
//
// Esta tabela é o CONTRATO DE LEITURA das Fases 130 (alerta de contrato
// preso + tela de liberação manual) e 131 (tela de administração) — mudar
// o schema depois quebra as duas.
//
// Decisões travadas aplicadas (ver 129-CONTEXT.md):
//   D-01 — liberação é POR (empresa, serviço), cada um no seu tempo.
//   D-03 — "liberada" é estado PRÓPRIO, gravado mesmo quando o serviço não
//   gera ficha operacional.
//   D-05 — tabela NOVA, separada de `contratos_servico`, com quem/quando/
//   por quê/por qual via.
//   `via` é STRING + constantes no model, NUNCA `enum` de banco — mesma
//   convenção de `ContratoAssinatura`/`ContratoAssinaturaSignatario`
//   (armadilha de `enum` + CHECK do MariaDB que o SQLite dos testes não
//   pega, já registrada no projeto).
//
// Armadilhas de MariaDB que o SQLite dos testes NÃO pega (mesma disciplina
// de `2026_08_10_100001_create_contrato_assinatura_signatarios_table.php`):
//   1. FK `nullOnDelete()` sem `->nullable()` na coluna (erro 1830) — vale
//      para `contrato_assinatura_id` e `liberado_por_user_id` abaixo.
//   2. Nome de índice/constraint acima de 64 caracteres (erro 1059) — falha
//      SILENCIOSA: cria a tabela SEM o índice/FK e deixa a migration
//      `Pending`. Toda FK e todo índice desta migration é nomeado à mão,
//      prefixo curto `cl_`.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contrato_liberacoes', function (Blueprint $table) {
            $table->id();

            // NOT NULL — a liberação é por (empresa, serviço); nenhuma das
            // duas pontas é opcional.
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('servico_id');

            // Nullable de propósito: a liberação manual da Fase 130 pode
            // acontecer justamente quando a Clicksign falhou e não há
            // contrato utilizável. O vínculo é intencional quando existe
            // (D-05 — separar a liberação da evidência que a causou foi o
            // motivo de recusar guardar isto em `contratos_servico`).
            $table->unsignedBigInteger('contrato_assinatura_id')->nullable();

            // webhook | manual. STRING + constantes no model, nunca `enum`
            // de banco (ver comentário de topo).
            $table->string('via', 20);

            // Nullable é OBRIGATÓRIO: a FK abaixo é `nullOnDelete` e o
            // MariaDB recusa `ON DELETE SET NULL` em coluna NOT NULL (erro
            // 1830, invisível no SQLite). Nulo no caminho `webhook` (não há
            // pessoa), preenchido no `manual`.
            $table->unsignedBigInteger('liberado_por_user_id')->nullable();

            // Obrigatório por REGRA DE NEGÓCIO só na liberação manual (Fase
            // 130, SC3); a obrigatoriedade fica no chamador, não no schema,
            // porque o caminho `webhook` não tem motivo humano.
            $table->text('motivo')->nullable();

            // Prova material da D-03: se esta liberação criou `MlbEmpresa`.
            // Sem esta coluna, o alerta da Fase 130 ("empresa sem liberação
            // há X dias") não teria como distinguir "não liberou" de
            // "liberou e o serviço simplesmente não gera ficha" — e
            // acusaria falso positivo eterno em toda empresa só-Gestão /
            // só-Mentoria.
            $table->boolean('gerou_ficha')->default(false);

            $table->timestamp('liberado_em');

            $table->timestamps();

            // Este índice É o guard de idempotência do EFEITO (CLICK-04,
            // "Segundo guard necessário" do RESEARCH). Idempotência de
            // INGESTÃO (`payload_hash`, em `contrato_assinatura_eventos`)
            // não impede que dois eventos DIFERENTES do mesmo envelope
            // (`sign` e depois `document_closed`) tentem liberar duas
            // vezes; e não impede a corrida da Fase 130 (SC4: liberação
            // manual e webhook simultâneos). Só a constraint única
            // sobrevive às duas.
            $table->unique(['company_id', 'servico_id'], 'cl_empresa_servico_uniq');

            $table->index('liberado_em', 'cl_liberado_em_idx');

            $table->foreign('company_id', 'cl_company_fk')
                ->references('id')->on('companies')
                ->cascadeOnDelete();

            $table->foreign('servico_id', 'cl_servico_fk')
                ->references('id')->on('servicos')
                ->cascadeOnDelete();

            $table->foreign('contrato_assinatura_id', 'cl_contrato_fk')
                ->references('id')->on('contrato_assinaturas')
                ->nullOnDelete();

            $table->foreign('liberado_por_user_id', 'cl_user_fk')
                ->references('id')->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contrato_liberacoes');
    }
};
