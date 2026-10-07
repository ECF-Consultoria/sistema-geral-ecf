<?php

namespace Tests\Unit\Quick261007Rmv;

use App\Services\Creative\Contracts\ImageGenerationProvider;
use App\Services\Creative\CreativePlanner;
use App\Services\Creative\CreativeSlotCatalog;
use App\Services\Creative\Dto\CreativeContext;
use App\Services\Creative\Dto\CreativeGenerationRequest;
use App\Services\Creative\Dto\CreativeGenerationResult;
use App\Services\Creative\ProductTruthBuilder;
use Tests\TestCase;

/**
 * Quick 261007-rmv — `CreativePlanner::planejar()` grava `podeTerTexto`/
 * `faltam` no `CreativePlan` (calculados com o MESMO Truth que decidiu os
 * slots), substituindo `comTextoHumanoReforcado()` (removido). É esse valor
 * que `PublicadorCriativoKitPresenter::paraTela()` lê de `kit.plano` para
 * avisar a tela, sem reconstruir Truth a cada leitura do kit.
 */
class CreativePlannerAvisoTextoTest extends TestCase
{
    private function contexto(array $atributos = [], string $produto = 'Produto de teste'): CreativeContext
    {
        return new CreativeContext(
            rascunhoId: 1,
            produto: $produto,
            marca: $atributos['BRAND'] ?? null,
            modelo: $atributos['MODEL'] ?? null,
            categoriaId: 'MLB1574',
            descricao: null,
            atributos: $atributos,
            variacoes: ['quantidade' => 0, 'combinacoes' => []],
            loja: 'Loja Teste',
            imagensReferencia: [],
            referenciasMeta: [['indice' => 0, 'mime' => 'image/jpeg', 'bytes' => 12345, 'nome' => 'foto.jpg']],
        );
    }

    private function truth(array $atributos = [])
    {
        return (new ProductTruthBuilder)->paraContexto($this->contexto($atributos));
    }

    /** Dublê que nunca preenche headline/badges — não é o foco deste teste. */
    private function providerSemTexto(array $tipos): ImageGenerationProvider
    {
        $slots = array_map(fn ($tipo) => ['tipo' => $tipo, 'objetivo' => 'x', 'cena' => 'y'], $tipos);
        $resposta = json_encode([
            'estrategia' => ['publico' => 'a', 'proposta_de_valor' => 'b', 'direcao_visual' => 'c'],
            'slots' => $slots,
        ]);

        return new class($resposta) implements ImageGenerationProvider {
            public function __construct(private string $resposta) {}

            public function gerarImagem(CreativeGenerationRequest $request): CreativeGenerationResult
            {
                throw new \RuntimeException('gerarImagem() não é usado pelo CreativePlanner.');
            }

            public function gerarTexto(string $prompt): string
            {
                return $this->resposta;
            }
        };
    }

    private function planner(ImageGenerationProvider $provider): CreativePlanner
    {
        return new CreativePlanner($provider, new CreativeSlotCatalog);
    }

    public function test_sem_fato_nenhum_do_cadastro_plano_sai_com_pode_ter_texto_falso_e_faltam_preenchido(): void
    {
        $plano = $this->planner($this->providerSemTexto(['hero', 'angles']))
            ->planejar($this->contexto(), $this->truth(), 7);

        $this->assertFalse($plano->podeTerTexto);
        $this->assertNotEmpty($plano->faltam);
        $this->assertSame($plano->podeTerTexto, $plano->paraAuditoria()['pode_ter_texto']);
        $this->assertSame($plano->faltam, $plano->paraAuditoria()['faltam']);
    }

    public function test_com_3_fatos_verificados_do_cadastro_plano_sai_com_pode_ter_texto_verdadeiro(): void
    {
        $atributos = ['MATERIAL' => 'MDF', 'COLOR' => 'Branco', 'BRAND' => 'ECF'];

        $plano = $this->planner($this->providerSemTexto(['hero', 'benefits']))
            ->planejar($this->contexto($atributos), $this->truth($atributos), 7);

        $this->assertTrue($plano->podeTerTexto);
        $this->assertSame([], $plano->faltam);
    }
}
