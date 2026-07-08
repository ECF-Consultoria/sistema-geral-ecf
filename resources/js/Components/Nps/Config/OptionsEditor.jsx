import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Plus, Pencil, Trash2, ArrowUp, ArrowDown, Check, X, Info } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { cn } from '@/lib/utils';

/**
 * OptionsEditor — Phase 70 Plan 05 v15.0.
 *
 * CRUD inline das opções de uma pergunta. Nested dentro do card da pergunta
 * pai (indentação `pl-8`) — layout compacto porque o admin edita muitas
 * opções em sequência.
 *
 * Regras de UX críticas (research §5 + backend Plan 70-03):
 *   - **Peso 1..5 clamp UI:** input type=number com min/max + botão Salvar
 *     desabilitado quando fora de range (defesa em profundidade — backend
 *     também valida via `Rule::in([1,2,3,4,5])`).
 *   - **Mínimo 1 opção em escala:** quando `question.tipo === 'escala'` e
 *     `question.options.length <= 1`, o botão excluir da única opção fica
 *     `disabled` (feedback imediato; backend também rejeita).
 *   - Setas ⬆⬇ para reorder (zero deps novas).
 *
 * Contrato de props:
 *   - template: NpsTemplate
 *   - question: NpsTemplateQuestion (com `options` eager-loaded)
 *   - onChange: () => void  (parent recarrega props após mutação)
 */

// Defaults do form de criação.
const FORM_NOVA_DEFAULT = {
    label: '',
    peso:  3, // valor médio da escala 1..5
};

