import AppLayout from '@/Layouts/AppLayout';
import { useState, useEffect, useMemo, useRef, useCallback } from 'react';
import { router, useForm } from '@inertiajs/react';
import { ExternalLink, CalendarClock, ArrowLeft } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Button } from '@/Components/ui/button';

import TemplatesGrid        from '@/Components/Nps/Config/TemplatesGrid';
import TemplateEditForm     from '@/Components/Nps/Config/TemplateEditForm';
import QuestionEditor       from '@/Components/Nps/Config/QuestionEditor';
import ServiceScopesModal   from '@/Components/Nps/Config/ServiceScopesModal';
import PreviewFormulario    from '@/Components/Nps/Config/PreviewFormulario';
import ToastSalvo           from '@/Components/Nps/Config/ToastSalvo';

/**
 * Página Nps/Configuracao — Phase 70 Plan 05 v15.0 + UX refactor 2026-07-08.
 *
 * Refactor 2026-07-08 (segundo round de feedback): reestruturado em DUAS
 * TELAS state-based para eliminar o desperdício da coluna esquerda (sidebar
 * de templates ocupava 320px vertical mesmo com só 1-2 modelos cadastrados):
 *
 *   MODO 'list' (nenhum template selecionado):
 *   ┌────────────────────────────────────────────────────────────────┐
 *   │  Header ("Modelos de NPS") + link Textos legado                 │
 *   ├────────────────────────────────────────────────────────────────┤
 *   │  DiaCobrancaWidget                                              │
 *   ├────────────────────────────────────────────────────────────────┤
 *   │  TemplatesGrid — cards grandes em grid responsivo (1/2/3 col)  │
 *   │  + botão "Novo modelo" destacado no topo direito                │
 *   └────────────────────────────────────────────────────────────────┘
 *
 *   MODO 'edit' (template selecionado OU criando):
 *   ┌────────────────────────────────────────────────────────────────┐
 *   │  ← Voltar aos modelos  |  Editando: {nome do template}          │
 *   ├────────────────────────────────────────┬───────────────────────┤
 *   │  TemplateEditForm (compacto)           │ Preview live sticky   │
 *   │  Perguntas do modelo (destaque)        │                       │
 *   │  QuestionEditor + Options inline       │                       │
 *   └────────────────────────────────────────┴───────────────────────┘
 *   + ServiceScopesModal (Radix Portal)
 *   + ToastSalvo fixed top-right
 *
 * Contrato de props do controller (NpsTemplateController@index):
 *   - templates:            Array<NpsTemplate> (com withCount + questions.options + servicos)
 *   - tipos_pergunta:       NpsTemplateQuestion::TIPOS   ['escala', 'opcoes']
 *   - dimensoes_labels:     { estrategista: 'Estrategista', ... }
 *   - servicos_disponiveis: Array<{id, nome, setor}>
 *   - dia_cobranca:         int
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
    // Refactor 2026-07-08: modo 'list' é o default (nenhum template selecionado).
    // Admin precisa clicar em "Editar" num card OU em "Novo modelo" para entrar
    // no modo 'edit'. Isso elimina o desperdício da coluna sidebar quando só
    // há 1-2 modelos cadastrados.
    const [selectedId, setSelectedId]     = useState(null);
    const [creating, setCreating]         = useState(false);
    const [previewData, setPreviewData]   = useState(null);
    const [servicosOpen, setServicosOpen] = useState(false);

    // Modo derivado dos states — encapsula a lógica de qual tela renderizar.
    const mode = (selectedId !== null || creating) ? 'edit' : 'list';

    // Voltar para a listagem: limpa seleção + creating + preview.
    const voltarParaLista = useCallback(() => {
        setSelectedId(null);
        setCreating(false);
        setPreviewData(null);
        setServicosOpen(false);
    }, []);

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

    // Refactor 2026-07-08: NÃO reseleciona automaticamente ao entrar na página
    // — modo 'list' é o default. O useEffect antigo pulava direto para modo
    // 'edit' no primeiro template, o que anulava a UX de escolha.

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
            {/* Toast global "Salvo" */}
            <ToastSalvo visible={toastVisible} />

            {/* Modal de serviços cobertos — Portal, só ativo em modo edit */}
            {selected && mode === 'edit' && (
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

                {/* Header — muda conforme o modo */}
                {mode === 'list' ? (
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
                ) : (
                    <header className="flex items-center gap-4 flex-wrap">
                        <button
                            type="button"
                            onClick={voltarParaLista}
                            className="inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-white/[0.04] border border-white/[0.08] text-white/80 hover:bg-white/[0.08] hover:text-white text-sm font-medium transition-colors"
                        >
                            <ArrowLeft size={15} />
                            Voltar aos modelos
                        </button>
                        <div className="min-w-0 flex-1">
                            <p className="text-white/40 text-[11px] uppercase tracking-wider font-semibold">
                                {creating ? 'Novo modelo' : 'Editando modelo'}
                            </p>
                            <h1 className="text-white font-display font-bold text-xl tracking-tight truncate">
                                {creating ? 'Configurar novo modelo' : (selected?.nome ?? '—')}
                            </h1>
                        </div>
                    </header>
                )}

                {/* Widget de config global "Dia de cobrança" — só na tela lista */}
                {mode === 'list' && <DiaCobrancaWidget diaAtual={dia_cobranca} />}

                {/* Conteúdo — muda conforme o modo */}
                {mode === 'list' ? (
                    <TemplatesGrid
                        templates={templates ?? []}
                        onEdit={(id) => { setSelectedId(id); setCreating(false); }}
                        onCreate={() => { setCreating(true); setSelectedId(null); }}
                    />
                ) : (
                    <div className="grid grid-cols-1 xl:grid-cols-[1fr_420px] gap-6">
                        {/* Editor principal — ocupa a coluna larga */}
                        <div className="space-y-6 min-w-0">
                            <TemplateEditForm
                                template={creating ? null : selected}
                                onSaved={(savedId) => {
                                    setCreating(false);
                                    if (savedId) setSelectedId(savedId);
                                    refresh();
                                }}
                                onDeleted={() => {
                                    // Modelo excluído (81-03) — volta pra lista e recarrega.
                                    voltarParaLista();
                                    refresh();
                                }}
                                onOpenServicos={() => setServicosOpen(true)}
                                mostrarToast={mostrarToast}
                                servicosCount={servicosCount}
                            />

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
                                                : 'Preview carregando…'}
                                        </p>
                                    </div>
                                )}
                            </div>
                        </aside>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
