<?php

namespace Tests\Feature\PortalCliente\Estrutura;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\EstruturaProdutoGeracao;
use App\Models\EstruturaTipoProduto;
use App\Services\Portal\Estrutura\EstruturaOfertaService;
use App\Services\Portal\Estrutura\Geracao\ChaveDeComposicao;
use App\Services\Portal\Estrutura\Geracao\SugestoesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CatalogoSinteticoDeSugestoes;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * Um nome só para o mesmo combo (10/10/2026). Os combos em lote da Lista SKUs
 * (`EstruturaOfertaService::criarCombos`) nascem pelo padrão do Planejamento
 * (`NomesSugeridos::combo`): antes a Lista dizia "Combo 4 Cadeira Polo — Natural" e o
 * Planejamento, para o MESMO combo, "Kit 4 Cadeiras Polo — Natural". O combo de uma
 * quantidade da Lista (FormOferta) pede a prévia do "Montar kit" com a oferta como item —
 * e recebe o mesmo nome. Só o padrão de CRIAÇÃO muda: combo que já existe fica como está.
 *
 * No mesmo dia o usuário pediu que o Combo se chame "Combo" (e não "Kit N …", o exemplo da planilha): o nome
 * único passou a ser "Combo 4 Cadeiras Polo — Natural".
 */
class CombosDaListaNoPadraoDoPlanejamentoTest extends TestCase
{
    use CatalogoSinteticoDeSugestoes;
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    private Company $empresa;

