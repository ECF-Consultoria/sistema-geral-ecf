<?php

namespace Tests\Unit\Publicador\Validacao;

use App\Support\Publicador\Imagem\ResolvedorGruposImagem as R;
use App\Support\Publicador\Payload\Alvo;
use App\Support\Publicador\Payload\MontadorDePlano;
use App\Support\Publicador\RascunhoSnapshot;
use App\Support\Publicador\Schema\ClassificadorAtributos;
use App\Support\Publicador\Schema\ContextoClassificacao;
use App\Support\Publicador\Validacao\ContextoValidacao;
use App\Support\Publicador\Validacao\Problema;
use App\Support\Publicador\Validacao\ValidadorRascunho;
use App\Support\Publicador\Variacao\ChaveCanonica;
use App\Support\Publicador\Variacao\Eixo;
use App\Support\Publicador\Variacao\GeradorCombinacoes;
use App\Support\Publicador\Variacao\ValorEixo;
use App\Support\Publicador\Variacao\Variante;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Publicador\Concerns\CarregaSchemas;

/**
 * `08` — validação L1 (campo) + L2 (rascunho com o schema), sem tocar o ML.
 * O ponto de partida é um rascunho COMPLETO da cadeira do print, que passa sem
 * nenhum bloqueio; cada teste estraga uma coisa só e confere a regra que acusa.
 */
class ValidadorRascunhoTest extends TestCase
{
    use CarregaSchemas;

    private const IMAGEM_BOA = ['mime' => 'image/jpeg', 'bytes' => 800_000, 'largura' => 1200, 'altura' => 1200, 'upload_status' => 'uploaded'];

    private static function completo(array $mudar = []): RascunhoSnapshot
    {
        return new RascunhoSnapshot(...[
            'categoriaId' => self::CADEIRA,
            'atributos' => [
                'BRAND' => ['value_name' => 'ECF'],
                'MODEL' => ['value_name' => 'Executiva'],
                'BACKREST_HEIGHT' => ['value_name' => '50 cm'],
                'SEAT_DEPTH' => ['value_name' => '45 cm'],
                'OFFICE_CHAIR_WIDTH' => ['value_name' => '60 cm'],
                'MAX_CHAIR_HEIGHT' => ['value_name' => '110 cm'],
                'REQUIRES_ASSEMBLY' => ['value_id' => '242085'],
                'IS_GAMER' => ['value_id' => '242084'],
                'IS_ERGONOMIC' => ['value_id' => '242085'],
                'IS_SWIVEL' => ['value_id' => '242085'],
                'INCLUDES_ASSEMBLY_MANUAL' => ['value_id' => '242085'],
            ],
            'variantes' => [new Variante(ChaveCanonica::UNICA, [], dados: [
                'estoque' => 3,
                'precos' => ['gold_special' => 150.0, 'gold_pro' => 165.0],
                'atributos' => ['SELLER_SKU' => ['value_name' => 'CAD'], 'GTIN' => ['value_name' => '7896553367645']],
            ])],
            'alvos' => [new Alvo('gold_special', 'Cadeira Escritório Executiva ECF Giratória'), new Alvo('gold_pro', 'Cadeira de Escritório ECF Executiva com Giro')],
            'imagens' => [['imagem' => 'a1', 'grupo' => R::GERAL, 'posicao' => 0]],
            'descricao' => 'Cadeira executiva giratória.',
            'envio' => ['modo' => 'me2', 'frete_gratis' => true, 'retirada' => false],
            'garantia' => ['tipo' => '2230280', 'tempo' => 30, 'unidade' => 'dias'],
            ...$mudar,
        ]);
    }

    private static function ctx(array $mudar = []): ContextoValidacao
    {
        return new ContextoValidacao(...[
            'modelo' => MontadorDePlano::UP,
            'imagens' => ['a1' => self::IMAGEM_BOA],
            'modosEnvio' => ['custom', 'not_specified', 'me2'],
            ...$mudar,
        ]);
    }

