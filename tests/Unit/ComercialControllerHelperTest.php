<?php

namespace Tests\Unit;

use App\Http\Controllers\ComercialController;
use Tests\TestCase;

/**
 * Testes unitários puros do helper estático
 * `ComercialController::servicoDisparaImplementacao()`.
 *
 * O helper roteia o NOME de um serviço do catálogo (Frente A) para o slug
 * de implementação que dispara criação de mlb_empresas/mlb_implementacao.
 * É puro (sem container Laravel) — testável sem RefreshDatabase.
 *
 * Per Phase 14 Plan 14-04 + CONTEXT.md D-05.
 */
class ComercialControllerHelperTest extends TestCase
{
    /**
     * 'Polos' (nome canônico do catálogo) dispara fluxo de implementação POLO.
     */
    public function test_polos_dispara_implementacao_polos(): void
    {
        $this->assertEquals(
            'polos',
            ComercialController::servicoDisparaImplementacao('Polos'),
            "O serviço 'Polos' deve disparar implementação POLO",
        );
    }

    /**
     * Variante com sufixo regional ('Polos SP') ainda bate via str_contains.
     */
    public function test_polos_sp_dispara_implementacao_polos(): void
    {
        $this->assertEquals(
            'polos',
            ComercialController::servicoDisparaImplementacao('Polos SP'),
            "Variantes 'Polos <região>' devem disparar POLO via str_contains",
        );
    }

    /**
     * 'Assessoria' (e variantes 'Assessoria Premium', etc.) dispara fluxo Assessoria.
     */
    public function test_assessoria_dispara_implementacao_assessoria(): void
    {
        $this->assertEquals(
            'assessoria',
            ComercialController::servicoDisparaImplementacao('Assessoria Premium'),
            "Variantes de 'Assessoria' devem disparar ASSESSORIA via str_contains",
        );
    }

    /**
     * 'Incubadora' (e variantes 'Incubadora Tech', etc.) dispara fluxo Incubadora.
     */
    public function test_incubadora_dispara_implementacao_incubadora(): void
    {
        $this->assertEquals(
            'incubadora',
            ComercialController::servicoDisparaImplementacao('Incubadora Tech'),
            "Variantes de 'Incubadora' devem disparar INCUBADORA via str_contains",
        );
    }

    /**
     * 'Publicidade' NÃO dispara implementação — só cria company (COM-06).
     */
    public function test_publicidade_retorna_null(): void
    {
        $this->assertNull(
            ComercialController::servicoDisparaImplementacao('Publicidade'),
            "Publicidade não cria mlb_empresas — fluxo de só company (COM-06)",
        );
    }

    /**
     * 'Gestão' (com cedilha pt-BR) NÃO dispara implementação — só cria company.
     */
    public function test_gestao_retorna_null(): void
    {
        $this->assertNull(
            ComercialController::servicoDisparaImplementacao('Gestão'),
            "Gestão não cria mlb_empresas — fluxo de só company (COM-06)",
        );
    }

    /**
     * Nomes arbitrários (catálogo customizado) retornam null por default —
     * roteamento conservador (apenas os 3 prefixos canônicos disparam).
     */
    public function test_treinamento_arbitrario_retorna_null(): void
    {
        $this->assertNull(
            ComercialController::servicoDisparaImplementacao('Treinamento Vendas'),
            "Serviços fora dos 3 prefixos canônicos não devem disparar implementação",
        );
    }

    /**
     * Case-sensitive: 'polos' lowercase NÃO bate por design.
     * D-02 garante Title Case via mb_convert_case na migration legacy;
     * nomes lowercase nunca entram no catálogo via fluxo normal.
     */
    public function test_polos_lowercase_retorna_null_por_case_sensitive(): void
    {
        $this->assertNull(
            ComercialController::servicoDisparaImplementacao('polos'),
            "str_contains é case-sensitive — D-02 garante Title Case no catálogo",
        );
    }

    /**
     * 'Publicação' (com acento, pt-BR canônico do catálogo) NÃO dispara
     * implementação — Publicação no Phase 14 é apenas tag organizacional,
     * o tipo concreto (POLOS vs Assessoria) é decidido pelo setor depois.
     */
    public function test_publicacao_retorna_null(): void
    {
        $this->assertNull(
            ComercialController::servicoDisparaImplementacao('Publicação'),
            "Publicação genérica não cria mlb_empresas — decidido depois pelo setor",
        );
    }
}
