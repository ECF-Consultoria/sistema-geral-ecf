import { useState, useEffect, useMemo } from 'react';
import { router } from '@inertiajs/react';
import { Save, Users, RefreshCw, Layers } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { cn } from '@/lib/utils';

/**
 * ServiceScopesPicker — Phase 70 Plan 05 v15.0.
 *
 * Multi-select de serviços cobertos por este template. Persiste via
 * `PUT nps.configuracao.templates.servicos.sync` (Plan 70-04) que faz
 * `syncServicos()` atômico no pivot `nps_template_service_scopes`.
 *
 * Regra de UX importante (decisão registrada no plan T5):
 *   O endpoint `GET empresas-afetadas` baseia-se no PIVOT PERSISTIDO (não no
 *   `selected` local em edição). Portanto:
 *     - Não fazemos debounce sobre `selected` (traria estimativa fantasma).
 *     - O fetch das empresas afetadas roda ao montar o componente e após
 *       cada `save` bem-sucedido.
 *   Se o admin quiser simular antes de salvar, a v15.1 pode expor um endpoint
 *   de simulação por payload. Trade-off aceito por simplicidade em v15.0.
 *
 * Contrato de props:
 *   - template: NpsTemplate (com `servicos` eager-loaded contendo IDs atuais)
 *   - servicos: Array<{id, nome, setor}>  (universo — controller index)
 *   - onSaved:  () => void                (parent recarrega props após sync)
 */
export default function ServiceScopesPicker({ template, servicos, onSaved }) {
    // IDs inicialmente associados via pivot (backend garante consistência).
    const inicial = useMemo(
        () => new Set((template?.servicos ?? []).map((s) => s.id)),
        [template?.id, template?.servicos],
    );

    const [selected, setSelected] = useState(inicial);
    const [processing, setProcessing] = useState(false);
    const [afetadas, setAfetadas] = useState(null);
    const [carregandoAfetadas, setCarregandoAfetadas] = useState(false);

    // Reset quando o template muda.
    useEffect(() => {
        setSelected(inicial);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [template?.id]);

    // Carrega estimativa ao montar / trocar de template (baseado no pivot atual).
    useEffect(() => {
        if (!template?.id) return;
        carregarAfetadas();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [template?.id]);

    const carregarAfetadas = async () => {
        setCarregandoAfetadas(true);
        try {
            const r = await window.axios.get(
                route('nps.configuracao.templates.empresas-afetadas', template.id),
            );
            // Response shape esperado (Plan 70-04):
            //   { template_id, count, empresas: [...], sampled_from, total_ativas, truncated }
            setAfetadas(r.data);
        } catch (e) {
            // Silencioso — a UI mostra "Não foi possível estimar" se falhar.
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
        setProcessing(true);
        router.put(
            route('nps.configuracao.templates.servicos.sync', template.id),
            { servicos: Array.from(selected) },
            {
                preserveScroll: true,
                onSuccess: () => {
                    onSaved && onSaved();
                    // Após persistir, recalcula empresas afetadas com o novo pivot.
                    carregarAfetadas();
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
        // Ordena setores alfabeticamente e serviços internamente por nome.
        return Object.entries(grupos)
            .sort(([a], [b]) => a.localeCompare(b))
            .map(([setor, arr]) => [setor, arr.sort((x, y) => x.nome.localeCompare(y.nome))]);
    }, [servicos]);

    return (
        <div className="bg-ecf-card border border-white/[0.08] rounded-2xl p-6 space-y-4">
            <div className="flex items-start justify-between gap-3">
                <div>
                    <h3 className="text-white font-semibold text-base tracking-tight flex items-center gap-2">
                        <Layers size={16} className="text-ecf-yellow" />
                        Serviços cobertos por este template
                    </h3>
                    <p className="text-white/50 text-xs mt-0.5 leading-relaxed">
                        Marque os serviços que este template deve atender. Empresas com esses serviços em contrato ativo receberão este formulário no envio mensal (regra de precedência: priority DESC).
                    </p>
                </div>
                <Button
                    type="button"
                    onClick={salvar}
                    disabled={!isDirty || processing}
                    className="bg-ecf-yellow text-[#050507] hover:bg-ecf-yellow/90 font-semibold shrink-0"
                >
                    <Save size={13} />
                    {processing ? 'Salvando...' : 'Salvar escopos'}
                </Button>
            </div>

            {/* Grid de checkboxes agrupados por setor */}
            <div className="space-y-3">
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

            {/* Estimativa de empresas afetadas (baseado no pivot ATUAL persistido) */}
            <div className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-4">
                <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0 flex-1">
                        <div className="flex items-center gap-2">
                            <Users size={14} className="text-white/60" />
                            <p className="text-white/70 text-[13px] font-semibold">
                                Empresas afetadas (com o pivot atual)
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
                                    {afetadas.count} empresa{afetadas.count === 1 ? '' : 's'} receberia{afetadas.count === 1 ? '' : 'm'} este template
                                </p>
                                {afetadas.truncated && (
                                    <p className="text-white/40 text-[11px] mt-1 leading-relaxed">
                                        Amostra baseada em {afetadas.sampled_from} de {afetadas.total_ativas} empresas ativas.
                                    </p>
                                )}
                                {isDirty && (
                                    <p className="text-amber-300/80 text-[11.5px] mt-2 leading-relaxed">
                                        Você tem alterações não salvas — a estimativa reflete o pivot antes das mudanças. Salve para recalcular.
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

                {/* Lista compacta das primeiras N empresas para conferência visual */}
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
        </div>
    );
}
