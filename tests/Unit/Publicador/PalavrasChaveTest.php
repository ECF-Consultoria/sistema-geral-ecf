<?php

namespace Tests\Unit\Publicador;

use App\Services\Publicador\PalavrasChaveService;
use App\Support\Publicador\Erros\MapeadorErrosMl;
use App\Support\Publicador\FatosDoProduto;
use PHPUnit\Framework\TestCase;

/**
 * As partes puras da melhoria de 03/10/2026: o corte do Modelo (120) e do
 * título que a IA devolve, e o aviso 4053 tratado como ruído.
 */
class PalavrasChaveTest extends TestCase
{
    public function test_modelo_normaliza_tira_repetidos_e_corta_no_ultimo_termo_inteiro(): void
    {
        $bruto = 'Cadeira Escritório, cadeira escritorio; Cadeira Home-Office, cadeira giratória, , cadeira presidente';

        $this->assertSame('cadeira escritorio, cadeira home office, cadeira giratoria, cadeira presidente', PalavrasChaveService::ajustarModelo($bruto));
        $this->assertSame('cadeira escritorio, cadeira home office', PalavrasChaveService::ajustarModelo($bruto, 45));
        $this->assertSame('', PalavrasChaveService::ajustarModelo('   '));
    }

    public function test_modelo_nunca_passa_de_120_e_pula_o_termo_que_nao_cabe(): void
    {
        $termos = array_map(fn ($i) => "cadeira modelo numero {$i}", range(1, 20));
        $saida = PalavrasChaveService::ajustarModelo(implode(', ', $termos));
        $this->assertLessThanOrEqual(120, strlen($saida));
        // Termos de 23-24 caracteres: sobra menos que ", termo" — chegou o mais perto possível.
        $this->assertGreaterThan(120 - strlen(', cadeira modelo numero 5'), strlen($saida));

        // Um termo enorme no meio não fecha a lista: os seguintes ainda entram.
        $comGigante = 'cadeira, '.str_repeat('x', 130).', mesa';
        $this->assertSame('cadeira, mesa', PalavrasChaveService::ajustarModelo($comGigante));
    }

    public function test_modelo_descarta_termo_cujas_palavras_ja_estao_todas_no_titulo(): void
    {
        // O exemplo do usuário (08/10/2026), literal, mais três termos que trazem palavra nova.
        $titulo = 'Puff Redondo Sala Quarto Enchimento Fofao Banqueta Descanso';
        $bruto = 'puff azul, puff redondo, puff sala, puff para sala, puff azul marinho, puff redondo de chao, puff com enchimento, puff, '
            .'puff azul marinho, puff redondo de chao, puff para quarto infantil';

        $saida = PalavrasChaveService::ajustarModelo($bruto, 120, $titulo);

        $this->assertSame('puff azul, puff azul marinho, puff redondo de chao, puff para quarto infantil', $saida);
        $this->assertLessThanOrEqual(120, strlen($saida));
        foreach (explode(', ', $saida) as $termo) {
            $this->assertTrue(PalavrasChaveService::trazPalavraNova($termo, PalavrasChaveService::palavrasDoTitulo($titulo)), $termo);
        }
        // Sem título, nada é filtrado (o Modelo antes do título continua funcionando).
        $this->assertStringStartsWith('puff azul, puff redondo, puff sala', PalavrasChaveService::ajustarModelo($bruto));
    }

    public function test_palavra_nova_ignora_acento_caixa_plural_e_palavra_de_ligacao(): void
    {
        $doTitulo = PalavrasChaveService::palavrasDoTitulo('Mesas Jantar Cores Madeira / Cadeira Estofada');

        $this->assertFalse(PalavrasChaveService::trazPalavraNova('mesa de jantar', $doTitulo), 'mesas × mesa e "de" é ligação');
        $this->assertFalse(PalavrasChaveService::trazPalavraNova('cor da madeira', $doTitulo), 'cores × cor');
        $this->assertFalse(PalavrasChaveService::trazPalavraNova('cadeiras estofadas', $doTitulo), 'o plural do título, das duas partes');
        $this->assertFalse(PalavrasChaveService::trazPalavraNova('mésa para a jantár', $doTitulo));
        $this->assertTrue(PalavrasChaveService::trazPalavraNova('mesa redonda', $doTitulo));
        $this->assertTrue(PalavrasChaveService::trazPalavraNova('mesa 4 lugares', $doTitulo), 'número também é termo novo');
        $this->assertFalse(PalavrasChaveService::trazPalavraNova('de para com', $doTitulo), 'só ligação não é palavra nova');
        $this->assertSame([], PalavrasChaveService::palavrasDoTitulo('  '));
    }

