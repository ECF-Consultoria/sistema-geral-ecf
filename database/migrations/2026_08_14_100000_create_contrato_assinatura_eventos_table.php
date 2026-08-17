<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// pt-BR: Migration da tabela `contrato_assinatura_eventos` — Fase 129
// (DADOS-03). Grava TODO corpo de webhook que chega da Clicksign, bruto,
// mesmo quando a assinatura não confere — é o instrumento de medição do
// gate A1 (129-CONTEXT.md) e a evidência que o comando
// `clicksign:verificar-assinatura` (D-09) e o receiver de produção (plano
// 129-02/03) vão ler depois.
//
// Decisões travadas aplicadas (ver 129-CONTEXT.md e 129-PATTERNS.md):
//   D-08 — a fórmula do HMAC ainda não foi medida contra webhook real.
//   `payload` é JSON GENÉRICO, sem promover nenhum campo do `document`
//   embutido a coluna própria (Pitfall C do 129-RESEARCH.md): a forma real
//   do corpo desta integração nunca foi medida contra este projeto.
//   D-04 (herdada da Fase 125) — `name`/`status`/`origem` são STRING +
//   constantes no model, NUNCA `enum` de banco: a lista documentada de
//   eventos da Clicksign não é exaustiva (`update_block_after_refusal` foi
//   MEDIDO e não aparece na doc oficial — 129-RESEARCH.md §1).
//   CLICK-04 — `payload_hash` UNIQUE é a idempotência de verdade. Duas
//   requisições HTTP concorrentes (retry real da Clicksign, gate #11 ainda
//   não medido) passam juntas por qualquer verificação `exists()`; só a
//   constraint única do banco sobrevive à corrida.
//
// Armadilhas de MariaDB que o SQLite dos testes NÃO pega (mesma disciplina
// de `2026_08_10_100001_create_contrato_assinatura_signatarios_table.php`):
//   1. FK `nullOnDelete()` sem `->nullable()` (erro 1830) — vale para
//      `contrato_assinatura_id`: o evento pode chegar antes de sabermos a
//      qual contrato ele pertence (assinatura inválida, envelope
//      desconhecido), e a DADOS-03 manda gravar assim mesmo.
//   2. Nome de índice/constraint acima de 64 caracteres (erro 1059) — a
//      falha é SILENCIOSA: cria a tabela SEM o índice/FK e deixa a
//      migration `Pending` (armadilha que já custou um índice inteiro na
//      Fase 122). Toda FK e todo índice desta migration é nomeado à mão,
//      sem exceção — nunca confiar no nome autogerado do Laravel.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contrato_assinatura_eventos', function (Blueprint $table) {
            $table->id();

            // Nullable OBRIGATÓRIO — par da FK `nullOnDelete` abaixo (erro
            // 1830 no MariaDB, ver topo). Também reflete a realidade do
            // domínio: um evento com assinatura inválida ou envelope
            // desconhecido não tem contrato para vincular, e mesmo assim
            // precisa ser gravado (DADOS-03).
            $table->unsignedBigInteger('contrato_assinatura_id')->nullable();

            $table->string('clicksign_envelope_id', 64)->nullable();

            // Nome do evento — STRING livre, NUNCA `enum` de banco: a lista
            // documentada de 24 eventos não é exaustiva
            // (`update_block_after_refusal` foi MEDIDO e não aparece na doc
            // — 129-RESEARCH.md §1), e `enum` em MariaDB vira CHECK que o
            // SQLite dos testes não enxerga (armadilha já registrada no
            // projeto).
            $table->string('name', 120)->nullable();

            $table->boolean('signature_valid')->default(false);

            // JSON genérico, sem promover nenhum campo do `document`
            // embutido a coluna própria (Pitfall C) — a forma real do
            // corpo do webhook nunca foi medida contra este projeto e a
            // doc mistura duas formas incompatíveis. Quando o corpo não
            // for JSON válido, quem grava põe aqui
            // ['raw' => <trecho>, 'motivo' => <texto>, 'ip' => <ip>]
            // (padrão HubspotWebhookController::gravarInvalido()).
            $table->json('payload');

            // Corpo BRUTO, verbatim. Sem isto o comando da D-09 é
            // impossível: o HMAC é sobre os bytes exatos, e recodificar o
            // JSON decodificado não reproduz a ordem das chaves. Truncado
            // em ContratoAssinaturaEvento::RAW_BODY_MAX_BYTES (65.000) por
            // mb_strcut() por quem grava.
            $table->mediumText('raw_body')->nullable();

            // Marca que `raw_body` foi cortado e portanto a assinatura NÃO
            // é reverificável a partir dele.
            $table->boolean('raw_truncado')->default(false);

            // hash('sha256', $rawBody) sobre o corpo INTEIRO (não sobre o
            // truncado, não sobre o JSON decodificado) — é esta constraint
            // que garante a idempotência da CLICK-04 (ver topo).
            $table->string('payload_hash', 64);

            // webhook | sonda. STRING + constantes no model.
            $table->string('origem', 20)->default('webhook');

            // STRING, não `enum` — coerência com `ContratoAssinatura`/
            // `ContratoAssinaturaSignatario` (D-04 da Fase 125), e evita a
            // armadilha de `enum` + MariaDB CHECK que `hubspot_eventos`
            // (precedente) usa e que aqui é deliberadamente NÃO copiada.
            $table->string('status', 20)->default('recebido');

            $table->string('ip_address', 45)->nullable();
            $table->text('erro_msg')->nullable();
            $table->timestamp('processado_em')->nullable();
            $table->timestamps();

            // Índices e FK — TODOS nomeados à mão (Pitfall D; a Fase 122 já
            // perdeu um índice em silêncio no MariaDB por nome acima de 64
            // caracteres).
            $table->unique('payload_hash', 'cae_payload_hash_uniq');
            $table->index('clicksign_envelope_id', 'cae_envelope_idx');
            $table->index(['status', 'created_at'], 'cae_status_created_idx');

            $table->foreign('contrato_assinatura_id', 'cae_contrato_fk')
                ->references('id')->on('contrato_assinaturas')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contrato_assinatura_eventos');
    }
};
