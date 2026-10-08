<?php

namespace Tests\Unit\Phase160;

use App\Jobs\GerarCriativoIaJob;
use App\Models\Company;
use App\Models\MlAnuncioCriativo;
use App\Models\MlAnuncioRascunho;
use App\Models\User;
use App\Services\Creative\Contracts\ImageGenerationProvider;
use App\Services\Creative\CreativeContextBuilder;
use App\Services\Creative\CreativeIdentidadeService;
use App\Services\Creative\CreativePromptBuilder;
use App\Services\Creative\ProductTruthBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Teste-guarda de GEN-05 / OPS-01 (Fase 160, Plano 04, Task 2) — a chave da
 * Gemini, o base64 (de entrada OU de saída) e o prompt completo NUNCA podem
 * aparecer em log, nem em sucesso nem em falha; e a chave nunca pode existir
 * em arquivo versionado.
 *
 * Captura TODAS as chamadas de log (qualquer nível, qualquer canal) via o
 * evento `Illuminate\Log\Events\MessageLogged` — e não `Log::spy()`. O spy
 * do Mockery intercepta o Facade inteiro (nada é de fato escrito), e
 * `shouldHaveReceived($nivel)` já VERIFICA (com `atLeast()->once()`
 * implícito) no instante em que é chamado, antes de qualquer `->withArgs()`
 * ou `->zeroOrMoreTimes()` encadeado conseguir afrouxar a contagem — o que
 * faz a asserção genérica "nenhuma chamada de QUALQUER nível vaza o segredo"
 * explodir em falso positivo para o nível que não foi chamado nenhuma vez.
 * O listener do evento não tem essa armadilha: inspeciona mensagem +
 * contexto serializado por `json_encode` de CADA chamada real, em qualquer
 * nível, sem exigir uma contagem mínima.
 */
class CreativeSegredoLogTest extends TestCase
{
    use RefreshDatabase;

    private const CHAVE_TESTE = 'chave-secreta-de-teste-nao-usar';

    /** Bytes CRUS e determinísticos da foto de referência — para conferir o base64 ENVIADO. */
    private const BYTES_REFERENCIA = 'bytes-crus-da-foto-de-referencia-de-teste';

    /** Bytes CRUS e determinísticos da imagem "gerada" pelo fake — para conferir o base64 RECEBIDO. */
    private const BYTES_GERADA = 'bytes-crus-da-imagem-gerada-de-teste';

    /** @var array<int, array{nivel:string, mensagem:string, contexto:array}> */
    private array $logsCapturados = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        // Canal "null" evita escrever em storage/logs/laravel.log durante o
        // teste — o `Logger` do Laravel ainda dispara `MessageLogged` por
        // cima, então a captura abaixo continua valendo.
        config(['logging.default' => 'null']);

        $this->logsCapturados = [];
        Event::listen(MessageLogged::class, function (MessageLogged $evento): void {
            $this->logsCapturados[] = [
                'nivel'    => $evento->level,
                'mensagem' => $evento->message,
                'contexto' => $evento->context,
            ];
        });

