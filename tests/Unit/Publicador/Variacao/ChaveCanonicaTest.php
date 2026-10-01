<?php

namespace Tests\Unit\Publicador\Variacao;

use App\Support\Publicador\Variacao\ChaveCanonica;
use PHPUnit\Framework\TestCase;

/** `05` §3 — a identidade de um valor e de uma combinação. */
class ChaveCanonicaTest extends TestCase
{
    public function test_valor_com_id_e_identificado_pelo_id(): void
    {
        $this->assertSame('id:52049', ChaveCanonica::valor('52049', 'Preto'));
        $this->assertSame('id:52049', ChaveCanonica::valor('52049', 'qualquer grafia'));
    }

    public function test_valor_livre_ignora_caixa_espacos_e_acentos(): void
    {
        $this->assertSame('txt:m', ChaveCanonica::valor(null, 'M'));
        $this->assertSame('txt:m', ChaveCanonica::valor(null, '  m  '));
        $this->assertSame('txt:m', ChaveCanonica::valor('', ' m '));
        $this->assertSame('txt:azul-ceu claro', ChaveCanonica::valor(null, ' Azul-Céu   Claro '));
    }

    public function test_combinacao_ordena_por_atributo_e_nao_pela_posicao_visual(): void
    {
        $a = ChaveCanonica::combinacao(['SIZE' => 'txt:m', 'COLOR' => 'id:52049']);
        $b = ChaveCanonica::combinacao(['COLOR' => 'id:52049', 'SIZE' => 'txt:m']);

        $this->assertSame('COLOR=id:52049|SIZE=txt:m', $a);
        $this->assertSame($a, $b);
    }

    public function test_eixo_customizado_entra_como_til_custom_e_vai_para_o_fim(): void
    {
        $this->assertSame(
            'COLOR=id:1|SIZE=txt:p|~custom=txt:lisa',
            ChaveCanonica::combinacao([ChaveCanonica::EIXO_CUSTOM => 'txt:lisa', 'SIZE' => 'txt:p', 'COLOR' => 'id:1']),
        );
    }

    public function test_produto_sem_eixos_e_a_variante_unica(): void
    {
        $this->assertSame('__single__', ChaveCanonica::combinacao([]));
        $this->assertSame(ChaveCanonica::UNICA, ChaveCanonica::combinacao([]));
    }

    public function test_hash_da_chave_cabe_no_indice(): void
    {
        $this->assertSame(64, strlen(ChaveCanonica::hash('COLOR=id:1|SIZE=txt:p')));
        $this->assertNotSame(ChaveCanonica::hash('COLOR=id:1'), ChaveCanonica::hash('COLOR=id:2'));
    }
}
