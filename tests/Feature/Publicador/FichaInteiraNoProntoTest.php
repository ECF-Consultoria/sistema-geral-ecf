<?php

namespace Tests\Feature\Publicador;

use App\Models\PubRascunho;
use App\Services\Publicador\EditorRascunhoService;
use App\Support\Publicador\Schema\AtributoClassificado as A;
use App\Support\Publicador\Schema\ClassificadorAtributos;
use App\Support\Publicador\Schema\ContextoClassificacao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Publicador\Concerns\CenarioCadeira;
use Tests\TestCase;

/**
 * 29/10/2026, o usuário, num "Mini Berço" que a vitrine dizia "Pronto": "os campos de ficha técnica falta muita coisa".
 * Decisão: o produto só fica pronto com a ficha técnica INTEIRA — cada campo das características principais e da ficha
 * preenchido ou marcado "Não se aplica" (V-ATT-13). Nos schemas reais, o que sobra vazio são OPCIONAIS (o "recomendado"
 * sem "obrigatório" quase não existe), então a regra é sobre eles.
 */
class FichaInteiraNoProntoTest extends TestCase
{
    use CenarioCadeira;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
        $this->fakeMl();
        // O cenário desliga a regra (é de publicação); aqui ela é o assunto.
        config(['publicador.exigir_ficha_inteira' => true]);
    }

    private function resumo(): array
    {
        app(EditorRascunhoService::class)->estado($this->r->fresh());

        return (array) PubRascunho::findOrFail($this->r->id)->step_state['resumo'];
    }

    /** @return list<string> os campos da ficha visível da cadeira que o cenário não preenche */
    private function vazios(): array
    {
        $schema = (new ClassificadorAtributos())->classificar(self::schema(self::CADEIRA), new ContextoClassificacao());

        return array_values(array_filter(array_keys($schema->atributos), fn (string $id) => ! isset(self::ATRIBUTOS[$id])
            && $schema->atributos[$id]->editavel()
            && in_array($schema->atributos[$id]->secao, [A::SECAO_PRINCIPAIS, A::SECAO_FICHA], true)));
    }

    public function test_so_os_obrigatorios_preenchidos_nao_e_pronto(): void
    {
        $resumo = $this->resumo();

        $this->assertGreaterThanOrEqual(count($this->vazios()), $resumo['bloqueios'], 'cada campo vazio da ficha é uma pendência');
        $textos = implode(' | ', array_column($resumo['itens'], 'mensagem'));
        $this->assertStringContainsString('ou marque «Não se aplica»', $textos);
        $this->assertSame(EditorRascunhoService::VERSAO_DO_RESUMO, $resumo['versao']);
    }

    public function test_nao_se_aplica_no_resto_da_ficha_deixa_pronto(): void
    {
        $naoSeAplica = array_fill_keys($this->vazios(), ['value_id' => '-1', 'value_name' => null]);
        $this->repo->gravarAtributos($this->r->fresh(), [...self::ATRIBUTOS, ...$naoSeAplica]);

        $this->assertSame(0, $this->resumo()['bloqueios']);
    }

    public function test_desligada_pela_configuracao_volta_a_regra_antiga(): void
    {
        config(['publicador.exigir_ficha_inteira' => false]);

        $this->assertSame(0, $this->resumo()['bloqueios']);
    }
}
