import { useState, useEffect, useMemo } from 'react';
import { router } from '@inertiajs/react';
import { Save, Users, RefreshCw, Layers } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
    DialogFooter,
} from '@/Components/ui/dialog';
import { cn } from '@/lib/utils';

/**
 * ServiceScopesModal — Phase 70 Plan 05 v15.0 + UX refactor 2026-07-08.
 *
 * Ajuste 4: substitui o antigo ServiceScopesPicker (empilhado na coluna
 * direita, poluía a UI) por um Modal Radix acionado pelo botão
 * "🔗 Serviços cobertos (N)" no TemplateEditForm.
 *
 * Persiste via `PUT nps.configuracao.templates.servicos.sync` (Plan 70-04) —
 * mesmo endpoint do antigo Picker.
 *
 * Regra de UX (herdada da v15.0 T5):
 *   O endpoint `GET empresas-afetadas` baseia-se no PIVOT PERSISTIDO. Estimativa
 *   é carregada ao abrir o modal e após cada save bem-sucedido. Enquanto o
 *   admin edita antes de salvar, o contador reflete o estado ANTERIOR
 *   (banner amarelo avisa quando há alterações não salvas).
 *
 * Contrato de props:
 *   - open:          boolean — controla open/close (parent state)
 *   - onOpenChange:  (open: boolean) => void — callback do Radix Dialog
 *   - template:      NpsTemplate (com `servicos` eager-loaded)
 *   - servicos:      Array<{id, nome, setor}> (universo)
 *   - onSaved:       () => void — parent recarrega props após sync
 *   - mostrarToast:  () => void — dispara toast "Salvo"
 */
