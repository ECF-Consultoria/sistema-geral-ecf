<?php

namespace Tests\Unit\Publicador\Erros;

use App\Support\Publicador\Erros\MapeadorErrosMl;
use App\Support\Publicador\Erros\RespostaMl;
use App\Support\Publicador\Payload\ItemPlano;
use App\Support\Publicador\Schema\ClassificadorAtributos;
use App\Support\Publicador\Schema\ContextoClassificacao;
use App\Support\Publicador\Schema\SchemaClassificado;
use App\Support\Publicador\Validacao\Problema;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Publicador\Concerns\CarregaSchemas;

/**
 * `09` §3 — cada causa REAL do `/items/validate` (sondagem de 01/10,
 * `tests/fixtures-ml/sondagem/conta*`) cai no campo certo da tela, com
 * mensagem em português e a causa crua guardada.
 */
class MapeadorErrosMlTest extends TestCase
{
    use CarregaSchemas;

    private static array $dicionario;

    public static function setUpBeforeClass(): void
    {
        self::$dicionario = require dirname(__DIR__, 4).'/config/publicador_erros.php';
    }

    private static function cadeira(): SchemaClassificado
    {
        return (new ClassificadorAtributos())->classificar(self::schema(self::CADEIRA), new ContextoClassificacao());
    }

    /** O payload que a sonda enviou e a resposta que voltou. */
    private static function fixture(string $conta, string $categoria, string $arquivo): array
    {
        $d = json_decode(file_get_contents(dirname(__DIR__, 3)."/fixtures-ml/sondagem/{$conta}/categorias/{$categoria}/{$arquivo}"), true);

        return [$d['requisicao']['corpo'], new RespostaMl($d['status'], $d['resposta'])];
    }

    private static function item(array $payload, int $indice = 0, string $variante = 'COLOR=id:52049'): ItemPlano
    {
        return new ItemPlano($indice, $payload['listing_type_id'] ?? 'gold_special', $variante, 'Preto', $payload, []);
    }

    /** @return list<Problema> */
    private static function mapear(string $arquivo, string $conta = 'conta-multideposito', string $categoria = self::CADEIRA, ?SchemaClassificado $schema = null): array
    {
        [$payload, $resposta] = self::fixture($conta, $categoria, $arquivo);

        return array_map(fn ($c) => MapeadorErrosMl::problema($c, self::item($payload), $schema ?? self::cadeira(), self::$dicionario), $resposta->causas);
    }

    public function test_gtin_pelo_indice_do_payload_enviado_vai_para_a_variante(): void
    {
        // references: item.attributes[16].values — o 17º atributo DO PAYLOAD (N-17).
        [$p] = self::mapear('validate_gtin_checksum_errado.json');

        $this->assertSame('V-VAR-14', $p->regra);
        $this->assertTrue($p->bloqueia());
        $this->assertSame('L3', $p->camada);
        $this->assertSame(['etapa' => 'E5', 'atributo' => 'GTIN', 'variante' => 'COLOR=id:52049', 'itens' => [0]], $p->alvo);
        $this->assertSame('Código universal (GTIN) inválido: confira os dígitos.', $p->mensagem);
        $this->assertSame(7711, $p->mlCausa['cause_id'], 'a causa crua vai junto, para mostrar recolhida');
    }

    public function test_titulo_curto_vira_v_tit_03_no_tipo_do_item(): void
    {
        [$p] = self::mapear('validate_nome_curto.json');

        $this->assertSame('V-TIT-03', $p->regra);
        $this->assertSame(['etapa' => 'E7', 'campo' => 'titulo', 'listing_type' => 'gold_special', 'itens' => [0]], $p->alvo);
    }

    public function test_nome_de_atributo_entre_aspas_acha_o_atributo(): void
    {
        // Nesta conta também vem o aviso 469 (multidepósito): fica de fora aqui.
        $problemas = array_values(array_filter(self::mapear('validate_numero_sem_unidade.json'), fn ($p) => in_array($p->mlCausa['cause_id'], [3708, 344], true)));
        $this->assertCount(2, $problemas);

        // 3708 cita "Altura do encosto" (nome); 344 cita BACKREST_HEIGHT (id). Os dois caem no mesmo campo.
        foreach ($problemas as $p) {
            $this->assertSame('BACKREST_HEIGHT', $p->alvo['atributo'], $p->mlCausa['code']);
            $this->assertSame('E8', $p->alvo['etapa'], 'a ficha técnica, onde a cadeira mostra a altura do encosto');
        }
        $this->assertStringStartsWith('«Altura do encosto»', $problemas[0]->mensagem);
    }

