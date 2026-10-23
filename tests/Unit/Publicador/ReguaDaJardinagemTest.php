<?php

namespace Tests\Unit\Publicador;

use App\Models\PubJardinagem;
use App\Support\Publicador\ReguaDaJardinagem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A árvore de Jardinagem do MAG (Turma 8, 27/11/2025) como regra pura — "fazer que nem a MAG" (23/10/2026).
 * As fronteiras são a parte que dá briga: "< 100" × "> 100" visitas e a conversão de 2,00%.
 */
class ReguaDaJardinagemTest extends TestCase
{
    /** @return array<string, array{int, int, string}> visitas, vendas, ramo */
    public static function casos(): array
    {
        return [
            'nenhuma visita, nenhuma venda' => [0, 0, PubJardinagem::SEM_TRACAO],
            '99 visitas sem venda: ninguém acha o anúncio' => [99, 0, PubJardinagem::SEM_TRACAO],
            'exatamente 100 visitas sem venda: já é achado' => [100, 0, PubJardinagem::VISITA_SEM_VENDA],
            'muita visita sem venda' => [2500, 0, PubJardinagem::VISITA_SEM_VENDA],
            '1 venda em 100 visitas = 1%' => [100, 1, PubJardinagem::CONVERSAO_BAIXA],
            '1,99% é baixa' => [10050, 200, PubJardinagem::CONVERSAO_BAIXA],
            'exatamente 2% é boa' => [100, 2, PubJardinagem::CONVERSAO_BOA],
            '1,995% arredonda para 2,00% e é boa (a tela mostra 2,00%)' => [20050, 400, PubJardinagem::CONVERSAO_BOA],
            'vendeu com pouca visita: conversão alta' => [10, 3, PubJardinagem::CONVERSAO_BOA],
            'vendeu sem visita contada: vendeu, ramo bom' => [0, 2, PubJardinagem::CONVERSAO_BOA],
        ];
    }

    #[DataProvider('casos')]
    public function test_cada_ramo_da_arvore(int $visitas, int $vendas, string $ramo): void
    {
        $this->assertSame($ramo, (new ReguaDaJardinagem())->diagnosticar($visitas, $vendas));
    }

    public function test_cortes_configuraveis_mudam_a_fronteira(): void
    {
        $regua = new ReguaDaJardinagem(corteVisitas: 50, corteConversao: 3.0);

        $this->assertSame(PubJardinagem::VISITA_SEM_VENDA, $regua->diagnosticar(50, 0));
        $this->assertSame(PubJardinagem::CONVERSAO_BAIXA, $regua->diagnosticar(100, 2));
        $this->assertSame(PubJardinagem::CONVERSAO_BOA, $regua->diagnosticar(100, 3));
    }

    public function test_conversao_com_duas_casas_e_nula_sem_visita(): void
    {
        $regua = new ReguaDaJardinagem();

        $this->assertSame(2.5, $regua->conversao(200, 5));
        $this->assertSame(0.33, $regua->conversao(300, 1));
        $this->assertNull($regua->conversao(0, 4));
    }

    public function test_textos_da_tela_trazem_os_cortes_em_vigor(): void
    {
        $textos = (new ReguaDaJardinagem(corteVisitas: 1000, corteConversao: 2.5))->descricoes();

        $this->assertSame('Menos de 1.000 visitas e nenhuma venda', $textos[PubJardinagem::SEM_TRACAO]['regra']);
        $this->assertSame('Vende, com conversão abaixo de 2,5%', $textos[PubJardinagem::CONVERSAO_BAIXA]['regra']);
        $this->assertSame('Excluir e criar nova oferta', $textos[PubJardinagem::SEM_TRACAO]['acao']);
        $this->assertSame('2%', ReguaDaJardinagem::porcentagem(2.0));
        $this->assertSame('1,75%', ReguaDaJardinagem::porcentagem(1.75));
    }

    public function test_todo_ramo_tem_checklist_e_toda_chave_e_aceita_pela_rota(): void
    {
        foreach (PubJardinagem::DIAGNOSTICOS as $d) {
            $this->assertNotEmpty(PubJardinagem::CHECKLISTS[$d], "o ramo {$d} não tem o que fazer");
            foreach (array_keys(PubJardinagem::CHECKLISTS[$d]) as $chave) {
                $this->assertContains($chave, PubJardinagem::CHAVES_DO_CHECKLIST, "a rota recusaria '{$chave}'");
            }
        }
    }
}
