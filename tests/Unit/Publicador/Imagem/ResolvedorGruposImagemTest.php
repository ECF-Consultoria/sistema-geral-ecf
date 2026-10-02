<?php

namespace Tests\Unit\Publicador\Imagem;

use App\Support\Publicador\Imagem\OpcoesImagem;
use App\Support\Publicador\Imagem\ResolvedorGruposImagem as R;
use App\Support\Publicador\Validacao\Problema;
use App\Support\Publicador\Variacao\Eixo;
use App\Support\Publicador\Variacao\GeradorCombinacoes;
use App\Support\Publicador\Variacao\ValorEixo;
use App\Support\Publicador\Variacao\Variante;
use PHPUnit\Framework\TestCase;

/**
 * `06` — galeria geral, grupos por `defines_picture` e a lista final de fotos
 * de cada variante. A atribuição é por GRUPO, nunca por variante: duas
 * variantes da mesma cor não têm como ficar com fotos diferentes (RN-61).
 */
class ResolvedorGruposImagemTest extends TestCase
{
    private const LEGADO = ['modelo' => OpcoesImagem::LEGADO, 'maxPorItem' => 12, 'maxPorVariacao' => 10];

    private static function cor(): Eixo
    {
        return new Eixo('COLOR', 'Cor', 0, definesPicture: true, valores: [
            new ValorEixo('52049', 'Preto'), new ValorEixo('52055', 'Branco'), new ValorEixo('52028', 'Azul'),
        ]);
    }

    private static function tamanho(): Eixo
    {
        return new Eixo('SIZE', 'Tamanho', 1, valores: [new ValorEixo(null, 'P'), new ValorEixo(null, 'M'), new ValorEixo(null, 'G')]);
    }

    private static function voltagem(): Eixo
    {
        return new Eixo('VOLTAGE', 'Voltagem', 0, valores: [new ValorEixo('39205162', '127V'), new ValorEixo('198813', '220V')]);
    }

    private static function variantes(array $eixos): array
    {
        return array_map(fn ($c) => Variante::daCombinacao($c), GeradorCombinacoes::gerar($eixos));
    }

    /** `['GENERAL' => ['g1'], 'COLOR=id:52049' => ['p1', 'p2']]` → atribuições. */
    private static function atribuir(array $porGrupo): array
    {
        $saida = [];
        foreach ($porGrupo as $grupo => $ids) {
            foreach ($ids as $i => $id) {
                $saida[] = ['imagem' => $id, 'grupo' => $grupo, 'posicao' => $i];
            }
        }

        return $saida;
    }

    private static function regras(array $problemas, ?string $severidade = null): array
    {
        return array_values(array_map(fn (Problema $p) => $p->regra, array_filter($problemas, fn (Problema $p) => $severidade === null || $p->severidade === $severidade)));
    }

    public function test_tc50_produto_simples_usa_a_galeria_geral_na_ordem(): void
    {
        $fotos = array_map(fn ($i) => "f{$i}", range(1, 8));

        $r = R::resolver(self::variantes([]), [], self::atribuir([R::GERAL => $fotos]), new OpcoesImagem(...self::LEGADO));

        $this->assertSame($fotos, $r->porVariante['__single__']);
        $this->assertSame($fotos, $r->uniaoLegado);
        $this->assertSame([], self::regras($r->problemas, Problema::BLOQUEIO));
    }

    public function test_tc51_excesso_bloqueia_e_lista_as_excedentes_sem_cortar(): void
    {
        $fotos = array_map(fn ($i) => "f{$i}", range(1, 13));

        $r = R::resolver(self::variantes([]), [], self::atribuir([R::GERAL => $fotos]), new OpcoesImagem(...self::LEGADO));

        $this->assertCount(13, $r->porVariante['__single__'], 'não corta em silêncio');
        $excesso = collect($r->problemas)->firstWhere('regra', 'V-IMG-07');
        $this->assertSame(Problema::BLOQUEIO, $excesso->severidade);
        $this->assertSame(['f13'], $excesso->alvo['excedentes']);
    }

    public function test_tc52_fotos_por_cor_identicas_e_uniao_do_legado_na_ordem(): void
    {
        $atrib = self::atribuir([
            'COLOR=id:52049' => ['preto1', 'preto2'],
            'COLOR=id:52055' => ['branco1'],
            'COLOR=id:52028' => ['azul1'],
            R::GERAL => ['geral1'],
        ]);

        $r = R::resolver(self::variantes([self::cor(), self::tamanho()]), [self::cor(), self::tamanho()], $atrib, new OpcoesImagem(...self::LEGADO));

        foreach (['P', 'M', 'G'] as $t) {
            $this->assertSame(['preto1', 'preto2', 'geral1'], $r->porVariante["COLOR=id:52049|SIZE=txt:".mb_strtolower($t)]);
        }
        $this->assertSame(['preto1', 'preto2', 'branco1', 'azul1', 'geral1'], $r->uniaoLegado);
        $this->assertSame([], self::regras($r->problemas, Problema::BLOQUEIO));

        $grupo = collect($r->grupos)->firstWhere('chave', 'COLOR=id:52049');
        $this->assertSame('Preto', $grupo['rotulo']);
        $this->assertSame(['P', 'M', 'G'], $grupo['usadaEm']);
    }

