<?php

namespace Tests\Unit\Publicador\Payload;

use App\Support\Publicador\Imagem\ResolvedorGruposImagem as R;
use App\Support\Publicador\Payload\Alvo;
use App\Support\Publicador\Payload\MontadorDePlano;
use App\Support\Publicador\Payload\PayloadBuilderUserProducts;
use App\Support\Publicador\RascunhoSnapshot;
use App\Support\Publicador\RegraViolada;
use App\Support\Publicador\Schema\ClassificadorAtributos;
use App\Support\Publicador\Schema\ContextoClassificacao;
use App\Support\Publicador\Variacao\ChaveCanonica;
use App\Support\Publicador\Variacao\Eixo;
use App\Support\Publicador\Variacao\GeradorCombinacoes;
use App\Support\Publicador\Variacao\ValorEixo;
use App\Support\Publicador\Variacao\Variante;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Publicador\Concerns\CarregaSchemas;
use Tests\Unit\Publicador\Concerns\ComparaSnapshot;

/**
 * `10` e `05` §9 — do rascunho ao que vai para o `POST /items`, no modelo User
 * Products (o de todas as contas de cliente medidas em 01/10). Um item por
 * alvo (Clássico/Premium) × variante ativa; mesmo `family_name` dentro do alvo;
 * nunca `title`, nunca `variations` (o ML recusa os dois — `12` H-02, N-13).
 */
class MontadorDePlanoTest extends TestCase
{
    use CarregaSchemas;
    use ComparaSnapshot;

    private const TITULO_CLASSICO = 'Cadeira Escritório Executiva ECF Giratória';
    private const TITULO_PREMIUM = 'Cadeira de Escritório ECF Executiva com Giro';

    /** O produto do print: atributos da cadeira MLB193945. */
    private const ATRIBUTOS_CADEIRA = [
        'BRAND' => ['value_name' => 'ECF'],
        'MODEL' => ['value_name' => 'Executiva'],
        'BACKREST_HEIGHT' => ['value_name' => '23'],
        'SEAT_DEPTH' => ['value_number' => 23, 'value_unit' => 'cm'],
        'OFFICE_CHAIR_WIDTH' => ['value_name' => '60 cm'],
        'MAX_CHAIR_HEIGHT' => ['value_name' => '110 cm'],
        'REQUIRES_ASSEMBLY' => ['value_id' => '242085'],
        'IS_GAMER' => ['value_name' => 'Não'],
        'IS_ERGONOMIC' => ['value_id' => '242085'],
        'IS_SWIVEL' => ['value_id' => '242085'],
        'INCLUDES_ASSEMBLY_MANUAL' => ['value_id' => '242085'],
        'INMETRO_CERTIFICATION_REGISTRATION_NUMBER' => ['value_id' => '-1'],
        'SELLER_PACKAGE_WEIGHT' => ['value_name' => '12000 g'],
        'SELLER_PACKAGE_HEIGHT' => ['value_name' => '70 cm'],
        'SELLER_PACKAGE_WIDTH' => ['value_name' => '60 cm'],
        'SELLER_PACKAGE_LENGTH' => ['value_name' => '30 cm'],
    ];

    private const FOTOS_ML = ['a1' => 'ML-A1', 'a2' => 'ML-A2', 'preto1' => 'ML-P1', 'azul1' => 'ML-Z1', 'geral1' => 'ML-G1'];

    private static function unica(array $dados): array
    {
        return [new Variante(ChaveCanonica::UNICA, [], dados: $dados)];
    }

    private static function rascunhoSimples(array $alvos, array $mudar = []): RascunhoSnapshot
    {
        return new RascunhoSnapshot(...[
            'categoriaId' => self::CADEIRA,
            'atributos' => self::ATRIBUTOS_CADEIRA,
            'variantes' => self::unica([
                'estoque' => 1,
                'precos' => ['gold_special' => 150.0, 'gold_pro' => 165.0],
                'atributos' => ['SELLER_SKU' => ['value_name' => 'CAD'], 'GTIN' => ['value_name' => '7896553367645']],
            ]),
            'alvos' => $alvos,
            'imagens' => [['imagem' => 'a1', 'grupo' => R::GERAL, 'posicao' => 0], ['imagem' => 'a2', 'grupo' => R::GERAL, 'posicao' => 1]],
            'descricao' => "Cadeira executiva giratória.\nInclui manual.",
            'envio' => ['modo' => 'me2', 'frete_gratis' => true, 'retirada' => false],
            'garantia' => ['tipo' => '2230280', 'tempo' => 30, 'unidade' => 'dias'],
            ...$mudar,
        ]);
    }

