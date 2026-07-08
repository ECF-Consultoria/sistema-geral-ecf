import { useState, useEffect, useRef, useMemo } from 'react';
import { router } from '@inertiajs/react';
import { Plus, Trash2, ArrowUp, ArrowDown, ListChecks, MessageSquarePlus } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Textarea } from '@/Components/ui/textarea';
import { Label } from '@/Components/ui/label';
import { cn } from '@/lib/utils';
import OptionsEditor from './OptionsEditor';

/**
 * QuestionEditor — Phase 70 Plan 05 v15.0 + UX refactor 2026-07-08.
 *
 * Ajustes aplicados (feedback pós-deploy):
 *   - Ajuste 2: DESTAQUE VISUAL — título grande "Perguntas do modelo" +
 *     botão prominente "+ Adicionar pergunta" + lista com mais espaço vertical.
 *     Cada pergunta renderiza <OptionsEditor> INLINE dentro do card (não mais
 *     empilhado separadamente na coluna).
 *   - Ajuste 3: sem botões Salvar/Cancelar/Editar. Cada pergunta é sempre
 *     editável direto — texto + dimensão + obrigatoriedade salvam via
 *     debounce 800ms + toast "Salvo". Adicionar cria com defaults e já entra
 *     editável. Excluir MANTÉM confirm() — operação destrutiva. Reorder
 *     (⬆⬇) salva imediatamente.
 *
 * Guard mantido: tipo IMUTÁVEL após criação. UI mostra como badge read-only
 * dentro do card. Backend também rejeita (Plan 70-02).
 *
 * Contrato de props:
 *   - template:         NpsTemplate (com `questions` eager-loaded)
 *   - tipos:            NpsTemplateQuestion::TIPOS (['escala', 'opcoes'])
 *   - dimensoesLabels:  { estrategista: 'Estrategista', ... }
 *   - onChange:         () => void   (parent recarrega props após mutação)
 *   - mostrarToast:     () => void   (parent dispara toast "Salvo")
 */
export default function QuestionEditor({ template, tipos, dimensoesLabels, onChange, mostrarToast }) {
    const perguntas = template?.questions ?? [];

    const [criando, setCriando]     = useState(false);
    const [formNova, setFormNova]   = useState({ texto: '', tipo: 'escala', dimensao: 'geral', obrigatoria: true });
    const [erroLocal, setErroLocal] = useState(null);

    // ─── Handlers ────────────────────────────────────────────────────────

    const resetFormNova = () => {
        setFormNova({ texto: '', tipo: 'escala', dimensao: 'geral', obrigatoria: true });
        setCriando(false);
        setErroLocal(null);
    };

    const criarPergunta = () => {
        if (!formNova.texto || !formNova.texto.trim()) {
            setErroLocal('Informe o texto da pergunta.');
            return;
        }
        if (!tipos.includes(formNova.tipo)) {
            setErroLocal('Tipo inválido.');
            return;
        }
        router.post(route('nps.configuracao.templates.perguntas.store', template.id), formNova, {
            preserveScroll: true,
            onSuccess: () => {
                resetFormNova();
                mostrarToast && mostrarToast();
                onChange && onChange();
            },
            onError: (errs) => {
                const first = Object.values(errs)[0];
                if (first) setErroLocal(first);
            },
        });
    };

    const excluir = (q) => {
        if (!confirm(`Excluir a pergunta "${q.texto}" e suas ${q.options?.length ?? 0} opção(ões)?`)) return;
        router.delete(route('nps.configuracao.templates.perguntas.destroy', [template.id, q.id]), {
            preserveScroll: true,
            onSuccess: () => {
                mostrarToast && mostrarToast();
                onChange && onChange();
            },
        });
    };

    const mover = (q, direcao) => {
        router.post(
            route('nps.configuracao.templates.perguntas.mover', [template.id, q.id]),
            { direcao },
            {
                preserveScroll: true,
                onSuccess: () => {
                    mostrarToast && mostrarToast();
                    onChange && onChange();
                },
            },
        );
    };

    // ─── Render ──────────────────────────────────────────────────────────

    return (
        <div className="space-y-4">
            {/* Título de destaque — Ajuste 2 */}
            <div className="flex items-center justify-between gap-3 flex-wrap">
                <div>
                    <h2 className="text-white font-display font-bold text-xl tracking-tight flex items-center gap-2">
                        <ListChecks size={20} className="text-ecf-yellow" />
                        Perguntas do modelo
                    </h2>
                    <p className="text-white/50 text-[12.5px] mt-0.5">
                        {perguntas.length} pergunta{perguntas.length === 1 ? '' : 's'} cadastrada{perguntas.length === 1 ? '' : 's'}. As alterações salvam automaticamente enquanto você digita.
                    </p>
                </div>
                {!criando && (
                    <Button
                        type="button"
                        onClick={() => { setCriando(true); setErroLocal(null); }}
                        className="bg-ecf-yellow text-[#050507] hover:bg-ecf-yellow/90 font-semibold shadow-sm shadow-ecf-yellow/20"
                    >
                        <MessageSquarePlus size={15} />
                        Adicionar pergunta
                    </Button>
                )}
            </div>

            {/* Form de criação (aparece só quando `criando`) */}
            {criando && (
                <FormNovaPergunta
                    formNova={formNova}
                    setFormNova={setFormNova}
                    tipos={tipos}
                    dimensoesLabels={dimensoesLabels}
                    erroLocal={erroLocal}
                    onCancelar={resetFormNova}
                    onCriar={criarPergunta}
                />
            )}

            {/* Empty state */}
            {perguntas.length === 0 && !criando && (
                <div className="rounded-2xl border border-dashed border-white/[0.08] bg-white/[0.01] p-10 text-center">
                    <ListChecks size={36} className="text-white/20 mx-auto mb-3" />
                    <p className="text-white/60 text-sm mb-1">
                        Nenhuma pergunta cadastrada
                    </p>
                    <p className="text-white/40 text-[12.5px]">
                        Clique em "Adicionar pergunta" para começar a montar o formulário.
                    </p>
                </div>
            )}

            {/* Lista de perguntas — cards grandes, OptionsEditor inline */}
            <div className="space-y-4">
                {perguntas.map((q, idx) => (
                    <PerguntaCard
                        key={q.id}
                        template={template}
                        pergunta={q}
                        primeira={idx === 0}
                        ultima={idx === perguntas.length - 1}
                        dimensoesLabels={dimensoesLabels}
                        onMoverUp={() => mover(q, 'up')}
                        onMoverDown={() => mover(q, 'down')}
                        onExcluir={() => excluir(q)}
                        onChange={onChange}
                        mostrarToast={mostrarToast}
                    />
                ))}
            </div>
        </div>
    );
}