export default function ServiceScopesModal({
    open,
    onOpenChange,
    template,
    servicos,
    onSaved,
    mostrarToast,
}) {
    // IDs inicialmente associados via pivot.
    const inicial = useMemo(
        () => new Set((template?.servicos ?? []).map((s) => s.id)),
        [template?.id, template?.servicos],
    );

    const [selected, setSelected]     = useState(inicial);
    const [processing, setProcessing] = useState(false);
    const [afetadas, setAfetadas]     = useState(null);
    const [carregandoAfetadas, setCarregandoAfetadas] = useState(false);

    // Reset quando template muda OU quando modal reabre.
    useEffect(() => {
        setSelected(inicial);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [template?.id, open]);

    // Carrega estimativa ao abrir modal / trocar template.
    useEffect(() => {
        if (!open || !template?.id) return;
        carregarAfetadas();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, template?.id]);

    const carregarAfetadas = async () => {
        setCarregandoAfetadas(true);
        try {
            const r = await window.axios.get(
                route('nps.configuracao.templates.empresas-afetadas', template.id),
            );
            setAfetadas(r.data);
        } catch (e) {
            setAfetadas({ error: true });
        } finally {
            setCarregandoAfetadas(false);
        }
    };

    const toggle = (id) => {
        setSelected((prev) => {
            const next = new Set(prev);
            if (next.has(id)) next.delete(id);
            else next.add(id);
            return next;
        });
    };

    const isDirty = useMemo(() => {
        if (selected.size !== inicial.size) return true;
        for (const id of selected) if (!inicial.has(id)) return true;
        return false;
    }, [selected, inicial]);

    const salvar = () => {
        if (!template?.id) return;
        setProcessing(true);
        router.put(
            route('nps.configuracao.templates.servicos.sync', template.id),
            { servicos: Array.from(selected) },
            {
                preserveScroll: true,
                onSuccess: () => {
                    mostrarToast && mostrarToast();
                    onSaved && onSaved();
                    // Após persistir, fecha o modal — o parent recarrega props
                    // e reabre em estado limpo se o admin clicar de novo.
                    onOpenChange(false);
                },
                onFinish: () => setProcessing(false),
            },
        );
    };

    // Agrupa serviços por setor pra facilitar leitura.
    const porSetor = useMemo(() => {
        const grupos = {};
        for (const s of servicos ?? []) {
            const key = s.setor || 'outros';
            (grupos[key] ??= []).push(s);
        }
        return Object.entries(grupos)
            .sort(([a], [b]) => a.localeCompare(b))
            .map(([setor, arr]) => [setor, arr.sort((x, y) => x.nome.localeCompare(y.nome))]);
    }, [servicos]);

    if (!template) return null;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                className={cn(
                    'bg-ecf-card border-white/[0.08] text-white',
                    'max-w-3xl w-[95vw] max-h-[85vh] overflow-y-auto',
                )}
            >
                <DialogHeader>
                    <DialogTitle className="text-white flex items-center gap-2 text-lg">
                        <Layers size={18} className="text-ecf-yellow" />
                        Serviços cobertos — {template.nome}
                    </DialogTitle>
                    <DialogDescription className="text-white/60 text-[13px] leading-relaxed">
                        Marque os serviços que este modelo deve atender. Empresas com esses serviços em contrato ativo receberão este formulário no envio mensal
                        <span className="text-white/50"> (precedência: prioridade mais alta vence)</span>.
                    </DialogDescription>
                </DialogHeader>

                {/* Grid de checkboxes agrupados por setor */}
                <div className="space-y-3 pt-2">
                    {porSetor.length === 0 && (
                        <p className="text-white/40 text-[12px] italic px-1">
                            Nenhum serviço ativo cadastrado.
                        </p>
                    )}
                    {porSetor.map(([setor, lista]) => (
                        <div key={setor} className="space-y-1.5">
                            <p className="text-white/50 text-[10.5px] font-semibold uppercase tracking-wider px-1">
                                {setor}
                            </p>
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-1.5">
                                {lista.map((s) => {
                                    const checked = selected.has(s.id);
                                    return (
                                        <label
                                            key={s.id}
                                            className={cn(
                                                'flex items-center gap-2 rounded-lg border px-3 py-2 cursor-pointer select-none transition-colors',
                                                checked
                                                    ? 'border-ecf-yellow/40 bg-ecf-yellow/[0.06]'
                                                    : 'border-white/[0.06] bg-white/[0.02] hover:bg-white/[0.04]',
                                            )}
                                        >
                                            <input
                                                type="checkbox"
                                                checked={checked}
                                                onChange={() => toggle(s.id)}
                                                className="h-4 w-4 rounded border-white/20 bg-white/[0.05] text-ecf-yellow focus:ring-ecf-yellow/40 cursor-pointer"
                                            />
                                            <span className={cn('text-[13px]', checked ? 'text-white' : 'text-white/80')}>
                                                {s.nome}
                                            </span>
                                        </label>
                                    );
                                })}
                            </div>
                        </div>
                    ))}
                </div>

                {/* Estimativa de empresas afetadas */}
                <div className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-4 mt-2">
                    <div className="flex items-start justify-between gap-3">
                        <div className="min-w-0 flex-1">
                            <div className="flex items-center gap-2">
                                <Users size={14} className="text-white/60" />
                                <p className="text-white/70 text-[13px] font-semibold">
                                    Empresas afetadas (pivot atual)
                                </p>
                            </div>
                            {carregandoAfetadas && (
                                <p className="text-white/40 text-[11.5px] mt-2 flex items-center gap-1.5">
                                    <RefreshCw size={11} className="animate-spin" />
                                    Calculando...
                                </p>
                            )}
                            {!carregandoAfetadas && afetadas?.error && (
                                <p className="text-red-300 text-[11.5px] mt-2">
                                    Não foi possível calcular a estimativa. Tente atualizar.
                                </p>
                            )}
                            {!carregandoAfetadas && afetadas && !afetadas.error && (
                                <>
                                    <p className="text-white/80 text-[14px] font-semibold mt-1">
                                        {afetadas.count} empresa{afetadas.count === 1 ? '' : 's'} receberia{afetadas.count === 1 ? '' : 'm'} este modelo
                                    </p>
                                    {afetadas.truncated && (
                                        <p className="text-white/40 text-[11px] mt-1 leading-relaxed">
                                            Amostra baseada em {afetadas.sampled_from} de {afetadas.total_ativas} empresas ativas.
                                        </p>
                                    )}
                                    {isDirty && (
                                        <p className="text-amber-300/80 text-[11.5px] mt-2 leading-relaxed">
                                            Você tem alterações não salvas — a estimativa reflete o pivot anterior. Salve para recalcular.
                                        </p>
                                    )}
                                </>
                            )}
                        </div>
                        <Button
                            type="button"
                            size="sm"
                            onClick={carregarAfetadas}
                            disabled={carregandoAfetadas}
                            variant="outline"
                            className="border-white/[0.08] bg-white/[0.03] text-white/70 hover:bg-white/[0.08] hover:text-white shrink-0"
                        >
                            <RefreshCw size={12} className={cn(carregandoAfetadas && 'animate-spin')} />
                            Atualizar
                        </Button>
                    </div>

                    {!carregandoAfetadas && afetadas?.empresas?.length > 0 && (
                        <div className="mt-3 flex flex-wrap gap-1.5">
                            {afetadas.empresas.slice(0, 12).map((e) => (
                                <span
                                    key={e.id}
                                    className="inline-flex items-center text-[11px] px-2 py-0.5 rounded bg-white/[0.04] text-white/60 border border-white/[0.06]"
                                    title={`Empresa #${e.id}`}
                                >
                                    {e.name}
                                </span>
                            ))}
                            {afetadas.empresas.length > 12 && (
                                <span className="text-[11px] text-white/40 self-center">
                                    +{afetadas.empresas.length - 12} mais
                                </span>
                            )}
                        </div>
                    )}
                </div>

                <DialogFooter className="pt-2 border-t border-white/[0.06] mt-2">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                        disabled={processing}
                        className="border-white/[0.08] bg-white/[0.03] text-white/70 hover:bg-white/[0.08] hover:text-white"
                    >
                        Fechar
                    </Button>
                    <Button
                        type="button"
                        onClick={salvar}
                        disabled={!isDirty || processing}
                        className="bg-ecf-yellow text-[#050507] hover:bg-ecf-yellow/90 font-semibold"
                    >
                        <Save size={13} />
                        {processing ? 'Salvando...' : 'Salvar escopos'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
