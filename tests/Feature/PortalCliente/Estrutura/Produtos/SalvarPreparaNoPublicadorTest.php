<?php

namespace Tests\Feature\PortalCliente\Estrutura\Produtos;

use App\Jobs\Publicador\PrepararProdutoNoPublicadorJob;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoVariacao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * Salvar o produto no Portal agenda o preparo no Publicador (09/10/2026): linhas/variações, descrição
 * e imagens. A resposta ao cliente NÃO muda e não cita nada disso (sigilo do Portal); linha sem
 * mudança não agenda.
 */
class SalvarPreparaNoPublicadorTest extends TestCase
{
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::put('ml_app_token_coleta', 'app-token-teste', 3600);
        Queue::fake();
    }

    private function semOrigem(string $json): void
    {
        $this->assertDoesNotMatchRegularExpression('/public(ar|ação|ador)|\bIA\b|preparo/iu', json_encode(json_decode($json, true), JSON_UNESCAPED_UNICODE));
    }

    public function test_gravar_linhas_agenda_o_produto_que_mudou_e_nao_o_que_ficou_igual(): void
    {
        $empresa = $this->empresaDoGabarito();
        $sessao = $this->withoutVite()->entrarNoPortal($empresa);
        $linha = ['chave' => 'k1', 'codigo' => 'MSA-1', 'nome' => 'Mesa', 'volumes' => [['c' => 93, 'l' => 55, 'a' => 6, 'kg' => 9.5]], 'custo' => '100,50'];

        $r = $sessao->postJson(route('portal.auth.estrutura.produtos.linhas'), ['linhas' => [$linha]])->assertOk();
        $this->semOrigem($r->getContent());
        $produto = EstruturaProduto::where('company_id', $empresa->id)->firstOrFail();
        Queue::assertPushed(PrepararProdutoNoPublicadorJob::class, fn ($j) => $j->estruturaProdutoId === $produto->id && $j->companyId === $empresa->id);

        $v = EstruturaProdutoVariacao::where('produto_id', $produto->id)->firstOrFail();
        $sessao->postJson(route('portal.auth.estrutura.produtos.linhas'), ['linhas' => [['id' => $v->id, 'codigo' => 'MSA-1', 'nome' => 'Mesa']]])->assertOk();
        Queue::assertPushed(PrepararProdutoNoPublicadorJob::class, 1);
    }

    public function test_salvar_descricao_e_ordenar_imagens_agendam_o_preparo(): void
    {
        $empresa = $this->empresaDoGabarito();
        $produto = EstruturaProduto::create(['company_id' => $empresa->id, 'codigo' => 'P-1', 'nome' => 'Mesa']);
        $v = EstruturaProdutoVariacao::create(['produto_id' => $produto->id, 'company_id' => $empresa->id, 'ordem' => 0, 'codigo' => 'P-1']);
        $sessao = $this->withoutVite()->entrarNoPortal($empresa);

        $r = $sessao->putJson(route('portal.auth.estrutura.produtos.descricao', $produto->id), ['descricao' => 'Mesa de jantar.'])->assertOk();
        $this->assertSame(['descricao', 'mensagem'], array_keys($r->json()), 'a resposta ao cliente não muda');
        $this->semOrigem($r->getContent());
        Queue::assertPushed(PrepararProdutoNoPublicadorJob::class, 1);

        $sessao->putJson(route('portal.auth.estrutura.produtos.imagens.ordem', $v->id), ['ordem' => [999]])->assertOk();
        Queue::assertPushed(PrepararProdutoNoPublicadorJob::class, 2);
    }

    public function test_chave_desligada_nao_agenda(): void
    {
        config(['publicador.preparo_ia.ativo' => false]);
        $empresa = $this->empresaDoGabarito();
        $produto = EstruturaProduto::create(['company_id' => $empresa->id, 'codigo' => 'P-1', 'nome' => 'Mesa']);

        $this->withoutVite()->entrarNoPortal($empresa)
            ->putJson(route('portal.auth.estrutura.produtos.descricao', $produto->id), ['descricao' => 'Mesa.'])->assertOk();

        Queue::assertNotPushed(PrepararProdutoNoPublicadorJob::class);
    }
}