    private function montar(RascunhoSnapshot $r, array $eixos = [], string $condicao = 'new')
    {
        return MontadorDePlano::montar($r, $this->classificado($r, $eixos, $condicao), MontadorDePlano::UP, self::FOTOS_ML);
    }

    private function classificado(RascunhoSnapshot $r, array $eixos, string $condicao)
    {
        return (new ClassificadorAtributos())->classificar(
            self::schema($r->categoriaId),
            new ContextoClassificacao(condicao: $condicao, eixos: array_values(array_filter(array_map(fn (Eixo $e) => $e->attributeId(), $eixos)))),
        );
    }

    private static function attrs(array $payload): array
    {
        $saida = [];
        foreach ($payload['attributes'] as $a) {
            $saida[$a['id'] ?? $a['name']] = $a;
        }

        return $saida;
    }

    public function test_tc01_produto_sem_variacoes_um_item_com_family_name(): void
    {
        $plano = $this->montar(self::rascunhoSimples([new Alvo('gold_special', self::TITULO_CLASSICO)]));

        $this->assertSame(MontadorDePlano::UP, $plano->modelo);
        $this->assertCount(1, $plano->itens);
        $item = $plano->itens[0];
        $this->assertSame('gold_special', $item->listingTypeId);
        $this->assertSame(ChaveCanonica::UNICA, $item->varianteChave);
        $this->assertSame([], $item->imagensPendentes);
        $this->assertSame("Cadeira executiva giratória.\nInclui manual.", $plano->descricao);

        $this->assertSnapshotJson('up_cadeira_simples_classico', $item->payload);
    }

    public function test_formato_de_cada_atributo_no_payload(): void
    {
        $a = self::attrs($this->montar(self::rascunhoSimples([new Alvo('gold_special', self::TITULO_CLASSICO)]))->itens[0]->payload);

        $this->assertSame('23 cm', $a['BACKREST_HEIGHT']['value_name'], 'TC-31: unidade padrão');
        $this->assertSame('23 cm', $a['SEAT_DEPTH']['value_name']);
        $this->assertSame('242084', $a['IS_GAMER']['value_id'], 'texto que bate com a lista vira id');
        $this->assertSame(['id' => 'INMETRO_CERTIFICATION_REGISTRATION_NUMBER', 'value_id' => '-1', 'value_name' => null], $a['INMETRO_CERTIFICATION_REGISTRATION_NUMBER'], 'TC-39');
        $this->assertSame('CAD', $a['SELLER_SKU']['value_name']);
        $this->assertSame('7896553367645', $a['GTIN']['value_name']);
        $this->assertSame('12000 g', $a['SELLER_PACKAGE_WEIGHT']['value_name']);
    }

    public function test_d1_classico_e_premium_saem_do_mesmo_rascunho_com_titulo_e_preco_proprios(): void
    {
        $plano = $this->montar(self::rascunhoSimples([new Alvo('gold_special', self::TITULO_CLASSICO), new Alvo('gold_pro', self::TITULO_PREMIUM)]));

        $this->assertSame(['gold_special', 'gold_pro'], array_map(fn ($i) => $i->listingTypeId, $plano->itens));
        [$classico, $premium] = array_map(fn ($i) => $i->payload, $plano->itens);
        $this->assertSame(self::TITULO_CLASSICO, $classico['family_name']);
        $this->assertSame(self::TITULO_PREMIUM, $premium['family_name']);
        $this->assertSame(150.0, $classico['price']);
        $this->assertSame(165.0, $premium['price']);
        // O que liga o par no ML é o SKU (learnings do portal §27): o mesmo nos dois.
        $this->assertSame(self::attrs($classico)['SELLER_SKU'], self::attrs($premium)['SELLER_SKU']);
    }

    public function test_alvo_inativo_nao_gera_item(): void
    {
        // Oferta "Falta Premium": o Clássico já está no ar e não se publica de novo.
        $plano = $this->montar(self::rascunhoSimples([new Alvo('gold_special', self::TITULO_CLASSICO, ativo: false), new Alvo('gold_pro', self::TITULO_PREMIUM)]));

        $this->assertSame(['gold_pro'], array_map(fn ($i) => $i->listingTypeId, $plano->itens));
    }

