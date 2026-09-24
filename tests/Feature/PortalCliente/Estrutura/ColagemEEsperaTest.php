<?php

namespace Tests\Feature\PortalCliente\Estrutura;

use App\Models\EstruturaAnuncio;
use App\Models\EstruturaAnuncioEspera;
use App\Services\Portal\Estrutura\ColagemAnunciosService;
use App\Services\Portal\Estrutura\EstruturaAnuncioService;
use App\Services\Portal\Estrutura\EstruturaConjunto;
use App\Services\Portal\Estrutura\EstruturaOfertaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * A colagem com reconciliação e a área de espera (ADR PORTAL-01).
 *
 * O modo de falha que isto existe para impedir: colar 200 anúncios, 30 SKUs
 * não baterem, e o painel passar a cobrar publicação do que já está no ar —
 * calado. Aqui o que não bate fica VISÍVEL na espera e sai de lá sozinho
 * quando uma ESCRITA faz o SKU casar.
 */
class ColagemEEsperaTest extends TestCase
{
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    private function colar($empresa, string $texto, $ator, string $modo = 'acrescentar'): array
    {
        return app(ColagemAnunciosService::class)->aplicar($empresa, $texto, $modo, $ator);
    }

    public function test_sku_que_nao_casa_vai_para_a_espera_e_nao_entra_no_painel(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $this->listaDoGabarito($empresa, $ator);

        $totais = $this->colar($empresa, "SKU\tTIPO\tCÓDIGO MLB\n"
            ."CAD-01\tClássico\tMLB1\n"
            ."CAD01\tPremium\tMLB2\n"          // não casa (sem hífen)
            ."\tPremium\tMLB3", $ator);        // veio sem SKU

        $this->assertSame(1, $totais['novos']);
        $this->assertSame(2, $totais['espera']);
        $this->assertEqualsCanonicalizing(['sem_oferta', 'sem_sku'], EstruturaAnuncioEspera::pluck('motivo')->all());
        $this->assertSame(1, EstruturaConjunto::daEmpresa($empresa)->painel()['publicados']);
    }

    /** "CAD-01 " e "cad-01" casam com CAD-01 — a planilha perdia o primeiro em silêncio. */
    public function test_casamento_ignora_caixa_e_espaco_nas_pontas(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $this->listaDoGabarito($empresa, $ator);

        $totais = $this->colar($empresa, "SKU\tTIPO\n  cad-01 \tClássico\nCAD-01-cb2\tPremium", $ator);

        $this->assertSame(2, $totais['novos']);
        $this->assertSame(0, EstruturaAnuncioEspera::count());
    }

    public function test_sku_repetido_em_duas_ofertas_vai_para_a_espera_com_esse_motivo(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $svc = app(EstruturaOfertaService::class);
        $svc->criar($empresa, ['sku' => 'Não tenho', 'fase' => 'simples'], $ator);
        $svc->criar($empresa, ['sku' => 'Não tenho', 'fase' => 'simples'], $ator);

        $this->colar($empresa, "SKU\tTIPO\nNão tenho\tClássico", $ator);

        $this->assertSame('sku_repetido', EstruturaAnuncioEspera::sole()->motivo);
        $this->assertSame(0, EstruturaAnuncio::count());
    }

    /** Colar de novo ATUALIZA — pelo MLB, e sem MLB por (oferta, tipo). Nada duplica. */
    public function test_colar_duas_vezes_atualiza_em_vez_de_duplicar(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $this->listaDoGabarito($empresa, $ator);

        $texto = "SKU\tTIPO\tCÓDIGO MLB\tSTATUS\nCAD-01\tClássico\tMLB1\tAtivo\nCAD-01\tPremium\t\tAtivo\nXPTO\tPremium\t\tAtivo";
        $this->colar($empresa, $texto, $ator);
        $segunda = $this->colar($empresa, str_replace('Ativo', 'Pausado', $texto), $ator);

        $this->assertSame(['novos' => 0, 'atualizados' => 2, 'espera' => 1, 'erros' => 0, 'removidos' => 0], $segunda);
        $this->assertSame(2, EstruturaAnuncio::count());
        $this->assertSame(['pausado'], EstruturaAnuncio::distinct()->pluck('status')->all());
        $this->assertSame(1, EstruturaAnuncioEspera::count());
        $this->assertSame('pausado', EstruturaAnuncioEspera::sole()->status);
    }

