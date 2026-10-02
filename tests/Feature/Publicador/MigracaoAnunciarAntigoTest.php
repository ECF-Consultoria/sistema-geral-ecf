<?php

namespace Tests\Feature\Publicador;

use App\Models\Company;
use App\Models\EstruturaAnuncio;
use App\Models\EstruturaOferta;
use App\Models\EstruturaPublicacao;
use App\Models\PubImagem;
use App\Models\PubPublicacaoItem;
use App\Models\PubRascunho;
use App\Services\Publicador\RascunhoRepository;
use App\Support\Publicador\Variacao\ChaveCanonica;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `16` §4.3 — os rascunhos do Anunciar antigo vão para o Publicador sem que a
 * tabela antiga mude, sem duplicar, e SEM herdar o congelamento de preço e
 * título do autosave antigo (`16` §1.6).
 */
class MigracaoAnunciarAntigoTest extends TestCase
{
    use RefreshDatabase;

    private function antiga(array $mudar = [], array $colunas = []): EstruturaPublicacao
    {
        $empresa = Company::factory()->create();
        $oferta = EstruturaOferta::create(['company_id' => $empresa->id, 'sku' => 'CAD-01', 'fase' => 'simples', 'nome' => 'Cadeira']);
        EstruturaAnuncio::create(['oferta_id' => $oferta->id, 'tipo' => 'classico', 'titulo' => 'Cadeira Planejada ECF']);

        return EstruturaPublicacao::create([
            'oferta_id' => $oferta->id,
            'status' => 'rascunho',
            'dados' => array_replace_recursive([
                'categoria_id' => 'MLB193945',
                'atributos' => ['BRAND' => ['value_id' => null, 'value_name' => 'ECF'], 'BACKREST_HEIGHT' => ['value_id' => null, 'value_name' => '50']],
                'fotos' => [['id' => 'PIC1', 'url' => 'https://http2.mlstatic.com/D_PIC1-O.jpg'], ['id' => 'PIC2', 'url' => null]],
                'estoque' => 5,
                'condicao' => 'new',
                'envio' => ['modo' => 'me2', 'frete_gratis' => true],
                'embalagem' => ['peso_g' => 7200, 'altura_cm' => 55, 'largura_cm' => 50.5, 'comprimento_cm' => null],
                'garantia' => '90 dias',
                'descricao' => 'Cadeira executiva.',
                // O título do Clássico é o planejado (foi congelado pelo autosave); o do Premium foi digitado.
                'tipos' => ['classico' => ['titulo' => 'Cadeira Planejada ECF', 'preco' => 150], 'premium' => ['titulo' => 'Cadeira Premium Digitada', 'preco' => null]],
            ], $mudar),
            ...$colunas,
        ]);
    }

    public function test_simulacao_nao_grava_nada(): void
    {
        $this->antiga();

        $this->artisan('publicador:migrar-anunciar')
            ->expectsOutputToContain('SIMULAÇÃO')
            ->expectsOutputToContain('1 a migrar, 0 pulada(s) — nada gravado.')
            ->assertSuccessful();

        $this->assertSame(0, PubRascunho::count());
    }

