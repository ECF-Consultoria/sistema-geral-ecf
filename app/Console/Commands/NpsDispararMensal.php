<?php

namespace App\Console\Commands;

use App\Mail\NpsMonthlyMail;
use App\Models\Company;
use App\Models\NpsEmailEnvio;
use App\Models\NpsSurvey;
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
 * `expires_at=hoje+30d`, `generated_by=NULL`.
 *
 * Schedule registrado em routes/console.php às 09:00 BRT.
 *
 * @see app/Mail/NpsMonthlyMail.php
 * @see app/Models/NpsEmailEnvio.php
 * @see app/Support/NpsTextRenderer.php
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
    public function __construct(private NpsTemplateService $templateService)
    {
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
        // Phase 69 Plan 05 (REQ NPS-B-04) — contador de empresas puladas por
        // ausência de template aplicável (nenhum scope + seed padrão faltando).
        // Situação anômala em prod, mas o comando NÃO crasha o batch por causa
        // de uma empresa isolada — apenas pula + loga warning.
        $puladosSemTemplate = 0;

        $this->info("Iniciando disparo NPS mensal — hoje={$hoje->toDateString()}, mes_ref={$mesAtual}" . ($dryRun ? ' [DRY-RUN]' : ''));

        // Itera em chunks para evitar carregar todas as empresas na memória de uma vez
        Company::where('active', true)
            ->whereNotNull('email_cliente')
            ->where('email_cliente', '!=', '')
            ->chunkById(50, function ($empresas) use (
                $hoje, $mesAtual, $dryRun, $textos, $mesReferencia,
                &$enviados, &$criados, &$elegiveisHoje,
                &$puladosSemEstrategista, &$puladosIdempotencia,
                &$puladosSemTemplate
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

                        // D-12 Phase 31 — Idempotência: já existe survey deste mês pra esta empresa?
                        $jaExiste = NpsSurvey::where('company_id', $empresa->id)
                            ->whereDate('month_reference', $mesAtual)
                            ->exists();

                        if ($jaExiste) {
                            $puladosIdempotencia++;
                            continue;
                        }

                        // D-07 Phase 31 — Estrategista é obrigatório. Loga warning e pula em vez de
                        // tentar enviar email genérico.
                        $estrategista = $empresa->estrategista()->first();
                        if (! $estrategista) {
                            Log::warning("[NPS Mensal] empresa {$empresa->id} ({$empresa->name}) sem estrategista atribuido, pulando disparo");
                            $puladosSemEstrategista++;
                            continue;
                        }

                        // D-07 Phase 31 — Analista é opcional (mentoria pura). Pode ser null.
                        $analista = $empresa->consultor()->first();

                        // Phase 69 Plan 05 (REQ NPS-B-04) — resolve template aplicável.
                        // Empresa sem NENHUM template (nem scope + nem seed padrão) é
                        // pulada com Log::warning estruturado; o batch continua para
                        // as próximas. Situação anômala: sinaliza seed 100004 revertido.
                        try {
                            $template = $this->templateService->resolveForCompany($empresa);
                        } catch (RuntimeException $e) {
                            Log::warning(
                                "[NPS Mensal] empresa {$empresa->id} ({$empresa->name}) sem template aplicavel — pulando disparo",
                                [
                                    'company_id'   => $empresa->id,
                                    'company_name' => $empresa->name,
                                    'reason'       => $e->getMessage(),
                                ]
                            );
                            $puladosSemTemplate++;
                            continue;
                        }

                        if ($dryRun) {
                            $this->line("[DRY] empresa #{$empresa->id} ({$empresa->name}) dispararia para {$empresa->email_cliente} usando template #{$template->id} ({$template->nome})");
                            continue;
                        }

                        // D-12 Phase 31 + Phase 69 NPS-B-04 — Cria survey já com
                        // `template_id` populado. Necessário para o dedup unique
                        // parcial do Plan 68-04 e para o snapshot per-row (nps_response_answers)
                        // amarrar cada answer ao template correto no submit.
                        $survey = NpsSurvey::create([
                            'token'           => Str::uuid()->toString(),
                            'company_id'      => $empresa->id,
                            'generated_by'    => null,
                            'expires_at'      => $hoje->copy()->addDays(30),
                            'status'          => 'pending',
                            'month_reference' => $mesAtual,
                            'auto_generated'  => true,
                            'template_id'     => $template->id,
                        ]);
                        $criados++;

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
                        $assuntoRender    = NpsTextRenderer::render($textos['email_assunto'],     $vars);
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

                            Log::info("[NPS Mensal] enviado para empresa {$empresa->id} ({$empresa->name}) email={$empresa->email_cliente} survey_id={$survey->id}");
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
        if ($puladosSemTemplate > 0) {
            $this->line("  ↳ {$puladosSemTemplate} pulada(s) por nao ter template NPS aplicavel.");
        }

        Log::info("[NPS Mensal] Concluído: {$criados} surveys, {$enviados} emails, {$elegiveisHoje} elegíveis, {$puladosIdempotencia} idempotentes, {$puladosSemEstrategista} sem estrategista, {$puladosSemTemplate} sem template");

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
