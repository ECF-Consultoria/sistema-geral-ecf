import AppLayout from '@/Layouts/AppLayout';
import { useState, useEffect, useMemo, useRef, useCallback } from 'react';
import { router, useForm } from '@inertiajs/react';
import { ExternalLink, CalendarClock } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Button } from '@/Components/ui/button';

import TemplatesList        from '@/Components/Nps/Config/TemplatesList';
import TemplateEditForm     from '@/Components/Nps/Config/TemplateEditForm';
import QuestionEditor       from '@/Components/Nps/Config/QuestionEditor';
import ServiceScopesModal   from '@/Components/Nps/Config/ServiceScopesModal';
import PreviewFormulario    from '@/Components/Nps/Config/PreviewFormulario';
import ToastSalvo           from '@/Components/Nps/Config/ToastSalvo';

/**
 * Página Nps/Configuracao — Phase 70 Plan 05 v15.0 + UX refactor 2026-07-08.
 *
 * Refactor UX aplicado (5 ajustes do feedback pós-deploy):
 *   1. Terminologia "Priority" → "Prioridade" + tooltip explicativo
 *      (aplicado dentro de TemplateEditForm).
 *   2. Perguntas em DESTAQUE — título grande "Perguntas do modelo" +
 *      botão prominente + lista com mais espaço vertical. OptionsEditor
 *      renderizado inline dentro de cada card de pergunta (não mais
 *      empilhado como componente separado).
 *   3. Auto-save híbrido — inputs disparam PUT/POST via debounce 800ms +
 *      toast "Salvo" no canto superior direito. Estado do toast gerenciado
 *      aqui e passado via prop `mostrarToast()`.
 *   4. Serviços cobertos em MODAL — botão "🔗 Serviços cobertos (N)" no
 *      TemplateEditForm dispara ServiceScopesModal (Radix Dialog).
 *   5. NPS Padrão restaurado via migration backend
 *      (2026_07_08_165206_migrate_perguntas_extras_to_nps_padrao.php) —
 *      sem mudanças no frontend, o QuestionEditor consome template.questions
 *      normalmente.
 *
 * Layout (após refactor):
 *   ┌────────────────────────────────────────────────────────────────────┐
 *   │  Header + link "Textos legado"                                      │
 *   ├────────────────────────────────────────────────────────────────────┤
 *   │  DiaCobrancaWidget (config global)                                  │
 *   ├──────────────┬───────────────────────────────┬──────────────────────┤
 *   │ TemplatesList│ [Card compacto TemplateEdit]  │ Preview live sticky │
 *   │              │ [Título "Perguntas" destaque] │                     │
 *   │              │ [QuestionEditor + Options inl]│                     │
 *   └──────────────┴───────────────────────────────┴──────────────────────┘
 *   + ServiceScopesModal (Radix Portal — dispara pelo TemplateEditForm)
 *   + ToastSalvo fixed top-right
 *
 * Contrato de props do controller (NpsTemplateController@index — Plan 70-01):
 *   - templates:            Array<NpsTemplate> (com withCount + questions.options + servicos)
 *   - tipos_pergunta:       NpsTemplateQuestion::TIPOS   ['escala', 'opcoes']
 *   - dimensoes_labels:     { estrategista: 'Estrategista', ... }
 *   - servicos_disponiveis: Array<{id, nome, setor}>
 *   - dia_cobranca:         int   (Phase 72 Plan 01 — config global)
 */