    private static function validar(?RascunhoSnapshot $r = null, ?ContextoValidacao $ctx = null, array $condicionais = [], ?callable $ajustarSchema = null): array
    {
        $r ??= self::completo();
        $schema = (new ClassificadorAtributos())->classificar(self::schema($r->categoriaId, $ajustarSchema), new ContextoClassificacao(
            condicao: $r->condicao,
            eixos: array_values(array_filter(array_map(fn (Eixo $e) => $e->attributeId(), $r->eixos))),
            condicionais: $condicionais,
        ));

        return (new ValidadorRascunho())->validar($r, $schema, $ctx ?? self::ctx())->problemas;
    }

    private static function regras(array $problemas, string $severidade = Problema::BLOQUEIO): array
    {
        return array_values(array_unique(array_map(fn (Problema $p) => $p->regra, array_filter($problemas, fn (Problema $p) => $p->severidade === $severidade))));
    }

    private static function primeiro(array $problemas, string $regra): ?Problema
    {
        foreach ($problemas as $p) {
            if ($p->regra === $regra) {
                return $p;
            }
        }

        return null;
    }

    public function test_rascunho_completo_nao_tem_bloqueio(): void
    {
        $this->assertSame([], self::regras(self::validar()));
    }

    public function test_tc30_obrigatorio_faltando_aponta_o_campo_e_a_etapa(): void
    {
        $r = self::completo(['atributos' => array_diff_key(self::completo()->atributos, ['MODEL' => 1])]);

        $p = self::primeiro(self::validar($r), 'V-ATT-01');
        $this->assertSame(['etapa' => 'E3', 'atributo' => 'MODEL'], $p->alvo);
        $this->assertStringContainsString('Modelo', $p->mensagem);
    }

    public function test_marca_vazia_tem_regra_propria(): void
    {
        $r = self::completo(['atributos' => array_diff_key(self::completo()->atributos, ['BRAND' => 1])]);

        $this->assertContains('V-ATT-12', self::regras(self::validar($r)));
        $this->assertStringContainsString('Genérica', self::primeiro(self::validar($r), 'V-ATT-12')->mensagem);
    }

    public function test_valor_invalido_do_campo_vem_do_l1(): void
    {
        $r = self::completo(['atributos' => [...self::completo()->atributos, 'BACKREST_HEIGHT' => ['value_name' => '50 pol'], 'IS_SWIVEL' => ['value_name' => 'Talvez']]]);

        $this->assertEqualsCanonicalizing(['V-ATT-03', 'V-ATT-04'], self::regras(self::validar($r)));
        $this->assertSame('L1', self::primeiro(self::validar($r), 'V-ATT-04')->camada);
    }

    public function test_tc32_gtin_condicional_se_resolve_com_o_motivo(): void
    {
        $semGtin = self::completo(['variantes' => [new Variante(ChaveCanonica::UNICA, [], dados: ['estoque' => 3, 'precos' => ['gold_special' => 150.0, 'gold_pro' => 165.0], 'atributos' => ['SELLER_SKU' => ['value_name' => 'CAD']]])]]);
        $this->assertContains('V-ATT-01', self::regras(self::validar($semGtin, condicionais: ['GTIN'])));
        $this->assertNotContains('V-ATT-01', self::regras(self::validar($semGtin)), 'sem o condicional, GTIN é opcional');

        $comMotivo = self::completo(['variantes' => [new Variante(ChaveCanonica::UNICA, [], dados: ['estoque' => 3, 'precos' => ['gold_special' => 150.0, 'gold_pro' => 165.0], 'atributos' => ['SELLER_SKU' => ['value_name' => 'CAD'], 'EMPTY_GTIN_REASON' => ['value_id' => '17055161']]])]]);
        $this->assertSame([], self::regras(self::validar($comMotivo, condicionais: ['GTIN'])));
    }

