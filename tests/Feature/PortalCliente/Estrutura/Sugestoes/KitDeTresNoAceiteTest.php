<?php

namespace Tests\Feature\PortalCliente\Estrutura\Sugestoes;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\EstruturaProdutoVariacao;
use App\Services\Portal\Estrutura\EstruturaConjunto;
use App\Services\Portal\Estrutura\EstruturaPrecificacaoService;
use App\Services\Portal\Estrutura\Geracao\ChaveDeComposicao;
use App\Services\Portal\Estrutura\Geracao\DecisoesDasSugestoes;
use App\Services\Portal\Estrutura\Geracao\ListaDeSugestoes;
use App\Services\Portal\Estrutura\Geracao\SugestoesService;
use App\Services\Portal\Estrutura\Produtos\ProdutoCadastroService;
use App\Support\Portal\AtorDoPortal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * Kit de 3 de ponta a ponta (08/10): com os tipos e pares SEMEADOS (sem ajuste de
 * teste), gabinete + espelho + lixeira do mesmo banheiro viram Kit de 3, que tem
 * logística e frete do conjunto na lista, aceita como oferta de 3 componentes,
 * aparece na Lista SKUs e na Precificação, não volta como sugestão e pode ser
 * descartado. Nomes, medidas e custos fictícios.
 */
class KitDeTresNoAceiteTest extends TestCase
{
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    /** @return array{0: Company, 1: AtorDoPortal, 2: array<string,int>, 3: array<string,int>} */
    private function cenario(): array
    {
        Http::fake();
        $empresa = $this->empresaDoGabarito();
        $ator    = $this->atorCliente($empresa);

        $linha = fn (string $grupo, string $codigo, string $nome, ?string $valor, array $volumes, float $custo, ?string $categoria) => array_filter([
            'grupo' => $grupo, 'codigo' => $codigo, 'nome' => $nome,
            'eixo' => $valor !== null ? 'cor' : null, 'valor' => $valor,
            'volumes' => $volumes, 'custo' => $custo,
        ] + ($categoria !== null ? ['familia' => 'Nuvem Minimal', 'ambientes' => ['Banheiro'], 'categoria_texto' => $categoria] : []), fn ($v) => $v !== null);

        $caixa = [['c' => 80, 'l' => 50, 'a' => 20, 'kg' => 18]];
        $leve  = [['c' => 72, 'l' => 72, 'a' => 5, 'kg' => 6]];
        $lixo  = [['c' => 25, 'l' => 25, 'a' => 30, 'kg' => 1]];

        $r = app(ProdutoCadastroService::class)->gravarLinhas($empresa, [
            $linha('G', 'GAB-B', 'Gabinete Banheiro 80cm Nuvem Minimal com Nichos', 'Branco', $caixa, 400, 'Gabinetes'),
            $linha('G', 'GAB-P', 'Gabinete Banheiro 80cm Nuvem Minimal com Nichos', 'Preto', $caixa, 420, null),
            $linha('E', 'ESP-1', 'Espelho Redondo 70cm Nuvem Minimal com Prateleira', null, $leve, 150, 'Espelhos'),
            // Categoria sem tipo: o tipo sai do nome.
            $linha('L', 'LIX-1', 'Lixeira 8L Nuvem Minimal', null, $lixo, 50, 'Móveis para Banheiro'),
        ], $ator);
        $this->assertSame([], $r['erros']);

        $variacoes = EstruturaProdutoVariacao::where('company_id', $empresa->id)->pluck('id', 'codigo')->all();
        $ofertas = EstruturaOferta::where('company_id', $empresa->id)->where('fase', EstruturaOferta::FASE_SIMPLES)
            ->whereNotNull('variacao_id')->pluck('id', 'variacao_id')->all();

        return [$empresa, $ator, $variacoes, $ofertas];
    }

    private function chave(array $variacoes, array $codigos): string
    {
        $mapa = [];
        foreach ($codigos as $c) {
            $mapa[$variacoes[$c]] = 1;
        }

        return ChaveDeComposicao::de($mapa);
    }

    /** @return array<string, array<string,mixed>> vigentes por chave */
    private function vigentes(Company $empresa): array
    {
        $saida = [];
        foreach (app(SugestoesService::class)->gerar($empresa)['sugestoes'] as $s) {
            if (! $s['descartada']) {
                $saida[$s['chave']] = $s;
            }
        }

        return $saida;
    }

