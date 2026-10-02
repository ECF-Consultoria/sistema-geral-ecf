<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `regeneracoes` em `ml_anuncio_criativos` (Fase 161, Plano 03) — contagem
 * de CLIQUES do operador no botão "Gerar de novo esta imagem", separada de
 * `tentativas`.
 *
 * Por que não reaproveitar `tentativas - 1` (como `MlAnuncioCriativoKit::
 * podeRegenerarAsset()` fazia desde o 161-01): `GerarCriativoIaJob::$tries
 * = 2` faz o Laravel CHAMAR `handle()` DE NOVO (e `tentativas` sobe de novo)
 * quando a 1ª tentativa falha por motivo transitório (503 do provedor,
 * timeout) — sem nenhum clique do operador. Usar `tentativas - 1` como teto
 * de regeneração contaria essa retentativa automática como se fosse uma
 * regeneração manual, consumindo cota de `MAX_REGENERACOES_ASSET` por um
 * evento que o operador nem viu. O teto de custo (é dinheiro, Decisão 6 do
 * 161-02-PLAN.md) precisa medir CLIQUE, não retentativa de provedor — por
 * isso esta coluna nova, incrementada SÓ dentro de
 * `MlbAnuncioController::criativoRegenerar()`.
 *
 * Guardada por `Schema::hasColumn` (mesma convenção das duas migrations
 * anteriores desta fase) — **há 1 linha em produção** (criativo id 1,
 * aprovado, sem kit); `default(0)` para não reescrever nenhuma linha
 * existente com NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('ml_anuncio_criativos', 'regeneracoes')) {
            return;
        }

        Schema::table('ml_anuncio_criativos', function (Blueprint $table) {
            $table->unsignedTinyInteger('regeneracoes')->default(0)->after('tentativas');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('ml_anuncio_criativos', 'regeneracoes')) {
            return;
        }

        Schema::table('ml_anuncio_criativos', function (Blueprint $table) {
            $table->dropColumn('regeneracoes');
        });
    }
};
