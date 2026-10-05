<?php

namespace Tests\Unit\Publicador\Alavancas;

use App\Services\Publicador\Alavancas\Acoes\AcaoAlavanca;
use App\Services\Publicador\Alavancas\RegistroDeAcoes;
use App\Support\Publicador\RegraViolada;
use PHPUnit\Framework\TestCase;

/** 166-11: o registro das 15 ações bate com o diretório e com o `nome()` de cada classe. */
class RegistroDeAcoesTest extends TestCase
{
    public function test_tem_exatamente_as_quinze_acoes(): void
    {
        $this->assertCount(15, RegistroDeAcoes::NOMES);
    }

    public function test_cada_chave_e_o_nome_da_classe_e_cabe_no_historico(): void
    {
        foreach (RegistroDeAcoes::NOMES as $chave => $classe) {
            $this->assertTrue(is_subclass_of($classe, AcaoAlavanca::class), $classe);
            $this->assertSame($chave, $classe::nome());
            $this->assertLessThanOrEqual(32, strlen($chave));
        }
        $this->assertCount(15, array_unique(array_map(fn ($c) => $c::nome(), RegistroDeAcoes::NOMES)));
    }

    public function test_toda_acao_concreta_do_diretorio_esta_registrada(): void
    {
        $registradas = array_values(RegistroDeAcoes::NOMES);
        $arquivos = glob(dirname(__DIR__, 4).'/app/Services/Publicador/Alavancas/Acoes/*.php');
        $this->assertNotEmpty($arquivos);

        foreach ($arquivos as $arquivo) {
            $base = basename($arquivo, '.php');
            if ($base === 'AcaoAlavanca') {
                continue;
            }
            $classe = (new \ReflectionClass(AcaoAlavanca::class))->getNamespaceName().chr(92).$base;
            $this->assertContains($classe, $registradas, "{$base} não está no RegistroDeAcoes");
        }
    }

    public function test_acao_desconhecida_lanca_alav_acao(): void
    {
        try {
            RegistroDeAcoes::classe('convite.inventar');
            $this->fail('devia lançar');
        } catch (RegraViolada $e) {
            $this->assertSame('ALAV-ACAO', $e->regra);
        }
    }
}
