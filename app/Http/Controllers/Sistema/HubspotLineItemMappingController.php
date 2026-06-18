<?php

namespace App\Http\Controllers\Sistema;

use App\Http\Controllers\Controller;
use App\Models\HubspotLineItemMapping;
use App\Models\Servico;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Phase 37 Plan 37-07 (REQ-37-02) — CRUD admin para hubspot_line_item_mapping.
 *
 * Permite cadastrar/editar mapeamentos sem deploy quando o Comercial cria nomes
 * de line item novos no HubSpot. Consumido pelo HubspotWebhookController (Plan
 * 37-04) via HubspotLineItemMapping::paraNome (case-insensitive).
 *
 * Acesso restrito a role:admin via grupo de rotas em routes/web.php
 * (defesa em profundidade: o middleware e a porta primaria; nao duplicamos
 * a checagem no metodo pq o grupo ja garante).
 */
class HubspotLineItemMappingController extends Controller
{
    /**
     * Lista os mappings com o Servico embedado + catalogo de servicos ativos
     * para alimentar o select da UI.
     *
     * Props snake_case (mappings, servicos_disponiveis) — convencao Phase 37.
     */
    public function index()
    {
        $mappings = HubspotLineItemMapping::with('servico:id,nome,setor,tipo_cobranca')
            ->orderBy('line_item_name')
            ->get()
            ->map(fn ($m) => [
                'id'             => $m->id,
                'line_item_name' => $m->line_item_name,
                'servico_id'     => $m->servico_id,
                'servico_nome'   => $m->servico?->nome,
                'servico_setor'  => $m->servico?->setor,
                'ativo'          => (bool) $m->ativo,
                'observacoes'    => $m->observacoes,
            ])
            ->all();

        $servicosDisponiveis = Servico::where('ativo', true)
            ->orderBy('nome')
            ->get(['id', 'nome', 'setor', 'tipo_cobranca']);

        return Inertia::render('Sistema/HubspotLineItems', [
            'mappings'             => $mappings,
            'servicos_disponiveis' => $servicosDisponiveis,
        ]);
    }

    /**
     * Cria novo mapping. line_item_name UNIQUE; servico_id deve apontar para
     * Servico ATIVO (mappings apontando para servico inativo viram lixo).
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'line_item_name' => 'required|string|max:255|unique:hubspot_line_item_mapping,line_item_name',
            'servico_id'     => ['required', 'integer', Rule::exists('servicos', 'id')->where('ativo', true)],
            'ativo'          => 'sometimes|boolean',
            'observacoes'    => 'nullable|string|max:2000',
        ]);

        $mapping = HubspotLineItemMapping::create($validated + ['ativo' => $validated['ativo'] ?? true]);

        activity('sistema')
            ->causedBy($request->user())
            ->withProperties([
                'line_item_name' => $mapping->line_item_name,
                'servico_id'     => $mapping->servico_id,
            ])
            ->log('Mapeamento HubSpot criado: "' . $mapping->line_item_name . '"');

        return back()->with('success', 'Mapeamento criado.');
    }

    /**
     * Edita mapping existente. unique no line_item_name ignora self —
     * admin pode salvar sem mudar o nome.
     */
    public function update(Request $request, HubspotLineItemMapping $mapping)
    {
        $validated = $request->validate([
            'line_item_name' => [
                'required', 'string', 'max:255',
                Rule::unique('hubspot_line_item_mapping', 'line_item_name')->ignore($mapping->id),
            ],
            'servico_id'     => ['required', 'integer', Rule::exists('servicos', 'id')->where('ativo', true)],
            'ativo'          => 'sometimes|boolean',
            'observacoes'    => 'nullable|string|max:2000',
        ]);

        $mapping->update($validated + ['ativo' => $validated['ativo'] ?? $mapping->ativo]);

        activity('sistema')
            ->causedBy($request->user())
            ->withProperties([
                'line_item_name' => $mapping->line_item_name,
                'servico_id'     => $mapping->servico_id,
            ])
            ->log('Mapeamento HubSpot atualizado: "' . $mapping->line_item_name . '"');

        return back()->with('success', 'Mapeamento atualizado.');
    }

    /**
     * Hard delete — admin sabe o que faz. Activity log registra o nome
     * removido para auditoria.
     */
    public function destroy(Request $request, HubspotLineItemMapping $mapping)
    {
        $nome      = $mapping->line_item_name;
        $servicoId = $mapping->servico_id;

        $mapping->delete();

        activity('sistema')
            ->causedBy($request->user())
            ->withProperties([
                'line_item_name' => $nome,
                'servico_id'     => $servicoId,
            ])
            ->log('Mapeamento HubSpot removido: "' . $nome . '"');

        return back()->with('success', 'Mapeamento removido.');
    }
}
