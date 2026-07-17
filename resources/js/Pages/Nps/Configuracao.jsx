import AppLayout from '@/Layouts/AppLayout';
import { useState, useEffect, useMemo, useRef, useCallback } from 'react';
import { router, useForm } from '@inertiajs/react';
import { ExternalLink, CalendarClock, ArrowLeft, ShieldCheck, X, Plus } from 'lucide-react';
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
 *   - ips_internos:         Array<string> (Phase 96 Plan 02 — IPs exatos cadastrados pela UI)
 *   - cidrs_internos:       Array<string> (Phase 96 Plan 02 — redes CIDR cadastradas pela UI)
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

/**
 * Widget "IPs internos da ECF" — Phase 96 Plan 02 (AB-96-2), molde do
 * `DiaCobrancaWidget` acima. Lista editável de chips (IPs exatos + redes
 * CIDR) com adicionar/remover, salvando via PATCH admin-only. O `.env`
 * do servidor continua valendo como fallback — esta lista é SOMADA a ele
 * (união), nunca substitui.
 */
function IpsInternosWidget({ ipsIniciais, cidrsIniciais }) {
    const { data, setData, patch, processing, errors, recentlySuccessful } = useForm({
        ips: ipsIniciais ?? [],
        cidrs: cidrsIniciais ?? [],
    });

    const [novoIp, setNovoIp] = useState('');
    const [novoCidr, setNovoCidr] = useState('');

    const adicionarIp = () => {
        const valor = novoIp.trim();
        if (!valor) return;
        setData('ips', [...data.ips, valor]);
        setNovoIp('');
    };

    const removerIp = (index) => setData('ips', data.ips.filter((_, i) => i !== index));

    const adicionarCidr = () => {
        const valor = novoCidr.trim();
        if (!valor) return;
        setData('cidrs', [...data.cidrs, valor]);
        setNovoCidr('');
    };

    const removerCidr = (index) => setData('cidrs', data.cidrs.filter((_, i) => i !== index));

    const submit = (e) => {
        e.preventDefault();
        patch(route('nps.configuracao.ips-internos.update'), {
            preserveScroll: true,
        });
    };

    return (
        <div className="bg-ecf-card border border-white/[0.08] rounded-2xl p-4 space-y-4">
            <div className="flex items-center gap-3">
                <div className="w-9 h-9 rounded-lg bg-ecf-yellow/10 border border-ecf-yellow/20 flex items-center justify-center shrink-0">
                    <ShieldCheck size={16} className="text-ecf-yellow" />
                </div>
                <div>
                    <div className="text-white text-sm font-medium">IPs internos da ECF</div>
                    <div className="text-white/50 text-xs mt-0.5">
                        Respostas enviadas destes endereços são marcadas como suspeitas (rede interna).
                        A configuração do servidor continua valendo — esta lista é somada a ela.
                    </div>
                </div>
            </div>

            <form onSubmit={submit} className="space-y-4">
                {/* IPs exatos */}
                <div>
                    <label className="text-white/70 text-xs font-medium block mb-1.5">IPs exatos</label>
                    <div className="flex flex-wrap gap-2 mb-2">
                        {data.ips.map((ip, index) => {
                            // Flag calculada DENTRO do callback do map — evita
                            // ReferenceError no bundle Rollup (pitfall conhecido).
                            const temErro = !!errors[`ips.${index}`];
                            return (
                                <span
                                    key={`${ip}-${index}`}
                                    className={cn(
                                        'inline-flex items-center gap-1.5 bg-white/[0.03] border rounded-lg px-2.5 py-1 text-xs text-white/80',
                                        temErro ? 'border-red-500/60' : 'border-white/[0.08]',
                                    )}
                                >
                                    {ip}
                                    <button
                                        type="button"
                                        onClick={() => removerIp(index)}
                                        className="text-white/40 hover:text-red-400"
                                        aria-label={`Remover IP ${ip}`}
                                    >
                                        <X size={12} />
                                    </button>
                                </span>
                            );
                        })}
                        {data.ips.length === 0 && (
                            <span className="text-white/30 text-xs italic">Nenhum IP cadastrado pela UI.</span>
                        )}
                    </div>
                    <div className="flex items-center gap-2">
                        <input
                            type="text"
                            value={novoIp}
                            onChange={(e) => setNovoIp(e.target.value)}
                            onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); adicionarIp(); } }}
                            placeholder="Ex.: 203.0.113.5"
                            className="flex-1 bg-white/[0.03] border border-white/[0.08] rounded-lg px-3 py-1.5 text-white text-sm focus:outline-none focus:ring-2 focus:ring-ecf-yellow/30"
                            aria-label="Novo IP interno"
                        />
                        <Button
                            type="button"
                            onClick={adicionarIp}
                            className="bg-white/[0.06] hover:bg-white/[0.1] text-white text-xs px-3 py-1.5 h-auto"
                        >
                            <Plus size={12} className="mr-1" /> Adicionar
                        </Button>
                    </div>
                </div>

                {/* Redes CIDR */}
                <div>
                    <label className="text-white/70 text-xs font-medium block mb-1.5">Redes (CIDR)</label>
                    <div className="flex flex-wrap gap-2 mb-2">
                        {data.cidrs.map((cidr, index) => {
                            const temErro = !!errors[`cidrs.${index}`];
                            return (
                                <span
                                    key={`${cidr}-${index}`}
                                    className={cn(
                                        'inline-flex items-center gap-1.5 bg-white/[0.03] border rounded-lg px-2.5 py-1 text-xs text-white/80',
                                        temErro ? 'border-red-500/60' : 'border-white/[0.08]',
                                    )}
                                >
                                    {cidr}
                                    <button
                                        type="button"
                                        onClick={() => removerCidr(index)}
                                        className="text-white/40 hover:text-red-400"
                                        aria-label={`Remover rede ${cidr}`}
                                    >
                                        <X size={12} />
                                    </button>
                                </span>
                            );
                        })}
                        {data.cidrs.length === 0 && (
                            <span className="text-white/30 text-xs italic">Nenhuma rede cadastrada pela UI.</span>
                        )}
                    </div>
                    <div className="flex items-center gap-2">
                        <input
                            type="text"
                            value={novoCidr}
                            onChange={(e) => setNovoCidr(e.target.value)}
                            onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); adicionarCidr(); } }}
                            placeholder="Ex.: 10.0.0.0/8"
                            className="flex-1 bg-white/[0.03] border border-white/[0.08] rounded-lg px-3 py-1.5 text-white text-sm focus:outline-none focus:ring-2 focus:ring-ecf-yellow/30"
                            aria-label="Nova rede CIDR interna"
                        />
                        <Button
                            type="button"
                            onClick={adicionarCidr}
                            className="bg-white/[0.06] hover:bg-white/[0.1] text-white text-xs px-3 py-1.5 h-auto"
                        >
                            <Plus size={12} className="mr-1" /> Adicionar
                        </Button>
                    </div>
                </div>

                <div className="flex items-center gap-3">
                    <Button
                        type="submit"
                        disabled={processing}
                        className="bg-ecf-yellow text-[#050507] hover:bg-ecf-yellow/90 text-sm px-4"
                    >
                        {processing ? 'Salvando…' : 'Salvar lista'}
                    </Button>
                    {recentlySuccessful && (
                        <span className="text-emerald-400 text-xs">Salvo.</span>
                    )}
                </div>
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
    ips_internos,
    cidrs_internos,
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

                {/* Widgets de config global — só na tela lista */}
                {mode === 'list' && (
                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-4 items-start">
                        <DiaCobrancaWidget diaAtual={dia_cobranca} />
                        <IpsInternosWidget ipsIniciais={ips_internos} cidrsIniciais={cidrs_internos} />
                    </div>
                )}

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
