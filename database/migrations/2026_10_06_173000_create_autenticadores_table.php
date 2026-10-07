<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabela do módulo "Autenticadores 2FA": cofre interno dos códigos TOTP das
 * contas que a ECF opera (Google, Amazon, Mercado Livre...).
 *
 * Decisão de schema (por escrito, conforme convenção do repo):
 *
 * - `secret` guarda o seed TOTP CIFRADO em repouso (cast App\Casts\TotpSecret,
 *   chave dedicada AUTENTICADORES_ENC_KEY, separada da APP_KEY). Nunca em texto
 *   puro. É `text` porque o ciphertext do Encrypter é bem maior que o Base32.
 *
 * - `secret_hash` = SHA-256 do secret normalizado (Base32). Serve para
 *   deduplicar na importação ("reimportar não duplica") SEM precisar decifrar, e
 *   tem índice único. Seeds são aleatórios, então colisão entre contas distintas
 *   é irrealista; o único = "a mesma conta não entra duas vezes".
 *
 * - `cliente`, `conta`, `servico` são string (não FK) porque a busca do time é
 *   livre — número no domínio, nome da loja, parte antes do @, serviço — e nem
 *   todo cliente aqui é um registro de `companies`. Índices em cliente/servico/
 *   status para os filtros da tela.
 *
 * - Acesso ao módulo é de todos os colaboradores logados (decisão do produto);
 *   o controle compensatório é o log de auditoria (quem viu/copiou), gravado no
 *   activity_log sob log_name 'autenticadores'. Por isso NÃO há coluna de acesso
 *   aqui — o histórico vive no activity_log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('autenticadores', function (Blueprint $table) {
            $table->id();
            $table->string('cliente');                 // nome da loja/empresa
            $table->string('conta');                   // e-mail ou identificação
            $table->string('servico');                 // Google, Amazon, Mercado Livre...
            $table->string('issuer')->nullable();      // issuer técnico do otpauth
            $table->text('secret');                    // CIFRADO (chave dedicada) — nunca texto puro
            $table->string('secret_hash', 64)->unique(); // SHA-256 do secret p/ dedup sem decifrar
            $table->string('algoritmo', 10)->default('SHA1');
            $table->unsignedTinyInteger('digitos')->default(6);
            $table->unsignedSmallInteger('periodo')->default(30);
            $table->string('status', 20)->default('ativo'); // ativo | expirando | inativo
            $table->foreignId('responsavel_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('cliente');
            $table->index('servico');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('autenticadores');
    }
};
