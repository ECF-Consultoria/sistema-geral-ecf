import { useForm, usePage, router, Link } from '@inertiajs/react';
import { useState, useEffect, useMemo } from 'react';
import {
    Save, Power, PowerOff, AlertCircle, Info, X, Trophy, BookOpen,
    CheckCircle2,
} from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { cn } from '@/lib/utils';

/**
 * Phase 74 D-12/D-13/D-21 · Plan 74-07 · UI admin de configuração da régua
 * de bônus do módulo Desempenho.
 *
 * Consome o payload de `DesempenhoConfigController::index` (Plan 74-05):
 *   faixas: BonusFaixa[] (ativas + inativas, ordenadas)
 *   flash?: { success?, error? }
 *
 * Recursos:
 *  - Edição inline de nome, descrição, nota_min, nota_max, ordem
 *  - Toggle ativo/inativo preservando histórico (não apaga row)
 *  - Preview do intervalo atualiza on-change
 *  - Erros de validação backend renderizados ao lado do campo (pt-BR)
 *  - Toast de sucesso via flash.success (auto-dismiss em 3s)
 *  - Design dark/glass consistente com o resto do painel (bg-ecf-card, etc)
 *
 * Rotas alvo (registradas em routes/web.php · middleware role:admin):
 *  - PATCH desempenho.configuracao.faixas.update  → editar
 *  - PATCH desempenho.configuracao.faixas.toggle  → ativar/desativar
 *
 * NOTA: NÃO oferece CRUD de novas faixas — o admin trabalha com o seed
 * fixo de 4 faixas (D-16). Se precisar adicionar uma faixa, criar seed
 * dedicada + reseed pelo dev.
 */

// ═══ Toast helper ══════════════════════════════════════════════════════
function Toast({ mensagem, onClose }) {
    useEffect(() => {
        if (!mensagem) return;
        const id = setTimeout(() => onClose(), 3000);
        return () => clearTimeout(id);
    }, [mensagem, onClose]);

    if (!mensagem) return null;

    return (
        <div className="fixed top-4 right-4 z-50 bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 rounded-2xl px-4 py-3 shadow-lg flex items-center gap-3">
            <CheckCircle2 size={16} className="shrink-0" />
            <span className="text-sm font-semibold">{mensagem}</span>
            <button
                type="button"
                onClick={onClose}
                className="text-emerald-300/60 hover:text-emerald-300"
                aria-label="Fechar"
            >
                <X size={14} />
            </button>
        </div>
    );
}

// ═══ Cabeçalho da página ═══════════════════════════════════════════════
function Cabecalho() {
    return (
        <div className="flex items-start gap-3 flex-wrap">
            <span className="grid h-11 w-11 place-items-center rounded-xl bg-ecf-yellow/10 text-ecf-yellow shrink-0">
                <Trophy size={20} />
            </span>
            <div className="flex-1 min-w-0">
                <h1 className="text-white text-xl font-display font-extrabold leading-tight">
                    Régua de Bonificação — Desempenho
                </h1>
                <p className="text-white/60 text-sm mt-1">
                    Configure as faixas de bônus do módulo Performance. As mudanças refletem
                    imediatamente no ranking e no artigo do Manual.
                </p>
            </div>
            <Link
                href="/manual/desempenho-bonificacao"
                className="inline-flex items-center gap-1.5 text-ecf-yellow text-xs font-semibold hover:underline"
            >
                <BookOpen size={12} />
                Ver artigo do Manual
            </Link>
        </div>
    );
}

// ═══ Alerta de regras ═════════════════════════════════════════════════
function AlertaRegras() {
    return (
        <div className="rounded-2xl border border-ecf-yellow/20 bg-ecf-yellow/[0.03] p-4">
            <div className="flex items-start gap-3">
                <Info size={16} className="text-ecf-yellow/70 shrink-0 mt-0.5" />
                <div className="space-y-1.5 text-sm text-white/70 leading-relaxed">
                    <p>
                        <strong className="text-white/90">Regras de validação:</strong>
                    </p>
                    <ul className="list-disc list-inside space-y-1 text-white/60 text-[13px]">
                        <li>Nota mínima estritamente menor que a nota máxima (exceto a faixa <code className="text-ecf-yellow font-mono">maximo</code>, que aceita <code className="text-ecf-yellow font-mono">[5.00, 5.00]</code>).</li>
                        <li>Sem sobreposição entre faixas ativas — intervalos <code className="text-ecf-yellow font-mono">[nota_min, nota_max]</code> fechados.</li>
                        <li>Valores entre <code className="text-ecf-yellow font-mono">0.00</code> e <code className="text-ecf-yellow font-mono">5.00</code> inclusive.</li>
                    </ul>
                    <p className="text-white/50 text-xs pt-1">
                        <strong>Regra especial:</strong> 2 meses consecutivos em <em>Intermediário</em> promovem automaticamente para <em>Máximo</em> — configurada no service (não editável aqui).
                    </p>
                </div>
            </div>
        </div>
    );
}