    public function test_tc02_um_eixo_sem_defines_picture_dois_itens_da_mesma_familia(): void
    {
        $voltagem = new Eixo('VOLTAGE', 'Voltagem', 0, valores: [new ValorEixo('39205162', '127V'), new ValorEixo('198813', '220V')]);
        $variantes = array_map(fn ($c, $i) => Variante::daCombinacao($c, [
            'estoque' => 5,
            'precos' => ['gold_special' => 199.9],
            'atributos' => ['SELLER_SKU' => ['value_name' => 'FUR-'.$c->valores['VOLTAGE']->valueName]],
        ]), GeradorCombinacoes::gerar([$voltagem]), [0, 1]);

        $r = new RascunhoSnapshot(
            categoriaId: self::FURADEIRA,
            atributos: ['BRAND' => ['value_name' => 'Genérica'], 'MODEL' => ['value_name' => 'FX-1']],
            eixos: [$voltagem],
            variantes: $variantes,
            alvos: [new Alvo('gold_special', 'Furadeira de Impacto Genérica FX-1 650W')],
            imagens: [['imagem' => 'geral1', 'grupo' => R::GERAL, 'posicao' => 0]],
            envio: ['modo' => 'me2', 'frete_gratis' => false, 'retirada' => false],
        );
        $plano = $this->montar($r, [$voltagem]);

        $this->assertCount(2, $plano->itens);
        $this->assertSame(
            ['39205162', '198813'],
            array_map(fn ($i) => self::attrs($i->payload)['VOLTAGE']['value_id'], $plano->itens),
        );
        $this->assertSame(1, count(array_unique(array_map(fn ($i) => $i->payload['family_name'], $plano->itens))), 'RN-46');
        $this->assertSame([['id' => 'ML-G1']], $plano->itens[1]->payload['pictures'], 'TC-54: sem defines_picture, galeria geral');
        $this->assertSnapshotJson('up_furadeira_voltagem', array_map(fn ($i) => $i->payload, $plano->itens));
    }

    public function test_tc03_tc05_dois_eixos_com_combinacao_desativada_e_fotos_por_cor(): void
    {
        $cor = new Eixo('COLOR', 'Cor', 0, true, [new ValorEixo('52049', 'Preto'), new ValorEixo('52028', 'Azul')]);
        $material = new Eixo('UPHOLSTERY_MATERIAL', 'Material', 1, true, [new ValorEixo(null, 'Couro'), new ValorEixo(null, 'Tecido')]);
        $variantes = array_map(
            fn ($c) => Variante::daCombinacao($c, ['estoque' => 2, 'precos' => ['gold_special' => 150.0], 'atributos' => ['SELLER_SKU' => ['value_name' => 'CAD-'.$c->rotulo]]], ativa: $c->rotulo !== 'Azul / Tecido'),
            GeradorCombinacoes::gerar([$cor, $material]),
        );

        $r = self::rascunhoSimples([new Alvo('gold_special', self::TITULO_CLASSICO)], [
            'eixos' => [$cor, $material],
            'variantes' => $variantes,
            'imagens' => [
                ['imagem' => 'preto1', 'grupo' => 'COLOR=id:52049|UPHOLSTERY_MATERIAL=txt:couro', 'posicao' => 0],
                ['imagem' => 'preto1', 'grupo' => 'COLOR=id:52049|UPHOLSTERY_MATERIAL=txt:tecido', 'posicao' => 0],
                ['imagem' => 'azul1', 'grupo' => 'COLOR=id:52028|UPHOLSTERY_MATERIAL=txt:couro', 'posicao' => 0],
                ['imagem' => 'geral1', 'grupo' => R::GERAL, 'posicao' => 0],
            ],
        ]);
        $plano = $this->montar($r, [$cor, $material]);

        $this->assertSame(['Preto / Couro', 'Preto / Tecido', 'Azul / Couro'], array_map(fn ($i) => $i->rotulo, $plano->itens), 'a desativada não sai');
        $azul = $plano->itens[2]->payload;
        $this->assertSame([['id' => 'ML-Z1'], ['id' => 'ML-G1']], $azul['pictures'], 'fotos do grupo primeiro, galeria geral depois');
        $this->assertSame('52028', self::attrs($azul)['COLOR']['value_id']);
        // "Couro" existe na lista do ML (482783): vai o id. "Tecido" não existe e vai como texto.
        $this->assertSame(['id' => 'UPHOLSTERY_MATERIAL', 'value_id' => '482783'], self::attrs($azul)['UPHOLSTERY_MATERIAL']);
        $this->assertSame(['id' => 'UPHOLSTERY_MATERIAL', 'value_name' => 'Tecido'], self::attrs($plano->itens[1]->payload)['UPHOLSTERY_MATERIAL']);
    }