        config([
            'services.creative.gemini.base_url'        => 'https://gemini.teste/v1beta',
            'services.creative.gemini.key'              => self::CHAVE_TESTE,
            'services.creative.gemini.text_model'        => 'modelo-texto-teste',
            'services.creative.gemini.image_model'       => 'modelo-imagem-teste',
            'services.creative.gemini.image_fallbacks'   => '',
            'services.creative.gemini.text_fallbacks'    => '',
            'services.creative.gemini.aspect_ratio'      => '1:1',
            'services.creative.gemini.image_size'        => '2K',
            'services.creative.gemini.mime'              => 'image/jpeg',
            'services.creative.gemini.timeout'           => 30,
            'services.creative.gemini.connect_timeout'   => 10,
        ]);
    }

    private function respostaImagemOk(): array
    {
        return [
            'status' => 'completed',
            'model'  => 'modelo-imagem-teste',
            'steps'  => [
                ['type' => 'thought', 'signature' => 'abc123'],
                ['type' => 'model_output', 'content' => [[
                    'type'      => 'image',
                    'mime_type' => 'image/jpeg',
                    'data'      => base64_encode(self::BYTES_GERADA),
                ]]],
            ],
        ];
    }

    /**
     * Criativo com UMA referência gravada com bytes CRUS conhecidos —
     * `ReferenciaEfemeraService::bytesDe()` lê exatamente este conteúdo do
     * disco fake, então o base64 ENVIADO ao provedor é determinístico e
     * conferível aqui.
     */
    private function criativoComReferencia(): MlAnuncioCriativo
    {
        $company = Company::factory()->create(['name' => 'Empresa Teste']);

        $rascunho = MlAnuncioRascunho::create([
            'company_id'  => $company->id,
            'category_id' => 'MLB1574',
            'payload'     => [
                'title'       => 'Gabinete de cozinha',
                'category_id' => 'MLB1574',
                'description' => 'Descrição qualquer.',
                'attributes'  => [['id' => 'COLOR', 'value_name' => 'Branco']],
            ],
            'status'  => MlAnuncioRascunho::STATUS_RASCUNHO,
            'user_id' => User::factory()->create()->id,
        ]);

        $token   = Str::random(32);
        $caminho = "creative-referencias/{$token}/0.jpg";
        Storage::disk('local')->put($caminho, self::BYTES_REFERENCIA);

        return MlAnuncioCriativo::create([
            'token'       => $token,
            'company_id'  => $company->id,
            'rascunho_id' => $rascunho->id,
            'user_id'     => $rascunho->user_id,
            'slot'        => 'hero',
            'status'      => MlAnuncioCriativo::STATUS_PENDENTE,
            'referencias' => [
                ['indice' => 0, 'path' => $caminho, 'mime' => 'image/jpeg', 'bytes' => strlen(self::BYTES_REFERENCIA), 'nome' => 'foto.jpg', 'hash' => 'x'],
            ],
        ]);
    }

    private function rodar(MlAnuncioCriativo $criativo): void
    {
        (new GerarCriativoIaJob($criativo->id))->handle(
            app(ImageGenerationProvider::class),
            app(CreativeContextBuilder::class),
            app(ProductTruthBuilder::class),
            app(CreativePromptBuilder::class),
            app(CreativeIdentidadeService::class),
        );
    }

    /**
     * Varre TODAS as chamadas de log capturadas (qualquer nível) e garante
     * que nenhuma — mensagem OU contexto serializado — contém nenhum dos
     * segredos passados. Também confere que pelo menos uma chamada ocorreu
     * (senão o teste não provaria nada: um job que não loga nada passaria
     * por vazio, não por seguro).
     */
    private function assertNenhumLogContem(array $segredos): void
    {
        $this->assertNotEmpty($this->logsCapturados, 'Nenhuma chamada de log foi capturada — o teste não provaria nada.');

        foreach ($this->logsCapturados as $chamada) {
            $tudo = $chamada['mensagem'] . json_encode($chamada['contexto']);

            foreach ($segredos as $rotulo => $segredo) {
                if ($segredo === '' || $segredo === null) {
                    continue;
                }

                $this->assertStringNotContainsString(
                    (string) $segredo,
                    $tudo,
                    "Log::{$chamada['nivel']}() vazou o segredo [{$rotulo}]: \"{$chamada['mensagem']}\""
                );
            }
        }
    }

    // ═══ Caminho de sucesso ══════════════════════════════════════════════

    public function test_sucesso_nenhum_log_contem_chave_prompt_ou_base64(): void
    {
        Storage::fake('local');
        Http::fake(['gemini.teste/*' => Http::response($this->respostaImagemOk())]);

        $criativo = $this->criativoComReferencia();
        $this->rodar($criativo);

        $criativo->refresh();
        $this->assertSame(MlAnuncioCriativo::STATUS_PRONTO, $criativo->status);
        $this->assertNotEmpty($criativo->prompt);

        $this->assertNenhumLogContem([
            'chave'            => self::CHAVE_TESTE,
            'prompt completo'  => $criativo->prompt,
            'base64 enviado'   => base64_encode(self::BYTES_REFERENCIA),
            'base64 recebido'  => base64_encode(self::BYTES_GERADA),
        ]);
    }

    // ═══ Caminho de falha — Gemini 500 em todos os modelos ══════════════

    public function test_falha_em_todos_os_modelos_nenhum_log_contem_chave_prompt_ou_base64(): void
    {
        Storage::fake('local');
        Http::fake(['gemini.teste/*' => Http::response(['error' => 'overloaded'], 500)]);

        $criativo = $this->criativoComReferencia();

        $job = new GerarCriativoIaJob($criativo->id);

        try {
            $job->handle(
                app(ImageGenerationProvider::class),
                app(CreativeContextBuilder::class),
                app(ProductTruthBuilder::class),
                app(CreativePromptBuilder::class),
                app(CreativeIdentidadeService::class),
            );
            $this->fail('Deveria ter lançado RuntimeException (500 em todos os modelos).');
        } catch (\RuntimeException $e) {
            $job->failed($e);
        }

        $criativo->refresh();
        $this->assertSame(MlAnuncioCriativo::STATUS_ERRO, $criativo->status);
        $prompt = $criativo->prompt; // gravado ANTES da chamada ao provedor falhar

        $this->assertNenhumLogContem([
            'chave'           => self::CHAVE_TESTE,
            'prompt completo' => $prompt,
            'base64 enviado'  => base64_encode(self::BYTES_REFERENCIA),
        ]);
    }

    // ═══ OPS-01 — chave nunca em arquivo versionado ══════════════════════

    public function test_env_example_tem_gemini_api_key_vazio(): void
    {
        $conteudo = file_get_contents(base_path('.env.example'));

        $this->assertNotFalse($conteudo);
        $this->assertMatchesRegularExpression('/^GEMINI_API_KEY=\s*$/m', $conteudo);
    }

    public function test_nenhum_arquivo_de_app_ou_config_contem_prefixo_de_chave_google(): void
    {
        $achados = [];

        foreach (['app', 'config'] as $pasta) {
            $arquivos = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(base_path($pasta), \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($arquivos as $arquivo) {
                if ($arquivo->getExtension() !== 'php') {
                    continue;
                }

                $conteudo = file_get_contents($arquivo->getPathname());
                if ($conteudo !== false && str_contains($conteudo, 'AIza')) {
                    $achados[] = $arquivo->getPathname();
                }
            }
        }

        $this->assertSame([], $achados, 'Chave da Google (prefixo "AIza") encontrada em arquivo versionado: ' . implode(', ', $achados));
    }
}
