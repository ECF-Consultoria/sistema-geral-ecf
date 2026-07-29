<?php

namespace App\Console\Commands;

use App\Mail\NpsMonthlyMail;
use App\Models\Company;
use App\Models\Configuracao;
use App\Models\NpsEmailEnvio;
use App\Models\NpsSurvey;
use App\Models\NpsSurveyEvent;
use App\Services\Digisac\NpsDigisacDispatchService;
use App\Services\Nps\NpsElegibilidadeService;
use App\Services\Nps\NpsTemplateService;
use App\Support\NpsTextRenderer;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Comando do disparo mensal automatizado da pesquisa NPS.
 *
 * Phase 31 (Plan 02) — criação inicial: dispara 1 email por empresa ativa
 *   COM `email_cliente` preenchido, no dia do mês correspondente ao aniversário
 *   do cadastro (D-01). Edge case D-03: empresa criada dia 31 dispara no último
 *   dia do mês (clamp via min(diaOriginal, daysInMonth)).
 *
 * Phase 32 (Plan 01) — customização + log:
 *   - Textos do email lidos da Configuracao::get('nps_textos') (D-03) e renderizados
 *     via NpsTextRenderer com placeholders {nome_estrategista}, {nome_analista},
 *     {nome_empresa}, {mes_referencia}, {bloco_analista}.
 *   - Cada disparo grava 1 NpsEmailEnvio (status=enviado em sucesso,
 *     status=falha + erro_msg em catch) — D-04.
 *
 * Idempotência: guard `where(company_id, month_reference)->exists()` impede
 * que re-runs no mesmo dia criem surveys/logs duplicados (D-12 Phase 31).
 *
 * Empresas sem email_cliente são silenciosamente puladas (D-04 Phase 31).
 *
 * Surveys criadas têm `auto_generated=true`, `month_reference=YYYY-MM-01`,
 * `expires_at=fim do mês corrente` (2026-07-20: o link vale só dentro do mês
 * do disparo; ao virar o mês expira, sem ser apagado), `generated_by=NULL`.
 *
 * Fase 119.1 Plan 01 (D2) — o agendamento diário às 09:00 BRT foi DESLIGADO
 * (`routes/console.php` não tem mais `Schedule::command('nps:disparar-mensal')`).
 * Este comando continua existindo e funcionando 100% igual, mas só roda
 * quando alguém o invoca manualmente. Elegibilidade (estrategista + modelo
 * aplicável) e o guard de duplicidade agora vêm de `NpsElegibilidadeService`
 * (fonte única, reusada também pelo disparo manual da tela e pelo bônus).
 *
 * @see app/Mail/NpsMonthlyMail.php
 * @see app/Models/NpsEmailEnvio.php
 * @see app/Support/NpsTextRenderer.php
 * @see app/Services/Nps/NpsElegibilidadeService.php
 * @see routes/console.php
 */
class NpsDispararMensal extends Command
{
    protected $signature = 'nps:disparar-mensal
        {--dry-run : Lista o que seria disparado sem criar survey nem enviar email}';

    protected $description = 'Cria survey NPS auto_generated + envia email customizado no aniversário do cadastro (DAY(companies.created_at) == DAY(today), com clamp pro último dia do mês). Idempotente.';

