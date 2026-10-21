<?php

namespace Tests\Feature\PortalCliente\Estrutura;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\EstruturaPrecificacao;
use App\Models\MlToken;
use App\Services\Portal\Estrutura\EstruturaOfertaService;
use App\Services\Portal\Estrutura\EstruturaPrecificacaoService;
use App\Services\Portal\Estrutura\Produtos\FreteMe2Service;
use App\Services\Portal\Estrutura\Produtos\LogisticaProduto;
use App\Services\Portal\Estrutura\Produtos\ModalidadeDeEnvio;
use App\Services\Portal\Estrutura\Produtos\ProdutoCadastroService;
use App\Services\Portal\Estrutura\Produtos\TabelaFreteEcf;
use App\Services\Publicador\DadosEfetivosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * Frete SUGERIDO na Precificação (09/10/2026, revoga a D-19 da 167, ADR PORTAL-02): o tipo
 * sem frete digitado usa o frete do Mercado Envios do próprio tipo — cotação da conta em
 * cache ou tabela do ML — e o preço sai com ele. O digitado vence; ME1, logística declarada
 * fora do ME2 e oferta sem medidas não têm sugestão.
 *
 * Modos de falha que estes testes impedem: preço sem frete quando o ML cobra frete, o
 * Premium herdando o frete do Clássico com outra faixa de preço, sugestão vencendo o
 * digitado, carregar a página chamando o ML, e o Publicador com preço diferente da tela.
 */
class FreteSugeridoNaPrecificacaoTest extends TestCase
{
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /** Uma oferta ligada a produto, com o pacote e o custo dados. */
    private function ligada(Company $empresa, string $codigo, array $volume, ?string $custo): EstruturaOferta
    {
        app(ProdutoCadastroService::class)->gravarLinhas($empresa, [[
            'grupo' => 'G'.$codigo, 'codigo' => $codigo, 'nome' => 'Produto '.$codigo, 'valor' => null, 'eixo' => 'cor',
            'volumes' => [$volume], ...($custo !== null ? ['custo' => $custo] : []),
        ]], $this->atorCliente($empresa));

        return EstruturaOferta::where('company_id', $empresa->id)->where('sku', $codigo)->whereNotNull('variacao_id')->firstOrFail();
    }

    /** 15×15×20 com 500 g: fatura 750 g, a linha "de 0,5 a 1 kg" da tabela. */
    private const MEIO_QUILO = ['c' => 15, 'l' => 15, 'a' => 20, 'kg' => 0.5];

    private function pagina(Company $empresa, array $ids): array
    {
        return app(EstruturaPrecificacaoService::class)->pagina($empresa, $ids);
    }

    private function conectar(Company $empresa): void
    {
        MlToken::create([
            'company_id' => $empresa->id, 'ml_user_id' => '436501796',
            'access_token' => 'fake-access-token', 'refresh_token' => 'fake-refresh-token',
            'token_type' => 'bearer', 'scope' => 'read write offline_access',
            'expires_at' => now()->addDays(6), 'last_refreshed_at' => now(),
            'status' => 'active', 'connected_at' => now(),
        ]);
        $empresa->unsetRelation('mlToken');
    }

