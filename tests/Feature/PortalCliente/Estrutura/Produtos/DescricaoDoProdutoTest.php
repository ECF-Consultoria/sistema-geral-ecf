<?php

namespace Tests\Feature\PortalCliente\Estrutura\Produtos;

use App\Models\EstruturaProduto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * Descrição por produto na ficha do portal (Fase 172, D-02): isolamento por empresa,
 * limite, limpar, auditoria e prop da ficha.
 */
class DescricaoDoProdutoTest extends TestCase
{
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    private function produto($empresa): EstruturaProduto
    {
        return EstruturaProduto::create([
            'company_id' => $empresa->id, 'codigo' => 'P-'.uniqid(), 'nome' => 'Mesa Teste',
        ]);
    }

    public function test_grava_com_trim_e_volta_nas_props_da_ficha(): void
    {
        $empresa = $this->empresaDoGabarito();
        $produto = $this->produto($empresa);
        $sessao = $this->withoutVite()->entrarNoPortal($empresa);

        $r = $sessao->putJson(route('portal.auth.estrutura.produtos.descricao', $produto->id),
            ['descricao' => "  Mesa de jantar em madeira maciça.  \n"])->assertOk();

        $this->assertSame('Descrição salva.', $r->json('mensagem'));
        $this->assertSame('Mesa de jantar em madeira maciça.', $produto->fresh()->descricao);

        $sessao->get(route('portal.auth.estrutura.produtos.ficha', $produto->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('descricao', 'Mesa de jantar em madeira maciça.'));
    }

    public function test_vazio_ou_nulo_limpa_a_descricao(): void
    {
        $empresa = $this->empresaDoGabarito();
        $produto = $this->produto($empresa);
        $produto->update(['descricao' => 'Antiga']);
        $sessao = $this->entrarNoPortal($empresa);
        $url = route('portal.auth.estrutura.produtos.descricao', $produto->id);

        $sessao->putJson($url, ['descricao' => '   '])->assertOk();
        $this->assertNull($produto->fresh()->descricao);

        $produto->update(['descricao' => 'Outra']);
        $sessao->putJson($url, ['descricao' => null])->assertOk();
        $this->assertNull($produto->fresh()->descricao);
    }

    public function test_limite_de_5000_caracteres(): void
    {
        $empresa = $this->empresaDoGabarito();
        $produto = $this->produto($empresa);
        $sessao = $this->entrarNoPortal($empresa);
        $url = route('portal.auth.estrutura.produtos.descricao', $produto->id);

        $sessao->putJson($url, ['descricao' => str_repeat('a', 5000)])->assertOk();
        $r = $sessao->putJson($url, ['descricao' => str_repeat('a', 5001)])->assertStatus(422);
        $this->assertSame('A descrição pode ter até 5.000 caracteres.', $r->json('errors.descricao.0'));
    }

    public function test_produto_de_outra_empresa_responde_404_mesmo_com_corpo_invalido(): void
    {
        $minha = $this->empresaDoGabarito();
        $outra = \App\Models\Company::factory()->create();
        $alheio = $this->produto($outra);
        $sessao = $this->entrarNoPortal($minha);

        $sessao->putJson(route('portal.auth.estrutura.produtos.descricao', $alheio->id),
            ['descricao' => str_repeat('a', 6000)])->assertNotFound();
        $sessao->putJson(route('portal.auth.estrutura.produtos.descricao', $alheio->id), ['descricao' => 'x'])->assertNotFound();
        $this->assertNull($alheio->fresh()->descricao);
    }

    public function test_grava_auditoria_so_quando_muda(): void
    {
        $empresa = $this->empresaDoGabarito();
        $produto = $this->produto($empresa);
        $sessao = $this->entrarNoPortal($empresa);
        $url = route('portal.auth.estrutura.produtos.descricao', $produto->id);

        $sessao->putJson($url, ['descricao' => 'Texto'])->assertOk();
        $sessao->putJson($url, ['descricao' => 'Texto'])->assertOk();

        $logs = Activity::where('log_name', 'portal')->where('properties->evento', 'descricao_gravada')->get();
        $this->assertCount(1, $logs);
        $this->assertSame($produto->id, (int) $logs->first()->subject_id);
    }

    public function test_resposta_e_erro_nao_revelam_origem(): void
    {
        $empresa = $this->empresaDoGabarito();
        $produto = $this->produto($empresa);
        $sessao = $this->entrarNoPortal($empresa);
        $url = route('portal.auth.estrutura.produtos.descricao', $produto->id);

        $ok = $sessao->putJson($url, ['descricao' => 'Mesa de jantar em madeira maciça, acompanha manual.'])->assertOk();
        $erro = $sessao->putJson($url, ['descricao' => str_repeat('a', 5001)])->assertStatus(422);
        $invalido = $sessao->putJson($url, ['descricao' => ['x']])->assertStatus(422);

        foreach ([$ok, $erro, $invalido] as $resp) {
            foreach (['mercado', 'mercadolib', 'anúncio', 'anuncio', 'publicar', 'mlb'] as $termo) {
                $this->assertStringNotContainsStringIgnoringCase($termo, $resp->getContent());
            }
        }
    }
}
