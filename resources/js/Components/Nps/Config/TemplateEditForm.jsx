import { useState, useEffect, useRef, useMemo } from 'react';
import { router, useForm } from '@inertiajs/react';
import { Power, PowerOff, Info, Link2, HelpCircle, Star } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Textarea } from '@/Components/ui/textarea';
import { Label } from '@/Components/ui/label';
import { cn } from '@/lib/utils';

/**
 * TemplateEditForm — Phase 70 Plan 05 v15.0 + UX refactor 2026-07-08.
 *
 * Ajustes aplicados (feedback pós-deploy):
 *   - Ajuste 1: label "Priority" → "Prioridade" + tooltip explicativo.
 *   - Ajuste 2: layout COMPACTO — só nome, descrição, prioridade e botão de
 *     serviços cobertos. Sem envio_automatico_mensal expandido (movido para
 *     linha compacta lado a lado). Perguntas movidas para bloco separado
 *     (QuestionEditor) com destaque próprio.
 *   - Ajuste 3: auto-save híbrido em modo edição — debounce 800ms nos
 *     campos + toast "Salvo". Sem botão "Salvar alterações". Modo criação
 *     mantém o botão "Criar template" (ação distinta, cria novo record).
 *   - Ajuste 4: botão "🔗 Serviços cobertos (N)" abre modal externo
 *     (ServiceScopesModal via callback `onOpenServicos`).
 *
 * Backend sem mudanças — reusa rotas existentes das Phases 70 (Plan 70-01).
 *
 * Contrato de props:
 *   - template:         NpsTemplate | null   (null = modo criação)
 *   - onSaved:          (savedId?: number) => void   (parent atualiza selectedId + refresh)
 *   - onOpenServicos:   () => void           (parent abre o ServiceScopesModal)
 *   - mostrarToast:     () => void           (parent dispara toast "Salvo")
 *   - servicosCount:    number               (nº de serviços cobertos — mostrado no botão)
 */
export default function TemplateEditForm({
    template,
    onSaved,
    onOpenServicos,
    mostrarToast,
    servicosCount = 0,
}) {
    const isEditing = !!template;

    // ─── MODO CRIAÇÃO — mantém useForm + botão explícito ────────────────
    // Por simplicidade e compatibilidade com validação Inertia, criação usa
    // o padrão original com POST + botão "Criar template".
    if (!isEditing) {
        return <FormCriacao onSaved={onSaved} />;
    }

    // ─── MODO EDIÇÃO — auto-save híbrido com debounce ────────────────────
    return (
        <FormEdicao
            template={template}
            onSaved={onSaved}
            onOpenServicos={onOpenServicos}
            mostrarToast={mostrarToast}
            servicosCount={servicosCount}
        />
    );
}

