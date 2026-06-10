<?php

namespace App\Console\Commands;

use App\Mail\NpsMonthlyMail;
use App\Models\Company;
use App\Models\NpsSurvey;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Comando do disparo mensal automatizado da pesquisa NPS (Phase 31, Plan 02).
 *
 * Dispara 1 email por empresa ativa COM `email_cliente` preenchido, no dia
 * do mês correspondente ao aniversário do cadastro (D-01). Edge case D-03:
 * empresa criada dia 31 dispara no último dia do mês (clamp via
 * min(diaOriginal, daysInMonth)).
 *
 * Idempotência: guard `where(company_id, month_reference)->exists()` impede
 * que re-runs no mesmo dia criem surveys duplicadas (D-12).
 *
 * Empresas sem email_cliente são silenciosamente puladas (D-04 — estado
 * esperado de empresas com campo ainda não preenchido pelo admin).
 *
 * Surveys criadas têm `auto_generated=true`, `month_reference=YYYY-MM-01`,
 * `expires_at=hoje+30d` (próximo disparo do ciclo), `generated_by=NULL`
 * (não há humano por trás — a coluna virou nullable na migration
 * `2026_06_10_100004_make_generated_by_nullable_on_nps_surveys_table`).
 *
 * Schedule registrado em routes/console.php às 09:00 BRT (D-02 — fora dos
 * horários de pico: adman 11:00, gross billing 12:45, sugadores 12:00).
 *
 * Tratamento de erro: try/catch por empresa dentro do loop, com Log::error
 * e continue — uma falha pontual não derruba o comando inteiro.
 *
 * @see app/Mail/NpsMonthlyMail.php
 * @see routes/console.php
 * @see .planning/phases/31-nps-mensal-automatizado/31-CONTEXT.md (D-01..D-04, D-12)
 */
class NpsDispararMensal extends Command
{
    protected $signature = 'nps:disparar-mensal
        {--dry-run : Lista o que seria disparado sem criar survey nem enviar email}';

    protected $description = 'Cria survey NPS auto_generated + envia email no aniversário do cadastro (DAY(companies.created_at) == DAY(today), com clamp pro último dia do mês). Idempotente.';

    public function handle(): int
    {
        // Hoje em horário Brasília — o servidor de produção roda em America/Sao_Paulo
        // (config/app.php), mas força-se aqui pra robustez caso o ambiente mude.
        $hoje = Carbon::now('America/Sao_Paulo');
        $mesAtual = $hoje->copy()->startOfMonth()->toDateString(); // YYYY-MM-01 — chave de idempotência

        $dryRun = (bool) $this->option('dry-run');

        $enviados = 0;
        $criados = 0;
        $elegiveisHoje = 0;
        $puladosSemEstrategista = 0;
        $puladosIdempotencia = 0;

        $this->info("Iniciando disparo NPS mensal — hoje={$hoje->toDateString()}, mes_ref={$mesAtual}" . ($dryRun ? ' [DRY-RUN]' : ''));

        // Itera em chunks para evitar carregar todas as empresas na memória de uma vez
        Company::where('active', true)
            ->whereNotNull('email_cliente')
            ->where('email_cliente', '!=', '')
            ->chunkById(50, function ($empresas) use (
                $hoje, $mesAtual, $dryRun,
                &$enviados, &$criados, &$elegiveisHoje,
                &$puladosSemEstrategista, &$puladosIdempotencia
            ) {
                foreach ($empresas as $empresa) {
                    try {
                        // D-01 + D-03 — calcula dia alvo com clamp para o último dia do mês quando
                        // o created_at original era 29/30/31 e o mês atual tem menos dias.
                        $diaOriginal = $empresa->created_at->day;
                        $diaAlvo = min($diaOriginal, $hoje->daysInMonth);

                        if ($hoje->day !== $diaAlvo) {
                            continue; // não é o dia desta empresa
                        }

                        $elegiveisHoje++;

                        // D-12 — Idempotência: já existe survey deste mês pra esta empresa?
                        $jaExiste = NpsSurvey::where('company_id', $empresa->id)
                            ->whereDate('month_reference', $mesAtual)
                            ->exists();

                        if ($jaExiste) {
                            $puladosIdempotencia++;
                            continue;
                        }

                        // D-07 — Estrategista é obrigatório (sem ele o email não tem como ser
                        // semanticamente útil — "Como você avalia o estrategista?"). Loga
                        // warning e pula em vez de tentar enviar email genérico.
                        $estrategista = $empresa->estrategista()->first();
                        if (! $estrategista) {
                            Log::warning("[NPS Mensal] empresa {$empresa->id} ({$empresa->name}) sem estrategista atribuido, pulando disparo");
                            $puladosSemEstrategista++;
                            continue;
                        }

                        // D-07 — Analista é opcional (mentoria pura). Pode ser null.
                        $analista = $empresa->consultor()->first();

                        if ($dryRun) {
                            $this->line("[DRY] empresa #{$empresa->id} ({$empresa->name}) dispararia para {$empresa->email_cliente}");
                            continue;
                        }

                        // D-12 — Cria survey: auto_generated=true, month_reference=YYYY-MM-01,
                        // expires_at = hoje + 30d (próximo disparo do ciclo). generated_by=null
                        // porque é disparo automatizado, sem humano por trás (migration
                        // 2026_06_10_100004 tornou a coluna nullable).
                        $survey = NpsSurvey::create([
                            'token'           => Str::uuid()->toString(),
                            'company_id'      => $empresa->id,
                            'generated_by'    => null,
                            'expires_at'      => $hoje->copy()->addDays(30),
                            'status'          => 'pending',
                            'month_reference' => $mesAtual,
                            'auto_generated'  => true,
                        ]);
                        $criados++;

                        $mesLabel = $this->mesLabelPt($hoje);

                        Mail::to($empresa->email_cliente)->send(new NpsMonthlyMail(
                            companyName:      $empresa->name,
                            estrategistaName: $estrategista->name,
                            analistaName:     $analista?->name,
                            linkPublico:      route('nps.respond', $survey->token),
                            mesLabel:         $mesLabel,
                        ));
                        $enviados++;

                        Log::info("[NPS Mensal] enviado para empresa {$empresa->id} ({$empresa->name}) email={$empresa->email_cliente} survey_id={$survey->id}");

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

        Log::info("[NPS Mensal] Concluído: {$criados} surveys, {$enviados} emails, {$elegiveisHoje} elegíveis, {$puladosIdempotencia} idempotentes, {$puladosSemEstrategista} sem estrategista");

        return self::SUCCESS;
    }

    /**
     * Converte uma data Carbon em label pt-BR legível.
     * Duplicação consciente (helper de 5 linhas) — mesmo padrão da Phase 28
     * (Decisão D-D).
     * Exemplo: Carbon('2026-06-15') → 'Junho/2026'.
     */
    private function mesLabelPt(Carbon $data): string
    {
        $meses = [
            'Janeiro', 'Fevereiro', 'Março', 'Abril',
            'Maio', 'Junho', 'Julho', 'Agosto',
            'Setembro', 'Outubro', 'Novembro', 'Dezembro',
        ];

        return $meses[$data->month - 1] . '/' . $data->year;
    }
}