    public function test_tc38_gtin_com_digito_errado(): void
    {
        $r = self::completo(['variantes' => [new Variante(ChaveCanonica::UNICA, [], dados: ['estoque' => 3, 'precos' => ['gold_special' => 150.0, 'gold_pro' => 165.0], 'atributos' => ['SELLER_SKU' => ['value_name' => 'CAD'], 'GTIN' => ['value_name' => '7896553367646']]])]]);

        $p = self::primeiro(self::validar($r), 'V-VAR-14');
        $this->assertSame(Problema::BLOQUEIO, $p->severidade);
        $this->assertSame('L1', $p->camada);
    }

    /** @return array<string, array{0: mixed, 1: string}> */
    public static function estoquesInvalidos(): array
    {
        return [
            'TC-20 negativo' => [-1, 'V-VAR-12'],
            'TC-21 decimal' => [2.5, 'V-VAR-12'],
            'TC-22 zero' => [0, 'V-VAR-12'],
            'TC-23 acima do limite' => [100000, 'V-VAR-12'],
            'vazio' => [null, 'V-VAR-12'],
        ];
    }

    #[DataProvider('estoquesInvalidos')]
    public function test_estoque_invalido(mixed $estoque, string $regra): void
    {
        $r = self::completo(['variantes' => [new Variante(ChaveCanonica::UNICA, [], dados: ['estoque' => $estoque, 'precos' => ['gold_special' => 150.0, 'gold_pro' => 165.0], 'atributos' => ['SELLER_SKU' => ['value_name' => 'CAD']]])]]);

        $this->assertContains($regra, self::regras(self::validar($r)));
    }

    public function test_tc22_estoque_zero_sugere_desativar(): void
    {
        $r = self::completo(['variantes' => [new Variante(ChaveCanonica::UNICA, [], dados: ['estoque' => 0, 'precos' => ['gold_special' => 150.0, 'gold_pro' => 165.0], 'atributos' => ['SELLER_SKU' => ['value_name' => 'CAD']]])]]);

        $this->assertStringContainsString('desative', self::primeiro(self::validar($r), 'V-VAR-12')->mensagem);
    }

    private static function duasCores(array $dadosPorCor): RascunhoSnapshot
    {
        $cor = new Eixo('COLOR', 'Cor', 0, true, [new ValorEixo('52049', 'Preto'), new ValorEixo('52028', 'Azul')]);
        $variantes = array_map(fn ($c) => Variante::daCombinacao($c, $dadosPorCor[$c->rotulo]), GeradorCombinacoes::gerar([$cor]));

        return self::completo([
            'eixos' => [$cor],
            'variantes' => $variantes,
            'imagens' => [['imagem' => 'a1', 'grupo' => 'COLOR=id:52049', 'posicao' => 0], ['imagem' => 'a1', 'grupo' => 'COLOR=id:52028', 'posicao' => 0]],
        ]);
    }

    public function test_tc24_tc25_sku_repetido_e_vazio(): void
    {
        $base = ['estoque' => 1, 'precos' => ['gold_special' => 150.0, 'gold_pro' => 165.0]];

        $repetido = self::duasCores(['Preto' => [...$base, 'atributos' => ['SELLER_SKU' => ['value_name' => 'CAD']]], 'Azul' => [...$base, 'atributos' => ['SELLER_SKU' => ['value_name' => ' cad ']]]]);
        $this->assertSame(['V-VAR-13'], self::regras(self::validar($repetido)));

        $vazio = self::duasCores(['Preto' => [...$base, 'atributos' => ['SELLER_SKU' => ['value_name' => 'CAD-P']]], 'Azul' => [...$base, 'atributos' => []]]);
        $this->assertSame(['V-VAR-13'], self::regras(self::validar($vazio)));
        $this->assertSame('COLOR=id:52028', self::primeiro(self::validar($vazio), 'V-VAR-13')->alvo['variante']);
    }

