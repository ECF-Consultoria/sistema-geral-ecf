<?php

namespace Tests\Feature\Phase130;

use App\Jobs\ReconciliarContratoClicksignJob;
use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Models\Servico;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 130, plano 130-03, Task 3 (Pitfall 3 do RESEARCH) — prova
 * arquitetural de que a reconciliação não pode estourar o rate limit da
 * Clicksign com um laço HTTP síncrono. O bucket `clicksign-webhook` é
 * 3/min GLOBAL para a conta INTEIRA (`AppServiceProvider::boot()`), não os
 * 20/min brutos medidos no sandbox — o número é o ponto de revisão da
 * Fase 132.
 *
 * O cenário só falha de verdade com mais de ~3 contratos presos no mesmo
 * dia: com 8 candidatos elegíveis, um laço HTTP síncrono dentro do comando
 * estouraria o bucket em segundos. Este teste prova que o comando não fala
 * HTTP — quem fala é o job, um de cada vez, sob o rate limiter.
 */
class ReconciliacaoRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private function servico(): Servico
    {
        return Servico::create([
            'nome'          => 'Assessoria',
            'valor_padrao'  => 0,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
        ]);
    }

    private function contratoElegivel(Servico $servico, int $i): ContratoAssinatura
    {
        $company = Company::factory()->create();

        return ContratoAssinatura::factory()->comSnapshot()->create([
            'company_id'            => $company->id,
            'servico_id'            => $servico->id,
            'status'                => ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS,
            'clicksign_envelope_id' => '00000000-0000-4000-8000-' . str_pad((string) (70 + $i), 12, '0', STR_PAD_LEFT),
        ]);
    }

    #[Test]
    public function comando_nao_fala_http_mesmo_com_oito_contratos_elegiveis(): void
    {
        Queue::fake();
        Http::fake();

        $servico = $this->servico();
        for ($i = 0; $i < 8; $i++) {
            $this->contratoElegivel($servico, $i);
        }

        $this->artisan('clicksign:reconciliar')->assertExitCode(0);

        // O comando faz SOMENTE SELECT + dispatch — quem fala com a
        // Clicksign é o job, isolado atrás do RateLimited.
        Http::assertNothingSent();
    }

    #[Test]
    public function oito_contratos_elegiveis_despacham_exatamente_oito_jobs(): void
    {
        Queue::fake();
        Http::fake();

        $servico = $this->servico();
        for ($i = 0; $i < 8; $i++) {
            $this->contratoElegivel($servico, $i);
        }

        $this->artisan('clicksign:reconciliar')->assertExitCode(0);

        Queue::assertPushed(ReconciliarContratoClicksignJob::class, 8);
    }

    #[Test]
    public function middleware_do_job_inclui_ratelimited_e_withoutoverlapping(): void
    {
        $servico  = $this->servico();
        $contrato = $this->contratoElegivel($servico, 0);

        $job         = new ReconciliarContratoClicksignJob($contrato);
        $middlewares = $job->middleware();

        $temRateLimited = false;
        $temWithoutOverlapping = false;

        foreach ($middlewares as $middleware) {
            if ($middleware instanceof RateLimited) {
                $temRateLimited = true;
            }
            if ($middleware instanceof WithoutOverlapping) {
                $temWithoutOverlapping = true;
            }
        }

        $this->assertTrue($temRateLimited, 'middleware() precisa incluir RateLimited');
        $this->assertTrue($temWithoutOverlapping, 'middleware() precisa incluir WithoutOverlapping');
    }

    #[Test]
    public function o_bucket_usado_pelo_job_e_exatamente_clicksign_webhook(): void
    {
        $servico  = $this->servico();
        $contrato = $this->contratoElegivel($servico, 1);

        $job = new ReconciliarContratoClicksignJob($contrato);

        $rateLimited = null;
        foreach ($job->middleware() as $middleware) {
            if ($middleware instanceof RateLimited) {
                $rateLimited = $middleware;
            }
        }

        $this->assertNotNull($rateLimited, 'nenhum RateLimited encontrado em middleware()');

        // Não lê o código-fonte como string — inspeciona o objeto real via
        // Reflection, a mesma técnica usada para provar comportamento sem
        // depender de paralelismo real de SO.
        $reflexao      = new \ReflectionProperty($rateLimited, 'limiterName');
        $nomeDoLimiter = $reflexao->getValue($rateLimited);

        $this->assertSame('clicksign-webhook', $nomeDoLimiter);
    }

    #[Test]
    public function o_limite_registrado_para_clicksign_webhook_e_tres_por_minuto(): void
    {
        $limiter = RateLimiter::limiter('clicksign-webhook');

        $this->assertNotNull($limiter, 'o bucket clicksign-webhook precisa estar registrado em AppServiceProvider::boot()');

        // O callback do limiter devolve um (ou uma coleção de) Limit — sem
        // job real para avaliar, chama o callback direto e inspeciona o
        // resultado (mesma API que RateLimited::handle() consome).
        $resultado = $limiter();
        $limit     = is_array($resultado) || $resultado instanceof \Illuminate\Support\Collection
            ? collect($resultado)->first()
            : $resultado;

        $this->assertSame(3, $limit->maxAttempts, 'se alguem afrouxar o bucket sem medir a janela de producao, este teste acusa');
        $this->assertSame(60, $limit->decaySeconds);
    }
}