// ═══════════════════════════════════════════════════════════════════════
// Form de nova pergunta — mantém botão explícito porque é ação de criação
// ═══════════════════════════════════════════════════════════════════════
function FormNovaPergunta({
    formNova, setFormNova, tipos, dimensoesLabels, erroLocal, onCancelar, onCriar,
}) {
    const inputCls    = 'bg-white/[0.03] border-white/[0.08] text-white placeholder:text-white/30 focus-visible:ring-ecf-yellow/30';
    const textareaCls = cn(inputCls, 'min-h-[70px] font-sans text-[13px]');

    return (
        <div className="rounded-xl border border-ecf-yellow/30 bg-ecf-yellow/[0.05] p-4 space-y-3">
            <h3 className="text-ecf-yellow font-semibold text-[13px] tracking-tight">
                Nova pergunta
            </h3>

            <div className="space-y-1.5">
                <Label className="text-white/80 text-[12px] font-medium">Texto</Label>
                <Textarea
                    value={formNova.texto}
                    onChange={(e) => setFormNova({ ...formNova, texto: e.target.value })}
                    placeholder="Ex.: Como você avalia o alinhamento estratégico do último mês?"
                    className={textareaCls}
                    autoFocus
                />
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div className="space-y-1.5">
                    <Label className="text-white/80 text-[12px] font-medium">Tipo</Label>
                    <NativeSelect
                        value={formNova.tipo}
                        onChange={(v) => setFormNova({ ...formNova, tipo: v })}
                        options={tipos.map(t => ({
                            value: t,
                            label: t === 'escala' ? 'Escala 1 a 5 (auto-gera 5 opções)' : 'Opções livres',
                        }))}
                    />
                    <p className="text-white/40 text-[10.5px] leading-relaxed">
                        O tipo é fixo após criar — escolha com cuidado.
                    </p>
                </div>
                <div className="space-y-1.5">
                    <Label className="text-white/80 text-[12px] font-medium">Dimensão</Label>
                    <NativeSelect
                        value={formNova.dimensao}
                        onChange={(v) => setFormNova({ ...formNova, dimensao: v })}
                        options={Object.entries(dimensoesLabels).map(([k, v]) => ({ value: k, label: v }))}
                    />
                </div>
                <div className="space-y-1.5 pt-1">
                    <Label className="text-white/80 text-[12px] font-medium">Obrigatoriedade</Label>
                    <label className="flex items-center gap-2 cursor-pointer select-none h-10">
                        <input
                            type="checkbox"
                            checked={!!formNova.obrigatoria}
                            onChange={(e) => setFormNova({ ...formNova, obrigatoria: e.target.checked })}
                            className="h-4 w-4 rounded border-white/20 bg-white/[0.05] text-ecf-yellow focus:ring-ecf-yellow/40 cursor-pointer"
                        />
                        <span className="text-white/85 text-[12.5px]">Obrigatória</span>
                    </label>
                </div>
            </div>

            {erroLocal && (
                <div className="rounded-lg border border-red-500/30 bg-red-950/40 px-3 py-2 text-red-300 text-[12px]">
                    {erroLocal}
                </div>
            )}

            <div className="flex items-center justify-end gap-2 pt-1">
                <Button
                    type="button"
                    variant="outline"
                    onClick={onCancelar}
                    className="border-white/[0.08] bg-white/[0.03] text-white/70 hover:bg-white/[0.08] hover:text-white"
                >
                    Cancelar
                </Button>
                <Button
                    type="button"
                    onClick={onCriar}
                    className="bg-ecf-yellow text-[#050507] hover:bg-ecf-yellow/90 font-semibold"
                >
                    <Plus size={13} />
                    Criar pergunta
                </Button>
            </div>
        </div>
    );
}

