<?php

namespace Tests\Unit\Quick261007Amb;

use App\Services\Creative\CreativeSlotCatalog;
use App\Services\Creative\Dto\ProductTruth;
use Tests\TestCase;

/**
 * Quick 261007-amb, Tarefa 1 — `CreativeSlotCatalog::elegiveis($truth, $categoriaMoveis)`.
 *
 * Prova que `$categoriaMoveis` só troca QUEM ocupa a posição 1 (`lifestyle`
 * em vez de `hero`) — tudo o resto da prioridade (COM_FATO elegíveis, resto
 * dos SEM_FATO) continua idêntico, e o default `false` preserva o
 * comportamento de sempre para quem ainda não passa o parâmetro.
 */
class CreativeSlotCatalogPrimeiroSlotTest extends TestCase
{
    private function catalogo(): CreativeSlotCatalog
    {
        return new CreativeSlotCatalog;
    }

    private function truth(array $fatosVerificados = [], array $atributosIds = []): ProductTruth
    {
        return new ProductTruth(
            marca: null,
            modelo: null,
            fatosVerificados: $fatosVerificados,
            contagens: [],
            beneficiosVerificados: [],
            claimsProibidas: ['claim fixa'],
            referenciasMeta: [],
            atributosIds: $atributosIds,
        );
    }

    // ═══ Default — compatibilidade retroativa ═══════════════════════════

    public function test_sem_passar_categoria_moveis_continua_hero_primeiro(): void
    {
        $elegiveis = $this->catalogo()->elegiveis($this->truth());

        $this->assertSame('hero', $elegiveis[0]);
    }

    public function test_categoria_moveis_falso_explicito_continua_hero_primeiro(): void
    {
        $elegiveis = $this->catalogo()->elegiveis($this->truth(), false);

        $this->assertSame('hero', $elegiveis[0]);
    }

    // ═══ Categoria de móvel — lifestyle assume a posição 1 ══════════════

    public function test_categoria_moveis_verdadeiro_poe_lifestyle_primeiro(): void
    {
        $elegiveis = $this->catalogo()->elegiveis($this->truth(), true);

        $this->assertSame('lifestyle', $elegiveis[0]);
    }

    public function test_hero_continua_na_lista_so_que_fora_da_frente_quando_e_moveis(): void
    {
        $elegiveis = $this->catalogo()->elegiveis($this->truth(), true);

        $this->assertContains('hero', $elegiveis);
        $this->assertNotSame('hero', $elegiveis[0]);
    }

    public function test_lifestyle_nunca_duplicado_na_lista_quando_e_moveis(): void
    {
        $elegiveis = $this->catalogo()->elegiveis($this->truth(), true);

        $this->assertSame(1, collect($elegiveis)->filter(fn ($t) => $t === 'lifestyle')->count());
    }

    // ═══ COM_FATO elegíveis entram depois do primeiro slot, com ou sem móvel ═

    public function test_com_fato_elegivel_entra_na_segunda_posicao_tanto_com_quanto_sem_moveis(): void
    {
        $atributos = ['WIDTH' => '45 cm'];

        $semMoveis = $this->catalogo()->elegiveis($this->truth(atributosIds: $atributos), false);
        $comMoveis = $this->catalogo()->elegiveis($this->truth(atributosIds: $atributos), true);

        $this->assertSame('dimensions', $semMoveis[1]);
        $this->assertSame('dimensions', $comMoveis[1]);
    }
}