    /**
     * Phase 69 Plan 05 (REQ NPS-B-04) — DI do NpsTemplateService.
     *
     * O comando resolve o template aplicável por empresa antes de criar o
     * survey; o service é stateless e resolve pelo container Laravel.
     * `parent::__construct()` é obrigatório — sem ele o Console\Command não
     * registra `signature` nem `description` corretamente.
     */
    /**
     * v15.5 — DI adicional do NpsDigisacDispatchService.
     *
     * O canal WhatsApp é opt-in (Configuracao::nps_envio_digisac_ativo). O
     * dispatcher é injetado sempre; a decisão de chamá-lo por empresa acontece
     * dentro do loop após checar as flags.
     */
    /**
     * Fase 119.1 Plan 01 — DI do NpsElegibilidadeService.
     *
     * O comando delega a resolução de estrategista/modelos aplicáveis/guard
     * de duplicidade ao serviço (fonte única, sem reimplementar a regra);
     * contadores, logs e filtros de DISPARO (canal, aniversário) continuam
     * aqui, porque são efeito colateral do comando, não de elegibilidade.
     */
    public function __construct(
        private NpsTemplateService $templateService,
        private NpsDigisacDispatchService $digisacDispatch,
        private NpsElegibilidadeService $elegibilidade,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        // Hoje em horário Brasília — o servidor de produção roda em America/Sao_Paulo
        // (config/app.php), mas força-se aqui pra robustez caso o ambiente mude.
        $hoje = Carbon::now('America/Sao_Paulo');
        $mesAtual = $hoje->copy()->startOfMonth()->toDateString(); // YYYY-MM-01 — chave de idempotência

        $dryRun = (bool) $this->option('dry-run');

        // Phase 32 — carrega textos customizáveis uma única vez antes do loop.
        // Se a chave 'nps_textos' não existir em configuracoes, o helper devolve
        // os defaults D-03.
        $textos = NpsTextRenderer::getTextos();
        $mesReferencia = $this->mesLabelPt($hoje);

        $enviados = 0;
        $criados = 0;
        $elegiveisHoje = 0;
        $puladosSemEstrategista = 0;
        $puladosIdempotencia = 0;
        // Phase 79 Plan 03 (DEC-79-A) — contador das empresas ELEGÍVEIS que, no
        // modo ESTRITO, não têm NENHUM modelo aplicável (nenhum serviço ATIVO
        // coberto por um scope de modelo com envio automático). Blindagem de
        // rollout: expõe no output + Log::warning quem ficaria sem NPS antes do
        // comportamento mudar (RESEARCH Pitfall 1).
        $puladosSemModelo = 0;

        // v15.5 — Flags de canal (opt-in explícito por Configuracao).
        // Email default '1' (comportamento atual). Digisac default '0' (opt-in).
        // Skip email = comando ainda cria surveys + tenta WhatsApp, se ativo.
        $canalEmailAtivo   = Configuracao::get('nps_envio_email_ativo', '1') === '1';
        $canalDigisacAtivo = Configuracao::get('nps_envio_digisac_ativo', '0') === '1';

        // v15.5 — Contadores por canal Digisac.
        $digisacEnviados = 0;
        $digisacFalhas   = 0;
        $digisacSkipped  = 0;

        $this->info("Iniciando disparo NPS mensal — hoje={$hoje->toDateString()}, mes_ref={$mesAtual}" . ($dryRun ? ' [DRY-RUN]' : ''));
        $this->info(sprintf(
            'Canais: email=%s | digisac=%s',
            $canalEmailAtivo ? 'ON' : 'OFF',
            $canalDigisacAtivo ? 'ON' : 'OFF',
        ));

        // v15.5 — Query base ajustada:
        //  - se canal digisac está ativo, empresas SEM email_cliente mas COM grupo
        //    mapeado ainda são elegíveis (envio só pra WhatsApp).
        //  - se só email está ativo, mantemos o filtro histórico (email_cliente).
        //  - se AMBOS desligados, o comando sai imediatamente sem tocar em nada.
        if (! $canalEmailAtivo && ! $canalDigisacAtivo) {
            $this->warn('Ambos os canais desligados (nps_envio_email_ativo=0 e nps_envio_digisac_ativo=0). Nada a disparar.');
            return self::SUCCESS;
        }

        // Phase 79 Plan 03 (DEC-79-A) — DISPARO ESTRITO por serviços cobertos.
        // Substitui o comportamento "força o principal (is_default) para TODAS as
        // empresas" (2026-07-13): agora resolvemos, DENTRO do loop, a coleção de
        // modelos aplicáveis a cada empresa via NpsElegibilidadeService::modelosAplicaveis()
        // (1 envio por modelo cujos serviços cobertos intersectam um contrato
        // ATIVO da empresa). Empresa sem cobertura → NENHUM NPS + warning.
        $query = Company::where('active', true);
        if ($canalEmailAtivo && ! $canalDigisacAtivo) {
            $query->whereNotNull('email_cliente')->where('email_cliente', '!=', '');
        } elseif (! $canalEmailAtivo && $canalDigisacAtivo) {
            $query->whereNotNull('digisac_group_contact_id')
                  ->where('digisac_group_contact_id', '!=', '');
        } else {
            // Ambos ativos: elegível se tem email OU grupo. Se não tem nem um, não
            // adianta criar survey (não haveria como avisar o cliente).
            $query->where(function ($q) {
                $q->where(function ($sub) {
                    $sub->whereNotNull('email_cliente')->where('email_cliente', '!=', '');
                })->orWhere(function ($sub) {
                    $sub->whereNotNull('digisac_group_contact_id')
                        ->where('digisac_group_contact_id', '!=', '');
                });
            });
        }

        // Itera em chunks para evitar carregar todas as empresas na memória de uma vez
        $query->chunkById(50, function ($empresas) use (
                $hoje, $mesAtual, $dryRun, $textos, $mesReferencia,
                $canalEmailAtivo, $canalDigisacAtivo,
                &$enviados, &$criados, &$elegiveisHoje,
                &$puladosSemEstrategista, &$puladosIdempotencia,
                &$puladosSemModelo,
                &$digisacEnviados, &$digisacFalhas, &$digisacSkipped
            ) {
                foreach ($empresas as $empresa) {
                    try {
                        // D-01 + D-03 Phase 31 — calcula dia alvo com clamp para o último dia do mês
                        // quando o created_at original era 29/30/31 e o mês atual tem menos dias.
                        $diaOriginal = $empresa->created_at->day;
                        $diaAlvo = min($diaOriginal, $hoje->daysInMonth);

                        if ($hoje->day !== $diaAlvo) {
                            continue; // não é o dia desta empresa
                        }

                        $elegiveisHoje++;

                        // D-07 Phase 31 — Estrategista é obrigatório. Loga warning e pula em vez de
                        // tentar enviar email genérico.
                        // Fase 119.1 Plan 01 — resolução via NpsElegibilidadeService (fonte
                        // única); o Log::warning e o contador continuam aqui (efeito colateral
                        // do comando, não da regra de elegibilidade em si).
                        $estrategista = $this->elegibilidade->estrategistaDaEmpresa($empresa);
                        if (! $estrategista) {
                            Log::warning("[NPS Mensal] empresa {$empresa->id} ({$empresa->name}) sem estrategista atribuido, pulando disparo");
                            $puladosSemEstrategista++;
                            continue;
                        }

                        // D-07 Phase 31 — Analista é opcional (mentoria pura). Pode ser null.
                        $analista = $empresa->consultor()->first();

                        // ─── Phase 79 Plan 03 (DEC-79-A) — modelos aplicáveis ────────────
                        // Serviços ATIVOS contratados pela empresa e, a partir deles, TODOS os
                        // modelos com envio automático cujos "Serviços cobertos" (pivot
                        // nps_template_service_scopes) intersectam esses serviços. Ex.: empresa
                        // com contrato de Performance → NPS Padrão; com Shopee → NPS Shopee;
                        // com ambos → os dois modelos (2 surveys).
                        // Fase 119.1 Plan 01 — resolução via NpsElegibilidadeService::modelosAplicaveis()
                        // (reproduz LITERALMENTE a mesma query; nenhuma mudança de comportamento).
                        $modelosAplicaveis = $this->elegibilidade->modelosAplicaveis($empresa);

                        // DEC-79-A é ESTRITO: sem serviço coberto por nenhum modelo → NENHUM NPS
                        // (sem fallback is_default). Loga a empresa que ficaria sem NPS (blindagem
                        // de rollout — RESEARCH Pitfall 1) e segue para a próxima.
                        if ($modelosAplicaveis->isEmpty()) {
                            Log::warning("[NPS Mensal] empresa {$empresa->id} ({$empresa->name}) sem modelo aplicável — NENHUM NPS gerado (serviços ativos sem cobertura).");
                            $puladosSemModelo++;
                            continue;
                        }

                        // 1 envio por modelo aplicável — o loop antes era "só o principal".
                        foreach ($modelosAplicaveis as $modelo) {
                            // D-12 Phase 31 + Pitfall 2 (Phase 79) — DEDUP por (company, mês,
                            // template). Incluir `template_id` é o que permite N modelos por
                            // empresa/mês: o guard antigo (só company+mês) bloquearia o 2º modelo.
                            // Fase 119.1 Plan 01 — guard via NpsElegibilidadeService::surveyExistenteNaCompetencia()
                            // (mesmo molde; cobre também month_reference NULL do disparo manual).
                            $jaExiste = $this->elegibilidade->surveyExistenteNaCompetencia($empresa->id, $modelo->id, $hoje) !== null;

                            if ($jaExiste) {
                                $puladosIdempotencia++;
                                continue;
                            }

                            if ($dryRun) {
                                $canais = [];
                                if ($canalEmailAtivo && ! empty($empresa->email_cliente)) {
                                    $canais[] = "email={$empresa->email_cliente}";
                                }
                                if ($canalDigisacAtivo) {
                                    $canais[] = ! empty($empresa->digisac_group_contact_id)
                                        ? "digisac={$empresa->digisac_group_contact_id}"
                                        : 'digisac=SKIP_sem_grupo';
                                }
                                $canaisStr = $canais ? implode(', ', $canais) : 'nenhum';
                                $this->line("[DRY] empresa #{$empresa->id} ({$empresa->name}) dispararia canais [{$canaisStr}] usando modelo #{$modelo->id} ({$modelo->nome})");
                                continue;
                            }

                            // D-12 Phase 31 + Phase 69 NPS-B-04 — Cria survey já com
                            // `template_id` populado. Necessário para o dedup unique
                            // parcial do Plan 68-04 e para o snapshot per-row (nps_response_answers)
                            // amarrar cada answer ao modelo correto no submit.
                            $survey = NpsSurvey::create([
                                'token'           => Str::uuid()->toString(),
                                'company_id'      => $empresa->id,
                                'generated_by'    => null,
                                // 2026-07-20: link vale só no mês corrente — expira ao virar
                                // o mês (sem ser apagado; prune de pendentes foi desligado).
                                'expires_at'      => $hoje->copy()->endOfMonth(),
                                'status'          => 'pending',
                                'month_reference' => $mesAtual,
                                'auto_generated'  => true,
                                'template_id'     => $modelo->id,
                            ]);
                            $criados++;

                            // Phase 94 AB-94-3 — evento 'generated' por survey criada (1 por
                            // empresa × modelo aplicável). Contexto de console: sem request,
                            // ip/user_agent/user_id ficam null. metadata.origem discrimina
                            // contra o 'manual' do NpsController::generate() (Plano 94-02).
                            NpsSurveyEvent::create([
                                'survey_id'  => $survey->id,
                                'event_type' => NpsSurveyEvent::TYPE_GENERATED,
                                'ip_address' => null,
                                'user_agent' => null,
                                'user_id'    => null,
                                'metadata'   => ['origem' => 'disparo_mensal'],
                            ]);

                            // Fase 116 (D2) — materializa a nota 1 (provisória) desde o
                            // disparo automático mensal, sem esperar o cron diário
                            // (`nps:materializar-nao-respondidos`). Falha aqui NUNCA pode
                            // abortar o disparo do NPS/e-mail — o cron corrige depois.
                            try {
                                app(\App\Services\Nps\NpsImputationService::class)->materializar($survey);
                            } catch (\Throwable $imputacaoErr) {
                                Log::warning("[NPS Imputação] falha ao materializar no disparo mensal empresa {$empresa->id} survey_id={$survey->id}: " . $imputacaoErr->getMessage());
                            }

                            // Phase 32 D-03 — monta vars com placeholders. `bloco_analista` é um trecho
                            // gerado dinamicamente: " e o analista é **Nome**" quando há analista, ou
                            // string vazia em mentoria pura. É renderizado SOZINHO como texto puro pra
                            // depois entrar no email_corpo já com nl2br aplicado no renderHtml.
                            $blocoAnalista = $analista
                                ? ' e o analista é **' . $analista->name . '**'
                                : '';

                            $vars = [
                                'nome_estrategista' => $estrategista->name,
                                'nome_analista'     => $analista?->name ?? '',
                                'nome_empresa'      => $empresa->name,
                                'mes_referencia'    => $mesReferencia,
                                'bloco_analista'    => $blocoAnalista,
                            ];

                            // Renderiza cada texto editável da config:
                            //  - texto-puro (sem escape): assunto e CTA (vão para subject e <a>texto</a>)
                            //  - HTML-safe (e()+nl2br): saudação, corpo, assinatura (vão em {!! !!})
                            //
                            // Phase 79 Plan 03 (Open Question a) — MVP multi-modelo: prefixa o assunto
                            // com o NOME DO MODELO ($modelo->nome) para o cliente distinguir a área
                            // (ex.: "NPS Padrão — ..." vs "NPS Shopee — ..."). Sem criar campo novo
                            // de assunto por-template; os textos globais de Configuracao::nps_textos
                            // seguem valendo para o restante do email.
                            $assuntoBase      = NpsTextRenderer::render($textos['email_assunto'],     $vars);
                            $assuntoRender    = $modelo->nome . ' — ' . $assuntoBase;
                            $saudacaoRender   = NpsTextRenderer::renderHtml($textos['email_saudacao'], $vars);
                            $corpoRender      = NpsTextRenderer::renderHtml($textos['email_corpo'],    $vars);
                            $ctaRender        = NpsTextRenderer::render($textos['email_cta'],          $vars);
                            $assinaturaRender = NpsTextRenderer::renderHtml($textos['email_assinatura'], $vars);

                            $linkPesquisa = route('nps.respond', $survey->token);

                            $mailVars = [
                                'assuntoRender'    => $assuntoRender,
                                'saudacaoRender'   => $saudacaoRender,
                                'corpoRender'      => $corpoRender,
                                'ctaRender'        => $ctaRender,
                                'assinaturaRender' => $assinaturaRender,
                                'linkPesquisa'     => $linkPesquisa,
                                'mesReferencia'    => $mesReferencia,
                            ];

                            // v15.5 — Envio email fica dentro de guard de canal + presença de email_cliente.
                            // Empresa elegível só por WhatsApp (email_cliente vazio) NÃO tem email disparado.
                            if ($canalEmailAtivo && ! empty($empresa->email_cliente)) {
                                // Phase 32 D-04 — envia + grava log (sucesso ou falha) no mesmo bloco
                                // pra garantir que o NpsEmailEnvio existe mesmo quando o Mailable lança.
                                try {
                                    Mail::to($empresa->email_cliente)->send(new NpsMonthlyMail($mailVars));

                                    NpsEmailEnvio::create([
                                        'survey_id'    => $survey->id,
                                        'company_id'   => $empresa->id,
                                        'destinatario' => $empresa->email_cliente,
                                        'assunto'      => $assuntoRender,
                                        'status'       => 'enviado',
                                        'erro_msg'     => null,
                                    ]);
                                    $enviados++;

                                    // Phase 94 AB-94-3 — evento 'sent_email' SOMENTE no branch de
                                    // sucesso do Mail::send (o branch catch abaixo grava
                                    // NpsEmailEnvio status=falha separadamente, sem evento).
                                    NpsSurveyEvent::create([
                                        'survey_id'  => $survey->id,
                                        'event_type' => NpsSurveyEvent::TYPE_SENT_EMAIL,
                                        'ip_address' => null,
                                        'user_agent' => null,
                                        'user_id'    => null,
                                        'metadata'   => ['destinatario' => $empresa->email_cliente],
                                    ]);

                                    Log::info("[NPS Mensal] enviado para empresa {$empresa->id} ({$empresa->name}) modelo={$modelo->nome} email={$empresa->email_cliente} survey_id={$survey->id}");
                                } catch (\Throwable $mailErr) {
                                    // Grava log de falha — substring(65000) cobre TEXT MySQL sem cortar UTF-8.
                                    NpsEmailEnvio::create([
                                        'survey_id'    => $survey->id,
                                        'company_id'   => $empresa->id,
                                        'destinatario' => $empresa->email_cliente,
                                        'assunto'      => $assuntoRender,
                                        'status'       => 'falha',
                                        'erro_msg'     => substr($mailErr->getMessage(), 0, 65000),
                                    ]);
                                    Log::error("[NPS Mensal] falha no envio empresa {$empresa->id} survey_id={$survey->id}: " . $mailErr->getMessage());
                                }
                            }

                            // v15.5 — Envio WhatsApp via Digisac, ISOLADO do email.
                            // Falhas aqui NÃO afetam o log de email nem o próximo passo do batch.
                            // O dispatcher decide internamente skip (sem grupo) vs falha (API erro).
                            if ($canalDigisacAtivo) {
                                $varsDigisac = [
                                    'nome_empresa'      => $empresa->name,
                                    'nome_estrategista' => $estrategista->name,
                                    'nome_analista'     => $analista?->name ?? '',
                                    'mes_referencia'    => $mesReferencia,
                                    'link_nps'          => $linkPesquisa,
                                ];
                                try {
                                    // Phase 79 — passa o MODELO da vez (não mais o principal único).
                                    $envio = $this->digisacDispatch->send($survey, $empresa, $modelo, $varsDigisac);
                                    match ($envio->status) {
                                        'enviado' => $digisacEnviados++,
                                        'falha'   => $digisacFalhas++,
                                        'skipped' => $digisacSkipped++,
                                        default   => null,
                                    };

                                    // Phase 94 AB-94-3 — evento 'sent_digisac' SOMENTE quando o
                                    // envio confirma status 'enviado' (não em falha/skipped — não
                                    // há event_type para isso no enum travado pelo CONTEXT).
                                    if ($envio->status === 'enviado') {
                                        NpsSurveyEvent::create([
                                            'survey_id'  => $survey->id,
                                            'event_type' => NpsSurveyEvent::TYPE_SENT_DIGISAC,
                                            'ip_address' => null,
                                            'user_agent' => null,
                                            'user_id'    => null,
                                            'metadata'   => ['nps_digisac_envio_id' => $envio->id],
                                        ]);
                                    }
                                } catch (\Throwable $digisacErr) {
                                    // Defesa em profundidade: o dispatcher já persiste falha; se estourou
                                    // ainda assim, loga sem quebrar o batch.
                                    $digisacFalhas++;
                                    Log::error("[NPS Mensal] excecao nao tratada no digisac empresa {$empresa->id}: " . $digisacErr->getMessage());
                                }
                            }
                        } // fim foreach modelo aplicável

                    } catch (\Throwable $e) {
                        Log::error("[NPS Mensal] falha empresa {$empresa->id}: " . $e->getMessage());
                        // não derruba o comando — continua próxima empresa
                        continue;
                    }
                }
            });

        $this->newLine();
        $this->info("✓ Concluido: {$criados} surveys criadas, {$enviados} emails enviados, {$elegiveisHoje} empresas elegiveis hoje.");
        if ($puladosIdempotencia > 0) {
            $this->line("  ↳ {$puladosIdempotencia} pulada(s) por idempotencia (já tinha survey deste mes).");
        }
        if ($puladosSemEstrategista > 0) {
            $this->line("  ↳ {$puladosSemEstrategista} pulada(s) por nao ter estrategista atribuido.");
        }
        // Phase 79 Plan 03 (DEC-79-A) — blindagem de rollout: empresas elegíveis que
        // ficaram SEM NPS por não ter serviço ativo coberto por nenhum modelo.
        if ($puladosSemModelo > 0) {
            $this->line("  ↳ {$puladosSemModelo} pulada(s) por nao ter modelo NPS aplicavel (serviços ativos sem cobertura).");
        }
        if ($canalDigisacAtivo) {
            $this->line("  ↳ Digisac: {$digisacEnviados} enviadas, {$digisacFalhas} falha(s), {$digisacSkipped} sem grupo mapeado.");
        }

        Log::info("[NPS Mensal] Concluído: {$criados} surveys, {$enviados} emails, {$elegiveisHoje} elegíveis, {$puladosIdempotencia} idempotentes, {$puladosSemEstrategista} sem estrategista, {$puladosSemModelo} sem modelo aplicavel, digisac[enviados={$digisacEnviados} falhas={$digisacFalhas} skipped={$digisacSkipped}]");

        return self::SUCCESS;
    }

    /**
     * Converte uma data Carbon em label pt-BR legível.
     * Duplicação consciente (helper de 5 linhas) — mesmo padrão da Phase 28.
     * Exemplo: Carbon('2026-06-15') → 'junho/2026'.
     *
     * Phase 32 — minúsculo para combinar com a frase "...satisfação ECF — junho/2026"
     * do email_assunto default. Caso o admin reescreva o assunto, o mes_referencia
     * é só uma string, ele pode integrar como quiser.
     */
    private function mesLabelPt(Carbon $data): string
    {
        $meses = [
            'janeiro', 'fevereiro', 'março', 'abril',
            'maio', 'junho', 'julho', 'agosto',
            'setembro', 'outubro', 'novembro', 'dezembro',
        ];

        return $meses[$data->month - 1] . '/' . $data->year;
    }
}