    public function test_gtin_repetido_entre_variantes_e_aviso(): void
    {
        $base = ['estoque' => 1, 'precos' => ['gold_special' => 150.0, 'gold_pro' => 165.0]];
        $r = self::duasCores([
            'Preto' => [...$base, 'atributos' => ['SELLER_SKU' => ['value_name' => 'P'], 'GTIN' => ['value_name' => '7896553367645']]],
            'Azul' => [...$base, 'atributos' => ['SELLER_SKU' => ['value_name' => 'A'], 'GTIN' => ['value_name' => '7896553367645']]],
        ]);

        $this->assertContains('V-VAR-15', self::regras(self::validar($r), Problema::AVISO));
        $this->assertSame([], self::regras(self::validar($r)));
    }

    public function test_variante_orfa_e_nenhuma_ativa(): void
    {
        $base = ['estoque' => 1, 'precos' => ['gold_special' => 150.0, 'gold_pro' => 165.0], 'atributos' => ['SELLER_SKU' => ['value_name' => 'X']]];
        $r = self::duasCores(['Preto' => $base, 'Azul' => [...$base, 'atributos' => ['SELLER_SKU' => ['value_name' => 'Y']]]]);

        $comOrfa = new RascunhoSnapshot(...[...get_object_vars($r), 'variantes' => [...$r->variantes, new Variante('COLOR=id:9', ['COLOR' => new ValorEixo('9', 'Velho')], ativa: false, orfa: true)]]);
        $this->assertContains('V-VAR-17', self::regras(self::validar($comOrfa)));

        $nenhuma = new RascunhoSnapshot(...[...get_object_vars($r), 'variantes' => array_map(fn ($v) => $v->comAtiva(false), $r->variantes)]);
        $this->assertContains('V-VAR-09', self::regras(self::validar($nenhuma)));
    }

    public function test_v_var_18_eixo_obrigatorio_nem_escolhido_nem_preenchido(): void
    {
        // Na camiseta, COLOR é required + allow_variations.
        $r = new RascunhoSnapshot(categoriaId: self::CAMISETA, atributos: ['BRAND' => ['value_name' => 'X'], 'MODEL' => ['value_name' => 'Y']], variantes: self::completo()->variantes, alvos: self::completo()->alvos);

        $problemas = self::validar($r);
        $this->assertSame('COLOR', self::primeiro($problemas, 'V-VAR-18')->alvo['atributo']);
        // A mesma falta não aparece duas vezes: vira V-VAR-18, não V-ATT-01.
        $this->assertNotContains('COLOR', array_map(fn ($p) => $p->alvo['atributo'] ?? null, array_filter($problemas, fn ($p) => $p->regra === 'V-ATT-01')));
    }

    public function test_tc74_categoria_com_tabela_de_medidas_bloqueia(): void
    {
        $camiseta = new RascunhoSnapshot(...[...get_object_vars(self::completo()), 'categoriaId' => self::CAMISETA]);
        $this->assertContains('V-CAT-04', self::regras(self::validar($camiseta)));
    }

    public function test_tc72_tc73_categoria_que_nao_e_folha_ou_nao_aceita_anuncio(): void
    {
        $pai = self::validar(ajustarSchema: function (array $f) {
            $f['categoria']['children_categories'] = [['id' => 'MLB1', 'name' => 'Filha']];
            $f['categoria']['settings']['listing_allowed'] = false;

            return $f;
        });

        $this->assertContains('V-CAT-01', self::regras($pai));
        $this->assertContains('V-CAT-02', self::regras($pai));
    }

    public function test_tc43_recondicionado_exige_90_dias(): void
    {
        $curta = self::completo(['condicao' => 'refurbished', 'garantia' => ['tipo' => '2230280', 'tempo' => 30, 'unidade' => 'dias']]);
        $this->assertContains('V-CND-02', self::regras(self::validar($curta)));

        $ok = self::completo(['condicao' => 'refurbished', 'garantia' => ['tipo' => '2230280', 'tempo' => 3, 'unidade' => 'meses']]);
        $this->assertNotContains('V-CND-02', self::regras(self::validar($ok)));
    }