    public function test_tc53_cor_sem_foto_propria_bloqueia_mesmo_com_galeria_geral(): void
    {
        $atrib = self::atribuir(['COLOR=id:52049' => ['p1'], 'COLOR=id:52055' => ['b1'], R::GERAL => ['g1']]);

        $r = R::resolver(self::variantes([self::cor(), self::tamanho()]), [self::cor(), self::tamanho()], $atrib, new OpcoesImagem(...self::LEGADO));

        $azul = collect($r->problemas)->firstWhere('regra', 'V-IMG-05');
        $this->assertSame(Problema::BLOQUEIO, $azul->severidade);
        $this->assertSame('COLOR=id:52028', $azul->alvo['grupo']);
        $this->assertStringContainsString('Azul', $azul->mensagem);
        $this->assertSame(['V-IMG-05'], self::regras($r->problemas, Problema::BLOQUEIO), 'um aviso por cor, não um por tamanho');
    }

    public function test_tc54_voltagem_sem_defines_picture_usa_a_galeria_geral(): void
    {
        $r = R::resolver(self::variantes([self::voltagem()]), [self::voltagem()], self::atribuir([R::GERAL => ['g1', 'g2']]), new OpcoesImagem(...self::LEGADO));

        $this->assertSame(['g1', 'g2'], $r->porVariante['VOLTAGE=id:39205162']);
        $this->assertSame(['g1', 'g2'], $r->porVariante['VOLTAGE=id:198813']);
        $this->assertSame([], self::regras($r->problemas, Problema::BLOQUEIO));
    }

    public function test_tc55_sem_nenhuma_foto(): void
    {
        $r = R::resolver(self::variantes([]), [], [], new OpcoesImagem(...self::LEGADO));

        $this->assertSame(['V-IMG-04'], self::regras($r->problemas, Problema::BLOQUEIO));
    }

    public function test_tc60_fotos_por_variante_sem_defines_picture(): void
    {
        $atrib = self::atribuir(['VOLTAGE=id:39205162' => ['v127']]);

        $r = R::resolver(self::variantes([self::voltagem()]), [self::voltagem()], $atrib, new OpcoesImagem(...self::LEGADO, fotosPorVariante: true));

        $this->assertSame(['v127'], $r->porVariante['VOLTAGE=id:39205162']);
        $semFoto = collect($r->problemas)->firstWhere('regra', 'V-IMG-04');
        $this->assertSame('VOLTAGE=id:198813', $semFoto->alvo['variante']);
    }

    public function test_dois_eixos_que_definem_foto_agrupam_pelo_par(): void
    {
        $material = new Eixo('UPHOLSTERY_MATERIAL', 'Material', 1, definesPicture: true, valores: [new ValorEixo(null, 'Couro'), new ValorEixo(null, 'Tecido')]);
        $eixos = [self::cor(), $material];

        $chave = R::chaveDoGrupo(self::variantes($eixos)[0], $eixos, false);

        $this->assertSame('COLOR=id:52049|UPHOLSTERY_MATERIAL=txt:couro', $chave);
    }

    public function test_variante_desativada_nao_cobra_foto_e_imagem_sem_uso_vira_info(): void
    {
        $eixos = [self::cor()];
        $variantes = array_map(fn (Variante $v) => $v->valores['COLOR']->valueName === 'Azul' ? $v->comAtiva(false) : $v, self::variantes($eixos));
        $atrib = self::atribuir(['COLOR=id:52049' => ['p1'], 'COLOR=id:52055' => ['b1'], R::GERAL => ['g1']]);

        $semAzul = R::resolver($variantes, $eixos, $atrib, new OpcoesImagem(...self::LEGADO));
        $this->assertSame([], self::regras($semAzul->problemas, Problema::BLOQUEIO));

        $semGeral = R::resolver($variantes, $eixos, $atrib, new OpcoesImagem(...self::LEGADO, incluirGeral: false));
        $info = collect($semGeral->problemas)->firstWhere('regra', 'V-IMG-11');
        $this->assertSame(Problema::INFO, $info->severidade);
        $this->assertSame('g1', $info->alvo['imagem']);
    }

    public function test_limite_por_variacao_no_legado_e_por_item_no_up(): void
    {
        $eixos = [self::voltagem()];
        $onze = array_map(fn ($i) => "g{$i}", range(1, 11));
        $atrib = self::atribuir([R::GERAL => $onze]);

        $legado = R::resolver(self::variantes($eixos), $eixos, $atrib, new OpcoesImagem(...self::LEGADO));
        $this->assertContains('V-IMG-06', self::regras($legado->problemas, Problema::BLOQUEIO), '11 > 10 por variação');

        $up = R::resolver(self::variantes($eixos), $eixos, $atrib, new OpcoesImagem(OpcoesImagem::UP, maxPorItem: 12, maxPorVariacao: 10));
        $this->assertSame([], self::regras($up->problemas, Problema::BLOQUEIO), 'no UP cada variante é um item: vale o limite do item');
        $this->assertSame([], $up->uniaoLegado);
    }
}
