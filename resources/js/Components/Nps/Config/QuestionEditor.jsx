import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Plus, Pencil, Trash2, ArrowUp, ArrowDown, Check, X, ListChecks } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Textarea } from '@/Components/ui/textarea';
import { Label } from '@/Components/ui/label';
import { cn } from '@/lib/utils';

/**
 * QuestionEditor — Phase 70 Plan 05 v15.0.
 *
 * CRUD inline das perguntas de um template. Espelha o padrão da Phase 33
 * (ver ConfiguracaoLegado.jsx — `criando` + `editandoId` + form clonado) mas
 * consome as rotas nomeadas do Plan 70-02 (`nps.configuracao.templates.perguntas.*`).
 *
 * Regras de UX críticas (research §5 + backend guard Plan 70-02):
 *   - **Tipo IMUTÁVEL após criação.** Backend (StoreQuestion/UpdateQuestion
 *     Requests) rejeita mudança de tipo — a UI reforça mostrando badge
 *     read-only em vez de select durante edição.
 *   - **Auto-geração de 5 opções quando tipo=escala:** o backend cria as
 *     opções ao criar a pergunta. UI só sinaliza no form de criação.
 *   - Setas ⬆⬇ para reorder (research §3 — zero deps novas). Backend
 *     `mover` faz SWAP com pergunta vizinha.
 *   - `confirm()` no delete (destrutivo — apaga cascade das opções).
 *
 * Contrato de props:
 *   - template:          NpsTemplate (com `questions` eager-loaded no controller)
 *   - tipos:             NpsTemplateQuestion::TIPOS (['escala', 'opcoes'])
 *   - dimensoesLabels:   { estrategista: 'Estrategista', ... }
 *   - onChange:          () => void   (parent recarrega props após mutação)
 *   - onSelectQuestion:  (q) => void  (opcional — parent abre editor de opções)
 */

// Defaults do form de criação (evita bug de state parcial).
const FORM_NOVA_DEFAULT = {
    texto:       '',
    tipo:        'escala',
    dimensao:    'geral',
    obrigatoria: true,
};