    // ═══ Fatos do produto: cor, público e tamanho (09/10/2026) ════════════════

    private const TITULO_PUFF = 'Puff Redondo Sala Quarto Enchimento Fofao Banqueta Descanso';

    private const BRUTO_PUFF = 'puff azul, puff gigante, puff redondo de chao, puff colorido, puff infantil, puff rosa, puff azul marinho, puff fofao';

    public function test_caso_do_usuario_puff_azul_sem_publico_nem_tamanho_tira_o_que_o_produto_nao_tem(): void
    {
        // O caso EXATO de produção (09/10): uma variante Azul, ficha sem público e sem tamanho.
        $fatos = new FatosDoProduto(cores: ['Azul'], ficha: [['Formato', 'Redondo']], produto: 'Puff Redondo', contexto: self::TITULO_PUFF);

        $r = PalavrasChaveService::filtrarModelo(self::BRUTO_PUFF, 120, self::TITULO_PUFF, $fatos);

        $this->assertSame('puff azul, puff redondo de chao', $r['valor']);
        $this->assertSame([
            ['termo' => 'puff gigante', 'motivo' => FatosDoProduto::MOTIVO_TAMANHO],
            ['termo' => 'puff colorido', 'motivo' => FatosDoProduto::MOTIVO_COR],
            ['termo' => 'puff infantil', 'motivo' => FatosDoProduto::MOTIVO_PUBLICO],
            ['termo' => 'puff rosa', 'motivo' => FatosDoProduto::MOTIVO_COR],
            ['termo' => 'puff azul marinho', 'motivo' => FatosDoProduto::MOTIVO_COR],
        ], $r['descartados']);
        // "puff fofao" sai por repetir o título — não conta como "não condiz".
        $this->assertNotContains('puff fofao', array_column($r['descartados'], 'termo'));
        $this->assertSame($r['valor'], PalavrasChaveService::ajustarModelo(self::BRUTO_PUFF, 120, self::TITULO_PUFF, $fatos));
    }

    public function test_tres_cores_aceitam_as_tres_e_colorido_e_azul_marinho_so_com_marinho_no_anuncio(): void
    {
        $fatos = new FatosDoProduto(cores: ['Azul', 'Rosa', 'Verde-limão']);
        $r = PalavrasChaveService::filtrarModelo('puff azul, puff rosa, puff verde, puff colorido, puff preto, puff azul marinho', 120, '', $fatos);

        $this->assertSame('puff azul, puff rosa, puff verde, puff colorido', $r['valor']);
        $this->assertSame(['puff preto', 'puff azul marinho'], array_column($r['descartados'], 'termo'));

        // A cor do anúncio tem "marinho": aí "azul marinho" é a cor dele, e "azul" também vale.
        $marinho = new FatosDoProduto(cores: ['Azul Marinho']);
        $this->assertSame('puff azul marinho, puff azul', PalavrasChaveService::ajustarModelo('puff azul marinho, puff azul, puff colorido', 120, '', $marinho));
        // Ficha que diz estampado libera "estampado/colorido" mesmo com uma cor.
        $estampa = new FatosDoProduto(cores: ['Azul'], ficha: [['Desenho do tecido', 'Estampa localizada']]);
        $this->assertSame('puff estampado, puff colorido', PalavrasChaveService::ajustarModelo('puff estampado, puff colorido', 120, '', $estampa));
        // Sem cor conhecida, nenhum termo com cor; plural e feminino contam ("pretas").
        $semCor = new FatosDoProduto(ficha: [['Material', 'Courino']]);
        $this->assertSame('cadeira de couro', PalavrasChaveService::ajustarModelo('cadeiras pretas, cadeira de couro, cadeira off white', 120, '', $semCor));
    }