    public function test_condicao_fora_das_aceitas_pela_categoria(): void
    {
        // A pastilha só aceita `new`.
        $r = new RascunhoSnapshot(...[...get_object_vars(self::completo()), 'categoriaId' => self::PASTILHA, 'condicao' => 'used', 'atributos' => ['BRAND' => ['value_name' => 'Bosch'], 'PART_NUMBER' => ['value_name' => 'X1']]]);

        $this->assertContains('V-CND-01', self::regras(self::validar($r)));
    }

    public function test_tc100_tc101_tc102_titulos(): void
    {
        $longo = self::completo(['alvos' => [new Alvo('gold_special', str_repeat('a', 61)), new Alvo('gold_pro', 'Cadeira ok')]]);
        $p = self::primeiro(self::validar($longo), 'V-TIT-01');
        $this->assertSame(['etapa' => 'E7', 'alvo' => 'gold_special'], $p->alvo);

        $frete = self::completo(['alvos' => [new Alvo('gold_special', 'Cadeira Escritório Frete Grátis'), new Alvo('gold_pro', 'Cadeira Escritório Executiva')]]);
        $this->assertContains('V-TIT-02', self::regras(self::validar($frete), Problema::AVISO));
        $this->assertSame([], self::regras(self::validar($frete)), 'termo proibido é aviso, não bloqueio');

        $vazio = self::completo(['alvos' => [new Alvo('gold_special', '  '), new Alvo('gold_pro', 'Cadeira ok')]]);
        $this->assertContains('V-TIT-01', self::regras(self::validar($vazio)));
    }

    public function test_d1_classico_e_premium_com_o_mesmo_titulo(): void
    {
        $r = self::completo(['alvos' => [new Alvo('gold_special', 'Cadeira Executiva ECF'), new Alvo('gold_pro', ' cadeira executiva ecf ')]]);

        $this->assertContains('D1', self::regras(self::validar($r)));
    }

    public function test_tc104_preco_abaixo_do_minimo_e_preco_invalido(): void
    {
        $camiseta = new RascunhoSnapshot(...[...get_object_vars(self::completo()), 'categoriaId' => self::CAMISETA, 'variantes' => [new Variante(ChaveCanonica::UNICA, [], dados: ['estoque' => 1, 'precos' => ['gold_special' => 7.0, 'gold_pro' => 9.0], 'atributos' => ['SELLER_SKU' => ['value_name' => 'C']]])]]);
        $p = self::primeiro(self::validar($camiseta), 'V-SAL-03');
        $this->assertSame(['etapa' => 'E10', 'alvo' => 'gold_special', 'variante' => ChaveCanonica::UNICA, 'campo' => 'preco'], $p->alvo);

        $quebrado = self::completo(['variantes' => [new Variante(ChaveCanonica::UNICA, [], dados: ['estoque' => 1, 'precos' => ['gold_special' => 150.555, 'gold_pro' => null], 'atributos' => ['SELLER_SKU' => ['value_name' => 'C']]])]]);
        $this->assertSame(['V-SAL-02'], self::regras(self::validar($quebrado)));
    }

    public function test_envio_que_a_conta_nao_tem_e_garantia_sem_tempo(): void
    {
        $this->assertContains('V-SAL-04', self::regras(self::validar(null, self::ctx(['modosEnvio' => ['custom']]))));

        $semTempo = self::completo(['garantia' => ['tipo' => '2230280', 'tempo' => null, 'unidade' => null]]);
        $this->assertContains('V-SAL-05', self::regras(self::validar($semTempo)));

        $semGarantia = self::completo(['garantia' => ['tipo' => '6150835', 'tempo' => null, 'unidade' => null]]);
        $this->assertNotContains('V-SAL-05', self::regras(self::validar($semGarantia)), '"Sem garantia" não tem tempo');
    }

