<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// pt-BR: Migration da tabela `contrato_assinatura_signatarios` — Fase 125
// (DADOS-02). Cada pessoa que assina um contrato, com papel, contato,
// situação individual e a evidência de autenticação do Gate #9 — a
// Clicksign não responde "fulano assinou?" (GET /signers/{id} só muda
// `modified` antes×depois; ver CLICKSIGN-SANDBOX-EMPIRICO.md §3), então
// esta tabela é a ÚNICA fonte consultável dessa informação.
//
// Decisões travadas aplicadas (ver 125-CONTEXT.md):
//   D-07 — `user_id` NULLABLE + nome/e-mail/CPF SEMPRE copiados para o
//   próprio registro. Evidência jurídica não pode depender de FK viva: se
//   a pessoa sair da ECF e o usuário for apagado, o contrato assinado
//   ainda precisa dizer quem assinou.
//   D-08 — `papel` é vocabulário NOSSO (contratante/contratada/
//   testemunha), NUNCA o vocabulário da Clicksign (sign/party/contractor,
//   que reaparece como `sign_as` no evento). O mapa é tarefa da Fase 126.
//   D-09 — `situacao` tem lista PRÓPRIA e curta (pendente/assinou/
//   recusou), nunca reusa os estados do `ContratoAssinatura` — eles não
//   significam nada do lado de uma pessoa.
//   D-04 — `papel`/`situacao` são STRING + constantes no model, nunca
//   `enum` de banco.
//
// Armadilhas de MariaDB que o SQLite dos testes NÃO pega (ver <pitfalls>
// do 125-CONTEXT.md) — as duas que mordem de verdade nesta migration:
//   1. FK `nullOnDelete()` sem `->nullable()` (erro 1830) — vale
//      diretamente para `user_id` (D-07). O `->nullable()` está
//      declarado na coluna, ANTES da FK lá embaixo — conferir as duas
//      juntas.
//   2. Nome de índice/constraint acima de 64 caracteres (erro 1059) — a
//      falha é SILENCIOSA: cria a tabela SEM o índice/FK e deixa a
//      migration `Pending`. O nome de tabela
//      `contrato_assinatura_signatarios` (31 chars) faz a FK que o
//      Laravel geraria sozinho para `contrato_assinatura_id`
//      (`contrato_assinatura_signatarios_contrato_assinatura_id_foreign`)
//      chegar a 62 caracteres — só 2 de margem para o limite. NÃO
//      confiar nessa margem: toda FK e todo índice desta migration é
//      nomeado à mão.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contrato_assinatura_signatarios', function (Blueprint $table) {
            $table->id();

            // NOT NULL — signatário sem contrato não tem significado.
            // Propositalmente SEM foreignId()->constrained(): o nome de
            // constraint autogerado estouraria o limite de 64 chars do
            // MariaDB (ver comentário no topo). FK nomeada à mão logo
            // abaixo, junto com os demais índices.
            $table->unsignedBigInteger('contrato_assinatura_id');

            // D-07 — nullable é OBRIGATÓRIO: a FK abaixo é `nullOnDelete`
            // e o MariaDB recusa `ON DELETE SET NULL` em coluna NOT NULL
            // (erro 1830). O SQLite dos testes não pega isso — só o
            // deploy em MariaDB real prova (plano 125-03).
            $table->unsignedBigInteger('user_id')->nullable();

            // D-08 — contratante | contratada | testemunha. Vocabulário
            // NOSSO: a Clicksign usa sign/party/contractor (que reaparece
            // como `sign_as` no evento `sign`) — esse vocabulário NÃO
            // entra aqui. O mapa entre os dois é tarefa da Fase 126.
            // STRING, nunca `enum` de banco (D-04).
            $table->string('papel', 20);

            // D-07 — sempre copiado, mesmo quando `user_id` está
            // preenchido. É o dado que sobrevive à exclusão do usuário.
            $table->string('nome');
            $table->string('email');

            // Nullable: nem todo signatário interno tem CPF cadastrado no
            // momento da geração do contrato. NOT NULL aqui quebraria a
            // Fase 126 em vez de sinalizar o dado faltante — o CPF
            // continua sendo copiado sempre que existir (D-07).
            $table->string('cpf', 14)->nullable();

            // D-09 — pendente | assinou | recusou. Lista PRÓPRIA, curta,
            // NUNCA reusa `ContratoAssinatura::STATUS_*` (rascunho, erro,
            // cancelado e expirado não significam nada do lado de uma
            // pessoa). É a ÚNICA fonte consultável dessa informação — a
            // Clicksign não responde "fulano assinou?" (Gate #9,
            // CLICKSIGN-SANDBOX-EMPIRICO.md §3). STRING, nunca `enum`
            // (D-04).
            $table->string('situacao', 20)->default('pendente');

            // Carimbo de tempo da assinatura — promovido do `created` do
            // evento `sign` (Gate #9). Não existe campo `signed_at`
            // dentro de `data.signer`; o timestamp vive no evento.
            $table->timestamp('assinado_em')->nullable();

            // Promovido de `data.signer.address` (Gate #9). 45 caracteres
            // cobre IPv6 (padrão já usado no projeto para IP).
            $table->string('ip_address', 45)->nullable();

            // Promovido de `data.signer.auths` — lista de métodos de
            // autenticação usados (ex.: ["email"]). JSON, não string
            // concatenada, para permitir múltiplos métodos.
            $table->json('auths')->nullable();

            // O bloco `data.signer` INTEIRO do evento `sign`, gravado sem
            // poda (Gate #9 — CLICKSIGN-SANDBOX-EMPIRICO.md §3). As três
            // colunas acima (assinado_em/ip_address/auths) são promoções
            // de LEITURA para consulta por SQL sem abrir o JSON; esta
            // coluna é a fonte íntegra, para auditoria e para qualquer
            // campo que a tela venha a precisar depois.
            $table->json('evidencia_signer')->nullable();

            // O `key` do signatário na Clicksign — preenchido pela Fase
            // 126 e usado pela Fase 129 para casar evento → signatário.
            $table->string('clicksign_signer_key', 64)->nullable();

            $table->timestamps();

            // FK do contrato — nome curto à mão (armadilha 1059, ver
            // topo). `cascadeOnDelete`: não existe fluxo de exclusão de
            // contrato nesta milestone, então só dispara em remoção
            // manual deliberada. Se a Fase 131 vier a criar um botão de
            // excluir contrato, ela precisa saber que a exclusão leva os
            // signatários junto (T-125-16).
            $table->foreign('contrato_assinatura_id', 'cas_contrato_fk')
                ->references('id')->on('contrato_assinaturas')
                ->cascadeOnDelete();

            // FK do usuário — par obrigatório do `->nullable()` da
            // coluna (D-07, armadilha 1830 acima).
            $table->foreign('user_id', 'cas_user_fk')
                ->references('id')->on('users')
                ->nullOnDelete();

            // Leitura "quem ainda não assinou este contrato".
            $table->index(['contrato_assinatura_id', 'situacao'], 'cas_contrato_situacao_idx');

            // Leitura "de quem é este evento" — usado pela Fase 129 para
            // casar evento do webhook → signatário.
            $table->index('clicksign_signer_key', 'cas_clicksign_signer_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contrato_assinatura_signatarios');
    }
};