    public function test_publico_e_tamanho_so_entram_quando_os_fatos_confirmam(): void
    {
        $infantil = new FatosDoProduto(cores: ['Azul'], publico: [['Idade', 'Crianças']]);
        $this->assertSame('puff infantil, puff para crianca', PalavrasChaveService::ajustarModelo('puff infantil, puff para crianca, puff adulto, puff bebe', 120, '', $infantil));

        // "Bebês" confirma "infantil"; "Sem gênero infantil" também.
        $bebe = new FatosDoProduto(publico: [['Gênero', 'Bebês']]);
        $this->assertSame('body bebe, body infantil', PalavrasChaveService::ajustarModelo('body bebe, body infantil, body adulto', 120, '', $bebe));

        // Tamanho: a ficha com "Grande" confirma "grande"; a medida em gramas NÃO confirma "g".
        $grande = new FatosDoProduto(ficha: [['Tamanho', 'Grande']], medidas: [['Peso', '500 g']]);
        $this->assertSame('puff grande', PalavrasChaveService::ajustarModelo('puff grande, puff gigante, puff g, puff mini', 120, '', $grande));

        // O nome do produto confirma: "Mini Puff" aceita "mini"; "Cadeira Gamer" aceita "gamer".
        $this->assertSame('mini puff redondo', PalavrasChaveService::ajustarModelo('mini puff redondo', 120, '', new FatosDoProduto(produto: 'Mini Puff')));
        $this->assertSame('cadeira gamer reclinavel', PalavrasChaveService::ajustarModelo('cadeira gamer reclinavel, cadeira infantil', 120, '', new FatosDoProduto(produto: 'Cadeira Gamer')));
        // Palavra de cor que é o próprio produto não é cor: "Taça Vinho".
        $this->assertSame('taca vinho tinto', PalavrasChaveService::ajustarModelo('taca vinho tinto', 120, '', new FatosDoProduto(produto: 'Taça Vinho')));
    }

    public function test_bloco_de_fatos_do_prompt_traz_cores_ficha_medidas_e_publico(): void
    {
        $p = (new FatosDoProduto(cores: ['Azul'], ficha: [['Material do estofamento', 'Courino']], medidas: [['Diâmetro', '50 cm']]))->paraPrompt();
        $this->assertStringContainsString('- Cores do anúncio: Azul (uma cor só)', $p);
        $this->assertStringContainsString('Material do estofamento: Courino', $p);
        $this->assertStringContainsString('Diâmetro: 50 cm', $p);
        $this->assertStringContainsString('Público/idade: não informado (não cite público)', $p);

        $vazio = (new FatosDoProduto)->paraPrompt();
        $this->assertStringContainsString('não informadas (não use nenhum termo com cor)', $vazio);
        $this->assertStringContainsString('Medidas: não informadas (não cite tamanho)', $vazio);
    }

    public function test_titulo_tira_caracteres_especiais_e_corta_na_palavra(): void
    {
        $this->assertSame('Cadeira Escritório Giratória Ergonômica', PalavrasChaveService::ajustarTitulo('Cadeira Escritório - Giratória (Ergonômica)!', 60));
        $this->assertSame('Cadeira Escritório', PalavrasChaveService::ajustarTitulo('Cadeira Escritório Giratória', 20));
        $this->assertSame('Supercalifragilisti', PalavrasChaveService::ajustarTitulo('Supercalifragilisticexpialidocious', 19));
    }

    public function test_aviso_4053_e_ruido_mas_o_erro_nao(): void
    {
        $this->assertTrue(MapeadorErrosMl::ehRuido(['cause_id' => 4053, 'code' => 'shipping.lost_me1_by_user', 'type' => 'warning']));
        $this->assertTrue(MapeadorErrosMl::ehRuido(['code' => 'shipping.lost_me1_by_user', 'type' => 'warning']));
        // Se um dia o ML voltar a mandar como erro (bloqueava em 10/07, N-16), aparece.
        $this->assertFalse(MapeadorErrosMl::ehRuido(['cause_id' => 4053, 'code' => 'shipping.lost_me1_by_user', 'type' => 'error']));
        $this->assertFalse(MapeadorErrosMl::ehRuido(['cause_id' => 350, 'code' => 'item.shipping.mandatory_free_shipping', 'type' => 'warning']));
        $this->assertFalse(MapeadorErrosMl::ehRuido([]));
    }
}
