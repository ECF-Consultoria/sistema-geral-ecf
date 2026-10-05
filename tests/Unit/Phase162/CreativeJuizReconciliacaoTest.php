<?php

namespace Tests\Unit\Phase162;

use App\Models\Company;
use App\Models\MlAnuncioCriativo;
use App\Models\MlAnuncioCriativoKit;
use App\Models\User;
use App\Services\Creative\Contracts\ImageJudgementProvider;
use App\Services\Creative\CreativeJuiz;
use App\Services\Creative\CreativeJuizPromptBuilder;
use App\Services\Creative\CreativeSlotCatalog;
use App\Services\Creative\Dto\CreativeJudgementRequest;
use App\Services\Creative\Dto\CreativeJudgementResult;
use App\Services\Creative\ReferenciaEfemeraService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Cobertura da reconciliação do `CreativeJuiz` (Fase 162, D-06) — com um
 * `ImageJudgementProvider` anônimo devolvendo texto FIXO (nunca a API real).
 * Confere especialmente VAL-04 (fidelidade eliminatória): o modelo NUNCA
 * consegue transformar uma falha em aprovação.
 */
class CreativeJuizReconciliacaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        config(['services.creative.validacao.max_problemas' => 5]);
        config(['services.creative.validacao.max_validacoes_asset' => 3]);
    }

    /** Provider anônimo com texto fixo e contador de chamadas (nunca a API real). */
    private function providerComTexto(string $texto): ImageJudgementProvider
    {
        return new class($texto) implements ImageJudgementProvider
        {
            public int $chamadas = 0;

            public function __construct(private string $texto) {}

            public function julgar(CreativeJudgementRequest $pedido): CreativeJudgementResult
            {
                $this->chamadas++;

                return new CreativeJudgementResult($this->texto, 'modelo-fake', 10);
            }
        };
    }

    private function juizCom(ImageJudgementProvider $provider): CreativeJuiz
    {
        return new CreativeJuiz(
            $provider,
            app(ReferenciaEfemeraService::class),
            new CreativeSlotCatalog(),
            new CreativeJuizPromptBuilder(new CreativeSlotCatalog()),
        );
    }

    private function criarCriativo(array $overrides = []): MlAnuncioCriativo
    {
        $company = Company::factory()->create();

        $criativo = MlAnuncioCriativo::create(array_merge([
            'token'      => Str::random(32),
            'company_id' => $company->id,
            'user_id'    => User::factory()->create()->id,
            'slot'       => 'hero',
            'status'     => MlAnuncioCriativo::STATUS_PRONTO,
            'truth'      => ['marca' => null, 'modelo' => null, 'fatos_verificados' => [], 'contagens' => []],
            'validacoes' => 0,
        ], $overrides));

        $referencias = app(ReferenciaEfemeraService::class)->guardar(
            $criativo,
            [UploadedFile::fake()->image('original.jpg')],
        );
        $criativo->update(['referencias' => $referencias]);

        $caminhoImagem = "creative-geradas/{$criativo->token}.jpg";
        Storage::disk('local')->put($caminhoImagem, 'bytes-da-imagem-gerada');
        $criativo->update(['imagem_path' => $caminhoImagem, 'imagem_mime' => 'image/jpeg']);

        return $criativo->fresh();
    }

    /** (a) JSON cercado por ```json é lido. */
    public function test_json_cercado_por_crases_e_lido(): void
    {
        $criativo = $this->criarCriativo();
        $provider = $this->providerComTexto('```json
        {"fidelidade":"ok","veredito":"aprovada","motivo_curto":"","problemas":[]}
        ```');

        $validacao = $this->juizCom($provider)->julgar($criativo);

        $this->assertSame(MlAnuncioCriativo::VALIDACAO_APROVADA, $validacao->status);
        $this->assertSame('ok', $validacao->fidelidade);
    }

    /** (b) fidelidade: falha + veredito: aprovada => reprovada (VAL-04 eliminatório). */
    public function test_fidelidade_falha_reprova_mesmo_com_veredito_aprovada(): void
    {
        $criativo = $this->criarCriativo();
        $provider = $this->providerComTexto(json_encode([
            'fidelidade'   => 'falha',
            'veredito'     => 'aprovada',
            'motivo_curto' => 'produto trocado',
            'problemas'    => [],
        ]));

        $validacao = $this->juizCom($provider)->julgar($criativo);

        $this->assertSame(MlAnuncioCriativo::VALIDACAO_REPROVADA, $validacao->status);
        $this->assertSame('falha', $validacao->fidelidade);
    }

    /** (c) problema gravidade alta/tipo contagem com veredito aprovada => reprovada. */
    public function test_problema_gravidade_alta_tipo_contagem_reprova_mesmo_com_veredito_aprovada(): void
    {
        $criativo = $this->criarCriativo();
        $provider = $this->providerComTexto(json_encode([
            'fidelidade'   => 'ok',
            'veredito'     => 'aprovada',
            'motivo_curto' => 'contagem errada',
            'problemas'    => [
                ['tipo' => 'contagem', 'gravidade' => 'alta', 'explicacao' => 'três portas em vez de duas'],
            ],
        ]));

        $validacao = $this->juizCom($provider)->julgar($criativo);

        $this->assertSame(MlAnuncioCriativo::VALIDACAO_REPROVADA, $validacao->status);
    }

    /** (d) tipo/gravidade fora da whitelist viram outro/media. */
    public function test_tipo_e_gravidade_fora_da_whitelist_viram_outro_media(): void
    {
        $criativo = $this->criarCriativo();
        $provider = $this->providerComTexto(json_encode([
            'fidelidade' => 'ok',
            'veredito'   => 'aprovada',
            'problemas'  => [
                ['tipo' => 'algo_inventado', 'gravidade' => 'critica', 'explicacao' => 'x'],
            ],
        ]));

        $validacao = $this->juizCom($provider)->julgar($criativo);

        $this->assertSame('outro', $validacao->problemas[0]['tipo']);
        $this->assertSame('media', $validacao->problemas[0]['gravidade']);
    }

    /** (e) 50 problemas devolvidos são cortados em max_problemas (5). */
    public function test_cinquenta_problemas_sao_cortados_em_max_problemas(): void
    {
        $criativo = $this->criarCriativo();

        $problemas = array_fill(0, 50, ['tipo' => 'outro', 'gravidade' => 'baixa', 'explicacao' => 'x']);
        $provider = $this->providerComTexto(json_encode([
            'fidelidade' => 'ok', 'veredito' => 'aprovada', 'problemas' => $problemas,
        ]));

        $validacao = $this->juizCom($provider)->julgar($criativo);

        $this->assertCount(5, $validacao->problemas);
    }

    /** (f) explicacao de 2000 caracteres é cortada em 300 e sanitizada. */
    public function test_explicacao_longa_e_cortada_em_300_e_sanitizada(): void
    {
        $criativo = $this->criarCriativo();

        $explicacaoSuja = "\x07" . str_repeat('a', 2000);
        $provider = $this->providerComTexto(json_encode([
            'fidelidade' => 'ok', 'veredito' => 'aprovada',
            'problemas'  => [['tipo' => 'outro', 'gravidade' => 'baixa', 'explicacao' => $explicacaoSuja]],
        ]));

        $validacao = $this->juizCom($provider)->julgar($criativo);

        $explicacao = $validacao->problemas[0]['explicacao'];
        $this->assertSame(300, mb_strlen($explicacao));
        $this->assertStringNotContainsString("\x07", $explicacao);
    }

    /** (g) resposta que não é JSON vira indisponivel (nunca exceção não tratada, nunca aprovada). */
    public function test_resposta_que_nao_e_json_vira_indisponivel(): void
    {
        $criativo = $this->criarCriativo();
        $provider = $this->providerComTexto('isto não é json nenhum');

        $validacao = $this->juizCom($provider)->julgar($criativo);

        $this->assertSame(MlAnuncioCriativo::VALIDACAO_INDISPONIVEL, $validacao->status);
    }

    /** (h) julgarEGravar() grava validacao_status/validacao/validacao_em e incrementa validacoes no criativo e no kit. */
    public function test_julgar_e_gravar_persiste_veredito_e_incrementa_contadores(): void
    {
        $kit = MlAnuncioCriativoKit::create([
            'token'  => Str::random(32),
            'status' => MlAnuncioCriativoKit::STATUS_GERANDO,
        ]);

        $criativo = $this->criarCriativo(['kit_id' => $kit->id]);
        $provider = $this->providerComTexto(json_encode([
            'fidelidade' => 'ok', 'veredito' => 'aprovada', 'motivo_curto' => 'tudo certo', 'problemas' => [],
        ]));

        $this->juizCom($provider)->julgarEGravar($criativo);

        $criativo->refresh();
        $kit->refresh();

        $this->assertSame(MlAnuncioCriativo::VALIDACAO_APROVADA, $criativo->validacao_status);
        $this->assertIsArray($criativo->validacao);
        $this->assertSame('ok', $criativo->validacao['fidelidade']);
        $this->assertNotNull($criativo->validacao_em);
        $this->assertSame(1, $criativo->validacoes);
        $this->assertSame(1, $kit->validacoes);
    }

    /** (i) com validacoes já no teto, NÃO chama o provedor (contador do fake fica em 0). */
    public function test_com_validacoes_no_teto_nao_chama_o_provedor(): void
    {
        $criativo = $this->criarCriativo(['validacoes' => 3]);

        $provider = $this->providerComTexto(json_encode([
            'fidelidade' => 'ok', 'veredito' => 'aprovada', 'problemas' => [],
        ]));

        $validacao = $this->juizCom($provider)->julgarEGravar($criativo);

        $this->assertSame(MlAnuncioCriativo::VALIDACAO_INDISPONIVEL, $validacao->status);
        $this->assertSame(0, $provider->chamadas);
    }
}
