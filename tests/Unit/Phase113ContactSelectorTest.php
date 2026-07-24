<?php

namespace Tests\Unit;

use App\Services\Hubspot\HubspotContactSelector;
use PHPUnit\Framework\TestCase;

/**
 * Fase 113, Plano 01 — cobre a regra determinística de escolha do contato
 * principal (HUB-CONTATO-01). Unidade pura, sem DB/HTTP — extends TestCase
 * puro (não RefreshDatabase) de propósito.
 */
class Phase113ContactSelectorTest extends TestCase
{
    public function test_lista_vazia_retorna_null(): void
    {
        $this->assertNull(HubspotContactSelector::selecionar([]));
    }

    public function test_unico_contato_retorna_esse_contato_independente_de_ter_email_ou_telefone(): void
    {
        $contato = ['id' => 1, 'firstname' => 'Zé'];

        $this->assertSame($contato, HubspotContactSelector::selecionar([$contato]));
    }

    public function test_entre_tres_contatos_vence_o_que_tem_email_e_telefone(): void
    {
        $soEmail       = ['id' => 1, 'email' => 'a@ex.com'];
        $soTelefone    = ['id' => 2, 'phone' => '11999999999'];
        $emailETelefone = ['id' => 3, 'email' => 'c@ex.com', 'phone' => '11988888888'];

        $vencedor = HubspotContactSelector::selecionar([$soEmail, $soTelefone, $emailETelefone]);

        $this->assertSame(3, $vencedor['id']);
    }

    public function test_mobilephone_conta_como_telefone_email_mais_mobilephone_vence_so_email(): void
    {
        $soEmail             = ['id' => 1, 'email' => 'a@ex.com'];
        $emailEMobilephone   = ['id' => 2, 'email' => 'b@ex.com', 'mobilephone' => '11977777777'];

        $vencedor = HubspotContactSelector::selecionar([$soEmail, $emailEMobilephone]);

        $this->assertSame(2, $vencedor['id']);
    }

    public function test_so_emails_disponiveis_empate_resolvido_pelo_menor_id(): void
    {
        $emailId5 = ['id' => 5, 'email' => 'a@ex.com'];
        $emailId2 = ['id' => 2, 'email' => 'b@ex.com'];
        $emailId9 = ['id' => 9, 'email' => 'c@ex.com'];

        $vencedor = HubspotContactSelector::selecionar([$emailId5, $emailId2, $emailId9]);

        $this->assertSame(2, $vencedor['id']);
    }

    public function test_nenhum_contato_tem_email_nem_telefone_escolhe_o_de_menor_id(): void
    {
        $id7 = ['id' => 7];
        $id3 = ['id' => 3];
        $id10 = ['id' => 10];

        $vencedor = HubspotContactSelector::selecionar([$id7, $id3, $id10]);

        $this->assertSame(3, $vencedor['id']);
    }

    public function test_empate_por_id_string_numerico_usa_comparacao_numerica(): void
    {
        // '10' é MAIOR que '2' numericamente, mas menor como string —
        // a regra exige comparação numérica quando ambos são numéricos.
        $id10 = ['id' => '10'];
        $id2  = ['id' => '2'];

        $vencedor = HubspotContactSelector::selecionar([$id10, $id2]);

        $this->assertSame('2', $vencedor['id']);
    }

    public function test_determinismo_mesma_lista_embaralhada_retorna_o_mesmo_id(): void
    {
        $contatos = [
            ['id' => 4, 'email' => 'd@ex.com'],
            ['id' => 1, 'email' => 'a@ex.com', 'phone' => '11911111111'],
            ['id' => 8, 'phone' => '11922222222'],
        ];

        $primeiro = HubspotContactSelector::selecionar($contatos);

        $embaralhado = $contatos;
        shuffle($embaralhado);
        $segundo = HubspotContactSelector::selecionar($embaralhado);

        $this->assertSame($primeiro['id'], $segundo['id']);
        $this->assertSame(1, $primeiro['id']);
    }

    public function test_campos_vazios_apos_trim_nao_contam_como_email_ou_telefone(): void
    {
        $emailEmBranco = ['id' => 1, 'email' => '   ', 'phone' => ''];
        $emailReal     = ['id' => 2, 'email' => 'real@ex.com'];

        $vencedor = HubspotContactSelector::selecionar([$emailEmBranco, $emailReal]);

        $this->assertSame(2, $vencedor['id']);
    }
}
