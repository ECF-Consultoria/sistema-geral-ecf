<?php

namespace Tests\Feature\Phase140;

use App\Models\Company;
use App\Services\Contratos\EmpresaPalpiteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 140 Plano 03 (TAB-05) — cobre `EmpresaPalpiteService`: a régua de
 * confiança que casa um contrato lido do Clicksign com uma empresa do
 * sistema.
 *
 * ⚠️ **Nomes fictícios de propósito.** Os 4 casos MEDIDOS do D-05 (140-
 * CONTEXT.md) usam nomes reais de empresas — este teste reproduz a MESMA
 * dinâmica de cada caso (mesma faixa de pontuação, mesmo veredito) com
 * pares fictícios, para não versionar nome de empresa real num fixture de
 * teste. O comentário de cada teste diz qual caso do D-05 ele espelha.
 */
class Phase140EmpresaPalpiteTest extends TestCase
{
    use RefreshDatabase;

    private const PREFIXO_ENVELOPE = 'Contrato Gestao de Ads ECF - ';

    private function servico(): EmpresaPalpiteService
    {
        return new EmpresaPalpiteService();
    }

    // ─── certo — só por CNPJ ou razão social exata ───

    #[Test]
    public function cnpj_lido_no_contrato_que_bate_com_empresa_cadastrada_da_confianca_certo(): void
    {
        $empresa = Company::factory()->create([
            'cnpj'         => '12.345.678/0001-99',
            'razao_social' => null,
        ]);

        // CNPJ lido do contrato vem sem máscara — a comparação normaliza os
        // dois lados antes de comparar.
        $resultado = $this->servico()->palpitar(
            cnpj: '12345678000199',
            razaoSocial: null,
            nomeEnvelope: self::PREFIXO_ENVELOPE . 'NOME QUALQUER LTDA',
        );

        $this->assertSame($empresa->id, $resultado['company_id']);
        $this->assertSame($empresa->name, $resultado['company_nome']);
        $this->assertSame('certo', $resultado['confianca']);
        $this->assertSame(100.0, $resultado['pontuacao']);
        $this->assertFalse($resultado['ambiguo']);
    }

    #[Test]
    public function razao_social_identica_normalizada_da_confianca_certo(): void
    {
        $empresa = Company::factory()->create([
            'razao_social' => 'Comercio Fictício Modelo LTDA',
            'cnpj'         => null,
        ]);

        // Maiúsculas/acentuação diferentes do lado do texto lido — a
        // comparação normaliza os dois lados.
        $resultado = $this->servico()->palpitar(
            cnpj: null,
            razaoSocial: 'COMERCIO FICTICIO MODELO LTDA',
            nomeEnvelope: self::PREFIXO_ENVELOPE . 'NOME QUALQUER LTDA',
        );

        $this->assertSame($empresa->id, $resultado['company_id']);
        $this->assertSame('certo', $resultado['confianca']);
        $this->assertSame(100.0, $resultado['pontuacao']);
    }

    // ─── incerto — semelhança de nome nunca vira certo/provavel ───

    #[Test]
    public function caso_d05_1_nome_de_pessoa_fisica_parecido_com_empresa_nunca_sai_como_certo_ou_provavel(): void
    {
        // Espelha o caso D-05 "GRAFICA ADHARA → Filipe Adada (50%)": nome de
        // PESSOA parecido com nome de empresa, palpite errado que parece
        // acerto. Fictício aqui, mesma dinâmica (~59%, abaixo de 85).
        $pessoaFisicaParecida = Company::factory()->create([
            'name'         => 'Felipe Burite',
            'razao_social' => null,
            'cnpj'         => null,
        ]);

        $resultado = $this->servico()->palpitar(
            cnpj: null,
            razaoSocial: null,
            nomeEnvelope: self::PREFIXO_ENVELOPE . 'GRAFICA BURITI',
        );

        $this->assertSame($pessoaFisicaParecida->id, $resultado['company_id']);
        $this->assertSame('incerto', $resultado['confianca']);
        $this->assertNotSame('certo', $resultado['confianca']);
        $this->assertNotSame('provavel', $resultado['confianca']);
        $this->assertLessThan(85.0, $resultado['pontuacao']);
    }

    #[Test]
    public function caso_d05_2_nome_abreviado_parecido_nunca_sai_como_certo_ou_provavel(): void
    {
        // Espelha o caso D-05 "DACOTEX → D.A DECOR (50%)".
        Company::factory()->create([
            'name'         => 'B.A Delux',
            'razao_social' => null,
        ]);

        $resultado = $this->servico()->palpitar(
            cnpj: null,
            razaoSocial: null,
            nomeEnvelope: self::PREFIXO_ENVELOPE . 'BACOFEX',
        );

        $this->assertSame('incerto', $resultado['confianca']);
        $this->assertLessThan(85.0, $resultado['pontuacao']);
    }

    #[Test]
    public function caso_d05_3_nome_parecido_com_pontuacao_media_alta_nunca_sai_como_certo_ou_provavel(): void
    {
        // Espelha o caso D-05 "M G MOVEIS → PARMAMOVEIS (66%)" — pontuação
        // mais alta que os outros dois casos errados, mas ainda abaixo do
        // patamar de `provavel`.
        Company::factory()->create([
            'name'         => 'Carmamodas',
            'razao_social' => null,
        ]);

        $resultado = $this->servico()->palpitar(
            cnpj: null,
            razaoSocial: null,
            nomeEnvelope: self::PREFIXO_ENVELOPE . 'A B MODAS',
        );

        $this->assertSame('incerto', $resultado['confianca']);
        $this->assertLessThan(85.0, $resultado['pontuacao']);
    }

