<?php

namespace Tests\Feature\Quick261016Mwe;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Phase165\Concerns\CenarioCriativoDoPublicador;
use Tests\TestCase;

/**
 * Quick 261016-mwe — "ao terminar a regeneração, a imagem não aparece sozinha; tive que atualizar a
 * página". A regeneração sobrescreve o arquivo no MESMO caminho (`{token}/{slot}.jpg`) e a URL da
 * imagem era sempre a mesma: o `src` do <img> não mudava e o navegador não pedia de novo. A URL agora
 * leva `v`, que muda a cada geração.
 */
class ImagemRegeneradaTrocaNaTelaTest extends TestCase
{
    use CenarioCriativoDoPublicador;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenarioCriativo('mlb_empresa');
    }

    private function urlDaImagem(int $kitId, int $posicao = 0): string
    {
        return (string) $this->actingAs($this->admin())->getJson(route('mlb.anuncios.publicador.criativos.kit.status', [
            'produto' => $this->produto->id, 'kit' => $kitId,
        ]))->assertOk()->json("slots.{$posicao}.imagem_url");
    }

    public function test_url_da_imagem_muda_quando_a_imagem_e_gerada_de_novo(): void
    {
        $kit = $this->kitProntoDoPublicador('GENERAL', 2);
        $slot = $kit->slots()->where('slot_indice', 1)->first();
        $slot->update(['finished_at' => now()->subMinutes(5)]);

        $antes = $this->urlDaImagem($kit->id);
        $this->assertStringContainsString('v=', $antes);

        // A regeneração termina: mesmo caminho de arquivo, `finished_at` novo.
        $slot->update(['finished_at' => now()]);
        $depois = $this->urlDaImagem($kit->id);

        $this->assertNotSame($antes, $depois);
        // Mesma rota, só a versão muda — e ela continua servindo a imagem.
        $this->assertSame(strtok($antes, '?'), strtok($depois, '?'));
        $this->actingAs($this->admin())->get($depois)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_a_url_nao_muda_entre_duas_leituras_sem_imagem_nova(): void
    {
        $kit = $this->kitProntoDoPublicador('GENERAL', 2);
        $kit->slots()->update(['finished_at' => now()->subMinutes(2)]);

        // Senão o <img> recarregaria a cada consulta de 5 s do acompanhamento.
        $this->assertSame($this->urlDaImagem($kit->id), $this->urlDaImagem($kit->id));
    }
}