    public function test_descricao_longa_bloqueia_e_html_avisa(): void
    {
        $longa = self::completo(['descricao' => str_repeat('a', 50001)]);
        $this->assertContains('V-DES-01', self::regras(self::validar($longa)));

        $html = self::completo(['descricao' => '<b>Cadeira</b> boa']);
        $this->assertContains('V-DES-01', self::regras(self::validar($html), Problema::AVISO));
    }

    public function test_tc42_plausibilidade_e_aviso(): void
    {
        $r = self::completo(['atributos' => [...self::completo()->atributos, 'BACKREST_HEIGHT' => ['value_name' => '23 cm'], 'MAX_CHAIR_HEIGHT' => ['value_name' => '22 cm']]]);
        $ctx = self::ctx(['plausibilidade' => [['maior' => 'MAX_CHAIR_HEIGHT', 'menor' => 'BACKREST_HEIGHT']]]);

        $this->assertContains('V-ATT-11', self::regras(self::validar($r, $ctx), Problema::AVISO));
        $this->assertSame([], self::regras(self::validar($r, $ctx)));
    }

    public function test_tc56_tc57_imagem_pequena_e_formato_invalido(): void
    {
        $ctx = self::ctx(['imagens' => ['a1' => ['mime' => 'image/webp', 'bytes' => 500_000, 'largura' => 400, 'altura' => 600, 'upload_status' => 'uploaded']]]);

        $this->assertEqualsCanonicalizing(['V-IMG-01', 'V-IMG-03'], array_values(array_intersect(self::regras(self::validar(null, $ctx)), ['V-IMG-01', 'V-IMG-03'])));
        $this->assertContains('V-IMG-10', self::regras(self::validar(null, $ctx), Problema::AVISO));
    }

    public function test_foto_nao_enviada_so_bloqueia_na_hora_de_publicar(): void
    {
        $pendente = ['a1' => [...self::IMAGEM_BOA, 'upload_status' => 'pending']];

        $this->assertNotContains('V-IMG-08', self::regras(self::validar(null, self::ctx(['imagens' => $pendente]))));
        $this->assertContains('V-IMG-08', self::regras(self::validar(null, self::ctx(['imagens' => $pendente, 'paraPublicar' => true]))));
    }

    public function test_d10_conta_no_modelo_antigo(): void
    {
        $this->assertContains('D10', self::regras(self::validar(null, self::ctx(['modelo' => MontadorDePlano::LEGADO]))));
    }

    public function test_d11_conta_multideposito_vira_informacao_nao_bloqueio(): void
    {
        $problemas = self::validar(null, self::ctx(['tagsDaConta' => ['user_product_seller', 'warehouse_management']]));

        $this->assertSame([], self::regras($problemas));
        $this->assertContains('V-VAR-20', self::regras($problemas, Problema::INFO));
    }

    public function test_atributo_sugerido_ou_migrado_pede_revisao(): void
    {
        $r = self::completo(['atributos' => [...self::completo()->atributos, 'MODEL' => ['value_name' => 'Executiva', 'origem' => 'migrated', 'revisar' => true]]]);

        $this->assertContains('V-ATT-09', self::regras(self::validar($r), Problema::AVISO));
    }

    public function test_problemas_agrupados_por_etapa(): void
    {
        $r = self::completo(['atributos' => array_diff_key(self::completo()->atributos, ['MODEL' => 1]), 'descricao' => str_repeat('a', 50001)]);
        $schema = (new ClassificadorAtributos())->classificar(self::schema(self::CADEIRA), new ContextoClassificacao());

        $resultado = (new ValidadorRascunho())->validar($r, $schema, self::ctx());

        $this->assertTrue($resultado->temBloqueio());
        $this->assertArrayHasKey('E3', $resultado->porEtapa());
        $this->assertArrayHasKey('E9', $resultado->porEtapa());
    }
}
