<?php

namespace Tests\Unit\Publicador;

use App\Support\Publicador\FatosDoProduto;
use App\Support\Publicador\RegrasDoTitulo;
use PHPUnit\Framework\TestCase;

/**
 * As regras determinísticas do título (09/10/2026): sem marca, sem número de especificação, e dois
 * títulos nunca iguais. Puro, sem banco.
 */
class RegrasDoTituloTest extends TestCase
{
    public function test_caso_do_usuario_sai_ecf_e_o_peso_suportado(): void
    {
        $this->assertSame('Puff Sala Redondo Banqueta Moderno', RegrasDoTitulo::limpar('Puff Sala Redondo Banqueta Moderno ECF 130 kg', [], 'Puff Redondo'));
        $this->assertSame('Puff Sala Redondo Banqueta Moderno', RegrasDoTitulo::limpar('Puff Sala Redondo Banqueta Moderno Ecf 130kg', [], 'Puff Redondo'));
    }

    public function test_peso_capacidade_potencia_e_tensao_saem_sempre(): void
    {
        $this->assertSame('Liquidificador Turbo', RegrasDoTitulo::limpar('Liquidificador 1000W 110/220v 2 L Turbo', [], 'Liquidificador 1000W'));
        $this->assertSame('Garrafa Térmica Inox', RegrasDoTitulo::limpar('Garrafa Térmica 1,5 l Inox 500ml', [], 'Garrafa Térmica'));
        $this->assertSame('Puff Banqueta', RegrasDoTitulo::limpar('Puff Banqueta Suporta 130 kg', [], 'Puff'), 'a ligação que sobrou na ponta sai');
        $this->assertSame('Celular 5G Tela Grande', RegrasDoTitulo::limpar('Celular 5G Tela Grande', [], 'Celular'), '5G não é grama');
    }

    public function test_medida_fica_quando_faz_parte_do_nome_ou_dos_termos(): void
    {
        $this->assertSame('Mesa Escritório 160x90 Moderna', RegrasDoTitulo::limpar('Mesa Escritório 160x90 Moderna', [], 'Mesa Escritório 160x90'));
        $this->assertSame('Mesa Escritório 160 x 90 cm', RegrasDoTitulo::limpar('Mesa Escritório 160 x 90 cm', [], 'Mesa Escritório 160x90'));
        $this->assertSame('Mesa Escritório Moderna', RegrasDoTitulo::limpar('Mesa Escritório 120x60 Moderna', [], 'Mesa Escritório 160x90'), 'medida que o produto não tem no nome sai');
        $this->assertSame('Sofá 3 Lugares Retrátil', RegrasDoTitulo::limpar('Sofá 3 Lugares Retrátil', [], 'Sofá Retrátil sofa 3 lugares'), 'veio dos termos de busca');
        $this->assertSame('Sofá Retrátil', RegrasDoTitulo::limpar('Sofá 3 Lugares Retrátil', [], 'Sofá Retrátil'));
    }

    public function test_marca_sai_como_frase_inteira(): void
    {
        $this->assertSame('Puff Redondo Sala', RegrasDoTitulo::limpar('Puff Redondo Lia Decor Sala', ['Lia Decor'], 'Puff Redondo'));
        $this->assertSame('Puff Redondo Lia Sala', RegrasDoTitulo::limpar('Puff Redondo Lia Sala', ['Lia Decor'], 'Puff Redondo'), 'só a frase toda é a marca');
        $this->assertSame('Cadeira Escritório Giratória', RegrasDoTitulo::limpar('Cadeira Escritório ECF® Giratória', [], 'Cadeira'));
        $this->assertSame('Mesa Moderna', RegrasDoTitulo::limpar('Mesa Móveis Brasília Moderna', ['Moveis Brasilia'], 'Mesa'), 'sem acento nem caixa');
    }

    public function test_mesmo_titulo_ignora_caixa_acento_e_plural_mas_nao_a_ordem(): void
    {
        $this->assertTrue(RegrasDoTitulo::mesmo('Puffs Sala Redondo', 'puff salá redondos'));
        $this->assertFalse(RegrasDoTitulo::mesmo('Puff Sala Redondo', 'Puff Redondo Sala'));
        $this->assertFalse(RegrasDoTitulo::mesmo('Puff Sala', 'Puff Sala Redondo'));
        $this->assertFalse(RegrasDoTitulo::mesmo('', ''), 'vazio não é título');
    }

    public function test_diferenciar_troca_a_ordem_das_duas_ultimas_sem_mexer_no_produto(): void
    {
        $this->assertSame('Puff Sala Redondo Moderno Banqueta', RegrasDoTitulo::diferenciar('Puff Sala Redondo Banqueta Moderno', 'puff sala redondo banqueta moderno', [], 60));
        $this->assertSame('Puff Sala Redondo', RegrasDoTitulo::diferenciar('Puff Sala Redondo', 'Puff Redondo Sala', [], 60), 'já diferente: não mexe');
    }

    public function test_diferenciar_com_duas_palavras_acrescenta_termo_de_busca_que_cabe(): void
    {
        $this->assertSame('Puff Redondo Banqueta', RegrasDoTitulo::diferenciar('Puff Redondo', 'puff redondo', ['redondo', 'banqueta'], 60));
        $this->assertSame('Puff Banqueta', RegrasDoTitulo::diferenciar('Puff Redondo', 'puff redondo', ['banqueta'], 13), 'não cabe somar: troca a última');
        $this->assertSame('Puff Redondo', RegrasDoTitulo::diferenciar('Puff Redondo', 'puff redondo', ['banqueta'], 12), 'sem saída devolve como veio');
    }

    public function test_sem_repetir_nunca_entrega_dois_iguais(): void
    {
        $this->assertSame(
            ['gold_special' => 'Puff Sala Redondo', 'gold_pro' => 'Puff Redondo Sala'],
            RegrasDoTitulo::semRepetir(['gold_special' => 'Puff Sala Redondo', 'gold_pro' => 'Puff Sala Redondo'], [], [], 60),
        );
        $this->assertSame(
            ['gold_special' => 'Puff Redondo Sala'],
            RegrasDoTitulo::semRepetir(['gold_special' => 'Puff Sala Redondo'], ['gold_pro' => 'puff sala redondo'], [], 60),
            'o da equipe fica; o da IA muda',
        );
        $this->assertSame(['gold_pro' => ''], RegrasDoTitulo::semRepetir(['gold_pro' => 'Puff'], ['Puff'], [], 60), 'sem saída: vazio, não grava');
    }

    public function test_candidatos_so_de_termos_do_produto_sem_cor_numero_marca_nem_o_que_os_fatos_negam(): void
    {
        $fatos = new FatosDoProduto(cores: ['Azul'], produto: 'Puff Redondo');

        $this->assertSame(['sala', 'banqueta', 'quarto'], RegrasDoTitulo::candidatos(
            ['puff sala', 'puff azul', 'puff infantil', 'cadeira gamer', 'puff banqueta 130 kg', 'puff para quarto', 'puff ecf'],
            'Puff Redondo', $fatos, [],
        ));
    }
}
