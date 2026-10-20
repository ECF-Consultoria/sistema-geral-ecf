<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 20/10/2026 — Agente ECF Publicador, fatia AG-01 (`PLANO_AGENTE_ECF_MVP_CLAUDE_CODE.md` §8.2 e
 * `.planning/quick/261020-m6m-ag-00-baseline-do-agente-publicador/AG-00-BASELINE.md`). Configuração e controle
 * POR LOJA e a execução de preparo com deduplicação. Não aprova nem publica nada: a oferta continua sendo
 * `pub_aprovacao_ofertas` e a publicação, a fila e o `PublicacaoService` de sempre.
 *
 * Só CRIA tabelas; nenhuma tabela existente muda. Sem ALTER, sem `enum()` (o CHECK é enforçado no SQLite dos
 * testes), sem default em json, sem backfill.
 *
 * ── `pub_agente_configuracoes` — uma por conta canônica ──────────────────────────────────────────
 * | coluna         | tipo                         | regra                                                          |
 * |----------------|------------------------------|----------------------------------------------------------------|
 * | conta_chave    | string(60) UNIQUE            | `empresa-N`/`company-N` canônica (`ProgramasPublicadorService::resolver`) |
 * | company_id     | unsignedBigInteger NULL      | âncora (sem FK, como `pub_filas_publicacao`)                    |
 * | mlb_empresa_id | unsignedBigInteger NULL      | âncora (sem FK)                                                 |
 * | habilitado     | boolean default false        | nasce DESLIGADA                                                 |
 * | controle       | string(20) default 'ativo'   | ativo / pausado / suspenso (texto livre)                        |
 * | versao         | unsignedInteger default 1    | sobe a cada gravação de configuração OU de controle             |
 * | modalidade     | string(30) NULL              | `listing_type_id` único do piloto (gold_special / gold_pro)     |
 * | estoque_minimo | unsignedInteger default 1    |                                                                |
 * | limite_diario  | unsignedSmallInteger def. 5  | preparos admitidos por dia (unidade: PRODUTO)                   |
 * | teto_fila      | unsignedSmallInteger def. 10 | ofertas aguardando aprovação (unidade: OFERTA)                  |
 * | janela_inicio  | time NULL                    | fuso de São Paulo, `HH:MM:00`; início > fim passa da meia-noite |
 * | janela_fim     | time NULL                    |                                                                |
 * | escopo         | json NULL                    | `{incluir: [pub_produto_id], excluir: [pub_produto_id]}`        |
 * | motivo_controle| string(500) NULL             | por que pausou/suspendeu                                        |
 * | atualizado_por | unsignedBigInteger NULL      | FK `pubagcfg_atualizado_por_fk` -> users, SET NULL               |
 *
 * ── `pub_agente_configuracao_versoes` — o que valia em cada versão ───────────────────────────────
 * Uma linha por versão gravada (`dados` = as chaves que explicam a decisão). Uma execução guarda só o número
 * da versão; a configuração atual pode ter mudado depois, e esta tabela reconstrói a que valia.
 * UNIQUE (configuracao_id, versao); FK CASCADE na configuração.
 *
 * ── `pub_agente_execucoes` — o trabalho de PREPARO de um produto ─────────────────────────────────
 * Estados desta tabela descrevem o preparo; o andamento da oferta continua em `pub_aprovacao_ofertas`.
 * | coluna               | tipo                    | regra                                                       |
 * |----------------------|-------------------------|-------------------------------------------------------------|
 * | uuid                 | char(36) UNIQUE         | identificador público                                       |
 * | configuracao_id      | unsignedBigInteger NULL | FK SET NULL                                                  |
 * | conta_chave          | string(60)              |                                                             |
 * | produto_id           | unsignedBigInteger NULL | FK SET NULL -> pub_produtos                                  |
 * | rascunho_id          | unsignedBigInteger NULL | FK SET NULL -> pub_rascunhos                                 |
 * | origem               | string(20)              | automatica / manual / ao_salvar                              |
 * | configuracao_versao  | unsignedInteger         | a versão que admitiu                                         |
 * | versao_dos_fatos     | char(64) NULL           | hash dos fatos do produto na admissão                        |
 * | chave_logica         | char(64)                | sha256(conta, produto, modalidade, fatos) — deduplicação     |
 * | chave_ativa          | string(100) NULL UNIQUE | = "conta:produto" enquanto viva; NULL ao terminar. 1 viva por produto |
 * | estado               | string(30)              | admitida / preparando / aguardando_aprovacao / concluida / bloqueada / falhou / cancelada |
 * | etapa, motivo        | string NULL             |                                                             |
 * | bloqueios            | json NULL               | pendências objetivas (AG-02)                                 |
 * | solicitada_por       | unsignedBigInteger NULL | FK SET NULL -> users; NULL = automática                      |
 * | tentativas           | unsignedTinyInteger 0   | quantas vezes a AG-06 repediu a cadeia de texto que morreu    |
 * | heartbeat_em         | dateTime NULL           | AG-06 recupera execução parada                               |
 * | iniciada_em, concluida_em | dateTime NULL      |                                                             |
 *
 * O unique de "viva" usa coluna-sombra (`chave_ativa`) porque nem MariaDB 10.4 nem SQLite têm índice parcial em
 * comum; um unique que incluísse `estado` deixaria duas vivas em estados diferentes.
 *
 * Índices nomeados à mão (MariaDB recusa nome acima de 64, erro 1059) e declarados ANTES das FKs. FKs anuláveis
 * são `nullOnDelete` em coluna `nullable()` (sem 1830). `dateTime`, nunca `timestamp()`, nas datas avulsas.
 * Idempotente (`hasTable`). Prova no MariaDB local com `--path` só desta migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pub_agente_configuracoes')) {
            Schema::create('pub_agente_configuracoes', function (Blueprint $t) {
                $t->id();
                $t->string('conta_chave', 60);
                $t->unsignedBigInteger('company_id')->nullable();
                $t->unsignedBigInteger('mlb_empresa_id')->nullable();
                $t->boolean('habilitado')->default(false);
                $t->string('controle', 20)->default('ativo');
                $t->unsignedInteger('versao')->default(1);
                $t->string('modalidade', 30)->nullable();
                $t->unsignedInteger('estoque_minimo')->default(1);
                $t->unsignedSmallInteger('limite_diario')->default(5);
                $t->unsignedSmallInteger('teto_fila')->default(10);
                $t->time('janela_inicio')->nullable();
                $t->time('janela_fim')->nullable();
                $t->json('escopo')->nullable();
                $t->string('motivo_controle', 500)->nullable();
                $t->unsignedBigInteger('atualizado_por')->nullable();
                $t->timestamps();

                $t->unique('conta_chave', 'pubagcfg_conta_uq');
                $t->index('company_id', 'pubagcfg_company_ix');
                $t->index('mlb_empresa_id', 'pubagcfg_mlb_empresa_ix');
                $t->index('atualizado_por', 'pubagcfg_atualizado_por_ix');

                $t->foreign('atualizado_por', 'pubagcfg_atualizado_por_fk')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('pub_agente_configuracao_versoes')) {
            Schema::create('pub_agente_configuracao_versoes', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('configuracao_id');
                $t->unsignedInteger('versao');
                $t->json('dados');
                $t->unsignedBigInteger('autor_id')->nullable();
                $t->dateTime('criada_em');

                $t->unique(['configuracao_id', 'versao'], 'pubagver_cfg_versao_uq');
                $t->index('autor_id', 'pubagver_autor_ix');

                $t->foreign('configuracao_id', 'pubagver_cfg_fk')->references('id')->on('pub_agente_configuracoes')->cascadeOnDelete();
                $t->foreign('autor_id', 'pubagver_autor_fk')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('pub_agente_execucoes')) {
            Schema::create('pub_agente_execucoes', function (Blueprint $t) {
                $t->id();
                $t->char('uuid', 36);
                $t->unsignedBigInteger('configuracao_id')->nullable();
                $t->string('conta_chave', 60);
                $t->unsignedBigInteger('produto_id')->nullable();
                $t->unsignedBigInteger('rascunho_id')->nullable();
                $t->string('origem', 20);
                $t->unsignedInteger('configuracao_versao');
                $t->char('versao_dos_fatos', 64)->nullable();
                $t->char('chave_logica', 64);
                $t->string('chave_ativa', 100)->nullable();
                $t->string('estado', 30)->default('admitida');
                $t->string('etapa', 40)->nullable();
                $t->string('motivo', 500)->nullable();
                $t->json('bloqueios')->nullable();
                $t->unsignedBigInteger('solicitada_por')->nullable();
                $t->unsignedTinyInteger('tentativas')->default(0);
                $t->dateTime('heartbeat_em')->nullable();
                $t->dateTime('iniciada_em')->nullable();
                $t->dateTime('concluida_em')->nullable();
                $t->timestamps();

                $t->unique('uuid', 'pubagexe_uuid_uq');
                $t->unique('chave_ativa', 'pubagexe_chave_ativa_uq');
                $t->index('chave_logica', 'pubagexe_chave_logica_ix');
                $t->index(['conta_chave', 'estado'], 'pubagexe_conta_estado_ix');
                $t->index('configuracao_id', 'pubagexe_cfg_ix');
                $t->index('produto_id', 'pubagexe_produto_ix');
                $t->index('rascunho_id', 'pubagexe_rascunho_ix');
                $t->index('solicitada_por', 'pubagexe_solicitada_por_ix');

                $t->foreign('configuracao_id', 'pubagexe_cfg_fk')->references('id')->on('pub_agente_configuracoes')->nullOnDelete();
                $t->foreign('produto_id', 'pubagexe_produto_fk')->references('id')->on('pub_produtos')->nullOnDelete();
                $t->foreign('rascunho_id', 'pubagexe_rascunho_fk')->references('id')->on('pub_rascunhos')->nullOnDelete();
                $t->foreign('solicitada_por', 'pubagexe_solicitada_por_fk')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        // Filhas antes da mãe.
        Schema::dropIfExists('pub_agente_execucoes');
        Schema::dropIfExists('pub_agente_configuracao_versoes');
        Schema::dropIfExists('pub_agente_configuracoes');
    }
};