// ═══════════════════════════════════════════════════════════════════════
// MODO CRIAÇÃO — form clássico com POST + botão explícito
// ═══════════════════════════════════════════════════════════════════════
function FormCriacao({ onSaved }) {
    const { data, setData, post, processing, errors } = useForm({
        nome:                    '',
        descricao:               '',
        priority:                0,
        envio_automatico_mensal: true,
    });

    const inputCls    = 'bg-white/[0.03] border-white/[0.08] text-white placeholder:text-white/30 focus-visible:ring-ecf-yellow/30';
    const textareaCls = cn(inputCls, 'min-h-[80px] font-sans text-[13px]');

    const salvar = (e) => {
        e.preventDefault();
        post(route('nps.configuracao.templates.store'), {
            preserveScroll: true,
            onSuccess: () => onSaved && onSaved(),
        });
    };

    return (
        <form onSubmit={salvar} className="bg-ecf-card border border-white/[0.08] rounded-2xl p-6 space-y-5">
            <div>
                <h2 className="text-white font-semibold text-base tracking-tight">
                    Novo modelo
                </h2>
                <p className="text-white/50 text-xs mt-0.5">
                    Preencha nome e descrição para começar. Perguntas e serviços são adicionados após salvar.
                </p>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div className="sm:col-span-2 space-y-1.5">
                    <Label className="text-white/80 text-[13px] font-medium">Nome</Label>
                    <Input
                        value={data.nome}
                        onChange={(e) => setData('nome', e.target.value)}
                        placeholder="Ex.: NPS Publicação — Foco em Anúncios"
                        className={inputCls}
                    />
                    {errors.nome && <p className="text-red-400 text-[11px] mt-1">{errors.nome}</p>}
                </div>
                <div className="space-y-1.5">
                    <PrioridadeLabel />
                    <Input
                        type="number"
                        value={data.priority}
                        onChange={(e) => setData('priority', parseIntSafe(e.target.value))}
                        className={inputCls}
                    />
                    {errors.priority && <p className="text-red-400 text-[11px]">{errors.priority}</p>}
                </div>
            </div>

            <div className="space-y-1.5">
                <Label className="text-white/80 text-[13px] font-medium">Descrição</Label>
                <Textarea
                    value={data.descricao ?? ''}
                    onChange={(e) => setData('descricao', e.target.value)}
                    placeholder="Contexto interno: quando este modelo se aplica, qual serviço cobre etc."
                    className={textareaCls}
                    rows={3}
                />
                {errors.descricao && <p className="text-red-400 text-[11px]">{errors.descricao}</p>}
            </div>

            <label className="flex items-start gap-3 cursor-pointer select-none">
                <input
                    type="checkbox"
                    checked={!!data.envio_automatico_mensal}
                    onChange={(e) => setData('envio_automatico_mensal', e.target.checked)}
                    className="mt-0.5 h-4 w-4 rounded border-white/20 bg-white/[0.05] text-ecf-yellow focus:ring-ecf-yellow/40 cursor-pointer"
                />
                <div>
                    <span className="text-white/85 text-[13px] font-medium block">
                        Enviar automaticamente todo mês
                    </span>
                    <span className="text-white/40 text-[11px] leading-relaxed block">
                        Quando ligado, o comando <code className="text-white/60">nps:disparar-mensal</code> inclui empresas cobertas por este modelo no envio agendado.
                    </span>
                </div>
            </label>

            <div className="flex items-center justify-end gap-2 pt-2 border-t border-white/[0.06]">
                <Button
                    type="submit"
                    disabled={processing}
                    className="bg-ecf-yellow text-[#050507] hover:bg-ecf-yellow/90 font-semibold"
                >
                    {processing ? 'Criando...' : 'Criar modelo'}
                </Button>
            </div>
        </form>
    );
}

