<?php

namespace Tests\Feature\Phase165;

use App\Jobs\PlanejarKitCriativosJob;
use App\Models\Company;
use App\Models\MlAnuncioCriativo;
use App\Models\MlAnuncioCriativoKit;
use App\Models\MlAnuncioRascunho;
use App\Models\User;
use App\Services\Creative\CreativeContextBuilder;
use App\Services\Creative\ProductTruthBuilder;
use App\Services\Creative\ReferenciaEfemeraService;
use App\Support\Publicador\Imagem\ResolvedorGruposImagem as R;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Phase165\Concerns\CenarioCriativoDoPublicador;
use Tests\TestCase;

/**
 * Fase 165, Plano 02, Task 2 — o segundo caminho do `CreativeContextBuilder`
 * (D-02): com `pub_rascunho_id`, o contexto vem do rascunho do Publicador.
 * O caminho do `payload` antigo é testado byte a byte igual (T-165-04).
 */
class CreativeContextBuilderPublicadorTest extends TestCase
{
    use CenarioCriativoDoPublicador;
    use RefreshDatabase;

    // ═══ Ramo novo: portador e slot do Publicador ═══

    public function test_portador_do_publicador_gera_contexto_do_rascunho(): void
    {
        $this->montarCenarioCriativo('mlb_empresa');
        $portador = $this->portadorDoPublicador(R::GERAL);

        $ctx = app(CreativeContextBuilder::class)->paraCriativo($portador);

        $this->assertSame('Cadeira Escritório Executiva ECF Giratória', $ctx->produto);
        $this->assertSame('MLB193945', $ctx->categoriaId);
        $this->assertSame('Cadeira executiva giratória.', $ctx->descricao);
        $this->assertSame('ECF', $ctx->atributos['BRAND'] ?? null);
        $this->assertArrayNotHasKey('REQUIRES_ASSEMBLY', $ctx->atributos);
        $this->assertSame('ECF', $ctx->marca);
        $this->assertSame('Executiva', $ctx->modelo);
        $this->assertSame('Loja Incubadora', $ctx->loja);
        $this->assertSame(0, $ctx->rascunhoId);
        $this->assertSame($this->r->id, $ctx->pubRascunhoId);
        $this->assertSame($this->r->id, $ctx->paraAuditoria()['pub_rascunho_id']);
    }

    public function test_slot_de_kit_do_publicador_cai_no_ramo_novo(): void
    {
        $this->montarCenarioCriativo('mlb_empresa');
        $kit = $this->kitProntoDoPublicador(R::GERAL, 3);
        $slot = $kit->slots()->first();

        // O slot não tem pub_rascunho_id próprio: resolve pelo kit.
        $this->assertNull($slot->pub_rascunho_id);

        $ctx = app(CreativeContextBuilder::class)->paraCriativo($slot);

        $this->assertSame($this->r->id, $ctx->pubRascunhoId);
        $this->assertSame('Cadeira Escritório Executiva ECF Giratória', $ctx->produto);
    }

    // ═══ Contexto da variação (D-14) ═══

    public function test_grupo_da_variacao_poe_a_cor_nos_fatos_e_tira_a_claim(): void
    {
        $this->montarCenarioCriativo('mlb_empresa');
        $grupoAzul = $this->comVariacaoDeCor();
        $portador = $this->portadorDoPublicador($grupoAzul);

        $ctx = app(CreativeContextBuilder::class)->paraCriativo($portador);
        $this->assertSame('Azul', $ctx->atributos['COLOR'] ?? null);

        $truth = app(ProductTruthBuilder::class)->paraContexto($ctx);
        $this->assertContains('Azul', $truth->fatosVerificados);
        $this->assertNotContains(
            'Não afirme nem altere a cor do produto — não há cor confirmada no cadastro.',
            $truth->claimsProibidas,
        );
    }

    public function test_galeria_geral_sem_cor_no_cadastro_mantem_a_claim(): void
    {
        $this->montarCenarioCriativo('mlb_empresa');
        $this->comVariacaoDeCor(); // liga a variação, mas pedimos a galeria geral abaixo.
        $portador = $this->portadorDoPublicador(R::GERAL);

        $ctx = app(CreativeContextBuilder::class)->paraCriativo($portador);
        $this->assertArrayNotHasKey('COLOR', $ctx->atributos);

        $truth = app(ProductTruthBuilder::class)->paraContexto($ctx);
        $this->assertContains(
            'Não afirme nem altere a cor do produto — não há cor confirmada no cadastro.',
            $truth->claimsProibidas,
        );
    }

    // ═══ Rascunho do Publicador apagado ═══

    public function test_pub_rascunho_apagado_zera_a_coluna_e_cai_na_mensagem_antiga(): void
    {
        $this->montarCenarioCriativo('mlb_empresa');
        $portador = $this->portadorDoPublicador(R::GERAL);

        $this->r->delete();
        $portador->refresh();
        $this->assertNull($portador->pub_rascunho_id);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('O rascunho deste criativo não existe mais — não há contexto para gerar a partir dele.');

        app(CreativeContextBuilder::class)->paraCriativo($portador);
    }