function DiaCobrancaWidget({ diaAtual }) {
    // useForm cuida do estado, erros de validação e flag `processing` do botão.
    const { data, setData, patch, processing, errors } = useForm({
        dia: diaAtual ?? 25,
    });

    const submit = (e) => {
        e.preventDefault();
        patch(route('nps.configuracao.dia-cobranca.update'), {
            preserveScroll: true,
        });
    };

    return (
        <div className="bg-ecf-card border border-white/[0.08] rounded-2xl p-4 flex flex-wrap items-center gap-4">
            <div className="flex items-center gap-3 flex-1 min-w-[240px]">
                <div className="w-9 h-9 rounded-lg bg-ecf-yellow/10 border border-ecf-yellow/20 flex items-center justify-center shrink-0">
                    <CalendarClock size={16} className="text-ecf-yellow" />
                </div>
                <div>
                    <div className="text-white text-sm font-medium">Dia de cobrança mensal</div>
                    <div className="text-white/50 text-xs mt-0.5">
                        A partir deste dia do mês, empresas sem resposta são marcadas como pendentes.
                    </div>
                </div>
            </div>
            <form onSubmit={submit} className="flex items-center gap-2">
                <input
                    type="number"
                    min={1}
                    max={31}
                    value={data.dia}
                    onChange={(e) => setData('dia', parseInt(e.target.value, 10) || 1)}
                    className={cn(
                        'w-20 bg-white/[0.03] border rounded-lg px-3 py-2 text-white text-sm',
                        'focus:outline-none focus:ring-2 focus:ring-ecf-yellow/30',
                        errors.dia ? 'border-red-500/60' : 'border-white/[0.08]',
                    )}
                    aria-invalid={!!errors.dia}
                    aria-label="Dia do mês para cobrança"
                />
                <Button
                    type="submit"
                    disabled={processing}
                    className="bg-ecf-yellow text-[#050507] hover:bg-ecf-yellow/90 text-sm px-4"
                >
                    {processing ? 'Salvando…' : 'Salvar'}
                </Button>
                {errors.dia && (
                    <span className="text-red-400 text-xs ml-2 w-full sm:w-auto">
                        {errors.dia}
                    </span>
                )}
            </form>
        </div>
    );
}

