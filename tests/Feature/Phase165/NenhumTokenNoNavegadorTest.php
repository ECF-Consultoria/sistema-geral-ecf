<?php

namespace Tests\Feature\Phase165;

use App\Models\MlAnuncioCriativoKit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Phase165\Concerns\CenarioCriativoDoPublicador;
use Tests\TestCase;

/**
 * Fase 165, Plano 05, Task 2 (CE165-12/D-13) — percorre as 9 rotas
 * `mlb.anuncios.publicador.criativos.*` (sucesso E recusas 404/422/409/429) e
 * prova que NENHUMA resposta JSON contém um token de 32 caracteres (kit,
 * slot ou portador) — nem como valor conhecido, nem por regex genérica, nem
 * como chave `token`/`kit_token` em qualquer nível. As URLs de imagem e
 * referência usam o `kit_id` numérico, nunca o token.
 */
class NenhumTokenNoNavegadorTest extends TestCase
{
    use CenarioCriativoDoPublicador;
    use RefreshDatabase;

    /** @var list<string> todo corpo JSON visto no fluxo — varredura final. */
    private array $corpos = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenarioCriativo('mlb_empresa');
        // O juiz da Fase 162 usa um contrato diferente do dublê que a trait
        // registra (ImageJudgementProvider, não ImageGenerationProvider) —
        // sem desligar, `ValidarCriativoIaJob` (despachado em fila `sync`
        // dentro do próprio `gerar()`) bateria numa chamada HTTP real e
        // quebraria em `Http::preventStrayRequests()` (mesmo ajuste do
        // 165-04-SUMMARY).
        config(['services.creative.validacao.ativa' => false]);
        // `.env` local tem APP_DEBUG=true — com debug ligado, o handler de
        // exceção do Laravel acrescenta `trace`/`file` ao JSON de QUALQUER
        // abort (inclusive o 404 de "kit não encontrado"), e esses caminhos
        // de arquivo batem na regex de 32 caracteres por acidente, sem
        // relação nenhuma com token. Desligado aqui para o teste refletir o
        // comportamento de produção (APP_DEBUG=false).
        config(['app.debug' => false]);
    }

    private function rotaAtual(array $query = []): string
    {
        return route('mlb.anuncios.publicador.criativos.atual', ['produto' => $this->produto->id, ...$query]);
    }

    private function rotaPlanejar(): string
    {
        return route('mlb.anuncios.publicador.criativos.kit.planejar', ['produto' => $this->produto->id]);
    }

    private function rotaStatus(int $kit): string
    {
        return route('mlb.anuncios.publicador.criativos.kit.status', ['produto' => $this->produto->id, 'kit' => $kit]);
    }

    private function rotaGerar(int $kit): string
    {
        return route('mlb.anuncios.publicador.criativos.kit.gerar', ['produto' => $this->produto->id, 'kit' => $kit]);
    }

    private function rotaReferencia(int $kit, int $indice): string
    {
        return route('mlb.anuncios.publicador.criativos.kit.referencia', ['produto' => $this->produto->id, 'kit' => $kit, 'indice' => $indice]);
    }

    private function rotaImagem(int $kit, int $indice): string
    {
        return route('mlb.anuncios.publicador.criativos.slot.imagem', ['produto' => $this->produto->id, 'kit' => $kit, 'indice' => $indice]);
    }

    private function rotaAprovarSlot(int $kit, int $indice): string
    {
        return route('mlb.anuncios.publicador.criativos.slot.aprovar', ['produto' => $this->produto->id, 'kit' => $kit, 'indice' => $indice]);
    }

    private function rotaRegenerar(int $kit, int $indice): string
    {
        return route('mlb.anuncios.publicador.criativos.slot.regenerar', ['produto' => $this->produto->id, 'kit' => $kit, 'indice' => $indice]);
    }

    private function rotaAprovarKit(int $kit): string
    {
        return route('mlb.anuncios.publicador.criativos.kit.aprovar', ['produto' => $this->produto->id, 'kit' => $kit]);
    }

    /** Guarda o corpo JSON (ignora binário — bytes de imagem casam com a regex de 32 chars por acaso, sem relação com token nenhum). */
    private function guardar(TestResponse $resp): TestResponse
    {
        $contentType = $resp->headers->get('Content-Type');
        if ($contentType !== null && str_contains($contentType, 'application/json')) {
            $this->corpos[] = $resp->getContent();
        }

        return $resp;
    }

    /** Varredura recursiva de chaves — nenhum nível do array pode ter `$chave` (T-165-20). */
    private function assertArrayNaoTemChaveEmNenhumNivel(array $array, string $chave): void
    {
        $this->assertArrayNotHasKey($chave, $array, "chave '{$chave}' encontrada numa resposta JSON devolvida ao navegador");

        foreach ($array as $valor) {
            if (is_array($valor)) {
                $this->assertArrayNaoTemChaveEmNenhumNivel($valor, $chave);
            }
        }
    }

    public function test_nenhum_token_de_32_caracteres_chega_ao_navegador_em_todo_o_fluxo(): void
    {
        $foto = $this->fotoComArquivo('GENERAL');
        $admin = $this->admin();

        // ─── atual — ANTES de existir kit ───
        $this->guardar($this->actingAs($admin)->getJson($this->rotaAtual(['grupo' => 'GENERAL'])))->assertOk();

        // ─── planejar ───
        $planejar = $this->guardar($this->actingAs($admin)->postJson($this->rotaPlanejar(), ['grupo' => 'GENERAL', 'imagens' => [$foto->id]]));
        $planejar->assertStatus(202);
        $kitId = (int) $planejar->json('kit_id');

        // ─── atual — DEPOIS de existir kit ───
        $this->guardar($this->actingAs($admin)->getJson($this->rotaAtual(['grupo' => 'GENERAL'])))->assertOk();

        // ─── gerar ───
        $this->guardar($this->actingAs($admin)->postJson($this->rotaGerar($kitId)))->assertStatus(202);

        // ─── kit.status (várias vezes — o painel faz polling) ───
        $this->guardar($this->actingAs($admin)->getJson($this->rotaStatus($kitId)))->assertOk();
        $status = $this->guardar($this->actingAs($admin)->getJson($this->rotaStatus($kitId)));
        $status->assertOk();

        // ─── referencia / imagem (binário — fora da varredura JSON, mas a URL que os aponta entra na checagem abaixo) ───
        $this->actingAs($admin)->get($this->rotaReferencia($kitId, 0))->assertOk();
        $this->actingAs($admin)->get($this->rotaImagem($kitId, 1))->assertOk();

        // ─── slot.aprovar ───
        $this->guardar($this->actingAs($admin)->postJson($this->rotaAprovarSlot($kitId, 1)))->assertOk();

        // ─── slot.regenerar ───
        $this->guardar($this->actingAs($admin)->postJson(
            $this->rotaRegenerar($kitId, 2),
            ['motivo' => 'ajustar o enquadramento'],
        ))->assertStatus(202);

        // ─── kit.aprovar ───
        $this->guardar($this->actingAs($admin)->postJson($this->rotaAprovarKit($kitId)))->assertOk();

        // ─── recusa 404 — kit inexistente ───
        $this->guardar($this->actingAs($admin)->getJson($this->rotaStatus(999999)))->assertStatus(404);

        // ─── recusa 422 — motivo acima do limite ───
        $this->guardar($this->actingAs($admin)->postJson(
            $this->rotaRegenerar($kitId, 1),
            ['motivo' => str_repeat('a', 400)],
        ))->assertStatus(422);

        // ─── recusa 422 — kit já aprovado ───
        $this->guardar($this->actingAs($admin)->postJson($this->rotaAprovarKit($kitId)))->assertStatus(422);

        // ─── recusa 409 — outra pessoa planejando o mesmo (rascunho, grupo) agora ───
        $lock = Cache::lock('criativo-kit-planejar-pub:' . $this->r->id . ':' . md5('GENERAL'), 5);
        $this->assertTrue($lock->get());
        $resp409 = $this->guardar($this->actingAs($admin)->postJson(
            $this->rotaPlanejar(),
            ['grupo' => 'GENERAL', 'imagens' => [$foto->id]],
        ));
        $lock->release();
        $resp409->assertStatus(409);

        // ─── recusa 429 — teto de custo do planejar ───
        $ultima429 = null;
        for ($i = 1; $i <= 15; $i++) {
            $ultima429 = $this->actingAs($admin)->postJson($this->rotaPlanejar(), ['grupo' => 'GENERAL']);
        }
        $this->guardar($ultima429)->assertStatus(429);

        // ═══ A varredura (T-165-20) ═══

        $kit = MlAnuncioCriativoKit::find($kitId);
        $corpoCompleto = implode('', $this->corpos);

        foreach ($this->tokensDoKit($kit) as $token) {
            $this->assertStringNotContainsString((string) $token, $corpoCompleto);
        }
        $this->assertDoesNotMatchRegularExpression('/(?<![A-Za-z0-9])[A-Za-z0-9]{32}(?![A-Za-z0-9])/', $corpoCompleto);

        foreach ($this->corpos as $corpo) {
            $json = json_decode($corpo, true);
            if (is_array($json)) {
                $this->assertArrayNaoTemChaveEmNenhumNivel($json, 'token');
                $this->assertArrayNaoTemChaveEmNenhumNivel($json, 'kit_token');
            }
        }

        foreach ($status->json('slots') as $slot) {
            if ($slot['imagem_url'] !== null) {
                $this->assertMatchesRegularExpression('#/criativos/kit/[0-9]+/#', $slot['imagem_url']);
            }
        }
        foreach ($status->json('referencias') as $ref) {
            $this->assertMatchesRegularExpression('#/criativos/kit/[0-9]+/#', $ref['url']);
        }
    }
}
