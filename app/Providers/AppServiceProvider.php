<?php

namespace App\Providers;

use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Phase 20 — registra EcfDriveService como singleton resolvendo de config/services.php
        $this->app->singleton(\App\Services\EcfDriveService::class, function ($app) {
            return new \App\Services\EcfDriveService(
                config('services.ecf.base'),
                config('services.ecf.key'),
            );
        });

        // Phase 41-04 CR-01 — MercadoLivreAdsService PRECISA ser singleton porque
        // ShadowRunService::run() le getLastRunMetrics() de uma instancia que tem
        // que ser a MESMA usada pelo MercadoLivreSugadoresProvider durante
        // analyzeCompany(provider='ml'). Sem singleton, Laravel resolve 2
        // instancias distintas via DI: a do provider acumula metricas reais,
        // a do ShadowRunService devolve zeros — quebrando o objetivo do Plan 41-04
        // (telemetria ml_metrics no summary JSON usada pelo cut-over Phase 42).
        $this->app->singleton(\App\Services\Sugadores\MercadoLivreAdsService::class);

        // Quick 261001-nkx — Creative Engine V0.1 (spike): amarra o contrato
        // de geração de imagem à implementação Gemini. Trocar de provedor no
        // futuro é mudar esta linha, não os chamadores.
        $this->app->singleton(
            \App\Services\Creative\Contracts\ImageGenerationProvider::class,
            \App\Services\Creative\GeminiImageProvider::class,
        );

        // Fase 162 (D-06) — contrato SEPARADO de julgamento (juiz de visão).
        // Mesma implementação Gemini, amarrada ao contrato novo e estreito
        // (ver docblock de ImageJudgementProvider para o motivo de não ter
        // entrado como método novo no contrato de geração).
        $this->app->singleton(
            \App\Services\Creative\Contracts\ImageJudgementProvider::class,
            \App\Services\Creative\GeminiImageProvider::class,
        );

        // Fase 135 Plano 03 — catálogo fechado de resolvers automáticos do
        // Onboarding geral (D-09). Lista EXPLÍCITA de instâncias — nunca
        // descoberta implícita por diretório. Os Planos 05/06 acrescentam
        // mais resolvers a esta mesma lista.
        $this->app->singleton(\App\Services\Onboarding\OnboardingResolverFactory::class, function ($app) {
            return new \App\Services\Onboarding\OnboardingResolverFactory([
                $app->make(\App\Services\Onboarding\Resolvers\AdmanAccountIdResolver::class),
                $app->make(\App\Services\Onboarding\Resolvers\MlTokenAtivoResolver::class),
                // Fase 135 Plano 06 — sondas de rede (Job-only, Pitfall 2).
                $app->make(\App\Services\Onboarding\Resolvers\AdmanGrantResolver::class),
                $app->make(\App\Services\Onboarding\Resolvers\MetricasContaResolver::class),
                // Fase 135 Plano 07 — passo 8, único resolver autorizado a
                // setar a chave reservada coleta_em_andamento (D-11). Fecha
                // o catálogo com as 5 chaves de OnboardingPasso::AUTO_FONTES.
                $app->make(\App\Services\Onboarding\Resolvers\AcervoColetadoResolver::class),
                // Relatorio inicial (PDF §3) — fecha so com as tres secoes de
                // analise escritas, nunca so com o retrato de dados.
                $app->make(\App\Services\Onboarding\Resolvers\RelatorioInicialResolver::class),
                // ── Itens do fluxo consolidado de 19/08 ────────────────────
                // Todos SÍNCRONOS e sem rede: fecham lendo uma tabela do
                // próprio módulo. Continuam sendo `auto_fonte` em vez de
                // conclusão manual pelo motivo que o RelatorioInicialResolver
                // já provou (D-19): com "marcar como feito", alguém fecha a
                // etapa sem ter respondido nada.
                $app->make(\App\Services\Onboarding\Resolvers\ConfirmacaoResolver::class),
                $app->make(\App\Services\Onboarding\Resolvers\InvestimentoResolver::class),
                $app->make(\App\Services\Onboarding\Resolvers\InvestimentoPublicidadeResolver::class),
                $app->make(\App\Services\Onboarding\Resolvers\PontoContatoResolver::class),
                $app->make(\App\Services\Onboarding\Resolvers\ParticipantesResolver::class),
                $app->make(\App\Services\Onboarding\Resolvers\AgendaQuinzenalResolver::class),
                $app->make(\App\Services\Onboarding\Resolvers\AnalistaDefinidoResolver::class),
            ]);
        });
    }

    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Phase 26 — Rate limiter para receiver de webhooks do ECF Drive.
        // 600 req/min por IP: cobre ~150 empresas × 6 eventos com margem (D-K do PLAN).
        // Exceder → 429 Too Many Requests. Defesa contra DDoS/replay massivo.
        RateLimiter::for('ecf-webhook', function (Request $request) {
            return Limit::perMinute(600)->by($request->ip());
        });

        // ─── Portal do Cliente — os primeiros limitadores de ACESSO ──────
        //
        // Dupla chave (identidade E IP) de propósito. Só por IP, um escritório
        // ou operadora com NAT derrubaria clientes legítimos que dividem a
        // saída; só por identidade, um atacante com uma lista de e-mails
        // contornaria o limite trocando o alvo. Os dois juntos fecham os dois
        // caminhos.

        // Pedir código: o gargalo real é o e-mail chegar — pedir mais que isso
        // não ajuda ninguém legítimo.
        RateLimiter::for('portal-codigo', function (Request $request) {
            $email = Str::lower(trim((string) $request->input('email')));

            return [
                Limit::perMinute(5)->by('portal-codigo-email:'.$email),
                Limit::perMinute(15)->by('portal-codigo-ip:'.$request->ip()),
            ];
        });

        // Validar: é aqui que se tenta adivinhar. O teto de 5 tentativas POR
        // CÓDIGO já existe no banco; este limite é a segunda rede, contra quem
        // pede código novo a cada 5 palpites.
        RateLimiter::for('portal-validar', function (Request $request) {
            $email = Str::lower(trim((string) $request->input('email')));

            return [
                Limit::perMinute(10)->by('portal-validar-email:'.$email),
                Limit::perMinute(30)->by('portal-validar-ip:'.$request->ip()),
            ];
        });

        // Phase 30 D-01 — Rate limiter GLOBAL para chamadas à Adman MCP.
        // 8/min deixa folga de 2 req sobre o hard limit 10/min/key da Adman.
        // 'global' faz workers concorrentes (ecf-worker_00/01) caírem no MESMO
        // bucket via cache Redis (atomic SETNX), evitando que cada worker
        // respeite 8/min isoladamente e juntos estourem 16/min.
        // Jobs com middleware RateLimited('adman-api') ficam em delayed quando
        // o limite estoura — NÃO falham, só atrasam até a janela liberar.
        RateLimiter::for('adman-api', function () {
            return Limit::perMinute(8)->by('global');
        });

        // Phase 41 — Rate limiter ML por seller (NAO global). 60 req/min por seller_id
        // alinha com §3 do plano de migracao Sugadores Adman→ML ("Comecar conservador,
        // 60 req/min por seller"). Bucket dinamico via Limit::by($sellerId) — workers
        // concorrentes batem no MESMO bucket por seller via cache backend.
        // Aplicado por MercadoLivreAdsService::withRateLimit antes de cada chamada
        // HTTP a Mercado Ads. Excedeu → RuntimeException (NAO 429 delayed: o job
        // sugadores ML eh idempotente e deve abortar/relogar, nao acumular delay).
        RateLimiter::for('ml-api', function (Request $request, $sellerId = 'unknown') {
            return Limit::perMinute(60)->by($sellerId);
        });

        // Fase 127 Plano 127-05 (D-01) — Rate limiter GLOBAL para a montagem
        // de envelope de contrato na Clicksign. Um envelope consome 15 das
        // 20 chamadas/min MEDIDAS na janela do sandbox (§1 do empírico,
        // 127-CONTEXT.md §restricao_medida) — 1/min deixa 5 de folga para o
        // resto da atividade na conta (clicksign:sondar-modelo, consultas
        // manuais). `by('global')` porque o rate limit é da CONTA inteira,
        // não por empresa: duas empresas gerando contrato ao mesmo tempo
        // estouram tanto quanto dois serviços da mesma empresa — um
        // `->delay()` calculado por empresa não cobriria esse caso.
        // GerarContratoAssinaturaJob::middleware() usa este bucket junto
        // com WithoutOverlapping (a corrida entre tooManyAttempts()/hit()
        // do RateLimited sozinho, rara, ainda assim somaria até 30 chamadas
        // com um envelope custando 15 de 20).
        // ⚠️ A janela de PRODUÇÃO nunca foi medida (gate 2 do plano 127-07)
        // — este número (1/min) é o ponto a revisar quando for.
        RateLimiter::for('clicksign-envelope', function () {
            return Limit::perMinute(1)->by('global');
        });

        // Fase 129 Plano 129-03 (CLICK-06, D-06) — Rate limiter GLOBAL para
        // o processamento de eventos de webhook Clicksign na fila
        // (ProcessarEventoClicksignJob). Aritmética explícita: a janela
        // MEDIDA no sandbox é de 20 chamadas/min para a conta INTEIRA
        // (§1 do empírico); cada evento de webhook processado custa 2
        // chamadas (consultarEnvelope() + listarEventosDoDocumento()). A
        // 3/min deste bucket = 6 chamadas/min, deixando folga para uma
        // montagem de envelope (15 chamadas, bucket `clicksign-envelope` a
        // 1/min) acontecer no MESMO minuto sem estourar (6 + 15 = 21 só se
        // os dois picos coincidirem no mesmo minuto — ainda assim é a
        // combinação mais provável de estourar; se acontecer, o job
        // reagenda via RateLimited, não falha).
        // ⚠️ A janela de PRODUÇÃO nunca foi medida (mesmo alerta do bucket
        // `clicksign-envelope` acima) — este número (3/min) é o ponto a
        // revisar na Fase 132.
        RateLimiter::for('clicksign-webhook', function () {
            return Limit::perMinute(3)->by('global');
        });

        // ─── Creative Engine — teto de custo que fala português ──────────
        //
        // Quick 261003-l8o (correção 4): o 429 que o operador viu era o
        // padrão do Laravel ("Too many attempts."), em inglês e sem prazo —
        // o LIMITE é teto de custo e não sai, só a RESPOSTA muda. Chave por
        // USUÁRIO (fallback de IP): todas as rotas do Creative Engine estão
        // sob `auth` + `role:admin` (`user()` existe sempre), e por
        // identidade evita que um escritório atrás de NAT compartilhado
        // barre um publicador legítimo pelo vizinho — mesmo raciocínio dos
        // limitadores do Portal do Cliente acima.
        RateLimiter::for('creative-kit-planejar', function (Request $request) {
            // 6 → 12/min: no fluxo de um botão só (Quick 261003-l8o), TODO
            // clique em "Gerar as 7 imagens" passa por planejar primeiro —
            // a chamada que encontra kit existente devolve o mesmo token
            // sem gastar NADA de cota de imagem, mas consumia slot de
            // throttle igual. Planejar é a chamada mais barata das três
            // (texto, não imagem).
            return Limit::perMinute(12)
                ->by('creative-planejar:'.($request->user()?->id ?? $request->ip()))
                ->response($this->respostaLimiteCriativo('planejar o kit'));
        });

        RateLimiter::for('creative-kit-gerar', function (Request $request) {
            // 3 → 4/min: o fluxo novo acrescenta UM motivo legítimo de 2ª
            // chamada no mesmo minuto (o operador recusa a confirmação, revê
            // o plano e confirma depois). 4 kits/min = teto de ≈ US$ 2,84/
            // min — o teto que de fato segura o dinheiro continua sendo por
            // kit (`max_imagens`) + os tetos de regeneração por asset/kit.
            return Limit::perMinute(4)
                ->by('creative-gerar:'.($request->user()?->id ?? $request->ip()))
                ->response($this->respostaLimiteCriativo('gerar as imagens do kit'));
        });

        RateLimiter::for('creative-regenerar', function (Request $request) {
            // MESMO número de sempre (12/min) — já estava calibrado em
            // US$ 0,101 por chamada; só a MENSAGEM muda (era a padrão do
            // Laravel, em inglês).
            return Limit::perMinute(12)
                ->by('creative-regenerar:'.($request->user()?->id ?? $request->ip()))
                ->response($this->respostaLimiteCriativo('gerar de novo uma imagem'));
        });

        Event::listen(Login::class, function (Login $event) {
            activity('auth')
                ->causedBy($event->user)
                ->withProperties(['ip' => request()->ip(), 'user_agent' => request()->userAgent()])
                ->log('Login realizado');
        });

        Event::listen(Logout::class, function (Logout $event) {
            if ($event->user) {
                activity('auth')
                    ->causedBy($event->user)
                    ->withProperties(['ip' => request()->ip()])
                    ->log('Logout realizado');
            }
        });
    }

    /**
     * Resposta em pt-BR do 429 dos três limitadores `creative-*` (Quick
     * 261003-l8o, correção 4) — substitui o "Too many attempts." padrão do
     * Laravel. `$headers['Retry-After']` já vem calculado pelo próprio
     * Laravel a partir da janela do `Limit`; devolvê-lo como 3º argumento da
     * resposta é o que preserva o header (T-L8O-08: sem nome de classe, de
     * rota ou de kit na mensagem — só a ação, em português, e os segundos).
     */
    private function respostaLimiteCriativo(string $acao): callable
    {
        return function ($request, array $headers) use ($acao) {
            $segundos = (int) ($headers['Retry-After'] ?? 60);

            return response()->json([
                'ok'          => false,
                'erros'       => [[
                    'mensagem' => "Você pediu para {$acao} muitas vezes em pouco tempo — este limite "
                        ."existe para proteger o custo. Tente de novo em {$segundos} segundos.",
                ]],
                'retry_after' => $segundos,
            ], 429, $headers);
        };
    }
}