    public function test_tc04_eixo_customizado_vai_como_name_e_value_name(): void
    {
        $estampa = Eixo::customizado('Estampa', 0, [new ValorEixo(null, 'Lisa')]);
        $variantes = array_map(fn ($c) => Variante::daCombinacao($c, ['estoque' => 1, 'precos' => ['gold_special' => 150.0], 'atributos' => ['SELLER_SKU' => ['value_name' => 'E1']]]), GeradorCombinacoes::gerar([$estampa]));

        $plano = $this->montar(self::rascunhoSimples([new Alvo('gold_special', self::TITULO_CLASSICO)], ['eixos' => [$estampa], 'variantes' => $variantes]), [$estampa]);

        $this->assertSame(['name' => 'Estampa', 'value_name' => 'Lisa'], self::attrs($plano->itens[0]->payload)['Estampa']);
    }

    public function test_tc14_no_up_cada_variante_tem_o_proprio_preco(): void
    {
        $cor = new Eixo('COLOR', 'Cor', 0, true, [new ValorEixo('52049', 'Preto'), new ValorEixo('52028', 'Azul')]);
        $precos = ['Preto' => 150.0, 'Azul' => 165.0];
        $variantes = array_map(fn ($c) => Variante::daCombinacao($c, ['estoque' => 1, 'precos' => ['gold_special' => $precos[$c->rotulo]], 'atributos' => ['SELLER_SKU' => ['value_name' => $c->rotulo]]]), GeradorCombinacoes::gerar([$cor]));

        $plano = $this->montar(self::rascunhoSimples([new Alvo('gold_special', self::TITULO_CLASSICO)], ['eixos' => [$cor], 'variantes' => $variantes]), [$cor]);

        $this->assertSame([150.0, 165.0], array_map(fn ($i) => $i->payload['price'], $plano->itens));
    }

    public function test_tc41_atributo_do_sistema_nunca_vai_e_eixo_nao_repete_no_produto(): void
    {
        $cor = new Eixo('COLOR', 'Cor', 0, true, [new ValorEixo('52049', 'Preto')]);
        $variantes = array_map(fn ($c) => Variante::daCombinacao($c, ['estoque' => 1, 'precos' => ['gold_special' => 150.0], 'atributos' => ['SELLER_SKU' => ['value_name' => 'P']]]), GeradorCombinacoes::gerar([$cor]));
        $atributos = [...self::ATRIBUTOS_CADEIRA, 'PACKAGE_HEIGHT' => ['value_name' => '10 cm'], 'COLOR' => ['value_id' => '52028']];

        $a = self::attrs($this->montar(self::rascunhoSimples([new Alvo('gold_special', self::TITULO_CLASSICO)], ['atributos' => $atributos, 'eixos' => [$cor], 'variantes' => $variantes]), [$cor])->itens[0]->payload);

        $this->assertArrayNotHasKey('PACKAGE_HEIGHT', $a, 'read_only');
        $this->assertSame('52049', $a['COLOR']['value_id'], 'vale o valor do eixo, não o do produto (V-VAR-10)');
    }

    public function test_tc43_recondicionado_vai_como_novo_com_item_condition(): void
    {
        $payload = $this->montar(
            self::rascunhoSimples([new Alvo('gold_special', self::TITULO_CLASSICO)], ['condicao' => 'refurbished', 'garantia' => ['tipo' => '2230280', 'tempo' => 90, 'unidade' => 'dias']]),
            condicao: 'refurbished',
        )->itens[0]->payload;

        $this->assertSame('new', $payload['condition']);
        $this->assertSame(['id' => 'ITEM_CONDITION', 'value_id' => '2230582'], self::attrs($payload)['ITEM_CONDITION']);
        $this->assertSame([['id' => 'WARRANTY_TYPE', 'value_id' => '2230280'], ['id' => 'WARRANTY_TIME', 'value_name' => '90 dias']], $payload['sale_terms']);
    }