    /**
     * Substituir remove o que não veio na colagem — e é deliberado: o anúncio
     * que a agenda cadastrou e que está no ar vem com o mesmo MLB e é
     * ATUALIZADO; só some o que não está na colagem.
     */
    public function test_substituir_remove_so_o_que_nao_veio_e_a_previa_avisa_antes(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $ofertas = $this->listaDoGabarito($empresa, $ator);
        $this->anunciosDoGabarito($ofertas, $ator); // MLB …1, …2, …3, …4
        $this->colar($empresa, "SKU\tTIPO\nNINGUEM\tPremium", $ator); // uma linha na espera

        // A exportação "real" traz …1 e …4; …2, …3 e a espera não vieram.
        $texto = "SKU\tCÓDIGO MLB\tTIPO\tCATÁLOGO?\nCAD-01\tMLB0000000001\tClássico\tNão\nMSA-MR\tMLB0000000004\tPremium\tSim";

        $previa = app(ColagemAnunciosService::class)->previa($empresa, $texto, 'substituir');
        $this->assertSame(3, $previa['totais']['removidos']);
        $this->assertSame(4, EstruturaAnuncio::count(), 'a prévia não pode gravar');

        $this->colar($empresa, $texto, $ator, 'substituir');

        $this->assertEqualsCanonicalizing(['MLB0000000001', 'MLB0000000004'], EstruturaAnuncio::pluck('codigo_mlb')->all());
        $this->assertSame(0, EstruturaAnuncioEspera::count());
    }

    // ═══ A promoção automática roda na ESCRITA ══════════════════════════════

    public function test_criar_a_oferta_absorve_os_colados_com_aquele_sku(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $this->colar($empresa, "SKU\tTIPO\tCÓDIGO MLB\nPOL-9\tClássico\tMLB9\npol-9\tPremium\t", $ator);
        $this->assertSame(2, EstruturaAnuncioEspera::count());

        [$oferta, $absorvidos] = app(EstruturaOfertaService::class)->criar($empresa, ['sku' => 'POL-9', 'fase' => 'simples'], $ator);

        $this->assertSame(2, $absorvidos);
        $this->assertSame(0, EstruturaAnuncioEspera::count());
        $this->assertSame('ok', EstruturaConjunto::daEmpresa($empresa)->oferta($oferta->id)['situacao']);
    }

    public function test_renomear_o_sku_absorve_e_desfaz_o_repetido(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $svc = app(EstruturaOfertaService::class);
        [$a] = $svc->criar($empresa, ['sku' => 'DUP', 'fase' => 'simples'], $ator);
        [$b] = $svc->criar($empresa, ['sku' => 'DUP', 'fase' => 'simples'], $ator);
        $this->colar($empresa, "SKU\tTIPO\nDUP\tClássico", $ator);
        $this->assertSame('sku_repetido', EstruturaAnuncioEspera::sole()->motivo);

        // Deixou de ser repetido: o colado vai para a que ficou com "DUP".
        [, $absorvidos] = $svc->atualizar($b, ['sku' => 'DUP-2', 'fase' => 'simples'], $ator);

        $this->assertSame(1, $absorvidos);
        $this->assertSame($a->id, EstruturaAnuncio::sole()->oferta_id);
    }