    public function test_embalagem_lista_os_quatro_campos_e_vai_para_condicoes_de_venda(): void
    {
        [$p] = self::mapear('validate_sem_embalagem.json');

        $this->assertSame('V-SAL-06', $p->regra);
        $this->assertSame('E10', $p->alvo['etapa']);
        $this->assertSame('embalagem', $p->alvo['campo']);
    }

    public function test_avisos_nao_bloqueiam(): void
    {
        $problemas = self::mapear('validate_base_up.json');

        $this->assertNotEmpty($problemas);
        foreach ($problemas as $p) {
            $this->assertSame(Problema::AVISO, $p->severidade);
        }
        $porCodigo = array_column(array_map(fn ($p) => [$p->mlCausa['cause_id'], $p], $problemas), 1, 0);
        $this->assertSame(['etapa' => 'E5', 'campo' => 'estoque', 'variante' => 'COLOR=id:52049', 'itens' => [0]], $porCodigo[469]->alvo);
        $this->assertSame('envio', $porCodigo[350]->alvo['campo']);
    }

    public function test_preco_minimo_cita_o_valor(): void
    {
        $camiseta = (new ClassificadorAtributos())->classificar(self::schema(self::CAMISETA), new ContextoClassificacao());
        $problemas = self::mapear('validate_preco_abaixo_minimo.json', 'conta', self::CAMISETA, $camiseta);
        $p = array_values(array_filter($problemas, fn ($p) => $p->mlCausa['cause_id'] === 109))[0];

        // O ML aponta `item.category_id` no 109; o código é que diz que é preço.
        $this->assertSame('V-SAL-03', $p->regra);
        $this->assertSame('E10', $p->alvo['etapa']);
        $this->assertSame('preco', $p->alvo['campo']);
        $this->assertSame('Preço abaixo do mínimo desta categoria (R$ 8,00).', $p->mensagem);
    }

    public function test_sem_fotos_vai_para_imagens(): void
    {
        [$p] = self::mapear('validate_sem_fotos.json');

        $this->assertSame('V-IMG-04', $p->regra);
        $this->assertSame('E6', $p->alvo['etapa']);
    }

    public function test_valor_fora_da_lista_cita_o_atributo_pelo_nome(): void
    {
        [$p] = self::mapear('validate_valor_livre_em_lista.json');

        $this->assertSame('REQUIRES_ASSEMBLY', $p->alvo['atributo']);
        $this->assertStringContainsString('escolha um valor da lista', $p->mensagem);
    }

    public function test_palavra_solta_nao_vira_campo_e_codigo_desconhecido_mostra_o_original(): void
    {
        $p = MapeadorErrosMl::problema(
            ['cause_id' => 99999, 'code' => 'item.something.new', 'type' => 'error', 'message' => 'Some NEW rule about VALUES', 'references' => ['item.attributes']],
            null, self::cadeira(), self::$dicionario,
        );

        $this->assertSame('V-REM-01', $p->regra);
        $this->assertSame(['etapa' => 'OUTROS'], $p->alvo);
        $this->assertSame('Some NEW rule about VALUES', $p->mensagem);
    }

    public function test_erro_de_corpo_sem_cause(): void
    {
        [$payload, $resposta] = self::fixture('conta-multideposito', self::CADEIRA, 'validate_base_title_e_family.json');
        [$p] = array_map(fn ($c) => MapeadorErrosMl::problema($c, self::item($payload), self::cadeira(), self::$dicionario), $resposta->causas);

        $this->assertTrue($p->bloqueia());
        $this->assertSame('O Mercado Livre recusou campos do envio (title).', $p->mensagem);
    }

    /**
     * 10/10/2026, produto 41 da #459: o aviso chegava cru ("User/Catalog has not intersected me2 logistics") e
     * ninguém entendia. A causa é a REAL da conferência; cai na Forma de envio e diz o que acontece ao publicar.
     */
    public function test_aviso_de_perda_do_mercado_envios_vem_em_portugues_e_aponta_a_forma_de_envio(): void
    {
        $causa = ['cause_id' => 4057, 'code' => 'shipping.lost_me2_by_intersected_logistics', 'type' => 'warning',
            'message' => 'User/Catalog has not intersected me2 logistics', 'references' => ['catalog.shipping_preferences.modes']];

        $p = MapeadorErrosMl::problema($causa, new ItemPlano(0, 'gold_special', 'COLOR=id:1', 'Preto', ['attributes' => []], []), self::cadeira(), self::$dicionario);

        $this->assertSame(Problema::AVISO, $p->severidade, 'o Mercado Livre publica mesmo assim');
        $this->assertSame(['etapa' => 'E10', 'campo' => 'envio', 'itens' => [0]], $p->alvo);
        $this->assertStringContainsString('vai tirar o Mercado Envios deste anúncio', $p->mensagem);
        $this->assertStringContainsString('A combinar com o comprador', $p->mensagem);
        $this->assertStringNotContainsString('intersected', $p->mensagem);
        $this->assertSame('shipping.lost_me2_by_intersected_logistics', $p->mlCausa['code'], 'a causa crua continua guardada');
        $this->assertFalse(MapeadorErrosMl::ehRuido($causa), 'não é o aviso de toda conferência (4053): este muda o anúncio');
    }