export default function QuestionEditor({ template, tipos, dimensoesLabels, onChange }) {
    const [criando, setCriando]           = useState(false);
    const [formNova, setFormNova]         = useState(FORM_NOVA_DEFAULT);
    const [editandoId, setEditandoId]     = useState(null);
    const [formEditando, setFormEditando] = useState(null);
    const [erroLocal, setErroLocal]       = useState(null);

    const perguntas = template?.questions ?? [];

    // Helpers de classes dark-themed (padrão do projeto).
    const inputCls    = 'bg-white/[0.03] border-white/[0.08] text-white placeholder:text-white/30 focus-visible:ring-ecf-yellow/30';
    const textareaCls = cn(inputCls, 'min-h-[70px] font-sans text-[13px]');

    // ─── Handlers ────────────────────────────────────────────────────────

    const resetFormNova = () => {
        setFormNova(FORM_NOVA_DEFAULT);
        setCriando(false);
        setErroLocal(null);
    };

    const iniciarEdicao = (q) => {
        setEditandoId(q.id);
        setFormEditando({
            texto:       q.texto ?? '',
            dimensao:    q.dimensao,
            obrigatoria: !!q.obrigatoria,
        });
        setErroLocal(null);
        // Fecha o form de criação se estava aberto (evita 2 forms simultâneos).
        if (criando) setCriando(false);
    };

    const cancelarEdicao = () => {
        setEditandoId(null);
        setFormEditando(null);
        setErroLocal(null);
    };

    const validarNova = () => {
        if (!formNova.texto || !formNova.texto.trim()) return 'Informe o texto da pergunta.';
        if (!tipos.includes(formNova.tipo)) return 'Tipo inválido.';
        return null;
    };

    const validarEdicao = () => {
        if (!formEditando?.texto || !formEditando.texto.trim()) return 'Informe o texto da pergunta.';
        return null;
    };

    const salvarNova = () => {
        const err = validarNova();
        if (err) { setErroLocal(err); return; }
        router.post(route('nps.configuracao.templates.perguntas.store', template.id), formNova, {
            preserveScroll: true,
            onSuccess: () => {
                resetFormNova();
                onChange && onChange();
            },
            onError: (errs) => {
                // Mostra o primeiro erro do backend (raro — geralmente a UI já cobre).
                const first = Object.values(errs)[0];
                if (first) setErroLocal(first);
            },
        });
    };

    const salvarEdicao = () => {
        const err = validarEdicao();
        if (err) { setErroLocal(err); return; }
        router.put(route('nps.configuracao.templates.perguntas.update', [template.id, editandoId]), formEditando, {
            preserveScroll: true,
            onSuccess: () => {
                cancelarEdicao();
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
            onSuccess: () => onChange && onChange(),
        });
    };

    const mover = (q, direcao) => {
        router.post(
            route('nps.configuracao.templates.perguntas.mover', [template.id, q.id]),
            { direcao },
            {
                preserveScroll: true,
                onSuccess: () => onChange && onChange(),
            },
        );
    };

    // ─── Render ──────────────────────────────────────────────────────────

    return (
        <div className="bg-ecf-card border border-white/[0.08] rounded-2xl p-6 space-y-4">
            <div className="flex items-center justify-between gap-3">
                <div>
                    <h3 className="text-white font-semibold text-base tracking-tight">
                        Perguntas do template
                    </h3>
                    <p className="text-white/50 text-xs mt-0.5">
                        {perguntas.length} pergunta{perguntas.length === 1 ? '' : 's'} cadastrada{perguntas.length === 1 ? '' : 's'}. Ordem 1 aparece primeiro no formulário público.
                    </p>
                </div>
                {!criando && (
                    <Button
                        type="button"
                        onClick={() => { cancelarEdicao(); setCriando(true); setErroLocal(null); }}
                        className="bg-ecf-yellow/[0.12] text-ecf-yellow border border-ecf-yellow/30 hover:bg-ecf-yellow/[0.18] font-semibold"
                    >
                        <Plus size={14} />
                        Nova pergunta
                    </Button>
                )}
            </div>

            {/* Form de criação */}
            {criando && (
                <div className="rounded-xl border border-ecf-yellow/25 bg-ecf-yellow/[0.04] p-4 space-y-3">
                    <div className="flex items-center justify-between">
                        <h4 className="text-ecf-yellow font-semibold text-[13px] tracking-tight">
                            Nova pergunta
                        </h4>
                    </div>

                    <div className="space-y-1.5">
                        <Label className="text-white/80 text-[12px] font-medium">Texto</Label>
                        <Textarea
                            value={formNova.texto}
                            onChange={(e) => setFormNova({ ...formNova, texto: e.target.value })}
                            placeholder="Ex.: Como você avalia o alinhamento estratégico do último mês?"
                            className={textareaCls}
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
                                O tipo é imutável após criar — escolha com cuidado.
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
                            onClick={resetFormNova}
                            className="border-white/[0.08] bg-white/[0.03] text-white/70 hover:bg-white/[0.08] hover:text-white"
                        >
                            <X size={13} />
                            Cancelar
                        </Button>
                        <Button
                            type="button"
                            onClick={salvarNova}
                            className="bg-ecf-yellow text-[#050507] hover:bg-ecf-yellow/90 font-semibold"
                        >
                            <Check size={13} />
                            Adicionar pergunta
                        </Button>
                    </div>
                </div>
            )}

            {/* Lista de perguntas */}
            {perguntas.length === 0 && !criando && (
                <div className="rounded-xl border border-dashed border-white/[0.08] bg-white/[0.01] p-6 text-center">
                    <ListChecks size={28} className="text-white/20 mx-auto mb-2" />
                    <p className="text-white/50 text-sm">
                        Nenhuma pergunta cadastrada — clique em "Nova pergunta" para começar.
                    </p>
                </div>
            )}

            <div className="space-y-2">
                {perguntas.map((q, idx) => {
                    const emEdicao = editandoId === q.id;
                    if (emEdicao) {
                        return (
                            <PerguntaEmEdicao
                                key={q.id}
                                pergunta={q}
                                dimensoesLabels={dimensoesLabels}
                                formEditando={formEditando}
                                setFormEditando={setFormEditando}
                                onSalvar={salvarEdicao}
                                onCancelar={cancelarEdicao}
                                erroLocal={erroLocal}
                                inputCls={inputCls}
                                textareaCls={textareaCls}
                            />
                        );
                    }
                    return (
                        <PerguntaCard
                            key={q.id}
                            pergunta={q}
                            primeira={idx === 0}
                            ultima={idx === perguntas.length - 1}
                            dimensoesLabels={dimensoesLabels}
                            onMoverUp={() => mover(q, 'up')}
                            onMoverDown={() => mover(q, 'down')}
                            onEditar={() => iniciarEdicao(q)}
                            onExcluir={() => excluir(q)}
                        />
                    );
                })}
            </div>
        </div>
    );
}

// ─── Sub-componentes locais ──────────────────────────────────────────────

/**
 * Card da pergunta em modo visualização. Setas ⬆⬇ + badges + ações inline.
 * Tipo aparece como BADGE (read-only) — reforça a regra "tipo imutável".
 */
function PerguntaCard({
    pergunta, primeira, ultima, dimensoesLabels,
    onMoverUp, onMoverDown, onEditar, onExcluir,
}) {
    const tipoLabel     = pergunta.tipo === 'escala' ? 'Escala 1-5' : 'Opções livres';
    const dimensaoLabel = dimensoesLabels[pergunta.dimensao] ?? pergunta.dimensao;
    const nOpcoes       = pergunta.options?.length ?? 0;

    return (
        <div className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-4 hover:border-white/[0.14] transition-colors">
            <div className="flex items-start gap-3">
                {/* Setas de reorder */}
                <div className="flex flex-col gap-0.5 shrink-0 pt-0.5">
                    <button
                        type="button"
                        onClick={onMoverUp}
                        disabled={primeira}
                        title="Mover para cima"
                        className={cn(
                            'h-6 w-6 rounded flex items-center justify-center text-white/40 hover:bg-white/[0.06] hover:text-white/80',
                            primeira && 'opacity-30 cursor-not-allowed hover:bg-transparent hover:text-white/40',
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
                            ultima && 'opacity-30 cursor-not-allowed hover:bg-transparent hover:text-white/40',
                        )}
                    >
                        <ArrowDown size={14} />
                    </button>
                </div>

                {/* Texto + badges */}
                <div className="flex-1 min-w-0 space-y-2">
                    <p className="text-white/90 text-[13.5px] leading-snug font-medium break-words">
                        {pergunta.texto}
                    </p>
                    <div className="flex flex-wrap items-center gap-1.5">
                        {/* Tipo — badge read-only (regra "tipo imutável") */}
                        <span className="inline-flex items-center text-[10px] font-semibold uppercase tracking-wider px-2 py-0.5 rounded-full bg-white/[0.06] text-white/70 border border-white/[0.10]">
                            {tipoLabel}
                        </span>
                        {/* Dimensão */}
                        <span className="inline-flex items-center text-[10px] font-semibold uppercase tracking-wider px-2 py-0.5 rounded-full bg-ecf-yellow/[0.12] text-ecf-yellow border border-ecf-yellow/20">
                            {dimensaoLabel}
                        </span>
                        {/* Obrigatoriedade */}
                        {pergunta.obrigatoria && (
                            <span className="inline-flex items-center text-[10px] font-semibold uppercase tracking-wider px-2 py-0.5 rounded-full bg-red-500/[0.10] text-red-300 border border-red-500/20">
                                Obrigatória
                            </span>
                        )}
                        <span className="text-white/30 text-[10.5px]">
                            ordem {pergunta.ordem} · {nOpcoes} opção{nOpcoes === 1 ? '' : 'es'}
                        </span>
                    </div>
                </div>

                {/* Ações */}
                <div className="flex items-center gap-1.5 shrink-0">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={onEditar}
                        className="border-white/[0.08] bg-white/[0.03] text-white/70 hover:bg-white/[0.08] hover:text-white"
                    >
                        <Pencil size={12} />
                        Editar
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={onExcluir}
                        className="border-red-500/20 bg-red-500/[0.04] text-red-300 hover:bg-red-500/[0.12] hover:text-red-200 hover:border-red-500/40"
                        title="Excluir pergunta"
                    >
                        <Trash2 size={12} />
                    </Button>
                </div>
            </div>
        </div>
    );
}

/**
 * Card da pergunta em modo edição. **NÃO renderiza select de tipo** — mostra
 * badge do tipo atual como read-only (regra imutável, Plan 70-02).
 */
function PerguntaEmEdicao({
    pergunta, dimensoesLabels, formEditando, setFormEditando,
    onSalvar, onCancelar, erroLocal, inputCls, textareaCls,
}) {
    if (!formEditando) return null;
    const tipoLabel = pergunta.tipo === 'escala' ? 'Escala 1-5' : 'Opções livres';

    return (
        <div className="rounded-xl border border-ecf-yellow/25 bg-ecf-yellow/[0.04] p-4 space-y-3">
            <div className="flex items-center justify-between gap-2">
                <h4 className="text-ecf-yellow font-semibold text-[13px] tracking-tight">
                    Editando pergunta #{pergunta.id}
                </h4>
                {/* Tipo read-only — badge cinza (regra imutável) */}
                <span
                    className="inline-flex items-center text-[10px] font-semibold uppercase tracking-wider px-2 py-1 rounded-full bg-white/[0.06] text-white/60 border border-white/[0.10]"
                    title="O tipo da pergunta não pode ser alterado após criação."
                >
                    Tipo: {tipoLabel} (imutável)
                </span>
            </div>

            <div className="space-y-1.5">
                <Label className="text-white/80 text-[12px] font-medium">Texto</Label>
                <Textarea
                    value={formEditando.texto}
                    onChange={(e) => setFormEditando({ ...formEditando, texto: e.target.value })}
                    className={textareaCls}
                />
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div className="space-y-1.5">
                    <Label className="text-white/80 text-[12px] font-medium">Dimensão</Label>
                    <NativeSelect
                        value={formEditando.dimensao}
                        onChange={(v) => setFormEditando({ ...formEditando, dimensao: v })}
                        options={Object.entries(dimensoesLabels).map(([k, v]) => ({ value: k, label: v }))}
                    />
                </div>
                <div className="space-y-1.5 pt-1">
                    <Label className="text-white/80 text-[12px] font-medium">Obrigatoriedade</Label>
                    <label className="flex items-center gap-2 cursor-pointer select-none h-10">
                        <input
                            type="checkbox"
                            checked={!!formEditando.obrigatoria}
                            onChange={(e) => setFormEditando({ ...formEditando, obrigatoria: e.target.checked })}
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
                    <X size={13} />
                    Cancelar
                </Button>
                <Button
                    type="button"
                    onClick={onSalvar}
                    className="bg-ecf-yellow text-[#050507] hover:bg-ecf-yellow/90 font-semibold"
                >
                    <Check size={13} />
                    Salvar
                </Button>
            </div>
        </div>
    );
}

/**
 * Select nativo dark-themed — mesmo padrão do Sugadores/Index.jsx e do
 * ConfiguracaoLegado.jsx. Evita o overhead do Select shadcn (Radix portal)
 * para inputs simples.
 */
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
