<?php

namespace Tests\Unit\Phase165;

use App\Services\Publicador\Criativos\ContextoCriativoDoPublicador as Ctx;
use App\Support\Publicador\Imagem\ResolvedorGruposImagem as R;
use App\Support\Publicador\RascunhoSnapshot;
use App\Support\Publicador\Variacao\ChaveCanonica;
use App\Support\Publicador\Variacao\Eixo;
use App\Support\Publicador\Variacao\GeradorCombinacoes;
use App\Support\Publicador\Variacao\ValorEixo;
use App\Support\Publicador\Variacao\Variante;
use PHPUnit\Framework\TestCase;

/**
 * Fase 165, Plano 02, Task 1 — `ContextoCriativoDoPublicador::atributosDoGrupo()`
 * e `atributosVerificados()`: funções puras, sem banco (molde de
 * `ResolvedorGruposImagemTest`). D-14: o grupo pedido injeta só os eixos que
 * definem a foto (ou todos, com "fotos por variante" sem eixo que a defina);
 * a galeria geral nunca injeta nada.
 */
class ContextoDaVariacaoTest extends TestCase
{
    private static function variantesDe(array $eixos): array
    {
        return array_map(fn ($c) => Variante::daCombinacao($c), GeradorCombinacoes::gerar($eixos));
    }

    public function test_grupo_geral_nunca_injeta_nada(): void
    {
        $cor = new Eixo('COLOR', 'Cor', 0, definesPicture: true, valores: [
            new ValorEixo('52028', 'Azul'), new ValorEixo('52049', 'Preto'),
        ]);
        $snapshot = new RascunhoSnapshot('MLB1', eixos: [$cor], variantes: self::variantesDe([$cor]));

        $this->assertSame([], Ctx::atributosDoGrupo($snapshot, R::GERAL));
    }

    public function test_grupo_da_cor_traz_so_a_cor_mesmo_com_eixo_tamanho(): void
    {
        $cor = new Eixo('COLOR', 'Cor', 0, definesPicture: true, valores: [
            new ValorEixo('52028', 'Azul'), new ValorEixo('52049', 'Preto'),
        ]);
        $tamanho = new Eixo('SIZE', 'Tamanho', 1, valores: [
            new ValorEixo(null, 'P'), new ValorEixo(null, 'M'),
        ]);
        $eixos = [$cor, $tamanho];
        $snapshot = new RascunhoSnapshot('MLB1', eixos: $eixos, variantes: self::variantesDe($eixos));

        $grupo = ChaveCanonica::combinacao(['COLOR' => 'id:52028']);

        $this->assertSame(['COLOR' => 'Azul'], Ctx::atributosDoGrupo($snapshot, $grupo));
    }

    public function test_fotos_por_variante_sem_eixo_que_defina_foto_usa_todos_os_eixos(): void
    {
        $cor = new Eixo('COLOR', 'Cor', 0, valores: [
            new ValorEixo('52028', 'Azul'), new ValorEixo('52049', 'Preto'),
        ]);
        $tamanho = new Eixo('SIZE', 'Tamanho', 1, valores: [
            new ValorEixo(null, 'P'), new ValorEixo(null, 'M'),
        ]);
        $eixos = [$cor, $tamanho];
        $variantes = self::variantesDe($eixos);
        $snapshot = new RascunhoSnapshot('MLB1', eixos: $eixos, variantes: $variantes, fotosPorVariante: true);

        // 1ª variante gerada é Azul/P (eixo COLOR varia mais devagar).
        $grupo = R::chaveDoGrupo($variantes[0], Eixo::ordenar($eixos), true);

        $this->assertSame(['COLOR' => 'Azul', 'SIZE' => 'P'], Ctx::atributosDoGrupo($snapshot, $grupo));
    }

    public function test_eixo_customizado_nunca_entra(): void
    {
        $cor = new Eixo('COLOR', 'Cor', 0, valores: [new ValorEixo('52028', 'Azul')]);
        $custom = Eixo::customizado('Padrão', 1, [new ValorEixo(null, 'Xadrez')]);
        $eixos = [$cor, $custom];
        $variantes = self::variantesDe($eixos);
        $snapshot = new RascunhoSnapshot('MLB1', eixos: $eixos, variantes: $variantes, fotosPorVariante: true);

        $grupo = R::chaveDoGrupo($variantes[0], Eixo::ordenar($eixos), true);

        $this->assertSame(['COLOR' => 'Azul'], Ctx::atributosDoGrupo($snapshot, $grupo));
    }

    public function test_grupo_sem_variante_correspondente_retorna_vazio(): void
    {
        $cor = new Eixo('COLOR', 'Cor', 0, definesPicture: true, valores: [
            new ValorEixo('52028', 'Azul'), new ValorEixo('52049', 'Preto'),
        ]);
        $snapshot = new RascunhoSnapshot('MLB1', eixos: [$cor], variantes: self::variantesDe([$cor]));

        $this->assertSame([], Ctx::atributosDoGrupo($snapshot, 'COLOR=id:99999'));
    }

    public function test_variante_desativada_nao_conta(): void
    {
        $cor = new Eixo('COLOR', 'Cor', 0, definesPicture: true, valores: [new ValorEixo('52028', 'Azul')]);
        $combinacoes = GeradorCombinacoes::gerar([$cor]);
        $variante = Variante::daCombinacao($combinacoes[0])->comAtiva(false);
        $snapshot = new RascunhoSnapshot('MLB1', eixos: [$cor], variantes: [$variante]);

        $grupo = ChaveCanonica::combinacao(['COLOR' => 'id:52028']);

        $this->assertSame([], Ctx::atributosDoGrupo($snapshot, $grupo));
    }

    public function test_atributos_verificados_so_entra_com_value_name_nao_vazio(): void
    {
        $atributos = [
            'BRAND'             => ['value_id' => '123', 'value_name' => 'ECF'],
            'REQUIRES_ASSEMBLY' => ['value_id' => '-1', 'value_name' => null],
            'VAZIO'             => ['value_id' => null, 'value_name' => '   '],
        ];

        $this->assertSame(['BRAND' => 'ECF'], Ctx::atributosVerificados($atributos));
    }
}