    /**
     * Só este caso cobre a mensagem NOVA: a corrida em que o id ainda
     * aponta para um rascunho que sumiu entre a leitura do criativo e a
     * montagem do contexto. `PRAGMA defer_foreign_keys = ON` — NÃO
     * `foreign_keys = OFF`, que é ignorado dentro da transação do
     * RefreshDatabase (medido em 04/10: a FK segue 1 e o insert dá
     * SQLSTATE 23000). A checagem adiada só rodaria no COMMIT, que o
     * RefreshDatabase nunca faz — nada a religar depois.
     */
    public function test_corrida_com_id_orfao_cai_na_mensagem_nova(): void
    {
        $this->montarCenarioCriativo('mlb_empresa');
        $portador = $this->portadorDoPublicador(R::GERAL);

        DB::statement('PRAGMA defer_foreign_keys = ON');
        MlAnuncioCriativo::whereKey($portador->id)->update(['pub_rascunho_id' => 999999]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('O rascunho do Publicador deste criativo não existe mais — não há contexto para gerar a partir dele.');

        app(CreativeContextBuilder::class)->paraCriativo($portador->fresh());
    }

    // ═══ Caminho antigo — byte a byte igual (T-165-04) ═══

    public function test_caminho_antigo_do_payload_fica_identico(): void
    {
        $this->montarCenarioCriativo('mlb_empresa');

        $company = Company::factory()->create(['name' => 'Empresa Teste']);
        $user = User::factory()->create();
        $rascunho = MlAnuncioRascunho::create([
            'company_id'  => $company->id,
            'category_id' => 'MLB1574',
            'payload'     => [
                'title'        => 'Produto de teste',
                'category_id'  => 'MLB1574',
                'description'  => 'Descrição antiga.',
                'attributes'   => [
                    ['id' => 'BRAND', 'value_name' => 'ECF'],
                    ['id' => 'MODEL', 'value_name' => 'X1'],
                ],
                'variations' => [
                    ['attribute_combinations' => [['value_name' => 'Azul']]],
                ],
            ],
            'status'  => MlAnuncioRascunho::STATUS_RASCUNHO,
            'user_id' => $user->id,
        ]);
        $criativo = MlAnuncioCriativo::create([
            'token'       => Str::random(32),
            'company_id'  => $company->id,
            'rascunho_id' => $rascunho->id,
            'user_id'     => $user->id,
            'slot'        => 'hero',
            'status'      => MlAnuncioCriativo::STATUS_PENDENTE,
        ]);
        $refs = app(ReferenciaEfemeraService::class)->guardar($criativo, [UploadedFile::fake()->image('a.jpg')]);
        $criativo->update(['referencias' => $refs]);
        $criativo = $criativo->fresh();

        $ctx = app(CreativeContextBuilder::class)->paraCriativo($criativo);

        $auditoria = $ctx->paraAuditoria();
        $this->assertSame([
            'rascunho_id', 'produto', 'marca', 'modelo', 'categoria_id', 'descricao',
            'atributos', 'variacoes', 'loja', 'referencias_meta',
        ], array_keys($auditoria));
        $this->assertArrayNotHasKey('pub_rascunho_id', $auditoria);
        $this->assertSame([
            'rascunho_id'      => $rascunho->id,
            'produto'          => 'Produto de teste',
            'marca'            => 'ECF',
            'modelo'           => 'X1',
            'categoria_id'     => 'MLB1574',
            'descricao'        => 'Descrição antiga.',
            'atributos'        => ['BRAND' => 'ECF', 'MODEL' => 'X1'],
            'variacoes'        => ['quantidade' => 1, 'combinacoes' => [['Azul']]],
            'loja'             => 'Empresa Teste',
            'referencias_meta' => $auditoria['referencias_meta'],
        ], $auditoria);
        $this->assertNull($ctx->pubRascunhoId);

        $rascunho->delete();
        $criativo->refresh();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('O rascunho deste criativo não existe mais — não há contexto para gerar a partir dele.');
        app(CreativeContextBuilder::class)->paraCriativo($criativo);
    }

    // ═══ Planejamento do kit do Publicador (jobs sem mudança) ═══

    public function test_kit_do_publicador_planeja_pelo_ramo_novo(): void
    {
        $this->montarCenarioCriativo('mlb_empresa');
        $portador = $this->portadorDoPublicador(R::GERAL);

        $kit = MlAnuncioCriativoKit::create([
            'token'                  => Str::random(32),
            'company_id'             => $portador->company_id,
            'mlb_empresa_id'         => $portador->mlb_empresa_id,
            'pub_rascunho_id'        => $this->r->id,
            'pub_grupo'              => R::GERAL,
            'criativo_referencia_id' => $portador->id,
            'status'                 => MlAnuncioCriativoKit::STATUS_PLANEJANDO,
        ]);

        PlanejarKitCriativosJob::dispatchSync($portador->id, $kit->id);

        $kit->refresh();
        $this->assertSame(MlAnuncioCriativoKit::STATUS_PLANEJADO, $kit->status);
        // Quick 261007-kit2 (2026-10-07): SLOTS_PADRAO caiu de 7 para 2.
        $this->assertSame(2, $kit->slots()->count());

        $slot = $kit->slots()->first();
        $this->assertSame($this->r->id, $slot->pubRascunhoIdEfetivo());
    }
}