    #[Test]
    public function caso_d05_4_grupo_luccauto_aparece_na_lista_mesmo_abaixo_do_corte_marcado_incerto(): void
    {
        // Espelha o caso D-05 "GRUPO LUCCAUTO → LUCCAUTO.COM (61%)": desta
        // vez o palpite está CERTO (é a empresa certa), mas a pontuação fica
        // abaixo do patamar de `provavel` — não pode ser descartado por
        // corte, e o relatório precisa mostrá-lo como um palpite, não como
        // certeza.
        $empresaCorreta = Company::factory()->create([
            'name'         => 'Ferrabel.com',
            'razao_social' => null,
        ]);

        $resultado = $this->servico()->palpitar(
            cnpj: null,
            razaoSocial: null,
            nomeEnvelope: self::PREFIXO_ENVELOPE . 'GRUPO FERRABEL',
        );

        $this->assertSame($empresaCorreta->id, $resultado['company_id']);
        $this->assertSame('Ferrabel.com', $resultado['company_nome']);
        $this->assertSame('incerto', $resultado['confianca']);
        $this->assertLessThan(85.0, $resultado['pontuacao']);

        // Não foi descartado por corte — aparece na lista de candidatos.
        $this->assertNotEmpty($resultado['candidatos']);
        $this->assertSame($empresaCorreta->id, $resultado['candidatos'][0]['company_id']);
    }

    // ─── ambiguidade — dois candidatos vizinhos rebaixam para incerto ───

    #[Test]
    public function dois_candidatos_com_pontuacao_vizinha_ficam_incerto_e_marcam_ambiguo_mesmo_com_pontuacao_alta(): void
    {
        $primeiro  = Company::factory()->create(['name' => 'Comercial Arantine', 'razao_social' => null]);
        $segundo   = Company::factory()->create(['name' => 'Comercial Arantes Sul', 'razao_social' => null]);

        $resultado = $this->servico()->palpitar(
            cnpj: null,
            razaoSocial: null,
            nomeEnvelope: self::PREFIXO_ENVELOPE . 'COMERCIAL ARANTES',
        );

        $this->assertGreaterThanOrEqual(85.0, $resultado['pontuacao']);
        $this->assertTrue($resultado['ambiguo']);
        $this->assertSame('incerto', $resultado['confianca']);
        $this->assertCount(2, $resultado['candidatos']);
    }

    // ─── provavel — pontuação alta e candidato isolado ───

    #[Test]
    public function candidato_isolado_com_pontuacao_alta_e_distancia_grande_do_segundo_vira_provavel(): void
    {
        $empresa = Company::factory()->create(['name' => 'Comercial Trevisol', 'razao_social' => null]);
        Company::factory()->create(['name' => 'Outra Empresa Qualquer', 'razao_social' => null]);

        $resultado = $this->servico()->palpitar(
            cnpj: null,
            razaoSocial: null,
            nomeEnvelope: self::PREFIXO_ENVELOPE . 'COMERCIAL TREVISOL LTDA',
        );

        $this->assertSame($empresa->id, $resultado['company_id']);
        $this->assertSame('provavel', $resultado['confianca']);
        $this->assertFalse($resultado['ambiguo']);
        // `provavel` nunca é `certo` — mesmo com pontuação máxima por nome.
        $this->assertNotSame('certo', $resultado['confianca']);
    }

    // ─── sem candidato — nunca inventa empresa ───

    #[Test]
    public function sem_nenhuma_empresa_cadastrada_devolve_palpite_nulo_com_incerto(): void
    {
        $resultado = $this->servico()->palpitar(
            cnpj: null,
            razaoSocial: null,
            nomeEnvelope: self::PREFIXO_ENVELOPE . 'QUALQUER EMPRESA XPTO',
        );

        $this->assertNull($resultado['company_id']);
        $this->assertNull($resultado['company_nome']);
        $this->assertSame('incerto', $resultado['confianca']);
        $this->assertSame(0.0, $resultado['pontuacao']);
        $this->assertFalse($resultado['ambiguo']);
        $this->assertSame([], $resultado['candidatos']);
    }

    // ─── até 3 candidatos, nunca mais ───

    #[Test]
    public function devolve_no_maximo_3_candidatos_mesmo_com_5_empresas_parecidas(): void
    {
        Company::factory()->create(['name' => 'Solucoes Betafort', 'razao_social' => null]);
        Company::factory()->create(['name' => 'Solucoes Betafin', 'razao_social' => null]);
        Company::factory()->create(['name' => 'Solucoes Alfafort', 'razao_social' => null]);
        Company::factory()->create(['name' => 'Comercio Gamafort', 'razao_social' => null]);
        Company::factory()->create(['name' => 'Distribuidora Zeta Corp', 'razao_social' => null]);

        $resultado = $this->servico()->palpitar(
            cnpj: null,
            razaoSocial: null,
            nomeEnvelope: self::PREFIXO_ENVELOPE . 'SOLUCOES BETAFORT',
        );

        $this->assertCount(3, $resultado['candidatos']);
        $this->assertSame('Solucoes Betafort', $resultado['candidatos'][0]['nome']);

        // Ordem decrescente de pontuação.
        $this->assertGreaterThanOrEqual($resultado['candidatos'][1]['pontuacao'], $resultado['candidatos'][0]['pontuacao']);
        $this->assertGreaterThanOrEqual($resultado['candidatos'][2]['pontuacao'], $resultado['candidatos'][1]['pontuacao']);
    }
}