    /** O ML falso: a preferência da #459 e a cotação respondendo a própria tabela + `$extra`. */
    private function mlFalso(float $extra = 0.0): void
    {
        $preferencia = json_decode(file_get_contents(base_path('tests/fixtures-ml/sondagem/conta/shipping_preferences.json')), true)['resposta'];
        Http::fake(function (Request $r) use ($preferencia, $extra) {
            if (str_contains($r->url(), '/shipping_preferences')) {
                return Http::response($preferencia);
            }
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);
            [$medidas, $gramas] = explode(',', $q['dimensions']);
            [$a, $l, $c] = array_map('floatval', explode('x', $medidas));
            $faturado = LogisticaProduto::avaliar(['c' => $c, 'l' => $l, 'a' => $a, 'peso_real' => (float) $gramas / 1000])['peso_faturado'];

            return Http::response(['coverage' => ['all_country' => [
                'list_cost' => round(TabelaFreteEcf::valor($faturado, (float) $q['item_price']) + $extra, 2),
                'discount'  => ['type' => 'mandatory'],
            ]]]);
        });
    }

    private function cotacoes(): array
    {
        return array_values(array_filter(Http::recorded()->all(), fn ($par) => str_contains($par[0]->url(), '/shipping_options/free')));
    }

    /** Nada foi ao Mercado Livre (o layout do portal fala com outros serviços; esses não contam). */
    private function nadaFoiAoMl(): void
    {
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'mercadolibre.com'));
    }

    /** Requisições ao Mercado Livre até agora. */
    private function idasAoMl(): int
    {
        return Http::recorded()->filter(fn ($par) => str_contains($par[0]->url(), 'mercadolibre.com'))->count();
    }

    /**
     * Sem frete digitado, cada tipo sai com o frete da faixa do PRÓPRIO preço: custo 45 dá
     * Clássico R$ 76,91 (faixa até 78,99: 8,45) e Premium R$ 92,17 (a partir de 79: 14,45).
     * Antes da revisão, os dois saíam com frete zero e a pendência "sem frete".
     */
    public function test_sem_frete_digitado_o_preco_usa_o_sugerido_de_cada_tipo(): void
    {
        Http::fake();
        $empresa = $this->empresaDoGabarito();
        $o = $this->ligada($empresa, 'LIG-1', self::MEIO_QUILO, '45');

        $p = $this->pagina($empresa, [$o->id]);
        $r = $p['por_oferta'][$o->id];

        $this->assertSame('sugerido', $r['classico']['frete_origem']);
        $this->assertSame(8.45, $r['classico']['frete']);
        $this->assertSame('tabela', $r['classico']['frete_sugerido']['fonte']);
        $this->assertSame(76.91, $r['classico']['minimo']);
        $this->assertSame(92.29, $r['classico']['anunciado']);

        $this->assertSame('sugerido', $r['premium']['frete_origem']);
        $this->assertSame(14.45, $r['premium']['frete']);
        $this->assertTrue($r['premium']['frete_sugerido']['gratis_obrigatorio']);
        $this->assertSame(92.17, $r['premium']['minimo']);
        $this->assertSame(110.6, $r['premium']['anunciado']);

        $this->assertNull($r['pendencia']);
        $this->assertNull($r['frete_classico'], 'o campo mostra o que o cliente digitou: nada');
        $this->assertSame(['total' => 1, 'precificadas' => 1, 'sem_custo' => 0, 'sem_frete' => 0, 'impossivel' => 0], $p['resumo']);
        $this->nadaFoiAoMl();
    }

    public function test_frete_digitado_vence_o_sugerido_e_o_outro_tipo_segue_o_proprio(): void
    {
        $empresa = $this->empresaDoGabarito();
        $o = $this->ligada($empresa, 'LIG-2', self::MEIO_QUILO, '45');
        app(EstruturaPrecificacaoService::class)->salvarOferta($o, ['frete_classico' => '20'], $this->atorCliente($empresa));

        $r = $this->pagina($empresa, [$o->id])['por_oferta'][$o->id];

        $this->assertSame('digitado', $r['classico']['frete_origem']);
        $this->assertSame(20.0, $r['classico']['frete']);
        $this->assertSame(8.45, $r['classico']['frete_sugerido']['valor'], 'a sugestão continua visível ao lado');
        // O Premium NÃO herda os 20 do Clássico: tem sugestão própria, na faixa do preço dele.
        $this->assertSame('sugerido', $r['premium']['frete_origem']);
        $this->assertSame(14.45, $r['premium']['frete']);

        // Zero digitado é escolha do cliente e fica (o preço sai avisado, como sempre).
        app(EstruturaPrecificacaoService::class)->salvarOferta($o, ['frete_classico' => '20', 'frete_premium' => '0'], $this->atorCliente($empresa));
        $r = $this->pagina($empresa, [$o->id])['por_oferta'][$o->id];
        $this->assertSame('digitado', $r['premium']['frete_origem']);
        $this->assertSame(0.0, $r['premium']['frete']);
    }

    /** Combo, kit e combit usam o pacote SOMADO dos componentes (provisório, "por enquanto deixa somando"). */
    public function test_composta_usa_o_pacote_somado_dos_componentes(): void
    {
        $empresa = $this->empresaDoGabarito();
        $base = $this->ligada($empresa, 'LIG-3', self::MEIO_QUILO, '45');
        [$combo] = app(EstruturaOfertaService::class)->criar($empresa, [
            'sku' => 'LIG-3-CB2', 'fase' => 'combo', 'nome' => 'Combo 2', 'componentes' => [['id' => $base->id, 'quantidade' => 2]],
        ], $this->atorCliente($empresa));

        $r = $this->pagina($empresa, [$combo->id])['por_oferta'][$combo->id];

        // 2 × 15×15×20 de 500 g: 15×15×40 com 1 kg, que fatura 1,5 kg.
        $pacote = LogisticaProduto::pacote([self::MEIO_QUILO, self::MEIO_QUILO]);
        $esperado = app(FreteMe2Service::class)->estimar($empresa, ['x' => [
            'pacote' => $pacote, 'peso_faturado' => LogisticaProduto::avaliar($pacote)['peso_faturado'],
            'logistica' => LogisticaProduto::ME2, 'custo' => 90.0,
        ]])['x'];

        $this->assertSame(90.0, $r['custo']['valor']);
        $this->assertSame('sugerido', $r['classico']['frete_origem']);
        $this->assertSame($esperado['por_tipo']['classico']['valor'], $r['classico']['frete']);
        $this->assertSame($esperado['por_tipo']['premium']['valor'], $r['premium']['frete']);
        $this->assertNotSame(TabelaFreteEcf::valor(0.75, $r['classico']['minimo']), $r['classico']['frete'], 'não é o frete de UMA unidade');
        $this->assertNull($r['pendencia']);
    }

    /**
     * 11/10/2026 — o caso da #459: a poltrona (53×60×80, 8 kg) cabe no Mercado Envios e mostra o frete; o
     * Combo 2 dela soma as duas caixas (53×60×160, 16 kg), passa do limite dos Correios e vira ME1. A tela
     * ficava com o frete em branco e nada dizia — "nem sei se é ME1". Agora cada oferta leva o `envio`: o
     * que ela é, o pacote (somado, no conjunto) e cada limite que estourou.
     */
    public function test_cada_oferta_diz_o_envio_o_pacote_e_por_que_ficou_fora_do_mercado_envios(): void
    {
        $empresa = $this->empresaDoGabarito();
        $unidade = $this->ligada($empresa, 'NINA', ['c' => 53, 'l' => 60, 'a' => 80, 'kg' => 8], '100');
        [$combo] = app(EstruturaOfertaService::class)->criar($empresa, [
            'sku' => 'NINA-CB2', 'fase' => 'combo', 'nome' => 'Combo 2', 'componentes' => [['id' => $unidade->id, 'quantidade' => 2]],
        ], $this->atorCliente($empresa));
        $declarada = $this->ligada($empresa, 'DECL', self::MEIO_QUILO, '45');
        $declarada->update(['logistica' => 'combinar']);
        // Oferta sem produto (a Lista SKUs antiga): não há pacote nem logística a calcular.
        [$solta] = app(EstruturaOfertaService::class)->criar($empresa, ['sku' => 'SOLTA', 'fase' => 'simples', 'nome' => 'Solta'], $this->atorCliente($empresa));

        $p = $this->pagina($empresa, [$unidade->id, $combo->id, $declarada->id, $solta->id])['por_oferta'];

        // A unidade: dentro do Mercado Envios, com o pacote dela e nada estourado.
        $this->assertContains($p[$unidade->id]['envio']['chave'], [LogisticaProduto::ME2, LogisticaProduto::ME2_FULL]);
        $this->assertSame(['c' => 53.0, 'l' => 60.0, 'a' => 80.0, 'peso' => 8.0], $p[$unidade->id]['envio']['pacote']);
        $this->assertFalse($p[$unidade->id]['envio']['somado']);
        $this->assertSame([], $p[$unidade->id]['envio']['estouros']);
        $this->assertNotNull($p[$unidade->id]['classico']['frete_sugerido']);

        // O combo: as duas caixas somadas, fora do Mercado Envios, com os dois limites que passou.
        $envio = $p[$combo->id]['envio'];
        $this->assertSame(LogisticaProduto::ME1, $envio['chave']);
        $this->assertSame(['c' => 53.0, 'l' => 60.0, 'a' => 160.0, 'peso' => 16.0], $envio['pacote']);
        $this->assertTrue($envio['somado']);
        $this->assertSame(2, $envio['volumes']);
        $this->assertSame([
            ['medida' => 'maior', 'valor' => 160.0, 'limite' => 100.0],
            ['medida' => 'soma', 'valor' => 273.0, 'limite' => 200.0],
        ], $envio['estouros'], 'o peso (16 kg) cabe: não entra');
        $this->assertNull($p[$combo->id]['classico']['frete_sugerido'], 'ME1 não tem frete do Mercado Livre a sugerir');

        // A logística declarada fora do Mercado Envios também se explica; a oferta sem produto não tem o que dizer.
        $this->assertSame('combinar', $p[$declarada->id]['envio']['declarada']);
        $this->assertNull($p[$unidade->id]['envio']['declarada']);
        $this->assertNull($p[$solta->id]['envio']['chave']);
        $this->assertNull($p[$solta->id]['envio']['pacote']);
    }

    /**
     * 21/10/2026 — fora do Mercado Envios o Mercado Livre não cobra frete, e o destino depende da conta: com ME1,
     * a transportadora do vendedor; sem ME1, "Combine a entrega" (a Poltrona Opala da #459). A conta é lida pelo
     * "Cotar agora" e fica no cache; antes disso (ou com a leitura de antes desta data), não se sabe.
     */
    public function test_o_que_nao_cabe_diz_se_vai_pela_transportadora_ou_a_combinar_pela_conta(): void
    {
        $empresa = $this->empresaDoGabarito();
        $grande = $this->ligada($empresa, 'OPALA', ['c' => 70, 'l' => 85, 'a' => 90, 'kg' => 12], '400');
        $pequena = $this->ligada($empresa, 'BANQ', self::MEIO_QUILO, '45');
        $envio = fn () => $this->pagina($empresa, [$grande->id, $pequena->id])['por_oferta'];

        $this->assertSame(ModalidadeDeEnvio::FORA_SEM_SABER, $envio()[$grande->id]['envio']['fora'], 'conta não lida');
        Cache::put(ModalidadeDeEnvio::chave((int) $empresa->id), ['tipo' => 'drop_off', 'lido_em' => now()->toIso8601String()], now()->addDay());
        $this->assertSame(ModalidadeDeEnvio::FORA_SEM_SABER, $envio()[$grande->id]['envio']['fora'], 'leitura de antes de 21/10');

        ModalidadeDeEnvio::guardar((int) $empresa->id, 'drop_off', null, false);
        $p = $envio();
        $this->assertSame(ModalidadeDeEnvio::FORA_COMBINAR, $p[$grande->id]['envio']['fora'], 'a #459: Correios e sem ME1');
        $this->assertNull($p[$pequena->id]['envio']['fora'], 'o que cabe não tem destino "fora"');

        ModalidadeDeEnvio::guardar((int) $empresa->id, 'drop_off', null, true);
        $this->assertSame(ModalidadeDeEnvio::FORA_ME1, $envio()[$grande->id]['envio']['fora']);

        // Na Coleta (soma até 300 cm) a mesma poltrona cabe: vira Mercado Envios, com frete do Mercado Livre.
        ModalidadeDeEnvio::guardar((int) $empresa->id, 'cross_docking', null, true);
        $p = $envio();
        $this->assertContains($p[$grande->id]['envio']['chave'], [LogisticaProduto::ME2, LogisticaProduto::ME2_FULL]);
        $this->assertNull($p[$grande->id]['envio']['fora']);
    }

    /** A preferência de envio da conta diz se ela tem ME1 — corpos medidos em produção em 21/10/2026. */
    public function test_a_preferencia_da_conta_diz_se_ela_tem_me1(): void
    {
        $da459 = json_decode(file_get_contents(base_path('tests/fixtures-ml/sondagem/conta/shipping_preferences.json')), true)['resposta'];
        $this->assertFalse(ModalidadeDeEnvio::temMe1($da459), 'a #459: custom, not_specified e me2');

        // A LOJA SHEEP (#165): me1 em `modes` e o bloco `logistics[mode=me1]`.
        $comMe1 = ['modes' => ['custom', 'not_specified', 'me2', 'me1'], 'logistics' => [
            ['mode' => 'me1', 'types' => [['type' => 'default', 'default' => true, 'status' => 'active']]],
            ['mode' => 'me2', 'types' => [['type' => 'cross_docking', 'default' => true, 'status' => 'active'], ['type' => 'fulfillment', 'default' => false, 'status' => 'active']]],
        ]];
        $this->assertTrue(ModalidadeDeEnvio::temMe1($comMe1));
        $this->assertSame('cross_docking', ModalidadeDeEnvio::daPreferencia($comMe1));
        $this->assertTrue(ModalidadeDeEnvio::temMe1(['modes' => ['me2'], 'logistics' => [['mode' => 'me1', 'types' => [['type' => 'default', 'status' => 'active']]]]]));
        $this->assertFalse(ModalidadeDeEnvio::temMe1(['modes' => ['me2'], 'logistics' => [['mode' => 'me1', 'types' => [['type' => 'default', 'status' => 'inactive']]]]]));
        $this->assertFalse(ModalidadeDeEnvio::temMe1([]));
    }

    public function test_os_limites_do_envio_seguem_a_modalidade_da_conta(): void
    {
        // Pacote do Combo 2 da #459: fora dos Correios (100 / 200) e dentro das Agências (200 / 300).
        $pacote = ['c' => 53.0, 'l' => 60.0, 'a' => 160.0, 'peso_real' => 16.0];

        $correios = LogisticaProduto::avaliar($pacote, null, 'drop_off');
        $this->assertSame(['maior', 'soma'], array_column(LogisticaProduto::estouros($correios, 'drop_off'), 'medida'));

        $agencias = LogisticaProduto::avaliar($pacote, null, 'xd_drop_off');
        $this->assertSame(LogisticaProduto::ME2, $agencias['logistica']);
        $this->assertSame([], LogisticaProduto::estouros($agencias, 'xd_drop_off'));

        // Só o peso passa: 40 kg numa caixa pequena.
        $pesado = LogisticaProduto::avaliar(['c' => 30.0, 'l' => 30.0, 'a' => 30.0, 'peso_real' => 40.0], null, 'drop_off');
        $this->assertSame([['medida' => 'peso', 'valor' => 40.0, 'limite' => 30.0]], LogisticaProduto::estouros($pesado, 'drop_off'));
    }

    public function test_me1_e_logistica_declarada_fora_do_me2_nao_tem_sugestao(): void
    {
        $empresa = $this->empresaDoGabarito();
        $me1 = $this->ligada($empresa, 'LIG-4', ['c' => 250, 'l' => 44, 'a' => 12, 'kg' => 60], '300');
        $declarada = $this->ligada($empresa, 'LIG-5', self::MEIO_QUILO, '45');
        $declarada->update(['logistica' => 'transportadora_me1']);

        $p = $this->pagina($empresa, [$me1->id, $declarada->id]);

        foreach ([$me1->id, $declarada->id] as $id) {
            $r = $p['por_oferta'][$id];
            $this->assertNull($r['classico']['frete_sugerido']);
            $this->assertNull($r['classico']['frete']);
            $this->assertSame('sem_frete', $r['pendencia'], '"Sem frete" continua pendência');
        }
    }

    /** Oferta sem produto (a Lista SKUs antiga, sem medidas) continua com o frete do outro tipo (PUFF-AZ, 30/09). */
    public function test_oferta_sem_medidas_mantem_o_frete_do_outro_tipo(): void
    {
        $empresa = $this->empresaDoGabarito();
        [$avulsa] = app(EstruturaOfertaService::class)->criar($empresa, ['sku' => 'AVULSA', 'fase' => 'simples', 'nome' => 'Avulsa'], $this->atorCliente($empresa));
        EstruturaPrecificacao::create(['oferta_id' => $avulsa->id, 'custo' => 44, 'frete_classico' => 32]);

        $r = $this->pagina($empresa, [$avulsa->id])['por_oferta'][$avulsa->id];

        $this->assertSame('outro_tipo', $r['premium']['frete_origem']);
        $this->assertSame(32.0, $r['premium']['frete']);
        $this->assertNull($r['premium']['frete_sugerido']);
        $this->assertGreaterThan($r['classico']['anunciado'], $r['premium']['anunciado']);
    }

    /** O resumo é da empresa inteira: a oferta fora da página também conta como precificada pela sugestão. */
    public function test_resumo_da_empresa_inteira_conta_a_sugestao_fora_da_pagina(): void
    {
        $empresa = $this->empresaDoGabarito();
        $a = $this->ligada($empresa, 'LIG-6', self::MEIO_QUILO, '45');
        $this->ligada($empresa, 'LIG-7', self::MEIO_QUILO, '60');

        $p = $this->pagina($empresa, [$a->id]);

        $this->assertCount(1, $p['por_oferta']);
        $this->assertSame(2, $p['resumo']['precificadas']);
        $this->assertSame(0, $p['resumo']['sem_frete']);
    }

    /** Uma só verdade: o Publicador herda o preço da tela, com o frete sugerido dentro. */
    public function test_publicador_herda_o_preco_com_o_frete_sugerido(): void
    {
        $empresa = $this->empresaDoGabarito();
        $o = $this->ligada($empresa, 'LIG-8', self::MEIO_QUILO, '45');

        $tela = $this->pagina($empresa, [$o->id])['por_oferta'][$o->id];
        $efetivos = app(DadosEfetivosService::class)->daOferta($o->fresh());

        $this->assertSame($tela['classico']['anunciado'], $efetivos['precos']['gold_special']);
        $this->assertSame($tela['premium']['anunciado'], $efetivos['precos']['gold_pro']);
    }

    /**
     * 11/10/2026 — a primeira das três conferências de frete: o frete que ENTROU no preço chega ao Publicador
     * com o valor, a origem em palavras e a oferta onde ele mora (é dela que sai o "levar para a Precificação").
     */
    public function test_publicador_recebe_o_frete_que_entrou_no_preco_com_a_origem(): void
    {
        $empresa = $this->empresaDoGabarito();
        $o = $this->ligada($empresa, 'LIG-FRT', self::MEIO_QUILO, '45');
        $produto = \App\Models\PubProduto::create(['company_id' => $empresa->id, 'oferta_id' => $o->id, 'sku' => 'LIG-FRT', 'nome' => 'Produto', 'origem' => 'portal']);
        $efetivos = app(DadosEfetivosService::class);

        // Sem nada digitado: vale o sugerido pela tabela, o mesmo número que a tela mostra.
        $tela = $this->pagina($empresa, [$o->id])['por_oferta'][$o->id];
        $fretes = $efetivos->fretesDoProduto($produto->fresh());
        $this->assertSame(['valor' => (float) $tela['classico']['frete'], 'origem' => 'tabela', 'oferta_id' => $o->id], $fretes['por_tipo']['gold_special']);
        $this->assertSame('tabela', $fretes['por_tipo']['gold_pro']['origem']);
        $this->assertSame([], $fretes['por_variante'], 'produto não agrupado não tem frete por cor');

        // Digitado no Clássico: vence o sugerido.
        app(EstruturaPrecificacaoService::class)->salvarFrete($o, ['classico' => 33.3], $this->atorCliente($empresa));
        $fretes = $efetivos->fretesDoProduto($produto->fresh());
        $this->assertSame(['valor' => 33.3, 'origem' => 'digitado', 'oferta_id' => $o->id], $fretes['por_tipo']['gold_special']);
        $this->assertNotSame('digitado', $fretes['por_tipo']['gold_pro']['origem'], 'salvarFrete mexe só no tipo que recebeu');

        // Produto sem oferta no Portal: não há frete da Precificação.
        $solto = \App\Models\PubProduto::create(['company_id' => $empresa->id, 'sku' => 'SOLTO', 'nome' => 'Solto', 'origem' => 'publicador']);
        $this->assertNull($efetivos->fretesDoProduto($solto)['por_tipo']['gold_special']['valor']);
    }

    // ═══ A tela e o "Cotar agora" ═══

    public function test_carregar_a_pagina_conectado_nao_chama_o_ml(): void
    {
        Http::fake();
        $empresa = $this->empresaDoGabarito();
        $this->conectar($empresa);
        $o = $this->ligada($empresa, 'LIG-9', self::MEIO_QUILO, '45');

        $props = $this->withoutVite()->entrarNoPortal($empresa)
            ->get(route('portal.auth.estrutura.precificacao'))
            ->assertOk()
            ->viewData('page')['props'];

        $this->nadaFoiAoMl();
        $this->assertTrue($props['ml_conectado']);
        $this->assertSame('2026-08-24', $props['frete_tabela']['vigente_desde']);
        $this->assertNull($props['cotacao']);
        $this->assertSame('tabela', $props['precificacao']['por_oferta'][$o->id]['classico']['frete_sugerido']['fonte']);
    }

    public function test_cotar_agora_cota_na_conta_e_a_pagina_passa_a_mostrar_sugerido_pela_conta(): void
    {
        $empresa = $this->empresaDoGabarito();
        $this->conectar($empresa);
        $this->mlFalso(extra: 1.0);
        $o = $this->ligada($empresa, 'LIG-10', self::MEIO_QUILO, '45');
        $sessao = $this->withoutVite()->entrarNoPortal($empresa);

        $props = $sessao->get(route('portal.auth.estrutura.precificacao', ['cotar' => 1]))->assertOk()->viewData('page')['props'];

        $this->assertSame(['conectado' => true, 'total' => 2, 'cotados' => 2, 'pendentes' => 0, 'falhou' => false], $props['cotacao']);
        $classico = $props['precificacao']['por_oferta'][$o->id]['classico'];
        $this->assertSame('conta', $classico['frete_sugerido']['fonte']);
        $this->assertSame(9.45, $classico['frete'], 'o valor da conta (8,45 + 1), não o da tabela');
        Http::assertNotSent(fn (Request $q) => $q->method() !== 'GET');

        // Depois, a página sem `cotar` lê do cache — sem nova requisição.
        $antes = $this->idasAoMl();
        $props = $sessao->get(route('portal.auth.estrutura.precificacao'))->viewData('page')['props'];
        $this->assertSame('conta', $props['precificacao']['por_oferta'][$o->id]['premium']['frete_sugerido']['fonte']);
        $this->assertSame($antes, $this->idasAoMl());
    }

    public function test_cotar_agora_nao_gasta_cotacao_com_o_tipo_que_tem_frete_digitado(): void
    {
        $empresa = $this->empresaDoGabarito();
        $this->conectar($empresa);
        $this->mlFalso();
        $o = $this->ligada($empresa, 'LIG-11', self::MEIO_QUILO, '45');
        app(EstruturaPrecificacaoService::class)->salvarOferta($o, ['frete_classico' => '20'], $this->atorCliente($empresa));

        $r = app(EstruturaPrecificacaoService::class)->cotarFretes($empresa, [$o->id]);

        $this->assertSame(1, $r['total']);
        $this->assertSame(1, $r['cotados']);
        $tipos = array_map(function ($par) {
            parse_str((string) parse_url($par[0]->url(), PHP_URL_QUERY), $q);

            return $q['listing_type_id'];
        }, $this->cotacoes());
        $this->assertSame(['gold_pro'], array_values(array_unique($tipos)));
    }

    public function test_cotar_agora_sem_conta_nao_faz_requisicao(): void
    {
        Http::fake();
        $empresa = $this->empresaDoGabarito();
        $this->ligada($empresa, 'LIG-12', self::MEIO_QUILO, '45');

        $props = $this->withoutVite()->entrarNoPortal($empresa)
            ->get(route('portal.auth.estrutura.precificacao', ['cotar' => 1]))->viewData('page')['props'];

        $this->assertFalse($props['cotacao']['conectado']);
        $this->nadaFoiAoMl();
    }

    public function test_cotar_agora_tem_teto_por_minuto_por_empresa(): void
    {
        Http::fake();
        $empresa = $this->empresaDoGabarito();
        $this->conectar($empresa);
        $chave = "estrutura.precificacao.cotar:{$empresa->id}";
        RateLimiter::clear($chave);
        for ($i = 0; $i < 20; $i++) {
            RateLimiter::hit($chave, 60);
        }

        $props = $this->withoutVite()->entrarNoPortal($empresa)
            ->get(route('portal.auth.estrutura.precificacao', ['cotar' => 1]))->viewData('page')['props'];

        $this->assertSame(['limitado' => true], $props['cotacao']);
        $this->nadaFoiAoMl();
    }
}
