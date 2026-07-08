import AppLayout from '@/Layouts/AppLayout';
import { useState, useEffect, useMemo, useRef } from 'react';
import { router } from '@inertiajs/react';
import { ExternalLink } from 'lucide-react';
import { cn } from '@/lib/utils';

import TemplatesList        from '@/Components/Nps/Config/TemplatesList';
import TemplateEditForm     from '@/Components/Nps/Config/TemplateEditForm';
import QuestionEditor       from '@/Components/Nps/Config/QuestionEditor';
import OptionsEditor        from '@/Components/Nps/Config/OptionsEditor';
import ServiceScopesPicker  from '@/Components/Nps/Config/ServiceScopesPicker';
import PreviewFormulario    from '@/Components/Nps/Config/PreviewFormulario';

/**
 * Página Nps/Configuracao — Phase 70 Plan 05 v15.0.
 *
 * Reescrita completa da UI de Configuração NPS para o modelo multi-template
 * (Milestone v15.0). Layout:
 *
 *   ┌──────────────────────────────────────────────────────────────────────┐
 *   │  Header: título + link "Textos legado (v13)"                        │
 *   ├──────────────┬────────────────────────────────┬─────────────────────┤
 *   │ TemplatesList│ Editor (Template/Perguntas/    │ PreviewFormulario   │
 *   │  (sidebar)   │  Opções/Service scopes)        │  (sticky, debounced)│
 *   └──────────────┴────────────────────────────────┴─────────────────────┘
 *
 * Componentes filhos (imports acima) são finos e focados por responsabilidade
 * — evita o arquivo monolítico da Phase 33 (1072 linhas em Configuracao.jsx
 * legado, agora migrado para ConfiguracaoLegado.jsx).
 *
 * Preview live via debounce 300ms POSTando route('nps.configuracao.templates.preview')
 * com o draft do template selecionado. Endpoint stateless (Plan 70-04) — não
 * salva nada, só normaliza e devolve o shape que PreviewFormulario renderiza.
 *
 * Modo LEGADO (Phase 33 — 11 textos + perguntas customizadas) preservado sob
 * `/nps/configuracao/textos-legado` — link discreto no header desta página.
 *
 * Contrato de props do controller (NpsTemplateController@index — Plan 70-01):
 *   - templates:            Array<NpsTemplate> (com withCount + questions.options + servicos)
 *   - tipos_pergunta:       NpsTemplateQuestion::TIPOS   ['escala', 'opcoes']
 *   - dimensoes_labels:     { estrategista: 'Estrategista', ... }
 *   - servicos_disponiveis: Array<{id, nome, setor}>
 */
