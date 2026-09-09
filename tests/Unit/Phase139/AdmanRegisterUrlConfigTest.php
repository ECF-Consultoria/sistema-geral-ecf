<?php

namespace Tests\Unit\Phase139;

use Tests\TestCase;

/**
 * Fase 139 (plano 01, ADMIN-03, D-04) — prova que `services.adman.register_url`
 * devolve o link FIXO de cadastro/indicação do Adman, idêntico para todas as
 * empresas, e que a URL não carrega nenhum placeholder por empresa. É a prova
 * automatizada de que o item 6 do checklist ("Link Adman entregue") não tem
 * estado observável para auto-marcar — por isso é manual (D-13).
 */
class AdmanRegisterUrlConfigTest extends TestCase
{
    public function test_register_url_default_e_o_link_fixo_do_adman(): void
    {
        // Sem ADMAN_REGISTER_URL definida no ambiente de teste, o default
        // hardcoded em config/services.php deve valer.
        $this->assertSame(
            'https://app.ad-man.io/register?ref=588D0DD78C4F',
            config('services.adman.register_url'),
        );
    }

    public function test_register_url_nao_contem_placeholder_por_empresa(): void
    {
        $url = config('services.adman.register_url');

        // D-04: é link fixo, não gerado por empresa. Nenhuma das formas usuais
        // de placeholder de rota/company pode aparecer aqui.
        $this->assertStringNotContainsString('{company}', $url);
        $this->assertStringNotContainsString('company_id', $url);
        $this->assertStringNotContainsString(':id', $url);
    }
}
