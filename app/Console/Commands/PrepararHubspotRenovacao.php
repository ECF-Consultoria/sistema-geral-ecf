<?php

namespace App\Console\Commands;

use App\Services\Hubspot\HubspotErroApi;
use App\Services\HubspotApiClient;
use Illuminate\Console\Command;

/**
 * Fase 202 — confere o pipeline "Renovações contrato" e as properties
 * customizadas de correlação no HubSpot. Dry-run por padrão: só cria as
 * properties ausentes com --aplicar (rodado pelo humano).
 */
class PrepararHubspotRenovacao extends Command
{
    protected $signature = 'renovacao:hubspot-preparar {--aplicar : Cria de fato as properties ausentes (sem isto, só simula)}';

    protected $description = 'Confere pipeline/etapas e properties de correlação da renovação no HubSpot (dry-run por padrão)';

    public function handle(HubspotApiClient $hubspot): int
    {
        $cfg     = config('services.hubspot.renovacao');
        $aplicar = (bool) $this->option('aplicar');

        $this->info('[Renovacao] ' . ($aplicar ? 'MODO APLICAR' : 'MODO SIMULAÇÃO (use --aplicar para criar as properties)'));

        try {
            // ─── 1. Pipeline e etapas ───
            $pipeline = $hubspot->buscarPipelineDeDeals((string) $cfg['pipeline_id']);
            $this->line("Pipeline {$pipeline['id']}: {$pipeline['label']}");

            $porId = [];
            foreach ($pipeline['stages'] as $s) {
                $porId[$s['id']] = $s;
                $this->line(sprintf('  etapa %s — %s%s', $s['id'], $s['label'], $s['fechado'] ? ' (fechada)' : ''));
            }

            $pipelineOk = true;
            foreach (['stage_inicial_id', 'stage_negociando_id', 'stage_fechado_id'] as $chave) {
                if (! isset($porId[(string) $cfg[$chave]])) {
                    $pipelineOk = false;
                    $this->error("Etapa de {$chave} ({$cfg[$chave]}) NÃO existe no pipeline.");
                }
            }
            if ($pipelineOk && ! ($porId[(string) $cfg['stage_fechado_id']]['fechado'] ?? false)) {
                $pipelineOk = false;
                $this->error("A etapa stage_fechado_id ({$cfg['stage_fechado_id']}) não é uma etapa fechada.");
            }
            if (! $pipelineOk) {
                $this->error('IDs mudaram — medir de novo com GET /crm/v3/pipelines/deals e ajustar HUBSPOT_RENOVACAO_* no .env; depois config:cache + queue:restart, nunca cache:clear.');
                return self::FAILURE;
            }

            // ─── 2. Properties de correlação ───
            $props = [
                [$cfg['propriedade_chave'], 'ECF Renovação — chave', true],
                [$cfg['propriedade_negocio_original'], 'ECF Renovação — negócio original', false],
            ];
            $todasExistem = true;
            foreach ($props as [$nome, $label, $unica]) {
                if ($hubspot->buscarPropriedadeDeDeal($nome) !== null) {
                    $this->line("Property {$nome}: existe.");
                    continue;
                }
                if (! $aplicar) {
                    $todasExistem = false;
                    $this->warn("SIMULAÇÃO — criaria a property {$nome}" . ($unica ? ' (valor único)' : ''));
                    continue;
                }
                $hubspot->criarPropriedadeDeDeal($nome, $label, $unica);
                $this->info("Property {$nome}: criada.");
            }
        } catch (HubspotErroApi $e) {
            $this->error($e->getMessage());
            if (in_array($e->status, [401, 403], true)) {
                $this->error('O token do Private App precisa dos escopos crm.objects.deals.write e crm.schemas.deals.write.');
            }
            return self::FAILURE;
        }

        if ($todasExistem) {
            $this->info('Pronto para simulação: pipeline OK e properties de correlação existem.');
        } else {
            $this->warn('Ainda faltam properties — rode com --aplicar.');
        }

        return self::SUCCESS;
    }
}