// ═══════════════════════════════════════════════════════════════════════
// MODO EDIÇÃO — auto-save híbrido debounced + botão de serviços
// ═══════════════════════════════════════════════════════════════════════
function FormEdicao({ template, onSaved, onOpenServicos, mostrarToast, servicosCount }) {
    // Data inicial derivada do template — recomputada quando template muda.
    // Usamos updated_at para forçar reset quando o parent recarrega props após save.
    const initialData = useMemo(() => ({
        nome:                    template?.nome                    ?? '',
        descricao:               template?.descricao               ?? '',
        priority:                template?.priority                ?? 0,
        envio_automatico_mensal: template?.envio_automatico_mensal ?? true,
    }), [template?.id, template?.updated_at]);

    const [data, setData]     = useState(initialData);
    const [errors, setErrors] = useState({});
    const [processing, setProcessing] = useState(false);
    const timerRef = useRef(null);

    // Reset quando template muda (troca de seleção OU reload após save).
    // Guard implícito: como setData(initialData) faz data === initialData, o
    // useEffect de auto-save abaixo detecta que não há dirty e não dispara.
    useEffect(() => {
        setData(initialData);
        setErrors({});
    }, [initialData]);

    // Auto-save debounced (800ms).
    // Guard: compara data com initialData — se igual (após reset ou primeiro
    // render), não dispara. Só o keystroke do usuário torna data ≠ initialData.
    useEffect(() => {
        const isDirty = JSON.stringify(data) !== JSON.stringify(initialData);
        if (!isDirty) return;

        // Captura template.id via closure — se o usuário trocar de template
        // antes do debounce disparar, o cleanup abaixo limpa o timer velho.
        const currentTemplateId = template.id;

        if (timerRef.current) clearTimeout(timerRef.current);
        timerRef.current = setTimeout(() => {
            setProcessing(true);
            router.put(route('nps.configuracao.templates.update', currentTemplateId), data, {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    setErrors({});
                    mostrarToast && mostrarToast();
                    onSaved && onSaved(currentTemplateId);
                },
                onError: (errs) => setErrors(errs),
                onFinish: () => setProcessing(false),
            });
        }, 800);

        return () => {
            if (timerRef.current) clearTimeout(timerRef.current);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [data, template?.id]);

    // Toggle active — chamado via router (não useForm) porque é ação sem body.
    const alternarAtivo = () => {
        router.patch(route('nps.configuracao.templates.toggle-active', template.id), {}, {
            preserveScroll: true,
            onSuccess: () => {
                mostrarToast && mostrarToast();
                onSaved && onSaved(template.id);
            },
        });
    };

    // Guard UI — quando o template é default E está ativo, desativar é bloqueado
    // pelo controller (abort 422). Preferimos deixar o botão `disabled` com hint
    // pra evitar bater no server e receber um flash de erro.
    const bloquearDesativacao = template.is_default && template.active;

    // 2026-07-13 — promove este modelo a PRINCIPAL (is_default). Só o principal
    // conta nas métricas (dashboard NPS, home, desempenho, metas) e é enviado
    // no disparo automático mensal. Confirmação porque muda o que alimenta
    // dashboards e e-mails automáticos de todos os clientes.
    const definirPrincipal = () => {
        const nome = template.nome || `Modelo #${template.id}`;
        if (! confirm(
            `Definir "${nome}" como modelo principal?\n\n` +
            `Só o principal conta nas métricas (dashboard NPS, widgets da home, ` +
            `desempenho e metas) e é o modelo enviado no disparo automático mensal ` +
            `para todos os clientes. O modelo principal atual deixa de ter essas funções.`
        )) return;
        router.patch(route('nps.configuracao.templates.set-principal', template.id), {}, {
            preserveScroll: true,
            onSuccess: () => {
                mostrarToast && mostrarToast();
                onSaved && onSaved(template.id);
            },
        });
    };

    const inputCls    = 'bg-white/[0.03] border-white/[0.08] text-white placeholder:text-white/30 focus-visible:ring-ecf-yellow/30';
    const textareaCls = cn(inputCls, 'min-h-[70px] font-sans text-[13px]');

    return (
        <div className="bg-ecf-card border border-white/[0.08] rounded-2xl p-5 space-y-4">
            {/* Header compacto */}
            <div className="flex items-start justify-between gap-3 flex-wrap">
                <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2 flex-wrap">
                        <h2 className="text-white font-semibold text-base tracking-tight">
                            Modelo #{template.id}
                        </h2>
                        {template.is_default && (
                            <span className="inline-flex items-center gap-1 text-[10px] font-semibold uppercase tracking-wider px-2 py-0.5 rounded-full bg-ecf-yellow/[0.14] text-ecf-yellow border border-ecf-yellow/25">
                                <Star size={10} className="fill-current" /> Principal
                            </span>
                        )}
                        {!template.active && (
                            <span className="inline-flex items-center gap-1 text-[10px] font-semibold uppercase tracking-wider px-2 py-0.5 rounded-full bg-red-500/[0.10] text-red-300 border border-red-500/25">
                                Inativo
                            </span>
                        )}
                        {processing && (
                            <span className="text-[10.5px] text-white/40 italic">salvando…</span>
                        )}
                    </div>
                </div>

                {/* Botão Serviços cobertos + definir principal + toggle active */}
                <div className="flex items-center gap-2 flex-wrap">
                    {! template.is_default && (
                        <Button
                            type="button"
                            onClick={definirPrincipal}
                            className="bg-ecf-yellow/[0.12] text-ecf-yellow border border-ecf-yellow/25 hover:bg-ecf-yellow/20 font-semibold"
                            title="Tornar este o modelo principal (métricas + disparo automático)"
                        >
                            <Star size={13} />
                            Definir como principal
                        </Button>
                    )}
                    <Button
                        type="button"
                        onClick={onOpenServicos}
                        className="bg-white/[0.06] text-white/85 border border-white/[0.10] hover:bg-white/[0.10] font-semibold"
                        title="Selecionar quais serviços são cobertos por este modelo"
                    >
                        <Link2 size={13} />
                        Serviços cobertos ({servicosCount})
                    </Button>
                    <Button
                        type="button"
                        onClick={alternarAtivo}
                        disabled={bloquearDesativacao}
                        title={bloquearDesativacao
                            ? 'O modelo principal não pode ser desativado. Defina outro como principal antes.'
                            : (template.active ? 'Desativar modelo' : 'Ativar modelo')}
                        className={cn(
                            'font-semibold',
                            template.active
                                ? 'bg-red-500/10 text-red-300 border border-red-500/25 hover:bg-red-500/20'
                                : 'bg-emerald-500/10 text-emerald-300 border border-emerald-500/25 hover:bg-emerald-500/20',
                        )}
                    >
                        {template.active
                            ? (<><PowerOff size={13} /> Desativar</>)
                            : (<><Power size={13} /> Ativar</>)}
                    </Button>
                </div>
            </div>

            {template.is_default ? (
                <p className="inline-flex items-center gap-1 text-[11px] text-white/40">
                    <Info size={11} /> Modelo principal: só as respostas dele contam nas métricas (dashboard NPS, home, desempenho) e é o enviado no disparo automático mensal. Não pode ser desativado.
                </p>
            ) : (
                <p className="inline-flex items-center gap-1 text-[11px] text-white/40">
                    <Info size={11} /> Modelo esporádico: usado só em geração manual de link. Não conta nas métricas nem é enviado automaticamente.
                </p>
            )}

            {/* Grid compacto: nome (2col) + prioridade (1col) */}
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div className="sm:col-span-2 space-y-1.5">
                    <Label className="text-white/80 text-[13px] font-medium">Nome</Label>
                    <Input
                        value={data.nome}
                        onChange={(e) => setData({ ...data, nome: e.target.value })}
                        placeholder="Ex.: NPS Publicação — Foco em Anúncios"
                        className={inputCls}
                    />
                    {errors.nome && <p className="text-red-400 text-[11px] mt-1">{errors.nome}</p>}
                </div>
                <div className="space-y-1.5">
                    <PrioridadeLabel />
                    <Input
                        type="number"
                        value={data.priority}
                        onChange={(e) => setData({ ...data, priority: parseIntSafe(e.target.value) })}
                        className={inputCls}
                    />
                    {errors.priority && <p className="text-red-400 text-[11px]">{errors.priority}</p>}
                </div>
            </div>

            {/* Descrição */}
            <div className="space-y-1.5">
                <Label className="text-white/80 text-[13px] font-medium">Descrição</Label>
                <Textarea
                    value={data.descricao ?? ''}
                    onChange={(e) => setData({ ...data, descricao: e.target.value })}
                    placeholder="Contexto interno: quando este modelo se aplica, qual serviço cobre etc."
                    className={textareaCls}
                    rows={2}
                />
                {errors.descricao && <p className="text-red-400 text-[11px]">{errors.descricao}</p>}
            </div>

            {/* Switch envio automático — compacto na base */}
            <label className="flex items-center gap-3 cursor-pointer select-none pt-2 border-t border-white/[0.06]">
                <input
                    type="checkbox"
                    checked={!!data.envio_automatico_mensal}
                    onChange={(e) => setData({ ...data, envio_automatico_mensal: e.target.checked })}
                    className="h-4 w-4 rounded border-white/20 bg-white/[0.05] text-ecf-yellow focus:ring-ecf-yellow/40 cursor-pointer"
                />
                <span className="text-white/85 text-[13px] font-medium">
                    Enviar automaticamente todo mês
                </span>
                <span className="text-white/40 text-[11px] leading-relaxed hidden sm:inline">
                    — inclui este modelo no <code className="text-white/60">nps:disparar-mensal</code>.
                </span>
            </label>
            {errors.envio_automatico_mensal && <p className="text-red-400 text-[11px]">{errors.envio_automatico_mensal}</p>}
        </div>
    );
}

// ═══════════════════════════════════════════════════════════════════════
// Sub-componentes utilitários
// ═══════════════════════════════════════════════════════════════════════

/**
 * PrioridadeLabel — label "Prioridade" com tooltip explicativo.
 * Ajuste 1: substitui "Priority" (jargão em inglês) por termo pt-BR + hint.
 */
function PrioridadeLabel() {
    return (
        <Label className="text-white/80 text-[13px] font-medium inline-flex items-center gap-1.5">
            Prioridade
            <span
                className="text-white/50 hover:text-white/80 cursor-help"
                title="Quando várias configurações se aplicam a uma mesma empresa, a de maior valor prevalece. Deixe 0 se este for um modelo geral."
            >
                <HelpCircle size={12} />
            </span>
        </Label>
    );
}

/**
 * parseIntSafe — parse number com fallback pra 0 (protege contra NaN quando
 * o campo fica vazio ou o usuário digita caractere inválido).
 */
function parseIntSafe(value) {
    const n = parseInt(value || '0', 10);
    return Number.isNaN(n) ? 0 : n;
}