// ═══ Card individual de faixa ═════════════════════════════════════════
function FaixaCard({ faixa }) {
    const { data, setData, patch, processing, errors, isDirty, reset } = useForm({
        nome: faixa.nome,
        descricao: faixa.descricao ?? '',
        nota_min: faixa.nota_min,
        nota_max: faixa.nota_max,
        ordem: faixa.ordem,
    });

    // Reset local ao trocar de faixa (ex.: após save que retorna props novas).
    useEffect(() => {
        reset();
        setData({
            nome: faixa.nome,
            descricao: faixa.descricao ?? '',
            nota_min: faixa.nota_min,
            nota_max: faixa.nota_max,
            ordem: faixa.ordem,
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [faixa.id, faixa.updated_at]);

    const handleSalvar = (e) => {
        e.preventDefault();
        patch(route('desempenho.configuracao.faixas.update', faixa.id), {
            preserveScroll: true,
        });
    };

    const handleToggle = () => {
        router.patch(
            route('desempenho.configuracao.faixas.toggle', faixa.id),
            {},
            { preserveScroll: true },
        );
    };

    // Preview do intervalo (usa string do useForm — reflete edição em curso).
    const previewMin = Number(data.nota_min ?? 0).toFixed(2);
    const previewMax = Number(data.nota_max ?? 0).toFixed(2);

    return (
        <div
            className={cn(
                'bg-ecf-card border border-white/[0.08] rounded-2xl p-6 space-y-4',
                !faixa.ativo && 'opacity-60',
            )}
        >
            {/* Header do card */}
            <div className="flex items-start justify-between gap-3 flex-wrap">
                <div className="flex-1 min-w-0">
                    <label className="text-[10px] uppercase tracking-wider font-bold text-white/50 block mb-1">
                        Nome da faixa
                    </label>
                    <input
                        type="text"
                        value={data.nome}
                        onChange={(e) => setData('nome', e.target.value)}
                        className="w-full bg-white/[0.03] border border-white/[0.08] rounded-xl px-3 py-2 text-white text-lg font-display font-bold focus:outline-none focus:ring-1 focus:ring-ecf-yellow/40"
                    />
                    {errors.nome && (
                        <p className="text-rose-400 text-xs mt-1 flex items-center gap-1">
                            <AlertCircle size={11} />
                            {errors.nome}
                        </p>
                    )}
                    <div className="flex items-center gap-2 mt-2">
                        <span className="text-white/40 text-[10px] uppercase tracking-wider font-mono px-2 py-0.5 rounded bg-white/[0.03] border border-white/[0.08]">
                            {faixa.slug}
                        </span>
                        {!faixa.ativo && (
                            <span className="text-white/60 text-[10px] uppercase tracking-wider font-semibold px-2 py-0.5 rounded bg-white/[0.04] border border-white/[0.10]">
                                Inativa
                            </span>
                        )}
                    </div>
                </div>

                <button
                    type="button"
                    onClick={handleToggle}
                    disabled={processing}
                    className={cn(
                        'inline-flex items-center gap-2 h-9 px-3 rounded-xl border transition-colors text-xs font-semibold',
                        faixa.ativo
                            ? 'bg-emerald-500/10 text-emerald-300 border-emerald-500/30 hover:bg-emerald-500/20'
                            : 'bg-white/[0.03] text-white/50 border-white/[0.10] hover:bg-white/[0.06]',
                    )}
                    title={faixa.ativo ? 'Desativar faixa (histórico preservado)' : 'Reativar faixa'}
                >
                    {faixa.ativo ? <Power size={14} /> : <PowerOff size={14} />}
                    {faixa.ativo ? 'Ativa' : 'Inativa'}
                </button>
            </div>

            {/* Form de edição */}
            <form onSubmit={handleSalvar} className="space-y-4">
                <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
                    {/* nota_min */}
                    <div>
                        <label className="text-[10px] uppercase tracking-wider font-bold text-white/50 block mb-1">
                            Nota mínima
                        </label>
                        <input
                            type="number"
                            step="0.01"
                            min="0"
                            max="5"
                            value={data.nota_min}
                            onChange={(e) => setData('nota_min', e.target.value)}
                            className="w-full bg-white/[0.03] border border-white/[0.08] rounded-xl px-3 py-2 text-white font-mono tabular-nums focus:outline-none focus:ring-1 focus:ring-ecf-yellow/40"
                        />
                        {errors.nota_min && (
                            <p className="text-rose-400 text-xs mt-1 flex items-center gap-1">
                                <AlertCircle size={11} />
                                {errors.nota_min}
                            </p>
                        )}
                    </div>

                    {/* nota_max */}
                    <div>
                        <label className="text-[10px] uppercase tracking-wider font-bold text-white/50 block mb-1">
                            Nota máxima
                        </label>
                        <input
                            type="number"
                            step="0.01"
                            min="0"
                            max="5"
                            value={data.nota_max}
                            onChange={(e) => setData('nota_max', e.target.value)}
                            className="w-full bg-white/[0.03] border border-white/[0.08] rounded-xl px-3 py-2 text-white font-mono tabular-nums focus:outline-none focus:ring-1 focus:ring-ecf-yellow/40"
                        />
                        {errors.nota_max && (
                            <p className="text-rose-400 text-xs mt-1 flex items-center gap-1">
                                <AlertCircle size={11} />
                                {errors.nota_max}
                            </p>
                        )}
                    </div>

                    {/* ordem */}
                    <div>
                        <label className="text-[10px] uppercase tracking-wider font-bold text-white/50 block mb-1">
                            Ordem de exibição
                        </label>
                        <input
                            type="number"
                            step="1"
                            min="0"
                            value={data.ordem}
                            onChange={(e) => setData('ordem', e.target.value)}
                            className="w-full bg-white/[0.03] border border-white/[0.08] rounded-xl px-3 py-2 text-white font-mono tabular-nums focus:outline-none focus:ring-1 focus:ring-ecf-yellow/40"
                        />
                        {errors.ordem && (
                            <p className="text-rose-400 text-xs mt-1 flex items-center gap-1">
                                <AlertCircle size={11} />
                                {errors.ordem}
                            </p>
                        )}
                    </div>
                </div>

                {/* descricao */}
                <div>
                    <label className="text-[10px] uppercase tracking-wider font-bold text-white/50 block mb-1">
                        Descrição (aparece no artigo do Manual)
                    </label>
                    <textarea
                        rows={3}
                        value={data.descricao}
                        onChange={(e) => setData('descricao', e.target.value)}
                        placeholder="Texto que aparece no artigo do Manual explicando esta faixa"
                        className="w-full bg-white/[0.03] border border-white/[0.08] rounded-xl px-3 py-2 text-white text-sm focus:outline-none focus:ring-1 focus:ring-ecf-yellow/40 resize-y"
                    />
                    {errors.descricao && (
                        <p className="text-rose-400 text-xs mt-1 flex items-center gap-1">
                            <AlertCircle size={11} />
                            {errors.descricao}
                        </p>
                    )}
                </div>

                {/* Preview + botão salvar */}
                <div className="flex items-center justify-between gap-3 flex-wrap pt-2 border-t border-white/[0.05]">
                    <p className="text-white/50 text-xs">
                        Preview: faixa cobre notas de{' '}
                        <span className="text-ecf-yellow font-mono tabular-nums">{previewMin}</span>{' '}
                        a{' '}
                        <span className="text-ecf-yellow font-mono tabular-nums">{previewMax}</span>.
                    </p>
                    <button
                        type="submit"
                        disabled={processing || !isDirty}
                        className={cn(
                            'inline-flex items-center gap-2 h-9 px-4 rounded-xl text-xs font-semibold transition-colors',
                            (processing || !isDirty)
                                ? 'bg-white/[0.04] text-white/30 cursor-not-allowed'
                                : 'bg-ecf-yellow text-[#252525] hover:bg-yellow-300',
                        )}
                    >
                        <Save size={14} />
                        Salvar alterações
                    </button>
                </div>
            </form>
        </div>
    );
}

// ═══ Página principal ══════════════════════════════════════════════════
export default function Configuracao({ faixas = [] }) {
    const { flash } = usePage().props;
    const [toast, setToast] = useState(flash?.success ?? null);

    useEffect(() => {
        setToast(flash?.success ?? null);
    }, [flash?.success]);

    // Contadores para exibição no cabeçalho (ativas x total).
    const totalAtivas = useMemo(
        () => faixas.filter(f => f.ativo).length,
        [faixas],
    );

    return (
        <AppLayout title="Configuração — Régua de Bônus">
            <Toast mensagem={toast} onClose={() => setToast(null)} />

            <div className="max-w-5xl mx-auto space-y-6">
                <Cabecalho />

                <div className="flex items-center gap-3 text-xs text-white/50">
                    <span>
                        <strong className="text-white">{totalAtivas}</strong> ativas · <strong className="text-white">{faixas.length}</strong> total
                    </span>
                </div>

                <AlertaRegras />

                {faixas.length === 0 ? (
                    <div className="rounded-2xl border border-white/[0.06] bg-white/[0.02] p-12 text-center">
                        <Trophy size={32} className="mx-auto mb-3 text-white/20" />
                        <p className="text-white/40 text-sm">
                            Nenhuma faixa configurada. Verifique a seed inicial.
                        </p>
                    </div>
                ) : (
                    <div className="space-y-4">
                        {faixas.map(f => (
                            <FaixaCard key={f.id} faixa={f} />
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
