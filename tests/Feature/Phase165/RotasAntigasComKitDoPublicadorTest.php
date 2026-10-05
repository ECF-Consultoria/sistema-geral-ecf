<?php

namespace Tests\Feature\Phase165;

use App\Models\Company;
use App\Models\MlAnuncioCriativo;
use App\Models\MlAnuncioCriativoKit;
use App\Models\MlAnuncioRascunho;
use App\Models\User;
use App\Services\Creative\ReferenciaEfemeraService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\Feature\Phase165\Concerns\CenarioCriativoDoPublicador;
use Tests\TestCase;

/**
 * Fase 165, Plano 05, Task 2 (CE165-12/D-13) — não regressão do fluxo antigo
 * + o risco residual das rotas `criativo.*` do `MlbAnuncioController` diante
 * de um kit/criativo do Publicador.
 *
 * **A cadeia da D-13.** As rotas antigas endereçam kit e criativo por
 * TOKEN, sem nenhum filtro por origem: `criativoKitStatus($kitToken)` faz
 * `MlAnuncioCriativoKit::where('token', $kitToken)->first()` puro, e
 * `checarEscopoDoRascunho()` DEVOLVE SEM ABORTAR quando `$kit->rascunho` é
 * `null` (a relação por `rascunho_id`, que num kit do Publicador é sempre
 * `null` — a ponte nova é `pub_rascunho_id`). Isso significa que, SE o
 * token de um kit do Publicador chegasse ao navegador, as rotas antigas
 * devolveriam 200 com os dados do kit (sentido 1: velho lê novo). No
 * sentido inverso (2: novo leria velho apagado), a rota nova nunca aceita
 * token — só o `id` numérico escopado por `pub_rascunho_id` — então um
 * criativo antigo removido nunca "aparece" no Publicador por essa via.
 *
 * **Por que a 165 fecha o lado dela mesmo assim.** O controller novo
 * (`MlbPublicadorCriativoController`, D-13 no docblock da classe) nunca
 * expõe um token de 32 caracteres — nem na URL, nem no JSON — e isso é
 * PROVADO por `NenhumTokenNoNavegadorTest` (regex + chaves + tokens
 * conhecidos nas 9 rotas, sucesso e recusa). Sem o token, o navegador não
 * tem com que chamar a rota antiga para um kit/criativo do Publicador — o
 * id numérico sozinho não vale nada nas rotas antigas (elas buscam por
 * `token`, nunca por `id`).
 *
 * **A guarda de 1 linha é do outro dev.** Um `abort_if($kit->rascunho_id
 * === null && $kit->pub_rascunho_id !== null, 404)` (ou equivalente) nos
 * métodos antigos fecharia o sentido 1 por completo — foi proposta no
 * checkpoint de coordenação do `165-01-PLAN.md` e `165-01-SUMMARY.md`
 * registra a decisão de NÃO acrescentá-la (fora do `files_modified` do
 * plano: `MlbAnuncioController.php` não pertence a nenhum plano da 165).
 * Este risco fica aceito e registrado (T-165-20 do threat model do
 * `165-05-PLAN.md`) — só admin acessa o módulo, e o token nunca sai do
 * servidor pelo lado novo.
 *
 * **O caso (2) se liga sozinho.** `test_guarda_das_rotas_antigas_se_liga_sozinha_quando_chegar()`
 * chama a rota antiga com o token de um kit do Publicador: se vier 404 (a
 * guarda chegou), o teste passa a EXIGIR 404 nas demais rotas antigas
 * também — sem precisar editar este arquivo de novo. Enquanto não vier,
 * `markTestIncomplete()` documenta o risco aceito sem nunca enshrinar
 * (nunca afirmar 200 como comportamento correto).
 */