    /**
     * Excluir a oferta devolve os anúncios dela à espera — como na planilha,
     * onde apagar a linha da Lista deixa a aba Anúncios intacta.
     */
    public function test_excluir_a_oferta_devolve_os_anuncios_para_a_espera(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $ofertas = $this->listaDoGabarito($empresa, $ator);
        $this->anunciosDoGabarito($ofertas, $ator);

        app(EstruturaOfertaService::class)->excluir($ofertas['CAD-01-CB2'], $ator);

        $linha = EstruturaAnuncioEspera::sole();
        $this->assertSame(['CAD-01-CB2', 'sem_oferta', 'MLB0000000003'], [$linha->sku_colado, $linha->motivo, $linha->codigo_mlb]);

        // Recriar a oferta traz o anúncio de volta.
        [, $absorvidos] = app(EstruturaOfertaService::class)->criar($empresa, ['sku' => 'CAD-01-CB2', 'fase' => 'combo',
            'componentes' => [['id' => $ofertas['CAD-01']->id, 'quantidade' => 2]]], $ator);
        $this->assertSame(1, $absorvidos);
    }

    /** Um GET não muta nada: abrir a tela com linhas "casáveis" na espera não as promove. */
    public function test_abrir_a_tela_nao_promove_nada(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $ofertas = $this->listaDoGabarito($empresa, $ator);
        // Linha casável posta direto na espera (como se a oferta tivesse
        // surgido por um caminho que esqueceu de varrer).
        EstruturaAnuncioEspera::create(['company_id' => $empresa->id, 'sku_colado' => 'CAD-01', 'motivo' => 'sem_oferta',
            'tipo' => 'classico', 'status' => 'ativo']);

        $this->withoutVite()->entrarNoPortal($empresa)->get(route('portal.auth.estrutura'))->assertOk();

        $this->assertSame(1, EstruturaAnuncioEspera::count());
        $this->assertSame(0, $ofertas['CAD-01']->anuncios()->count());
    }

    // ═══ Identidade do anúncio ══════════════════════════════════════════════

    public function test_mlb_e_unico_na_empresa_contando_a_espera(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $ofertas = $this->listaDoGabarito($empresa, $ator);
        $this->colar($empresa, "SKU\tTIPO\tCÓDIGO MLB\nNINGUEM\tPremium\tMLB77", $ator);

        $this->expectException(ValidationException::class);
        app(EstruturaAnuncioService::class)->cadastrar($ofertas['CAD-01'], ['tipo' => 'premium', 'codigo_mlb' => 'mlb-77'], $ator);
    }

