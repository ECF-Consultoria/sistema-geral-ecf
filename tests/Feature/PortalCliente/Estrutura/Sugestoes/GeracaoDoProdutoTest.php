<?php

namespace Tests\Feature\PortalCliente\Estrutura\Sugestoes;

use App\Models\Company;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoGeracao;
use App\Models\EstruturaTipoProduto;
use App\Services\Portal\Estrutura\Geracao\ChaveDeComposicao;
use App\Services\Portal\Estrutura\Geracao\DecisoesDasSugestoes;
use App\Services\Portal\Estrutura\Geracao\SugestoesService;
use App\Support\Portal\AtorDoPortal;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\CatalogoSinteticoDeSugestoes;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * Fase 168-11 (D-07, D-12): o tipo e as quantidades do produto, escolhidos pela
 * pessoa, na tabela 1:1 e sem tocar a ficha da 167.
 *
 * Modos de falha impedidos: gravar quantidade inválida, produto de outra empresa
 * ser alterado, '0' (nenhuma) confundir com vazio (herda) e a ficha do produto
 * mudar junto.
 */
class GeracaoDoProdutoTest extends TestCase
{
    use CatalogoSinteticoDeSugestoes;
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    /** @return array{0: Company, 1: AtorDoPortal, 2: array} */
    private function cenario(): array
    {
        Http::fake();
        $empresa = $this->empresaDoGabarito();
        $ator    = $this->atorCliente($empresa);

        return [$empresa, $ator, $this->catalogoSintetico($empresa, $ator)];
    }

    private function chave(array $cat, array $porCodigo): string
    {
        $mapa = [];
        foreach ($porCodigo as $codigo => $q) {
            $mapa[$cat['variacoes'][$codigo]] = $q;
        }

        return ChaveDeComposicao::de($mapa);
    }

    /** @return array<string, array> */
    private function chaves(Company $empresa): array
    {
        return array_flip(array_column(app(SugestoesService::class)->gerar($empresa)['sugestoes'], 'chave'));
    }

    private function definir(Company $empresa, AtorDoPortal $ator, int $produto, ?int $tipo, ?string $combo, ?string $combit): array
    {
        return app(DecisoesDasSugestoes::class)->definirGeracao($empresa, $produto, $tipo, $combo, $combit, $ator);
    }

    private function tipo(string $slug): int
    {
        return EstruturaTipoProduto::where('slug', $slug)->value('id');
    }

    public function test_definir_o_tipo_cadeira_gera_combos_e_entra_no_par_com_a_mesa(): void
    {
        [$empresa, $ator, $cat] = $this->cenario();
        $antes = $this->chaves($empresa);
        $this->assertArrayNotHasKey($this->chave($cat, ['V701' => 2]), $antes);

        $r = $this->definir($empresa, $ator, $cat['produtos']['Peça Decorativa Polo'], $this->tipo('cadeira'), null, null);

        $this->assertSame('cadeira', $r['tipo']);
        $depois = $this->chaves($empresa);
        foreach ([2, 4, 6] as $q) {
            $this->assertArrayHasKey($this->chave($cat, ['V701' => $q]), $depois);
        }
        $this->assertArrayHasKey($this->chave($cat, ['V101' => 1, 'V701' => 1]), $depois);
        $this->assertArrayHasKey($this->chave($cat, ['V101' => 1, 'V701' => 4]), $depois);
    }

    public function test_tipo_e_quantidades_nulos_removem_a_linha_e_voltam_a_sem_tipo(): void
    {
        [$empresa, $ator, $cat] = $this->cenario();
        $id = $cat['produtos']['Peça Decorativa Polo'];
        $this->definir($empresa, $ator, $id, $this->tipo('cadeira'), null, null);
        $this->assertSame(1, EstruturaProdutoGeracao::where('produto_id', $id)->count());

        $this->definir($empresa, $ator, $id, null, null, null);

        $this->assertSame(0, EstruturaProdutoGeracao::where('produto_id', $id)->count());
        $this->assertArrayNotHasKey($this->chave($cat, ['V701' => 2]), $this->chaves($empresa));
    }

    public function test_zero_e_nenhuma_oito_e_so_oito_e_vazio_herda(): void
    {
        [$empresa, $ator, $cat] = $this->cenario();
        $p2 = $cat['produtos']['Cadeira Polo'];

        $this->definir($empresa, $ator, $p2, null, '0', null);
        $this->assertArrayNotHasKey($this->chave($cat, ['V201' => 2]), $this->chaves($empresa));

        $r = $this->definir($empresa, $ator, $p2, null, '8', null);
        $chaves = $this->chaves($empresa);
        $this->assertSame('8', $r['qtd_combo']);
        $this->assertArrayHasKey($this->chave($cat, ['V201' => 8]), $chaves);
        $this->assertArrayNotHasKey($this->chave($cat, ['V201' => 2]), $chaves);

        $this->definir($empresa, $ator, $p2, null, null, null);
        $chaves = $this->chaves($empresa);
        foreach ([2, 4, 6] as $q) {
            $this->assertArrayHasKey($this->chave($cat, ['V201' => $q]), $chaves);
        }
    }

    public function test_quantidade_invalida_recusa_com_a_mensagem_do_campo(): void
    {
        [$empresa, $ator, $cat] = $this->cenario();

        try {
            $this->definir($empresa, $ator, $cat['produtos']['Cadeira Polo'], null, null, '1');
            $this->fail('Deveria recusar.');
        } catch (ValidationException $e) {
            $this->assertSame('Use números inteiros de 2 a 999, separados por vírgula.', $e->errors()['qtd_combit'][0]);
        }
    }

    public function test_tipo_inexistente_e_recusado(): void
    {
        [$empresa, $ator, $cat] = $this->cenario();

        try {
            $this->definir($empresa, $ator, $cat['produtos']['Cadeira Polo'], 999999, null, null);
            $this->fail('Deveria recusar.');
        } catch (ValidationException $e) {
            $this->assertSame('Tipo inválido.', $e->errors()['tipo_id'][0]);
        }
    }

    public function test_produto_de_outra_empresa_e_404(): void
    {
        [$empresa, $ator] = $this->cenario();
        $outra = $this->empresaDoGabarito();
        $catOutra = $this->catalogoSintetico($outra, $this->atorCliente($outra));

        $this->expectException(ModelNotFoundException::class);

        $this->definir($empresa, $ator, $catOutra['produtos']['Cadeira Polo'], $this->tipo('cadeira'), null, null);
    }

    public function test_a_ficha_da_167_nao_muda(): void
    {
        [$empresa, $ator, $cat] = $this->cenario();
        $id = $cat['produtos']['Peça Decorativa Polo'];
        $antes = EstruturaProduto::findOrFail($id)->getAttributes();

        $this->definir($empresa, $ator, $id, $this->tipo('cadeira'), '2, 3', null);

        $this->assertSame($antes, EstruturaProduto::findOrFail($id)->getAttributes());
    }

    public function test_deixa_rastro_com_o_produto_como_alvo(): void
    {
        [$empresa, $ator, $cat] = $this->cenario();
        $id = $cat['produtos']['Peça Decorativa Polo'];
        Activity::query()->delete();

        $this->definir($empresa, $ator, $id, $this->tipo('cadeira'), null, null);

        $log = Activity::where('log_name', 'portal')->get()->first(fn ($l) => $l->properties['evento'] === 'tipo_do_produto_definido');
        $this->assertNotNull($log);
        $this->assertSame('cliente', $log->properties['origem']);
        $this->assertSame($id, (int) $log->subject_id);
        $this->assertSame(EstruturaProduto::class, $log->subject_type);
    }
}