    /**
     * A conferência do produto 41 foi gravada ANTES de o 4057 entrar no dicionário, e ele já está publicado:
     * não há "conferir de novo". A tela traduz na leitura; o que depende do item fica como foi gravado.
     */
    public function test_conferencia_gravada_com_a_mensagem_crua_aparece_com_a_traducao_de_hoje(): void
    {
        $gravado = ['regra' => 'V-REM-01', 'severidade' => 'WARNING', 'camada' => 'L3', 'alvo' => ['campo' => 'envio', 'etapa' => 'E10', 'itens' => [0, 1]],
            'mensagem' => 'User/Catalog has not intersected me2 logistics',
            'ml_causa' => ['cause_id' => 4057, 'code' => 'shipping.lost_me2_by_intersected_logistics', 'type' => 'warning', 'message' => 'User/Catalog has not intersected me2 logistics']];

        $naTela = MapeadorErrosMl::comTraducaoDeHoje($gravado, self::$dicionario);

        $this->assertStringContainsString('vai tirar o Mercado Envios deste anúncio', $naTela['mensagem']);
        $this->assertSame($gravado['alvo'], $naTela['alvo']);
        $this->assertSame($gravado['ml_causa'], $naTela['ml_causa'], 'a causa crua continua junto');

        // Já estava traduzido (ou foi escrito por nós): não mexe.
        $traduzido = ['mensagem' => 'Adicione pelo menos uma foto.', 'ml_causa' => ['cause_id' => 173, 'message' => 'Item pictures are mandatory']];
        $this->assertSame($traduzido, MapeadorErrosMl::comTraducaoDeHoje($traduzido, self::$dicionario));
        // Tradução que cita atributos precisa do item: sem ele, fica a mensagem gravada.
        $comAtributos = ['mensagem' => 'The attributes [BRAND] are required', 'ml_causa' => ['cause_id' => 147, 'message' => 'The attributes [BRAND] are required']];
        $this->assertSame($comAtributos, MapeadorErrosMl::comTraducaoDeHoje($comAtributos, self::$dicionario));
        // Código que o dicionário não conhece, e problema local (sem causa do ML).
        $desconhecido = ['mensagem' => 'Something new', 'ml_causa' => ['cause_id' => 999999, 'code' => 'x.y', 'message' => 'Something new']];
        $this->assertSame($desconhecido, MapeadorErrosMl::comTraducaoDeHoje($desconhecido, self::$dicionario));
        $local = ['mensagem' => 'Preencha o título.', 'ml_causa' => null];
        $this->assertSame($local, MapeadorErrosMl::comTraducaoDeHoje($local, self::$dicionario));
    }

    public function test_o_mesmo_erro_em_varios_itens_vira_um_so(): void
    {
        $causa = ['cause_id' => 147, 'code' => 'item.attributes.missing_required', 'type' => 'error', 'message' => 'The attributes [BRAND] are required', 'references' => ['item.attributes']];
        $gtin = ['cause_id' => 7711, 'code' => 'item.attribute.product_identifier.invalid_format', 'type' => 'error', 'message' => 'Product Identifier [GTIN] contains values with invalid format: [1]', 'references' => ['item.attributes']];

        $problemas = [];
        foreach ([[0, 'COLOR=id:1'], [1, 'COLOR=id:2'], [2, 'COLOR=id:1'], [3, 'COLOR=id:2']] as [$i, $v]) {
            $item = new ItemPlano($i, $i < 2 ? 'gold_special' : 'gold_pro', $v, $v, ['attributes' => []], []);
            $problemas[] = MapeadorErrosMl::problema($causa, $item, self::cadeira(), self::$dicionario);
            if ($v === 'COLOR=id:2') {
                $problemas[] = MapeadorErrosMl::problema($gtin, $item, self::cadeira(), self::$dicionario);
            }
        }

        $agrupados = MapeadorErrosMl::agrupar($problemas);

        $this->assertCount(2, $agrupados);
        $this->assertSame(['etapa' => 'E3', 'atributo' => 'BRAND', 'itens' => [0, 1, 2, 3]], $agrupados[0]->alvo);
        $this->assertSame('Preencha os atributos obrigatórios: «Marca».', $agrupados[0]->mensagem);
        $this->assertSame(['etapa' => 'E5', 'atributo' => 'GTIN', 'variante' => 'COLOR=id:2', 'itens' => [1, 3]], $agrupados[1]->alvo);
    }
}