    public function test_sem_garantia_vai_so_o_tipo(): void
    {
        $payload = $this->montar(self::rascunhoSimples([new Alvo('gold_special', self::TITULO_CLASSICO)], ['garantia' => ['tipo' => '6150835', 'tempo' => null, 'unidade' => null]]))->itens[0]->payload;

        $this->assertSame([['id' => 'WARRANTY_TYPE', 'value_id' => '6150835']], $payload['sale_terms']);
    }

    public function test_foto_ainda_nao_enviada_fica_pendente_e_fora_do_payload(): void
    {
        $r = self::rascunhoSimples([new Alvo('gold_special', self::TITULO_CLASSICO)], ['imagens' => [
            ['imagem' => 'a1', 'grupo' => R::GERAL, 'posicao' => 0],
            ['imagem' => 'nova', 'grupo' => R::GERAL, 'posicao' => 1],
        ]]);
        $item = $this->montar($r)->itens[0];

        $this->assertSame(['nova'], $item->imagensPendentes);
        $this->assertSame([['id' => 'ML-A1']], $item->payload['pictures']);
    }

    public function test_variante_orfa_nunca_e_publicada(): void
    {
        $r = self::rascunhoSimples([new Alvo('gold_special', self::TITULO_CLASSICO)]);
        $orfa = new Variante('COLOR=id:1', ['COLOR' => new ValorEixo('1', 'X')], ativa: true, orfa: true, dados: ['estoque' => 1]);
        $r = self::rascunhoSimples([new Alvo('gold_special', self::TITULO_CLASSICO)], ['variantes' => [...$r->variantes, $orfa]]);

        $this->assertSame([ChaveCanonica::UNICA], array_map(fn ($i) => $i->varianteChave, $this->montar($r)->itens));
    }

    public function test_d10_conta_no_modelo_antigo_e_recusada_com_mensagem(): void
    {
        $r = self::rascunhoSimples([new Alvo('gold_special', self::TITULO_CLASSICO)]);

        try {
            MontadorDePlano::montar($r, $this->classificado($r, [], 'new'), MontadorDePlano::LEGADO, self::FOTOS_ML);
            $this->fail('Esperava RegraViolada');
        } catch (RegraViolada $e) {
            $this->assertSame('D10', $e->regra);
            $this->assertStringContainsString('modelo antigo', $e->getMessage());
        }
    }

    public function test_tc90_payload_up_nunca_leva_variations_nem_title(): void
    {
        foreach ([['variations' => [[]]], ['title' => 'x']] as $proibido) {
            try {
                PayloadBuilderUserProducts::garantirFormato(['family_name' => 'x', ...$proibido]);
                $this->fail('Esperava LogicException para '.array_key_first($proibido));
            } catch (\LogicException $e) {
                $this->assertStringContainsString(array_key_first($proibido), $e->getMessage());
            }
        }

        $plano = $this->montar(self::rascunhoSimples([new Alvo('gold_special', self::TITULO_CLASSICO), new Alvo('gold_pro', self::TITULO_PREMIUM)]));
        foreach ($plano->itens as $item) {
            $this->assertArrayNotHasKey('variations', $item->payload);
            $this->assertArrayNotHasKey('title', $item->payload);
        }
    }

    public function test_mesmo_rascunho_mesmo_plano(): void
    {
        $r = self::rascunhoSimples([new Alvo('gold_special', self::TITULO_CLASSICO), new Alvo('gold_pro', self::TITULO_PREMIUM)]);

        $this->assertSame($this->montar($r)->hash(), $this->montar($r)->hash(), 'determinístico: é o hash que a conferência grava');
        $outro = self::rascunhoSimples([new Alvo('gold_special', self::TITULO_CLASSICO.' Preta'), new Alvo('gold_pro', self::TITULO_PREMIUM)]);
        $this->assertNotSame($this->montar($r)->hash(), $this->montar($outro)->hash());
    }

