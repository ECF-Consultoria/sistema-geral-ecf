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
use App\Support\Publicador\Variacao\ValorEixo;
use App\Support\Publicador\Variacao\Variante;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Publicador\Concerns\CarregaSchemas;

/**
 * `ValidadorRascunho::bloqueiosSemSchema` (10/10/2026) — os bloqueios que a visão rápida da publicação em lote
 * mostra ANTES de conferir (V-TIT-05, termo que o ML veta no título; V-TIT-04, títulos iguais; V-DES-05, termo vetado
 * na descrição; V-SAL-08, preço do Portal sem frete) são EXATAMENTE os que o `validar()` completo dá para as mesmas
 * regras: mesma mensagem, mesmo alvo, mesma ordem. Uma regra só, dois caminhos de leitura.
 */
class BloqueiosSemSchemaTest extends TestCase
{
    use CarregaSchemas;

    private const REGRAS_SEM_SCHEMA = ['V-TIT-05', 'V-TIT-04', 'V-DES-05', 'V-SAL-08'];

    private static function cadeira(array $alvos, array $variantes, array $eixos = [], string $descricao = 'Cadeira executiva giratória.'): RascunhoSnapshot
    {
        return new RascunhoSnapshot(...[
            'categoriaId' => self::CADEIRA,
            'atributos' => [
                'BRAND' => ['value_name' => 'ECF'], 'MODEL' => ['value_name' => 'Executiva'],
                'BACKREST_HEIGHT' => ['value_name' => '50 cm'], 'SEAT_DEPTH' => ['value_name' => '45 cm'],
                'OFFICE_CHAIR_WIDTH' => ['value_name' => '60 cm'], 'MAX_CHAIR_HEIGHT' => ['value_name' => '110 cm'],
                'REQUIRES_ASSEMBLY' => ['value_id' => '242085'], 'IS_GAMER' => ['value_id' => '242084'],
                'IS_ERGONOMIC' => ['value_id' => '242085'], 'IS_SWIVEL' => ['value_id' => '242085'],
                'INCLUDES_ASSEMBLY_MANUAL' => ['value_id' => '242085'],
            ],
            'eixos' => $eixos,
            'variantes' => $variantes,
            'alvos' => $alvos,
            'imagens' => [['imagem' => 'a1', 'grupo' => R::GERAL, 'posicao' => 0]],
            'descricao' => $descricao,
            'envio' => ['modo' => 'me2', 'frete_gratis' => true, 'retirada' => false],
            'garantia' => ['tipo' => '2230280', 'tempo' => 30, 'unidade' => 'dias'],
        ]);
    }

    /** @return list<array{regra: string, severidade: string, mensagem: string, alvo: array}> */
    private static function daValidacao(RascunhoSnapshot $r): array
    {
        $schema = (new ClassificadorAtributos())->classificar(self::schema($r->categoriaId), new ContextoClassificacao(
            condicao: $r->condicao,
            eixos: array_values(array_filter(array_map(fn (Eixo $e) => $e->attributeId(), $r->eixos))),
        ));
        $ctx = new ContextoValidacao(modelo: MontadorDePlano::UP, imagens: ['a1' => ['mime' => 'image/jpeg', 'bytes' => 800_000, 'largura' => 1200, 'altura' => 1200, 'upload_status' => 'uploaded']],
            modosEnvio: ['me2']);
        $problemas = (new ValidadorRascunho())->validar($r, $schema, $ctx)->problemas;

        return self::comoArray(array_values(array_filter($problemas, fn (Problema $p) => in_array($p->regra, self::REGRAS_SEM_SCHEMA, true))));
    }

    /** @param list<Problema> $problemas */
    private static function comoArray(array $problemas): array
    {
        return array_map(fn (Problema $p) => ['regra' => $p->regra, 'severidade' => $p->severidade, 'mensagem' => $p->mensagem, 'alvo' => $p->alvo], $problemas);
    }

    /** Duas cores; o preço do Clássico vem do Portal (Preto sem frete), o Premium do Azul é digitado. */
    private static function comCores(array $alvos): RascunhoSnapshot
    {
        $cor = new Eixo('COLOR', 'Cor', 0, true, [new ValorEixo('52049', 'Preto'), new ValorEixo('52028', 'Azul')]);
        $variante = fn (string $id, string $nome, string $sku, array $precos) => new Variante(ChaveCanonica::combinacao(['COLOR' => (new ValorEixo($id, $nome))->chave()]),
            ['COLOR' => new ValorEixo($id, $nome)], dados: ['estoque' => 2, 'precos' => $precos,
                'atributos' => ['SELLER_SKU' => ['value_name' => $sku], 'GTIN' => ['value_name' => '7896553367645']]]);
        $r = self::cadeira($alvos, [
            $variante('52049', 'Preto', 'CAD-PT', []),
            $variante('52028', 'Azul', 'CAD-AZ', ['gold_pro' => 230.0]),
        ], [$cor]);

        return $r->comEfetivosDe([
            'titulos' => [], 'precos' => ['gold_special' => 200.0, 'gold_pro' => 220.0], 'mlbs' => [],
            'promocoes' => ['gold_special' => 166.67, 'gold_pro' => 183.33], 'sem_frete' => ['gold_special' => false, 'gold_pro' => false],
            'precos_por_variante' => ['cad-pt' => ['gold_special' => 207.19, 'gold_pro' => 223.26], 'cad-az' => ['gold_special' => 210.0, 'gold_pro' => 225.0]],
            'promocoes_por_variante' => ['cad-pt' => ['gold_special' => 172.66, 'gold_pro' => 186.05], 'cad-az' => ['gold_special' => 175.0, 'gold_pro' => 187.5]],
            'sem_frete_por_variante' => ['cad-pt' => ['gold_special' => true, 'gold_pro' => true], 'cad-az' => ['gold_special' => false, 'gold_pro' => true]],
        ]);
    }

