<?php

namespace Tests\Unit\Phase152;

use App\Services\ChecklistAdministrativo\ChecklistAdministrativoDefinicao;
use Tests\TestCase;

/**
 * Fase 152 (plano 03) — prova D-01 (9 itens com contrato), D-07 (6 sem
 * contrato, grupo Contrato inexistente) e D-03 (natureza por item).
 *
 * Catálogo puro em código — sem RefreshDatabase, não toca banco.
 */
class ChecklistDefinicaoCatalogoTest extends TestCase
{
    public function test_itens_com_contrato_sao_exatamente_9_e_sem_contrato_sao_exatamente_6(): void
    {
        $this->assertCount(9, ChecklistAdministrativoDefinicao::itens(true));
        $this->assertCount(6, ChecklistAdministrativoDefinicao::itens(false));
    }

    public function test_itens_sem_contrato_nao_contem_grupo_contrato(): void
    {
        $itens = ChecklistAdministrativoDefinicao::itens(false);

        foreach ($itens as $item) {
            $this->assertNotSame(
                ChecklistAdministrativoDefinicao::GRUPO_CONTRATO,
                $item['grupo'],
                'D-07: grupo Contrato não deve existir para serviço isento — item não deveria ter sido instanciado.'
            );
        }
    }

    public function test_chaves_com_contrato_sao_exatamente_as_9_chaves_do_d03_na_ordem(): void
    {
        $this->assertSame([
            'contrato_revisado',
            'contrato_enviado',
            'contrato_assinado',
            // Decisão do usuário (2026-09-10): boas-vindas vem logo após o
            // contrato, não no fim — é a mensagem que abre a relação.
            'boas_vindas_enviada',
            'grupo_whatsapp_criado',
            'email_colaborador_criado',
            'link_adman_entregue',
            'grant_consultoria_ml',
            'conexao_ecf_gerada',
        ], ChecklistAdministrativoDefinicao::chaves(true));
    }

    public function test_natureza_e_auto_fonte_batem_com_a_tabela_d03(): void
    {
        $auto = [
            'contrato_enviado',
            'contrato_assinado',
            'grant_consultoria_ml',
            'conexao_ecf_gerada',
        ];

        $manual = [
            'contrato_revisado',
            'boas_vindas_enviada',
            'grupo_whatsapp_criado',
            'email_colaborador_criado',
            'link_adman_entregue',
        ];

        foreach ($auto as $chave) {
            $item = ChecklistAdministrativoDefinicao::item($chave);

            $this->assertNotNull($item, "item '{$chave}' deveria existir no catálogo.");
            $this->assertSame(ChecklistAdministrativoDefinicao::NATUREZA_AUTO, $item['natureza']);
            $this->assertNotNull($item['auto_fonte'], "item auto '{$chave}' precisa declarar auto_fonte.");
        }

        foreach ($manual as $chave) {
            $item = ChecklistAdministrativoDefinicao::item($chave);

            $this->assertNotNull($item, "item '{$chave}' deveria existir no catálogo.");
            $this->assertSame(ChecklistAdministrativoDefinicao::NATUREZA_MANUAL, $item['natureza']);
            $this->assertNull($item['auto_fonte'], "item manual '{$chave}' não deve declarar auto_fonte.");
        }
    }

    public function test_item_com_chave_desconhecida_devolve_null_sem_lancar_excecao(): void
    {
        $this->assertNull(ChecklistAdministrativoDefinicao::item('chave_que_nao_existe'));
    }

    public function test_nenhum_item_tem_estado_nao_aplicavel_em_lugar_nenhum(): void
    {
        foreach (ChecklistAdministrativoDefinicao::itens(true) as $item) {
            // D-02: o estado "não aplicável" do motor de Onboarding foi
            // deliberadamente rejeitado — não pode sobreviver nem como chave
            // nem como valor de nenhum campo do item.
            $this->assertArrayNotHasKey('nao_aplicavel', $item);
            $this->assertFalse(
                in_array('nao_aplicavel', $item, true),
                "item '{$item['chave']}' não pode carregar o estado não aplicável em nenhum campo."
            );
        }
    }

    public function test_item_6_cita_adman_e_nao_a_grafia_antiga_isolada(): void
    {
        $item = ChecklistAdministrativoDefinicao::item('link_adman_entregue');

        $this->assertNotNull($item);
        $this->assertStringContainsString('Adman', $item['titulo']);
        $this->assertDoesNotMatchRegularExpression('/\bADMA\b/', $item['titulo']);
    }
}