    /**
     * O cliente leigo não sabe onde fica "o código do anúncio", mas sabe copiar
     * o endereço da página. O link de PRODUTO DE CATÁLOGO (`/p/MLB…`) é o id da
     * ficha, não do anúncio — recusado.
     */
    public function test_aceita_o_link_do_anuncio_e_recusa_o_da_ficha_de_catalogo(): void
    {
        $this->assertSame('MLB3456789012', EstruturaAnuncio::normalizarMlb('https://produto.mercadolivre.com.br/MLB-3456789012-cadeira-jantar-_JM'));
        $this->assertSame('MLB999', EstruturaAnuncio::normalizarMlb('articulo.mercadolivre.com.br/MLB999'));
        $this->assertSame('MLB1234567890', EstruturaAnuncio::normalizarMlb('mlb-1234567890'));
        $this->assertNull(EstruturaAnuncio::normalizarMlb('https://www.mercadolivre.com.br/cadeira/p/MLB19876543'));
        $this->assertNull(EstruturaAnuncio::normalizarMlb('https://www.google.com'));

        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $ofertas = $this->listaDoGabarito($empresa, $ator);

        $this->entrarNoPortal($empresa)
            ->post(route('portal.auth.estrutura.anuncios.criar', $ofertas['CAD-01-CB3']->id), [
                'tipo' => 'classico', 'via_agenda' => true,
                'codigo_mlb' => 'https://produto.mercadolivre.com.br/MLB-3456789012-cadeira-jantar-_JM',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('MLB3456789012', $ofertas['CAD-01-CB3']->anuncios()->sole()->codigo_mlb);
    }

    /**
     * O cliente cadastra a lista inteira e LOGO DEPOIS cola os anúncios — o
     * passo 1 e o passo 2 da aula, em sequência. Sem prefixo, todas as rotas
     * com `throttle` dividiam um contador por usuário/IP, e a colagem (10/min)
     * estourava contando as escritas de antes: 429 no meio do uso normal.
     */
    public function test_cadastrar_varios_produtos_e_colar_em_seguida_nao_da_429(): void
    {
        $empresa = $this->empresaDoGabarito();
        $sessao = $this->entrarNoPortal($empresa);

        foreach (range(1, 12) as $i) {
            $sessao->post(route('portal.auth.estrutura.ofertas.criar'), ['sku' => "PROD-{$i}", 'fase' => 'simples'])
                ->assertRedirect();
        }

        $sessao->postJson(route('portal.auth.estrutura.colagem.previa'), ['texto' => "SKU\tTIPO\nPROD-1\tClássico", 'modo' => 'acrescentar'])
            ->assertOk();
        $sessao->post(route('portal.auth.estrutura.colagem'), ['texto' => "SKU\tTIPO\nPROD-1\tClássico", 'modo' => 'acrescentar'])
            ->assertRedirect();

        $this->assertSame(1, EstruturaAnuncio::count());
    }

    public function test_mlb_igual_em_outra_empresa_nao_conflita(): void
    {
        $a = $this->empresaDoGabarito();
        $b = $this->empresaDoGabarito();
        $oa = $this->listaDoGabarito($a, $this->atorCliente($a));
        $ob = $this->listaDoGabarito($b, $this->atorCliente($b));
        $svc = app(EstruturaAnuncioService::class);

        $svc->cadastrar($oa['CAD-01'], ['tipo' => 'classico', 'codigo_mlb' => 'MLB5'], $this->atorCliente($a));
        $svc->cadastrar($ob['CAD-01'], ['tipo' => 'classico', 'codigo_mlb' => 'MLB5'], $this->atorCliente($b));

        $this->assertSame(2, EstruturaAnuncio::where('codigo_mlb', 'MLB5')->count());
    }

    /** No máximo UM anúncio sem MLB por (oferta, tipo): é o que torna o upsert determinístico. */
    public function test_segundo_anuncio_sem_mlb_do_mesmo_tipo_pede_o_mlb(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $ofertas = $this->listaDoGabarito($empresa, $ator);
        $svc = app(EstruturaAnuncioService::class);

        $svc->cadastrar($ofertas['CAD-01'], ['tipo' => 'classico'], $ator);

        try {
            $svc->cadastrar($ofertas['CAD-01'], ['tipo' => 'classico'], $ator);
            $this->fail('Era para pedir o MLB.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('codigo_mlb', $e->errors());
        }

        // Com MLB, o segundo entra (duplicado do mesmo tipo é permitido, só não soma).
        $svc->cadastrar($ofertas['CAD-01'], ['tipo' => 'classico', 'codigo_mlb' => 'MLB8'], $ator);
        $this->assertSame(2, $ofertas['CAD-01']->anuncios()->count());
    }

    public function test_vincular_da_espera_a_uma_oferta_escolhida(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $ofertas = $this->listaDoGabarito($empresa, $ator);
        $this->anunciosDoGabarito($ofertas, $ator); // CB2 já tem o Clássico; falta o Premium
        $this->colar($empresa, "SKU\tTIPO\tCÓDIGO MLB\nCADEIRA-UM\tPremium\tMLB40", $ator);
        $linha = EstruturaAnuncioEspera::sole();

        $this->entrarNoPortal($empresa)
            ->post(route('portal.auth.estrutura.espera.vincular', $linha->id), ['oferta_id' => $ofertas['CAD-01-CB2']->id])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, EstruturaAnuncioEspera::count());
        $this->assertSame('ok', EstruturaConjunto::daEmpresa($empresa)->oferta($ofertas['CAD-01-CB2']->id)['situacao']);
    }
}