export default function Configuracao({
    templates,
    tipos_pergunta,
    dimensoes_labels,
    servicos_disponiveis,
}) {
    // ─── Estado principal ────────────────────────────────────────────────
    // selectedId: id do template atualmente aberto no editor.
    // creating:   modo "novo template" (form vazio, esconde perguntas/opções).
    const [selectedId, setSelectedId] = useState(templates?.[0]?.id ?? null);
    const [creating, setCreating]     = useState(false);
    const [previewData, setPreviewData] = useState(null);

    // Template selecionado (recalculado a cada render do useMemo — leve).
    const selected = useMemo(
        () => templates?.find((t) => t.id === selectedId) ?? null,
        [templates, selectedId],
    );

    // Se o template selecionado sumiu (ex.: prop reload trocou a lista),
    // reseleciona o primeiro disponível para evitar tela em branco.
    useEffect(() => {
        if (!selected && (templates?.length ?? 0) > 0 && !creating) {
            setSelectedId(templates[0].id);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [templates]);

    // ─── Preview live debounced (300ms) ──────────────────────────────────
    // Regeneramos o preview sempre que o `selected` muda (troca de template,
    // reload de props após CRUD). Chamada é silenciosa — o preview é
    // secundário e não pode bloquear a UI.
    const previewTimerRef = useRef(null);
    useEffect(() => {
        if (!selected || creating) {
            setPreviewData(null);
            return;
        }
        // Cancela timer anterior (debounce).
        if (previewTimerRef.current) clearTimeout(previewTimerRef.current);

        previewTimerRef.current = setTimeout(async () => {
            const draft = {
                nome:      selected.nome,
                descricao: selected.descricao,
                perguntas: (selected.questions ?? []).map((q) => ({
                    texto:       q.texto,
                    tipo:        q.tipo,
                    dimensao:    q.dimensao,
                    obrigatoria: !!q.obrigatoria,
                    options:     (q.options ?? []).map((o) => ({ label: o.label, peso: o.peso })),
                })),
            };
            try {
                const r = await window.axios.post(
                    route('nps.configuracao.templates.preview'),
                    draft,
                );
                setPreviewData(r.data?.template ?? null);
            } catch (e) {
                // Silencioso — preview é secundário. UI mostra "sem preview".
            }
        }, 300);

        return () => {
            if (previewTimerRef.current) clearTimeout(previewTimerRef.current);
        };
    }, [selected, creating]);

    // ─── Handler de refresh (chamado pelos filhos após mutar dados) ──────
    // router.reload({only: ['templates']}) faz partial reload — o controller
    // re-executa apenas o closure da prop `templates` (mais rápido que
    // full reload da página).
    const refresh = () => router.reload({ only: ['templates'] });

    return (
        <AppLayout title="Configuração NPS">
            <div className="max-w-[1600px] mx-auto p-6 space-y-6">

                {/* Header */}
                <header className="flex items-start justify-between gap-4 flex-wrap">
                    <div>
                        <h1 className="text-white font-display font-bold text-2xl tracking-tight">
                            Templates de NPS
                        </h1>
                        <p className="text-white/50 text-sm mt-1 max-w-2xl">
                            Gerencie os formulários enviados pelo NPS mensal. Cada template define
                            perguntas, opções e a quais serviços ele se aplica. O template com maior
                            <span className="text-white/70"> priority</span> vence quando 2+ cobrem a mesma empresa.
                        </p>
                    </div>
                    {/* Link discreto para o modo legado (Phase 33) */}
                    <a
                        href="/nps/configuracao/textos-legado"
                        className="inline-flex items-center gap-1.5 text-xs text-white/50 hover:text-white/80 underline underline-offset-4 shrink-0"
                        title="Editor legado v13 — textos do email + perguntas extras"
                    >
                        <ExternalLink size={12} />
                        Textos e perguntas extras (legado v13)
                    </a>
                </header>

                {/* Layout principal: sidebar templates | editor | preview */}
                <div className="grid grid-cols-1 lg:grid-cols-[320px_1fr] gap-6">

                    {/* ─── Coluna esquerda: lista de templates ────────────── */}
                    <TemplatesList
                        templates={templates ?? []}
                        selectedId={creating ? null : selectedId}
                        onSelect={(id) => { setSelectedId(id); setCreating(false); }}
                        onCreate={() => { setCreating(true); setSelectedId(null); }}
                    />

                    {/* ─── Coluna direita: editor + preview ─────────────────── */}
                    <div className="grid grid-cols-1 xl:grid-cols-[1fr_420px] gap-6">

                        {/* Editor principal (empilhado) */}
                        <div className="space-y-6 min-w-0">
                            <TemplateEditForm
                                template={creating ? null : selected}
                                onSaved={(savedId) => {
                                    // Após criar, o back().reload traz o template novo.
                                    // Reload para pegar templates[] atualizado + selecionar
                                    // o recém-criado (usa flash id se disponível, senão
                                    // pega o primeiro após reload).
                                    setCreating(false);
                                    if (savedId) setSelectedId(savedId);
                                    refresh();
                                }}
                            />

                            {/* Só mostra editors avançados em modo edição (não criação). */}
                            {selected && !creating && (
                                <>
                                    <QuestionEditor
                                        template={selected}
                                        tipos={tipos_pergunta}
                                        dimensoesLabels={dimensoes_labels}
                                        onChange={refresh}
                                    />

                                    {/* OptionsEditor por pergunta — nested sob cada uma. */}
                                    {(selected.questions ?? []).map((q) => (
                                        <OptionsEditor
                                            key={q.id}
                                            template={selected}
                                            question={q}
                                            onChange={refresh}
                                        />
                                    ))}

                                    <ServiceScopesPicker
                                        template={selected}
                                        servicos={servicos_disponiveis ?? []}
                                        onSaved={refresh}
                                    />
                                </>
                            )}

                            {creating && (
                                <div className="rounded-xl border border-dashed border-white/[0.08] bg-white/[0.01] p-6 text-center">
                                    <p className="text-white/50 text-sm">
                                        Salve o template para adicionar perguntas, opções e escopos de serviço.
                                    </p>
                                </div>
                            )}
                        </div>

                        {/* Preview live — sticky à direita em xl+ */}
                        <aside className="hidden xl:block">
                            <div className="sticky top-6 space-y-2">
                                <p className="text-white/50 text-xs uppercase tracking-wider font-semibold">
                                    Preview do formulário público
                                </p>
                                {previewData ? (
                                    <PreviewFormulario template={previewData} mode="preview" />
                                ) : (
                                    <div className="max-w-md mx-auto bg-ecf-card border border-dashed border-white/[0.08] rounded-2xl p-6 text-center">
                                        <p className="text-white/40 text-sm">
                                            {creating
                                                ? 'Salve o template para ver o preview.'
                                                : 'Selecione um template para ver o preview.'}
                                        </p>
                                    </div>
                                )}
                            </div>
                        </aside>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