export default function OptionsEditor({ template, question, onChange }) {
    const [criando, setCriando]           = useState(false);
    const [formNova, setFormNova]         = useState(FORM_NOVA_DEFAULT);
    const [editandoId, setEditandoId]     = useState(null);
    const [formEditando, setFormEditando] = useState(null);
    const [erroLocal, setErroLocal]       = useState(null);

    const opcoes    = question?.options ?? [];
    const isEscala  = question?.tipo === 'escala';
    // Guard: em pergunta escala, o backend nunca deixa cair pra 0 opções.
    // UI reforça bloqueando excluir a última.
    const podeExcluir = (o) => !(isEscala && opcoes.length <= 1);

    const inputCls = 'bg-white/[0.03] border-white/[0.08] text-white placeholder:text-white/30 focus-visible:ring-ecf-yellow/30';

    // ─── Handlers ────────────────────────────────────────────────────────

    const resetFormNova = () => {
        setFormNova(FORM_NOVA_DEFAULT);
        setCriando(false);
        setErroLocal(null);
    };

    const pesoValido = (peso) => Number.isInteger(peso) && peso >= 1 && peso <= 5;

    const iniciarEdicao = (o) => {
        setEditandoId(o.id);
        setFormEditando({
            label: o.label ?? '',
            peso:  o.peso  ?? 3,
        });
        setErroLocal(null);
        if (criando) setCriando(false);
    };

    const cancelarEdicao = () => {
        setEditandoId(null);
        setFormEditando(null);
        setErroLocal(null);
    };

    const salvarNova = () => {
        if (!formNova.label || !formNova.label.trim()) {
            setErroLocal('Informe o rótulo da opção.');
            return;
        }
        if (!pesoValido(formNova.peso)) {
            setErroLocal('O peso precisa estar entre 1 e 5.');
            return;
        }
        router.post(
            route('nps.configuracao.templates.perguntas.opcoes.store', [template.id, question.id]),
            formNova,
            {
                preserveScroll: true,
                onSuccess: () => { resetFormNova(); onChange && onChange(); },
                onError: (errs) => {
                    const first = Object.values(errs)[0];
                    if (first) setErroLocal(first);
                },
            },
        );
    };

    const salvarEdicao = () => {
        if (!formEditando?.label || !formEditando.label.trim()) {
            setErroLocal('Informe o rótulo da opção.');
            return;
        }
        if (!pesoValido(formEditando.peso)) {
            setErroLocal('O peso precisa estar entre 1 e 5.');
            return;
        }
        router.put(
            route('nps.configuracao.templates.perguntas.opcoes.update', [template.id, question.id, editandoId]),
            formEditando,
            {
                preserveScroll: true,
                onSuccess: () => { cancelarEdicao(); onChange && onChange(); },
                onError: (errs) => {
                    const first = Object.values(errs)[0];
                    if (first) setErroLocal(first);
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
                onSuccess: () => onChange && onChange(),
            },
        );
    };

    const mover = (o, direcao) => {
        router.post(
            route('nps.configuracao.templates.perguntas.opcoes.mover', [template.id, question.id, o.id]),
            { direcao },
            {
                preserveScroll: true,
                onSuccess: () => onChange && onChange(),
            },
        );
    };

    // ─── Render ──────────────────────────────────────────────────────────

    // Guard peso invalido: usado nos botões Salvar (bloqueia submit).
    const pesoNovaFora  = !pesoValido(formNova.peso);
    const pesoEditFora  = formEditando ? !pesoValido(formEditando.peso) : false;

    return (
        <div className="rounded-xl border border-white/[0.06] bg-white/[0.015] pl-8 pr-4 py-4 space-y-3">
            {/* Cabeçalho compacto */}
            <div className="flex items-center justify-between gap-2 flex-wrap">
                <div className="min-w-0">
                    <p className="text-white/70 text-[12px] font-semibold uppercase tracking-wider">
                        Opções · {question.texto}
                    </p>
                    <p className="text-white/40 text-[11px] mt-0.5">
                        {opcoes.length} opção{opcoes.length === 1 ? '' : 'es'}. Peso é usado no cálculo NPS (1..5).
                    </p>
                </div>
                {!criando && (
                    <Button
                        type="button"
                        size="sm"
                        onClick={() => { cancelarEdicao(); setCriando(true); setErroLocal(null); }}
                        className="bg-white/[0.06] text-white/80 hover:bg-white/[0.10] border border-white/[0.08]"
                    >
                        <Plus size={12} />
                        Adicionar opção
                    </Button>
                )}
            </div>

            {/* Aviso quando tipo=escala + única opção — reforça guard do backend */}
            {isEscala && opcoes.length <= 1 && (
                <div className="inline-flex items-start gap-2 rounded-lg border border-amber-500/25 bg-amber-500/[0.06] px-3 py-2 text-amber-200 text-[11.5px] leading-relaxed">
                    <Info size={13} className="mt-0.5 shrink-0" />
                    <span>
                        Perguntas do tipo <strong>escala</strong> exigem no mínimo <strong>1 opção</strong>. A última opção não pode ser excluída — ajuste rótulo/peso.
                    </span>
                </div>
            )}

            {/* Form de criação inline */}
            {criando && (
                <div className="rounded-lg border border-ecf-yellow/25 bg-ecf-yellow/[0.04] p-3 space-y-2">
                    <div className="grid grid-cols-[1fr_120px] gap-2">
                        <div className="space-y-1">
                            <Label className="text-white/80 text-[11.5px] font-medium">Rótulo</Label>
                            <Input
                                value={formNova.label}
                                onChange={(e) => setFormNova({ ...formNova, label: e.target.value })}
                                placeholder='Ex.: "Muito bom" ou "5"'
                                className={inputCls}
                            />
                        </div>
                        <div className="space-y-1">
                            <Label className="text-white/80 text-[11.5px] font-medium">Peso (1..5)</Label>
                            <Input
                                type="number"
                                min="1"
                                max="5"
                                step="1"
                                value={formNova.peso}
                                onChange={(e) => setFormNova({ ...formNova, peso: parseInt(e.target.value || '0', 10) })}
                                className={cn(inputCls, pesoNovaFora && 'border-red-500/40 focus-visible:ring-red-500/30')}
                            />
                        </div>
                    </div>
                    {pesoNovaFora && (
                        <p className="text-red-400 text-[11px]">O peso precisa estar entre 1 e 5.</p>
                    )}
                    {erroLocal && (
                        <div className="rounded-lg border border-red-500/30 bg-red-950/40 px-3 py-1.5 text-red-300 text-[11.5px]">
                            {erroLocal}
                        </div>
                    )}
                    <div className="flex items-center justify-end gap-2">
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={resetFormNova}
                            className="border-white/[0.08] bg-white/[0.03] text-white/70 hover:bg-white/[0.08] hover:text-white"
                        >
                            <X size={12} />
                            Cancelar
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            onClick={salvarNova}
                            disabled={pesoNovaFora}
                            className="bg-ecf-yellow text-[#050507] hover:bg-ecf-yellow/90 font-semibold"
                        >
                            <Check size={12} />
                            Adicionar
                        </Button>
                    </div>
                </div>
            )}

            {/* Lista de opções */}
            {opcoes.length === 0 && !criando && (
                <p className="text-white/40 text-[12px] italic px-1">
                    Nenhuma opção cadastrada. {isEscala ? 'Escala esperada — crie a primeira opção.' : 'Clique em "Adicionar opção".'}
                </p>
            )}

            <div className="space-y-1.5">
                {opcoes.map((o, idx) => {
                    const emEdicao   = editandoId === o.id;
                    const bloqueiaDelete = !podeExcluir(o);

                    if (emEdicao) {
                        return (
                            <div key={o.id} className="rounded-lg border border-ecf-yellow/25 bg-ecf-yellow/[0.04] p-3 space-y-2">
                                <div className="grid grid-cols-[1fr_120px] gap-2">
                                    <div className="space-y-1">
                                        <Label className="text-white/80 text-[11.5px] font-medium">Rótulo</Label>
                                        <Input
                                            value={formEditando.label}
                                            onChange={(e) => setFormEditando({ ...formEditando, label: e.target.value })}
                                            className={inputCls}
                                        />
                                    </div>
                                    <div className="space-y-1">
                                        <Label className="text-white/80 text-[11.5px] font-medium">Peso (1..5)</Label>
                                        <Input
                                            type="number"
                                            min="1"
                                            max="5"
                                            step="1"
                                            value={formEditando.peso}
                                            onChange={(e) => setFormEditando({ ...formEditando, peso: parseInt(e.target.value || '0', 10) })}
                                            className={cn(inputCls, pesoEditFora && 'border-red-500/40 focus-visible:ring-red-500/30')}
                                        />
                                    </div>
                                </div>
                                {pesoEditFora && (
                                    <p className="text-red-400 text-[11px]">O peso precisa estar entre 1 e 5.</p>
                                )}
                                {erroLocal && (
                                    <div className="rounded-lg border border-red-500/30 bg-red-950/40 px-3 py-1.5 text-red-300 text-[11.5px]">
                                        {erroLocal}
                                    </div>
                                )}
                                <div className="flex items-center justify-end gap-2">
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={cancelarEdicao}
                                        className="border-white/[0.08] bg-white/[0.03] text-white/70 hover:bg-white/[0.08] hover:text-white"
                                    >
                                        <X size={12} />
                                        Cancelar
                                    </Button>
                                    <Button
                                        type="button"
                                        size="sm"
                                        onClick={salvarEdicao}
                                        disabled={pesoEditFora}
                                        className="bg-ecf-yellow text-[#050507] hover:bg-ecf-yellow/90 font-semibold"
                                    >
                                        <Check size={12} />
                                        Salvar
                                    </Button>
                                </div>
                            </div>
                        );
                    }

                    return (
                        <div key={o.id} className="flex items-center gap-2 rounded-lg border border-white/[0.06] bg-white/[0.02] px-3 py-2">
                            {/* Setas de reorder compactas */}
                            <div className="flex flex-col gap-0.5 shrink-0">
                                <button
                                    type="button"
                                    onClick={() => mover(o, 'up')}
                                    disabled={idx === 0}
                                    title="Mover para cima"
                                    className={cn(
                                        'h-4 w-5 rounded flex items-center justify-center text-white/40 hover:bg-white/[0.06] hover:text-white/80',
                                        idx === 0 && 'opacity-30 cursor-not-allowed',
                                    )}
                                >
                                    <ArrowUp size={11} />
                                </button>
                                <button
                                    type="button"
                                    onClick={() => mover(o, 'down')}
                                    disabled={idx === opcoes.length - 1}
                                    title="Mover para baixo"
                                    className={cn(
                                        'h-4 w-5 rounded flex items-center justify-center text-white/40 hover:bg-white/[0.06] hover:text-white/80',
                                        idx === opcoes.length - 1 && 'opacity-30 cursor-not-allowed',
                                    )}
                                >
                                    <ArrowDown size={11} />
                                </button>
                            </div>

                            {/* Label + peso pill */}
                            <div className="flex-1 min-w-0 flex items-center gap-2">
                                <span className="text-white/85 text-[13px] break-words truncate">
                                    {o.label}
                                </span>
                                <span className="shrink-0 text-[10px] font-mono font-semibold px-1.5 py-0.5 rounded bg-ecf-yellow/[0.10] text-ecf-yellow/90 border border-ecf-yellow/20">
                                    peso {o.peso}
                                </span>
                            </div>

                            {/* Ações */}
                            <div className="flex items-center gap-1 shrink-0">
                                <button
                                    type="button"
                                    onClick={() => iniciarEdicao(o)}
                                    className="h-7 w-7 rounded flex items-center justify-center text-white/50 hover:bg-white/[0.06] hover:text-white/80"
                                    title="Editar opção"
                                >
                                    <Pencil size={12} />
                                </button>
                                <button
                                    type="button"
                                    onClick={() => excluir(o)}
                                    disabled={bloqueiaDelete}
                                    className={cn(
                                        'h-7 w-7 rounded flex items-center justify-center text-red-300/70 hover:bg-red-500/[0.10] hover:text-red-200',
                                        bloqueiaDelete && 'opacity-30 cursor-not-allowed hover:bg-transparent hover:text-red-300/70',
                                    )}
                                    title={bloqueiaDelete
                                        ? 'Não é possível excluir — perguntas escala exigem no mínimo 1 opção.'
                                        : 'Excluir opção'}
                                >
                                    <Trash2 size={12} />
                                </button>
                            </div>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