    /** D6/CAPA-01: com 2 alvos ativos e 2+ fotos aprovadas, a capa do Premium nunca repete a do Clássico. */
    public function test_capa01_premium_nunca_repete_a_capa_do_classico_com_duas_fotos_aprovadas(): void
    {
        $plano = $this->montar(self::rascunhoSimples([new Alvo('gold_special', self::TITULO_CLASSICO), new Alvo('gold_pro', self::TITULO_PREMIUM)]));

        [$classico, $premium] = array_map(fn ($i) => $i->payload, $plano->itens);
        $this->assertSame(['id' => 'ML-A1'], $classico['pictures'][0], 'Clássico mantém a capa de hoje');
        $this->assertSame(['id' => 'ML-A2'], $premium['pictures'][0], 'Premium rotaciona para a 2ª foto como capa');
        $this->assertNotSame($classico['pictures'][0], $premium['pictures'][0]);
    }

    /**
     * Fase 2 (combo), decisão do usuário em 2026-10-09: *"use a mesma foto tanto
     * para clássico quanto para o premium, nesse caso pode quebrar aquela regra"*.
     *
     * A capa do combo é a imagem que mostra as N unidades. Rotacionar o Premium
     * (D6/CAPA-01) o jogaria para a 2ª foto — herdada do produto base, mostrando
     * UMA unidade —, o que é pior do que repetir a capa: o anúncio de 2 unidades
     * abriria com a foto de 1. Então, e SÓ no combo, a rotação não se aplica.
     *
     * O CAPA-01 segue valendo inteiro na Fase 1 (`unidadesPorOferta = 1`), provado
     * pelo teste logo acima — este aqui não o afrouxa, delimita.
     */
    public function test_combo_usa_a_mesma_capa_no_classico_e_no_premium(): void
    {
        $plano = $this->montar(self::rascunhoSimples(
            [new Alvo('gold_special', self::TITULO_CLASSICO), new Alvo('gold_pro', self::TITULO_PREMIUM)],
            ['unidadesPorOferta' => 2],
        ));

        [$classico, $premium] = array_map(fn ($i) => $i->payload, $plano->itens);
        $this->assertSame(['id' => 'ML-A1'], $classico['pictures'][0], 'a capa do combo abre o Clássico');
        $this->assertSame(['id' => 'ML-A1'], $premium['pictures'][0], 'e a MESMA capa abre o Premium');

        // Nada além da capa muda: a lista continua inteira e na mesma ordem nos dois.
        $this->assertSame(['ML-A1', 'ML-A2'], collect($classico['pictures'])->pluck('id')->all());
        $this->assertSame(['ML-A1', 'ML-A2'], collect($premium['pictures'])->pluck('id')->all());
    }

    /** D6/CAPA-02: mesma lista de fotos aprovadas nos dois alvos — nunca duplica, nunca descarta. */
    public function test_capa02_mesma_lista_de_fotos_sem_duplicar_nem_descartar(): void
    {
        $plano = $this->montar(self::rascunhoSimples([new Alvo('gold_special', self::TITULO_CLASSICO), new Alvo('gold_pro', self::TITULO_PREMIUM)]));

        [$classico, $premium] = array_map(fn ($i) => $i->payload, $plano->itens);
        $this->assertSame(['ML-A1', 'ML-A2'], collect($classico['pictures'])->pluck('id')->all());
        $this->assertEqualsCanonicalizing(['ML-A1', 'ML-A2'], collect($premium['pictures'])->pluck('id')->all());
    }

    /** D6/CAPA-03: alvo único mantém a capa de hoje — snapshot existente não muda. */
    public function test_capa03_alvo_unico_mantem_a_capa_de_hoje(): void
    {
        $plano = $this->montar(self::rascunhoSimples([new Alvo('gold_special', self::TITULO_CLASSICO)]));

        $this->assertSnapshotJson('up_cadeira_simples_classico', $plano->itens[0]->payload);
    }

    /** D6/CAPA-04: o mesmo rascunho produz sempre a mesma ordem de capa por alvo. */
    public function test_capa04_mesmo_rascunho_mesma_ordem_de_capa_sempre(): void
    {
        $r = self::rascunhoSimples([new Alvo('gold_special', self::TITULO_CLASSICO), new Alvo('gold_pro', self::TITULO_PREMIUM)]);

        $pictures1 = array_map(fn ($i) => $i->payload['pictures'], $this->montar($r)->itens);
        $pictures2 = array_map(fn ($i) => $i->payload['pictures'], $this->montar($r)->itens);

        $this->assertSame($pictures1, $pictures2);
    }
}