class RotasAntigasComKitDoPublicadorTest extends TestCase
{
    use CenarioCriativoDoPublicador;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenarioCriativo('mlb_empresa');
    }

    /** Um criativo PORTADOR do assistente antigo — `rascunho_id` preenchido, referência viva. */
    private function criativoAntigoComReferenciaViva(): MlAnuncioCriativo
    {
        $company = Company::factory()->create(['name' => 'Empresa do assistente antigo']);
        $rascunho = MlAnuncioRascunho::create([
            'company_id' => $company->id,
            'category_id' => 'MLB1574',
            'payload' => [
                'title' => 'Produto do assistente antigo',
                'category_id' => 'MLB1574',
                'description' => 'Descrição qualquer.',
                'attributes' => [],
                'pictures' => [],
            ],
            'status' => MlAnuncioRascunho::STATUS_RASCUNHO,
            'user_id' => User::factory()->create()->id,
        ]);

        $criativo = MlAnuncioCriativo::create([
            'token' => Str::random(32),
            'company_id' => $company->id,
            'rascunho_id' => $rascunho->id,
            'user_id' => $rascunho->user_id,
            'slot' => 'referencia',
            'status' => MlAnuncioCriativo::STATUS_PRONTO,
        ]);
        $refs = app(ReferenciaEfemeraService::class)->guardar($criativo, [UploadedFile::fake()->image('ref.jpg')]);
        $criativo->update(['referencias' => $refs]);

        return $criativo->fresh();
    }

    // ═══ Caso (1) — não regressão: o antigo nunca recebe kit do Publicador ═══

    public function test_rota_antiga_de_planejar_nunca_devolve_kit_do_publicador(): void
    {
        $kitDoPublicador = $this->kitProntoDoPublicador('GENERAL', 3);
        $statusAntes = $kitDoPublicador->status;
        $atualizadoEmAntes = $kitDoPublicador->updated_at;

        $criativoAntigo = $this->criativoAntigoComReferenciaViva();
        $admin = $this->admin();

        $resp = $this->actingAs($admin)->postJson(
            route('mlb.anuncios.criativo.kit.planejar', ['token' => $criativoAntigo->token]),
        );

        $resp->assertStatus(202);
        $kitTokenDevolvido = $resp->json('kit_token');

        $this->assertNotSame($kitDoPublicador->token, $kitTokenDevolvido);
        $this->assertNotSame((string) $kitDoPublicador->id, $kitTokenDevolvido);

        $kitCriado = MlAnuncioCriativoKit::where('token', $kitTokenDevolvido)->first();
        $this->assertNotNull($kitCriado, 'a rota antiga deveria ter criado um kit novo para o criativo antigo');
        $this->assertSame($criativoAntigo->rascunho_id, $kitCriado->rascunho_id);
        $this->assertNull($kitCriado->pub_rascunho_id);
        $this->assertNotSame($kitDoPublicador->id, $kitCriado->id);

        // O kit do Publicador não foi tocado.
        $kitDoPublicador->refresh();
        $this->assertSame($statusAntes, $kitDoPublicador->status);
        $this->assertEquals($atualizadoEmAntes, $kitDoPublicador->updated_at);
    }

    // ═══ Caso (2) — a guarda das rotas antigas, que se liga sozinha ═════════

    public function test_guarda_das_rotas_antigas_se_liga_sozinha_quando_chegar(): void
    {
        $kit = $this->kitProntoDoPublicador('GENERAL', 3);
        $portador = $kit->criativoReferencia;
        $admin = $this->admin();

        $respStatus = $this->actingAs($admin)->getJson(
            route('mlb.anuncios.criativo.kit.status', ['kit' => $kit->token]),
        );

        if ($respStatus->getStatusCode() !== 404) {
            $this->markTestIncomplete(
                'Rotas antigas criativo.* sem guarda para kit do Publicador — risco aceito em 2026-10-04 '
                . '(165-01-SUMMARY, T-165-20). O lado da 165 está fechado: nenhum token de 32 caracteres '
                . 'chega ao navegador (NenhumTokenNoNavegadorTest).',
            );
        }

        // A guarda chegou — passa a exigir 404 também nas demais rotas antigas.
        $respAprovar = $this->actingAs($admin)->postJson(
            route('mlb.anuncios.criativo.kit.aprovar', ['kit' => $kit->token]),
        );
        $respAprovar->assertStatus(404);

        $respGerar = $this->actingAs($admin)->postJson(
            route('mlb.anuncios.criativo.kit.gerar', ['kit' => $kit->token]),
        );
        $respGerar->assertStatus(404);

        $respPlanejar = $this->actingAs($admin)->postJson(
            route('mlb.anuncios.criativo.kit.planejar', ['token' => $portador->token]),
        );
        $respPlanejar->assertStatus(404);
    }
}