export default function Configuracao({
    templates,
    tipos_pergunta,
    dimensoes_labels,
    servicos_disponiveis,
    dia_cobranca,
}) {
    // ─── Estado principal ────────────────────────────────────────────────
    const [selectedId, setSelectedId]     = useState(templates?.[0]?.id ?? null);
    const [creating, setCreating]         = useState(false);
    const [previewData, setPreviewData]   = useState(null);
    const [servicosOpen, setServicosOpen] = useState(false);

    // Estado do toast global (Ajuste 3).
    const [toastVisible, setToastVisible] = useState(false);
    const toastTimerRef = useRef(null);

    const mostrarToast = useCallback(() => {
        if (toastTimerRef.current) clearTimeout(toastTimerRef.current);
        setToastVisible(true);
        toastTimerRef.current = setTimeout(() => setToastVisible(false), 1500);
    }, []);

    // Cleanup do timer do toast ao desmontar (evita setState em componente unmounted).
    useEffect(() => {
        return () => {
            if (toastTimerRef.current) clearTimeout(toastTimerRef.current);
        };
    }, []);

    // Template selecionado (recalculado a cada render — leve).
    const selected = useMemo(
        () => templates?.find((t) => t.id === selectedId) ?? null,
        [templates, selectedId],
    );

    // Se o template selecionado sumiu (prop reload trocou a lista), reseleciona
    // o primeiro disponível para evitar tela em branco.
    useEffect(() => {
        if (!selected && (templates?.length ?? 0) > 0 && !creating) {
            setSelectedId(templates[0].id);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [templates]);

    // ─── Preview live debounced (300ms) ──────────────────────────────────
    const previewTimerRef = useRef(null);
    useEffect(() => {
        if (!selected || creating) {
            setPreviewData(null);
            return;
        }
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
                // Silencioso — preview é secundário.
            }
        }, 300);

        return () => {
            if (previewTimerRef.current) clearTimeout(previewTimerRef.current);
        };
    }, [selected, creating]);

    // ─── Handler de refresh (chamado pelos filhos após mutar dados) ──────
    const refresh = () => router.reload({ only: ['templates'] });

    // Serviços cobertos count — usado no botão do TemplateEditForm.
    const servicosCount = selected?.servicos?.length ?? selected?.servicos_count ?? 0;

    return (
        <AppLayout title="Configuração NPS">
            {/* Toast global "Salvo" (Ajuste 3) */}
            <ToastSalvo visible={toastVisible} />

            {/* Modal de serviços cobertos (Ajuste 4) — Portal, controlado por state daqui */}
            {selected && !creating && (
                <ServiceScopesModal
                    open={servicosOpen}
                    onOpenChange={setServicosOpen}
                    template={selected}
                    servicos={servicos_disponiveis ?? []}
                    onSaved={refresh}
                    mostrarToast={mostrarToast}
                />
            )}

            <div className="max-w-[1600px] mx-auto p-6 space-y-6">

                {/* Header */}
                <header className="flex items-start justify-between gap-4 flex-wrap">
                    <div>
                        <h1 className="text-white font-display font-bold text-2xl tracking-tight">
                            Modelos de NPS
                        </h1>
                        <p className="text-white/50 text-sm mt-1 max-w-2xl">
                            Gerencie os formulários enviados pelo NPS mensal. Cada modelo define
                            perguntas, opções e a quais serviços ele se aplica. O modelo com
                            <span className="text-white/70"> maior prioridade</span> vence quando dois ou mais cobrem a mesma empresa.
                        </p>
                    </div>
                    <a
                        href="/nps/configuracao/textos-legado"
                        className="inline-flex items-center gap-1.5 text-xs text-white/50 hover:text-white/80 underline underline-offset-4 shrink-0"
                        title="Editor legado v13 — textos do email + perguntas extras"
                    >
                        <ExternalLink size={12} />
                        Textos e perguntas extras (legado v13)
                    </a>
                </header>

                {/* Widget de config global "Dia de cobrança" (Phase 72 Plan 01) */}
                <DiaCobrancaWidget diaAtual={dia_cobranca} />

                {/* Layout principal: sidebar templates | editor + preview */}
                <div className="grid grid-cols-1 lg:grid-cols-[320px_1fr] gap-6">

                    {/* ─── Coluna esquerda: lista de templates ────────────── */}
                    <TemplatesList
                        templates={templates ?? []}
                        selectedId={creating ? null : selectedId}
                        onSelect={(id) => { setSelectedId(id); setCreating(false); }}
                        onCreate={() => { setCreating(true); setSelectedId(null); }}
                    />

                    {/* ─── Coluna direita: editor (compacto + destaque) | preview ─── */}
                    <div className="grid grid-cols-1 xl:grid-cols-[1fr_420px] gap-6">

                        {/* Editor principal */}
                        <div className="space-y-6 min-w-0">
                            {/* Card compacto do template (Ajuste 2) */}
                            <TemplateEditForm
                                template={creating ? null : selected}
                                onSaved={(savedId) => {
                                    setCreating(false);
                                    if (savedId) setSelectedId(savedId);
                                    refresh();
                                }}
                                onOpenServicos={() => setServicosOpen(true)}
                                mostrarToast={mostrarToast}
                                servicosCount={servicosCount}
                            />

                            {/* Perguntas em destaque (Ajuste 2) — só em modo edição */}
                            {selected && !creating && (
                                <QuestionEditor
                                    template={selected}
                                    tipos={tipos_pergunta}
                                    dimensoesLabels={dimensoes_labels}
                                    onChange={refresh}
                                    mostrarToast={mostrarToast}
                                />
                            )}

                            {creating && (
                                <div className="rounded-xl border border-dashed border-white/[0.08] bg-white/[0.01] p-6 text-center">
                                    <p className="text-white/50 text-sm">
                                        Salve o modelo para adicionar perguntas, opções e serviços cobertos.
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
                                                ? 'Salve o modelo para ver o preview.'
                                                : 'Selecione um modelo para ver o preview.'}
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