    public function test_aplicar_leva_tudo_e_so_o_digitado_fica_como_digitado(): void
    {
        $antiga = $this->antiga();
        $dadosAntes = $antiga->fresh()->dados;

        $this->artisan('publicador:migrar-anunciar --apply')->assertSuccessful();

        $r = PubRascunho::sole();
        $s = (new RascunhoRepository())->snapshot($r);

        // D15: a oferta ganhou o produto (origem portal), e rodar de novo não duplica.
        $this->assertSame(1, \App\Models\PubProduto::where('oferta_id', $antiga->oferta_id)->where('origem', 'portal')->count());
        $this->artisan('publicador:migrar-anunciar --apply')->assertSuccessful();
        $this->assertSame(1, \App\Models\PubProduto::where('oferta_id', $antiga->oferta_id)->count());
        $this->assertSame(1, PubRascunho::count());

        $this->assertSame(PubRascunho::DRAFT, $r->status);
        $this->assertSame('MLB193945', $s->categoriaId);
        $this->assertSame(['tipo' => '2230280', 'tempo' => 90, 'unidade' => 'dias'], $s->garantia);
        $this->assertSame(['modo' => 'me2', 'frete_gratis' => true, 'retirada' => false], $s->envio);

        // Título igual ao planejado volta a herdar; o digitado fica.
        $this->assertNull($s->alvos[0]->titulo);
        $this->assertSame('Cadeira Premium Digitada', $s->alvos[1]->titulo);
        // Sem Precificação, os 150 foram digitados: ficam.
        $this->assertSame(['gold_special' => 150.0, 'gold_pro' => null], $s->variantes[0]->dados['precos']);

        $this->assertSame(['value_name' => 'ECF', 'origem' => 'migrated', 'revisar' => true], $s->atributos['BRAND']);
        $this->assertSame('50', $s->atributos['BACKREST_HEIGHT']['value_name'], 'o número sem unidade vem como estava — a validação acusa (V-ATT-04)');
        $this->assertSame('7200 g', $s->atributos['SELLER_PACKAGE_WEIGHT']['value_name']);
        $this->assertSame('50.5 cm', $s->atributos['SELLER_PACKAGE_WIDTH']['value_name']);
        $this->assertArrayNotHasKey('SELLER_PACKAGE_LENGTH', $s->atributos);

        $this->assertSame(5, $s->variantes[0]->dados['estoque']);
        $this->assertSame(['SELLER_SKU' => ['value_name' => 'CAD-01']], $s->variantes[0]->dados['atributos']);
        $this->assertSame(ChaveCanonica::UNICA, $s->variantes[0]->chave);

        $fotos = PubImagem::orderBy('id')->get();
        $this->assertSame(['PIC1', 'PIC2'], $fotos->pluck('ml_picture_id')->all());
        $this->assertSame([PubImagem::ENVIADA, PubImagem::ENVIADA], $fotos->pluck('upload_status')->all());
        $this->assertNull($fotos[0]->caminho, 'sem arquivo: o Anunciar antigo só guardava o id do ML');
        $this->assertSame([0, 1], array_column($s->imagens, 'posicao'));

        // A tabela antiga não mudou.
        $this->assertSame($dadosAntes, $antiga->fresh()->dados);
    }

    public function test_rodar_de_novo_nao_duplica(): void
    {
        $this->antiga();

        $this->artisan('publicador:migrar-anunciar --apply')->assertSuccessful();
        $this->artisan('publicador:migrar-anunciar --apply')->expectsOutputToContain('0 a migrar, 1 pulada(s)')->assertSuccessful();

        $this->assertSame(1, PubRascunho::count());
    }

    public function test_par_ja_publicado_em_parte_vira_publicacao_e_so_o_que_falta_fica_ativo(): void
    {
        $this->antiga([], ['status' => 'parcial', 'ml_item_classico' => 'MLB111']);

        $this->artisan('publicador:migrar-anunciar --apply')->assertSuccessful();

        $r = PubRascunho::sole();
        $s = (new RascunhoRepository())->snapshot($r);
        $this->assertSame(PubRascunho::PARTIALLY_PUBLISHED, $r->status);
        $this->assertSame([false, true], array_map(fn ($a) => $a->ativo, $s->alvos), 'o Clássico já está no ar: não se publica de novo');
        $this->assertTrue($s->variantes[0]->publicada);

        $item = PubPublicacaoItem::sole();
        $this->assertSame('MLB111', $item->ml_item_id);
        $this->assertSame(PubPublicacaoItem::CREATED, $item->status);
        $this->assertSame('gold_special', $item->listing_type_id);
        $this->assertNull($item->payload, 'o Anunciar antigo não guardava o payload');
    }
}
