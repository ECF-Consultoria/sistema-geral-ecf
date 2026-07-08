import { useState, useEffect, useRef, useMemo } from 'react';
import { router } from '@inertiajs/react';
import { Plus, Trash2, ArrowUp, ArrowDown, Info } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { cn } from '@/lib/utils';

/**
 * OptionsEditor — Phase 70 Plan 05 v15.0 + UX refactor 2026-07-08.
 *
 * Ajustes aplicados (feedback pós-deploy):
 *   - Ajuste 2: renderizado INLINE dentro de cada pergunta (não mais empilhado
 *     como card separado abaixo). Usa layout mais denso e menor padding.
 *   - Ajuste 3: sem botões Salvar/Cancelar. Cada opção existente tem inputs
 *     editáveis direto (label + peso) com auto-save debounce 800ms. Adicionar
 *     opção cria com defaults e já entra em modo editável. Excluir MANTÉM
 *     confirm() — operação destrutiva. Reorder (⬆⬇) salva imediatamente.
 *
 * Guards mantidos (defesa em profundidade contra o backend):
 *   - Peso 1..5 clamp UI: input type=number com min/max. Save só dispara
 *     quando pesoValido() retorna true — evita hit no backend com valor bad.
 *   - Mínimo 1 opção em escala: quando `question.tipo === 'escala'` e
 *     `question.options.length <= 1`, botão excluir da única opção fica
 *     `disabled` (feedback imediato; backend também rejeita).
 *
 * Contrato de props:
 *   - template:      NpsTemplate
 *   - question:      NpsTemplateQuestion (com `options` eager-loaded)
 *   - onChange:      () => void  (parent recarrega props após mutação)
 *   - mostrarToast:  () => void  (parent dispara toast "Salvo")
 */