// ═══════════════════════════════════════════════════════════════════════
// Card de pergunta — sempre editável, auto-save debounced, OptionsEditor inline
// ═══════════════════════════════════════════════════════════════════════
function PerguntaCard({
    template, pergunta,
    primeira, ultima, dimensoesLabels,
    onMoverUp, onMoverDown, onExcluir,
    onChange, mostrarToast,
}) {
    // Data inicial derivada da pergunta.
    const initialData = useMemo(() => ({
        texto:       pergunta.texto ?? '',
        dimensao:    pergunta.dimensao,
        obrigatoria: !!pergunta.obrigatoria,
    }), [pergunta.id, pergunta.texto, pergunta.dimensao, pergunta.obrigatoria]);

    const [data, setData] = useState(initialData);
    const [erro, setErro] = useState(null);
    const timerRef = useRef(null);

    // Reset quando pergunta muda (reload após save).
    useEffect(() => {
        setData(initialData);
        setErro(null);
    }, [initialData]);

    // Auto-save debounced (800ms).
    useEffect(() => {
        const isDirty =
            data.texto       !== initialData.texto       ||
            data.dimensao    !== initialData.dimensao    ||
            data.obrigatoria !== initialData.obrigatoria;
        if (!isDirty) return;

        // Guard: texto vazio não salva (mostra erro local, backend também rejeita).
        if (!data.texto || !data.texto.trim()) {
            setErro('Informe o texto da pergunta.');
            return;
        }
        setErro(null);

        const currentTemplateId  = template.id;
        const currentPerguntaId  = pergunta.id;

        if (timerRef.current) clearTimeout(timerRef.current);
        timerRef.current = setTimeout(() => {
            router.put(
                route('nps.configuracao.templates.perguntas.update', [currentTemplateId, currentPerguntaId]),
                data,
                {
                    preserveScroll: true,
                    preserveState:  true,
                    onSuccess: () => {
                        mostrarToast && mostrarToast();
                        onChange && onChange();
                    },
                    onError: (errs) => {
                        const first = Object.values(errs)[0];
                        if (first) setErro(first);
                    },
                },
            );
        }, 800);

        return () => {
            if (timerRef.current) clearTimeout(timerRef.current);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [data, pergunta.id]);

    const tipoLabel   = pergunta.tipo === 'escala' ? 'Escala 1-5' : 'Opções livres';
    const inputCls    = 'bg-white/[0.03] border-white/[0.08] text-white placeholder:text-white/30 focus-visible:ring-ecf-yellow/30';
    const textareaCls = cn(inputCls, 'min-h-[60px] font-sans text-[13.5px]');

    return (
        <div className="rounded-xl border border-white/[0.08] bg-ecf-card p-4 space-y-3">
            {/* Header do card: setas + badges + delete */}
            <div className="flex items-start gap-3">
                {/* Setas reorder */}
                <div className="flex flex-col gap-0.5 shrink-0 pt-1">
                    <button
                        type="button"
                        onClick={onMoverUp}
                        disabled={primeira}
                        title="Mover para cima"
                        className={cn(
                            'h-6 w-6 rounded flex items-center justify-center text-white/40 hover:bg-white/[0.06] hover:text-white/80',
                            primeira && 'opacity-30 cursor-not-allowed hover:bg-transparent',
                        )}
                    >
                        <ArrowUp size={14} />
                    </button>
                    <button
                        type="button"
                        onClick={onMoverDown}
                        disabled={ultima}
                        title="Mover para baixo"
                        className={cn(
                            'h-6 w-6 rounded flex items-center justify-center text-white/40 hover:bg-white/[0.06] hover:text-white/80',
                            ultima && 'opacity-30 cursor-not-allowed hover:bg-transparent',
                        )}
                    >
                        <ArrowDown size={14} />
                    </button>
                </div>

                {/* Corpo do card: texto editável + campos */}
                <div className="flex-1 min-w-0 space-y-3">
                    {/* Badges (tipo read-only, dimensão, obrigatória) */}
                    <div className="flex flex-wrap items-center gap-1.5">
                        <span
                            className="inline-flex items-center text-[10px] font-semibold uppercase tracking-wider px-2 py-0.5 rounded-full bg-white/[0.06] text-white/70 border border-white/[0.10]"
                            title="Tipo imutável após criação."
                        >
                            {tipoLabel}
                        </span>
                        <span className="text-white/30 text-[10.5px]">
                            ordem {pergunta.ordem}
                        </span>
                    </div>

                    {/* Texto da pergunta — editável direto */}
                    <div className="space-y-1">
                        <Label className="text-white/70 text-[11.5px] font-medium">Texto da pergunta</Label>
                        <Textarea
                            value={data.texto}
                            onChange={(e) => setData({ ...data, texto: e.target.value })}
                            className={textareaCls}
                        />
                    </div>

                    {/* Dimensão + obrigatoriedade lado a lado */}
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div className="space-y-1">
                            <Label className="text-white/70 text-[11.5px] font-medium">Dimensão</Label>
                            <NativeSelect
                                value={data.dimensao}
                                onChange={(v) => setData({ ...data, dimensao: v })}
                                options={Object.entries(dimensoesLabels).map(([k, v]) => ({ value: k, label: v }))}
                            />
                        </div>
                        <div className="space-y-1">
                            <Label className="text-white/70 text-[11.5px] font-medium">Obrigatoriedade</Label>
                            <label className="flex items-center gap-2 cursor-pointer select-none h-10">
                                <input
                                    type="checkbox"
                                    checked={!!data.obrigatoria}
                                    onChange={(e) => setData({ ...data, obrigatoria: e.target.checked })}
                                    className="h-4 w-4 rounded border-white/20 bg-white/[0.05] text-ecf-yellow focus:ring-ecf-yellow/40 cursor-pointer"
                                />
                                <span className="text-white/85 text-[12.5px]">Obrigatória</span>
                            </label>
                        </div>
                    </div>

                    {erro && (
                        <p className="text-red-400 text-[11px]">{erro}</p>
                    )}
                </div>

                {/* Delete */}
                <button
                    type="button"
                    onClick={onExcluir}
                    className="h-8 w-8 rounded flex items-center justify-center text-red-300/70 hover:bg-red-500/[0.10] hover:text-red-200 shrink-0"
                    title="Excluir pergunta"
                >
                    <Trash2 size={14} />
                </button>
            </div>

            {/* Ajuste 2: OptionsEditor renderizado INLINE dentro do card da pergunta */}
            <div className="pl-9">
                <OptionsEditor
                    template={template}
                    question={pergunta}
                    onChange={onChange}
                    mostrarToast={mostrarToast}
                />
            </div>
        </div>
    );
}

// ─── Select nativo dark-themed (padrão do projeto) ───────────────────────
function NativeSelect({ value, onChange, options, className }) {
    return (
        <select
            value={value ?? ''}
            onChange={(e) => onChange(e.target.value)}
            className={cn(
                'h-10 pl-3 pr-8 rounded-md border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/85 focus:outline-none focus:border-ecf-yellow/40 cursor-pointer w-full',
                className,
            )}
        >
            {options.map((o) => (
                <option key={o.value} value={o.value} className="bg-[#0f1116] text-white">
                    {o.label}
                </option>
            ))}
        </select>
    );
}