    /** @var array{produtos: array<string,int>, variacoes: array<string,int>, ofertas: array<string,int>} */
    private array $cat;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        $this->empresa = $this->empresaDoGabarito();
        $this->cat = $this->catalogoSintetico($this->empresa, $this->atorCliente($this->empresa));
    }

    /** O Combo que o Planejamento sugere para a variação × quantidade: [nome, sku], ou null. */
    private function sugeridoPeloPlanejamento(string $codigo, int $n): ?array
    {
        $chave = ChaveDeComposicao::de([$this->cat['variacoes'][$codigo] => $n]);
        $s = collect(app(SugestoesService::class)->gerar($this->empresa)['sugestoes'])->firstWhere('chave', $chave);

        return $s === null ? null : [$s['nome'], $s['sku']];
    }

    /** O que a prévia do "Montar kit" sugere para a oferta como único item (o pedido do FormOferta). */
    private function previaDoCombo(int $ofertaId, int $n): array
    {
        $r = $this->entrarNoPortal($this->empresa)->postJson(route('portal.auth.estrutura.sugestoes.montar.previa'), [
            'componentes' => [['oferta_id' => $ofertaId, 'quantidade' => $n]],
        ])->assertOk()->assertJson(['fase' => 'combo']);

        return [$r->json('sugerido.nome'), $r->json('sugerido.sku')];
    }

    /** @return list<array{0: ?string, 1: string}> [nome, sku] dos combos da base, na ordem de criação */
    private function combosDe(int $baseId): array
    {
        return EstruturaOferta::query()
            ->where('company_id', $this->empresa->id)
            ->where('fase', EstruturaOferta::FASE_COMBO)
            ->whereHas('componentes', fn ($q) => $q->where('componente_id', $baseId))
            ->orderBy('id')
            ->get()
            ->map(fn (EstruturaOferta $o) => [$o->nome, $o->sku])
            ->all();
    }

    public function test_combos_em_lote_de_produto_cadastrado_nascem_com_o_nome_do_planejamento(): void
    {
        $base = $this->cat['ofertas']['V202'];   // Cadeira Polo — Preto; o tipo Cadeira sai da categoria

        // Antes de criar: o que o Planejamento sugere e o que a prévia do combo avulso da Lista diz.
        $sugeridos = [];
        foreach ([2, 4] as $n) {
            $sugeridos[$n] = $this->sugeridoPeloPlanejamento('V202', $n);
            $this->assertNotNull($sugeridos[$n], "o Planejamento sugere o Combo {$n} da cadeira");
            $this->assertSame($sugeridos[$n], $this->previaDoCombo($base, $n), 'o combo avulso da Lista recebe o mesmo nome');
        }

        $this->entrarNoPortal($this->empresa)
            ->post(route('portal.auth.estrutura.ofertas.combos', $base), ['quantidades' => [4, 2]])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'V202-CB2, V202-CB4'));

        $this->assertSame([['Combo 2 Cadeiras Polo — Preto', 'V202-CB2'], ['Combo 4 Cadeiras Polo — Preto', 'V202-CB4']], $this->combosDe($base));
        $this->assertSame([$sugeridos[2], $sugeridos[4]], $this->combosDe($base), 'a Lista e o Planejamento dão o MESMO nome e SKU');
    }

    /** O tipo escolhido no Planejamento vence o inferido — na Lista também. */
    public function test_o_tipo_escolhido_no_planejamento_vale_para_o_nome_da_lista(): void
    {
        $base = EstruturaOferta::findOrFail($this->cat['ofertas']['V1001']);   // Cadeira Avulsa: inferida Cadeira
        $ator = $this->atorCliente($this->empresa);
        $svc = app(EstruturaOfertaService::class);

        $svc->criarCombos($base, [2], null, null, $ator);
        $this->assertSame([['Combo 2 Cadeiras Avulsa', 'V1001-CB2']], $this->combosDe($base->id), 'pelo tipo inferido');

        // A ECF escolhe Banco para o produto (e o Combo 3, para o Planejamento sugerir).
        EstruturaProdutoGeracao::create([
            'produto_id' => $this->cat['produtos']['Cadeira Avulsa'], 'company_id' => $this->empresa->id,
            'tipo_id' => EstruturaTipoProduto::where('slug', 'banco')->value('id'), 'qtd_combo' => '3', 'qtd_combit' => null,
        ]);
        $sugerido = $this->sugeridoPeloPlanejamento('V1001', 3);
        $this->assertSame(['Combo 3 Cadeira Avulsa', 'V1001-CB3'], $sugerido, 'o nome não começa por "banco": vira "Combo N"');

        $svc->criarCombos($base, [3], null, null, $ator);
        $this->assertSame($sugerido, $this->combosDe($base->id)[1]);
    }

    /** Oferta sem produto (importada): o nome dela, sem tipo — como o "Montar kit" trata o avulso. */
    public function test_oferta_sem_produto_segue_combo_n_e_o_nome_dela(): void
    {
        $ator = $this->atorCliente($this->empresa);
        $svc = app(EstruturaOfertaService::class);
        [$avulsa] = $svc->criar($this->empresa, ['sku' => 'AV-CAD', 'fase' => 'simples', 'nome' => 'Cadeira Importada'], $ator);

        $this->assertSame(['Combo 2 Cadeira Importada', 'AV-CAD-CB2'], $this->previaDoCombo($avulsa->id, 2));

        $svc->criarCombos($avulsa, [2], null, null, $ator);
        $this->assertSame([['Combo 2 Cadeira Importada', 'AV-CAD-CB2']], $this->combosDe($avulsa->id));
    }

    /** Só o padrão de criação mudou: o combo criado no padrão antigo fica com o nome que tem. */
    public function test_combo_que_ja_existe_nao_e_renomeado(): void
    {
        $ator = $this->atorCliente($this->empresa);
        $svc = app(EstruturaOfertaService::class);
        $base = EstruturaOferta::findOrFail($this->cat['ofertas']['V202']);
        [$antigo] = $svc->criar($this->empresa, ['sku' => 'V202-CB2', 'fase' => 'combo', 'nome' => 'Combo 2 Cadeira Polo — Preto',
            'componentes' => [['id' => $base->id, 'quantidade' => 2]]], $ator);

        $r = $svc->criarCombos($base, [2, 4], null, null, $ator);

        $this->assertSame(['V202-CB4'], $r['criados']);
        $this->assertSame([2], $r['pulados']);
        $this->assertSame('Combo 2 Cadeira Polo — Preto', $antigo->fresh()->nome);
        $this->assertSame([['Combo 2 Cadeira Polo — Preto', 'V202-CB2'], ['Combo 4 Cadeiras Polo — Preto', 'V202-CB4']], $this->combosDe($base->id));
    }
}
