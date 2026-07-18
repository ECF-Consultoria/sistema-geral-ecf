<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Integração OAuth Shopee (API v2) — espelha ml_tokens, mas por SHOP.
 *
 * Diferenças em relação ao ML:
 * - `shop_id` é a chave nativa da loja na Shopee (vai em toda request assinada).
 * - `access_token` dura ~4h (expire_in=14400) → refresh proativo agendado.
 * - `refresh_token` dura ~30 dias e é rotativo (single-use) — sempre substituir
 *   o par ao renovar. `refresh_expires_at` é informativo (a API não devolve a
 *   validade exata; assumimos 30 dias a partir da última renovação).
 *
 * Mantém 1 token por empresa (unique company_id) para paridade com o ML e com a
 * pivot company_marketplaces (marketplace='shopee', store_id=shop_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopee_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('shop_id')->index();        // ID nativo da loja na Shopee (= store_id do company_marketplaces)
            $table->string('merchant_id')->nullable(); // conta cross-border (CB); null p/ shop-level comum
            $table->text('access_token');              // encrypted via cast — validade ~4h
            $table->text('refresh_token');             // encrypted via cast — validade ~30d, rotativo
            $table->timestamp('expires_at');           // expiração do access_token
            $table->timestamp('refresh_expires_at')->nullable(); // informativo (~30d)
            $table->timestamp('last_refreshed_at')->nullable();
            $table->enum('status', ['active', 'expired', 'revoked'])->default('active')->index();
            $table->timestamp('connected_at')->nullable();
            $table->timestamps();

            $table->unique('company_id');              // 1 conexão Shopee ativa por empresa
        });

        // Link pendente de autorização (o admin gera e envia ao cliente) —
        // espelha companies.ml_link_generated_at / ml_link_url.
        Schema::table('companies', function (Blueprint $table) {
            $table->timestamp('shopee_link_generated_at')->nullable()->after('ml_link_url');
            $table->text('shopee_link_url')->nullable()->after('shopee_link_generated_at');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['shopee_link_generated_at', 'shopee_link_url']);
        });

        Schema::dropIfExists('shopee_tokens');
    }
};