export default function OptionsEditor({ template, question, onChange, mostrarToast }) {
    const opcoes    = question?.options ?? [];
    const isEscala  = question?.tipo === 'escala';
    // Guard: em pergunta escala, o backend nunca deixa cair pra 0 opções.
    const podeExcluir = () => !(isEscala && opcoes.length <= 1);

    // ─── Handlers de mutação ─────────────────────────────────────────────

    const adicionarOpcao = () => {
        // Cria opção com defaults; o admin edita label/peso inline logo depois.
        router.post(
            route('nps.configuracao.templates.perguntas.opcoes.store', [template.id, question.id]),
            { label: 'Nova opção', peso: 3 },
            {
                preserveScroll: true,
                onSuccess: () => {
                    mostrarToast && mostrarToast();
                    onChange && onChange();
                },
            },
        );
    };

    const excluir = (o) => {
        if (!confirm(`Excluir a opção "${o.label}"?`)) return;
        router.delete(
            route('nps.configuracao.templates.perguntas.opcoes.destroy', [template.id, question.id, o.id]),
            {
                preserveScroll: true,
                onSuccess: () => {
                    mostrarToast && mostrarToast();
                    onChange && onChange();
                },
            },
        );
    };

    const mover = (o, direcao) => {
        router.post(
            route('nps.configuracao.templates.perguntas.opcoes.mover', [template.id, question.id, o.id]),
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
        <div className="rounded-lg border border-white/[0.06] bg-white/[0.02] p-3 space-y-2">
            {/* Cabeçalho compacto */}
            <div className="flex items-center justify-between gap-2">
                <p className="text-white/60 text-[11px] font-semibold uppercase tracking-wider">
                    Opções ({opcoes.length}) · peso 1–5
                </p>
                <button
                    type="button"
                    onClick={adicionarOpcao}
                    className="inline-flex items-center gap-1 text-[11px] font-semibold px-2 py-1 rounded-md bg-white/[0.05] text-white/75 border border-white/[0.08] hover:bg-white/[0.10] hover:text-white transition-colors"
                    title="Adicionar nova opção"
                >
                    <Plus size={11} />
                    Adicionar opção
                </button>
            </div>

            {/* Aviso escala + 1 opção */}
            {isEscala && opcoes.length <= 1 && (
                <div className="inline-flex items-start gap-2 rounded-lg border border-amber-500/25 bg-amber-500/[0.06] px-3 py-1.5 text-amber-200 text-[11px] leading-relaxed">
                    <Info size={12} className="mt-0.5 shrink-0" />
                    <span>
                        Perguntas do tipo <strong>escala</strong> exigem no mínimo <strong>1 opção</strong>. Ajuste rótulo/peso — não é possível excluir a única opção.
                    </span>
                </div>
            )}

            {opcoes.length === 0 && (
                <p className="text-white/40 text-[11.5px] italic px-1 py-1">
                    Nenhuma opção cadastrada. Clique em "Adicionar opção".
                </p>
            )}

            <div className="space-y-1.5">
                {opcoes.map((o, idx) => (
                    <OpcaoInline
                        key={o.id}
                        template={template}
                        question={question}
                        opcao={o}
                        primeira={idx === 0}
                        ultima={idx === opcoes.length - 1}
                        bloqueiaDelete={!podeExcluir()}
                        onExcluir={() => excluir(o)}
                        onMoverUp={() => mover(o, 'up')}
                        onMoverDown={() => mover(o, 'down')}
                        onChange={onChange}
                        mostrarToast={mostrarToast}
                    />
                ))}
            </div>
        </div>
    );
}

// ═══════════════════════════════════════════════════════════════════════
// Opção inline — inputs editáveis direto com auto-save debounce
// ═══════════════════════════════════════════════════════════════════════
function OpcaoInline({
    template, question, opcao,
    primeira, ultima, bloqueiaDelete,
    onExcluir, onMoverUp, onMoverDown,
    onChange, mostrarToast,
}) {
    // Data inicial derivada da opção — recomputa quando opção muda (ex.: reload).
    const initialData = useMemo(() => ({
        label: opcao.label ?? '',
        peso:  opcao.peso  ?? 3,
    }), [opcao.id, opcao.label, opcao.peso]);

    const [data, setData] = useState(initialData);
    const [erro, setErro] = useState(null);
    const timerRef = useRef(null);

    // Reset quando a opção subjacente muda (reload após save).
    useEffect(() => {
        setData(initialData);
        setErro(null);
    }, [initialData]);

    // Auto-save debounced (800ms). Guard: só dispara quando dirty E peso válido.
    useEffect(() => {
        const isDirty = data.label !== initialData.label || data.peso !== initialData.peso;
        if (!isDirty) return;

        // Peso inválido → não salva, mostra erro local.
        if (!pesoValido(data.peso)) {
            setErro('Peso deve estar entre 1 e 5.');
            return;
        }
        // Label vazio → não salva.
        if (!data.label || !data.label.trim()) {
            setErro('Informe o rótulo.');
            return;
        }
        setErro(null);

        const currentTemplateId = template.id;
        const currentQuestionId = question.id;
        const currentOpcaoId    = opcao.id;

        if (timerRef.current) clearTimeout(timerRef.current);
        timerRef.current = setTimeout(() => {
            router.put(
                route('nps.configuracao.templates.perguntas.opcoes.update', [currentTemplateId, currentQuestionId, currentOpcaoId]),
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
    }, [data, opcao.id]);

    const inputCls    = 'bg-white/[0.03] border-white/[0.08] text-white placeholder:text-white/30 focus-visible:ring-ecf-yellow/30 h-8 text-[12.5px]';
    const pesoInvalid = !pesoValido(data.peso);

    return (
        <div className="rounded-md border border-white/[0.06] bg-white/[0.02] px-2 py-1.5">
            <div className="flex items-center gap-2">
                {/* Setas reorder compactas */}
                <div className="flex flex-col gap-0.5 shrink-0">
                    <button
                        type="button"
                        onClick={onMoverUp}
                        disabled={primeira}
                        title="Mover para cima"
                        className={cn(
                            'h-4 w-5 rounded flex items-center justify-center text-white/40 hover:bg-white/[0.06] hover:text-white/80',
                            primeira && 'opacity-30 cursor-not-allowed',
                        )}
                    >
                        <ArrowUp size={11} />
                    </button>
                    <button
                        type="button"
                        onClick={onMoverDown}
                        disabled={ultima}
                        title="Mover para baixo"
                        className={cn(
                            'h-4 w-5 rounded flex items-center justify-center text-white/40 hover:bg-white/[0.06] hover:text-white/80',
                            ultima && 'opacity-30 cursor-not-allowed',
                        )}
                    >
                        <ArrowDown size={11} />
                    </button>
                </div>

                {/* Input label (flex-1) */}
                <Input
                    value={data.label}
                    onChange={(e) => setData({ ...data, label: e.target.value })}
                    placeholder='Ex.: "Muito bom" ou "5"'
                    className={cn(inputCls, 'flex-1 min-w-0')}
                />

                {/* Input peso (fixo) */}
                <Input
                    type="number"
                    min="1"
                    max="5"
                    step="1"
                    value={data.peso}
                    onChange={(e) => setData({ ...data, peso: parseIntSafe(e.target.value) })}
                    className={cn(inputCls, 'w-16 text-center shrink-0', pesoInvalid && 'border-red-500/40 focus-visible:ring-red-500/30')}
                    title="Peso 1..5 usado no cálculo NPS"
                />

                {/* Delete */}
                <button
                    type="button"
                    onClick={onExcluir}
                    disabled={bloqueiaDelete}
                    className={cn(
                        'h-7 w-7 rounded flex items-center justify-center text-red-300/70 hover:bg-red-500/[0.10] hover:text-red-200 shrink-0',
                        bloqueiaDelete && 'opacity-30 cursor-not-allowed hover:bg-transparent hover:text-red-300/70',
                    )}
                    title={bloqueiaDelete
                        ? 'Não é possível excluir — perguntas escala exigem no mínimo 1 opção.'
                        : 'Excluir opção'}
                >
                    <Trash2 size={12} />
                </button>
            </div>
            {erro && (
                <p className="text-red-400 text-[10.5px] mt-1 pl-7">{erro}</p>
            )}
        </div>
    );
}

// ─── Helpers ─────────────────────────────────────────────────────────────

const pesoValido = (peso) => Number.isInteger(peso) && peso >= 1 && peso <= 5;

const parseIntSafe = (value) => {
    const n = parseInt(value || '0', 10);
    return Number.isNaN(n) ? 0 : n;
};