    public function test_os_mesmos_bloqueios_do_validar_com_titulos_iguais_e_preco_do_portal_sem_frete(): void
    {
        $r = self::comCores([new Alvo('gold_special', 'Cadeiras Executivas ECF'), new Alvo('gold_pro', 'cadeira executiva ecf')]);

        $semSchema = self::comoArray(ValidadorRascunho::bloqueiosSemSchema($r));

        $this->assertSame(self::daValidacao($r), $semSchema, 'mesma regra, mesma mensagem, mesmo alvo e mesma ordem');
        // Clássico do Preto (Portal, sem frete) e Premium do Preto (Portal, sem frete); o Premium do Azul é digitado.
        $this->assertSame(['V-TIT-04', 'V-SAL-08', 'V-SAL-08'], array_column($semSchema, 'regra'));
        $this->assertSame('gold_pro', $semSchema[0]['alvo']['alvo']);
        $this->assertSame('O preço do Clássico de Preto veio da Precificação do Portal calculado sem frete. Informe ou aceite o frete na Precificação do Portal, ou digite o preço aqui.', $semSchema[1]['mensagem']);
        $this->assertSame('gold_pro', $semSchema[2]['alvo']['alvo']);
        $this->assertNotContains('COLOR=id:52028', array_map(fn ($p) => $p['alvo']['variante'] ?? null, array_slice($semSchema, 1)), 'o Clássico do Azul tem frete e o Premium é digitado');
    }

    public function test_sem_bloqueio_quando_os_titulos_diferem_e_o_portal_tem_frete(): void
    {
        $r = self::cadeira([new Alvo('gold_special', 'Cadeira Executiva Giratória'), new Alvo('gold_pro', 'Cadeira Giratória Executiva')], [
            new Variante(ChaveCanonica::UNICA, [], dados: ['estoque' => 3, 'precos' => [], 'atributos' => ['SELLER_SKU' => ['value_name' => 'CAD'], 'GTIN' => ['value_name' => '7896553367645']]]),
        ])->comEfetivosDe(['titulos' => [], 'precos' => ['gold_special' => 150.0, 'gold_pro' => 165.0], 'mlbs' => [],
            'promocoes' => ['gold_special' => 125.0, 'gold_pro' => 137.5], 'sem_frete' => ['gold_special' => false, 'gold_pro' => false]]);

        $this->assertSame([], ValidadorRascunho::bloqueiosSemSchema($r));
        $this->assertSame([], self::daValidacao($r));
    }

    public function test_termo_vetado_pelo_ml_no_titulo_e_na_descricao_trava_nos_dois_caminhos(): void
    {
        // O que derrubou o anúncio de teste da #459 (infração de linguagem, 10/10/2026): "criado-mudo".
        $r = self::cadeira([new Alvo('gold_special', 'Mesa Cabeceira Criado Mudo Madeira'), new Alvo('gold_pro', 'Mesa de Cabeceira Madeira Gaveta')], [
            new Variante(ChaveCanonica::UNICA, [], dados: ['estoque' => 3, 'precos' => [], 'atributos' => ['SELLER_SKU' => ['value_name' => 'MC'], 'GTIN' => ['value_name' => '7896553367645']]]),
        ], descricao: 'Lindo CRIADO-MUDO de madeira maciça.')->comEfetivosDe(['titulos' => [], 'precos' => ['gold_special' => 150.0, 'gold_pro' => 165.0], 'mlbs' => [],
            'promocoes' => ['gold_special' => 125.0, 'gold_pro' => 137.5], 'sem_frete' => ['gold_special' => false, 'gold_pro' => false]]);

        $semSchema = self::comoArray(ValidadorRascunho::bloqueiosSemSchema($r));

        $this->assertSame(self::daValidacao($r), $semSchema, 'mesma regra, mesma mensagem, mesmo alvo e mesma ordem');
        $this->assertSame(['V-TIT-05', 'V-DES-05'], array_column($semSchema, 'regra'));
        $this->assertSame([Problema::BLOQUEIO, Problema::BLOQUEIO], array_column($semSchema, 'severidade'));
        $this->assertSame('O título do Clássico tem «criado-mudo», termo que o Mercado Livre não aceita (o anúncio é pausado por linguagem): troque por «mesa de cabeceira».', $semSchema[0]['mensagem']);
        $this->assertSame('gold_special', $semSchema[0]['alvo']['alvo']);
        $this->assertSame(['etapa' => 'E9', 'campo' => 'descricao'], $semSchema[1]['alvo']);
    }

    public function test_preco_invalido_e_titulo_vazio_ficam_para_a_conferencia(): void
    {
        // Sem preço nenhum (V-SAL-02) e sem título no Premium (V-TIT-01): nenhum dos dois é "sem schema".
        $r = self::cadeira([new Alvo('gold_special', 'Cadeira Executiva'), new Alvo('gold_pro', null)], [
            new Variante(ChaveCanonica::UNICA, [], dados: ['estoque' => 3, 'precos' => [], 'atributos' => ['SELLER_SKU' => ['value_name' => 'CAD']]]),
        ])->comEfetivosDe(['titulos' => [], 'precos' => [], 'mlbs' => [], 'promocoes' => [], 'sem_frete' => []]);

        $this->assertSame([], ValidadorRascunho::bloqueiosSemSchema($r));
        $this->assertSame([], self::daValidacao($r), 'o validar() também não dá V-TIT-04 nem V-SAL-08 aqui');
    }
}