    public function test_kit_de_tres_tem_logistica_na_lista_aceita_aparece_e_nao_volta(): void
    {
        [$empresa, $ator, $var, $ofertas] = $this->cenario();
        $branco = $this->chave($var, ['GAB-B', 'ESP-1', 'LIX-1']);
        $preto  = $this->chave($var, ['GAB-P', 'ESP-1', 'LIX-1']);

        $vigentes = $this->vigentes($empresa);
        $this->assertArrayHasKey($branco, $vigentes);
        $this->assertArrayHasKey($preto, $vigentes);
        $this->assertSame(['gabinete', 'espelho', 'lixeira'], $vigentes[$branco]['tipos']);

        // Na lista: linha com os 3 componentes, logística e frete estimado do conjunto.
        $pagina = app(ListaDeSugestoes::class)->listar($empresa, ['fase' => 'kit'], 1);
        $linha = collect($pagina['itens'])->firstWhere('chave', $branco);
        $this->assertNotNull($linha);
        $this->assertCount(3, $linha['itens']);
        $this->assertNotNull($linha['logistica']);
        $this->assertSame([], $linha['logistica']['sem_medida']);
        $this->assertSame(600.0, (float) $linha['custo']);

        // Aceitar: oferta Kit com os três simples x1, pela regra da Lista SKUs.
        $r = app(DecisoesDasSugestoes::class)->aceitar($empresa, [['chave' => $branco]], $ator);
        $this->assertSame([], $r['erros']);
        $this->assertCount(1, $r['criadas']);

        $oferta = EstruturaOferta::findOrFail($r['criadas'][0]['oferta_id']);
        $this->assertSame('kit', $oferta->fase);
        $this->assertSame($vigentes[$branco]['sku'], $oferta->sku);
        $this->assertSame([
            $ofertas[$var['GAB-B']] => 1, $ofertas[$var['ESP-1']] => 1, $ofertas[$var['LIX-1']] => 1,
        ], $oferta->componentes()->orderBy('componente_id')->pluck('quantidade', 'componente_id')->all());

        $this->assertContains($oferta->id, array_column(EstruturaConjunto::daEmpresa($empresa)->ofertas(), 'id'));
        $p = app(EstruturaPrecificacaoService::class)->pagina($empresa, [$oferta->id])['por_oferta'][$oferta->id];
        $this->assertSame(600.0, (float) $p['custo']['valor']);

        // Já existe: não volta; aceitar de novo não duplica.
        $this->assertArrayNotHasKey($branco, $this->vigentes($empresa));
        $de_novo = app(DecisoesDasSugestoes::class)->aceitar($empresa, [['chave' => $branco]], $ator);
        $this->assertSame([$branco], $de_novo['ja_existiam']);
        $this->assertSame(1, EstruturaOferta::where('company_id', $empresa->id)->where('sku', $oferta->sku)->count());

        // Descartar o outro: sai das vigentes e fica marcado.
        app(DecisoesDasSugestoes::class)->descartar($empresa, [$preto], $ator);
        $this->assertArrayNotHasKey($preto, $this->vigentes($empresa));
        $descartada = collect(app(SugestoesService::class)->gerar($empresa)['sugestoes'])->firstWhere('chave', $preto);
        $this->assertTrue($descartada['descartada']);
    }

    public function test_kit_de_tres_feito_a_mao_na_lista_skus_nao_e_sugerido(): void
    {
        [$empresa, $ator, $var, $ofertas] = $this->cenario();
        $branco = $this->chave($var, ['GAB-B', 'ESP-1', 'LIX-1']);

        app(\App\Services\Portal\Estrutura\EstruturaOfertaService::class)->criar($empresa, [
            'sku' => 'MEU-KIT-BANHEIRO', 'nome' => 'Meu kit', 'fase' => 'kit',
            'componentes' => [
                ['id' => $ofertas[$var['LIX-1']], 'quantidade' => 1],
                ['id' => $ofertas[$var['GAB-B']], 'quantidade' => 1],
                ['id' => $ofertas[$var['ESP-1']], 'quantidade' => 1],
            ],
        ], $ator);

        $this->assertArrayNotHasKey($branco, $this->vigentes($empresa));
    }
}
