<?php

namespace App\Services;

use App\Models\MlbConfiguracao;
use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
use Illuminate\Support\Str;

/**
 * Phase 35 Plan 35-02 — Factory estática reutilizável para criar
 * `MlbImplementacao` (workspace público de onboarding Polos).
 *
 * Extraída de `ComercialController::criarImplementacaoPolo` (Phase 13/14)
 * para permitir reuso no `HubspotWebhookController` quando um deal "Fechado
 * Ganho" do HubSpot cria a empresa automaticamente (D-05).
 *
 * Comportamento 1:1 com o método original — testes existentes do fluxo
 * Comercial continuam válidos (o controller agora delega via proxy).
 */
class MlbImplementacaoFactory
{
    /**
     * Cria uma `MlbImplementacao` para uma empresa do projeto Polos.
     *
     * Merge dos defaults globais (`MlbConfiguracao::implementacaoPadroes`)
     * com handoff per-cadastro (ex.: `gmail_colaborador` capturado no
     * wizard Polos). Token aleatório (48 chars) único por empresa habilita
     * o link público `/implementacao/{token}`.
     *
     * @param  array  $handoff  campos vindos do cadastro (Comercial ou webhook)
     */
    public static function criarParaPolo(MlbEmpresa $empresa, array $handoff = []): MlbImplementacao
    {
        $dados = MlbImplementacao::dadosPadrao();
        $p     = MlbConfiguracao::implementacaoPadroes();

        if ($p['tutorial_intro']) {
            $dados['tutorial_intro'] = $p['tutorial_intro'];
        }
        if (!empty($p['tutoriais'])) {
            $dados['tutoriais'] = array_merge($dados['tutoriais'], $p['tutoriais']);
        }
        if (!empty($p['links_admin_extra'])) {
            $dados['links_admin']['programa_decola'] = $p['links_admin_extra']['programa_decola'] ?? '';
        }

        // Gmail capturado no cadastro (wizard Polos) alimenta o passo público
        // "Acesso Colaborador" do Onboarding, que lê dados.links_admin.gmail_colaborador.
        // Sobrescreve o padrão global da ECF quando informado por empresa.
        if (!empty($handoff['gmail_colaborador'])) {
            $dados['links_admin']['gmail_colaborador'] = $handoff['gmail_colaborador'];
        }

        return MlbImplementacao::create([
            'empresa_id' => $empresa->id,
            'token'      => Str::random(48),
            'dados'      => $dados,
        ]);
    }
}
