import { ShoppingCart, Briefcase } from 'lucide-react';
import { Card, CardContent } from '@/Components/ui/card';
import { cn } from '@/lib/utils';

/**
 * BlocoFuncionario — contadores diferenciados por cargo no painel lateral.
 *
 * Props:
 *   cargoSlug {"analista"|"estrategista"|null}
 *   sugadorCounters {{resolvidos: number, pendentes: number, nao_resolvidos: number}|null}
 *     — preenchido apenas para analistas (vem do payload Plan 48-01)
 *   ppaCounters {{concluidos_mes: number, em_andamento: number, total: number}|null}
 *     — preenchido apenas para estrategistas (vem do payload Plan 48-01)
 *
 * Renderização:
 *   analista  + sugadorCounters → card "Sugadores" (pendentes/resolvidos/não resolvidos)
 *   estrategista + ppaCounters  → card "PPAs"       (em andamento/concluídos este mês/total)
 *   null / admin               → return null (não renderiza nada)
 */

// Sub-componente: número grande em destaque + label pequeno abaixo.
function CounterItem({ valor, label, corCls = 'text-white/80' }) {
    return (
        <div className="text-center">
            <div className={cn('text-2xl font-bold tabular-nums', corCls)}>
                {valor ?? 0}
            </div>
            <div className="text-white/45 text-[10px] mt-0.5 leading-tight">{label}</div>
        </div>
    );
}

export default function BlocoFuncionario({ cargoSlug, sugadorCounters, ppaCounters }) {
    // Analista com contadores de sugadores disponíveis
    if (cargoSlug === 'analista' && sugadorCounters !== null && sugadorCounters !== undefined) {
        const { pendentes = 0, resolvidos = 0, nao_resolvidos = 0 } = sugadorCounters;
        return (
            <Card className="bg-ecf-card/60 border-white/[0.06]">
                <CardContent className="p-4">
                    {/* Header */}
                    <div className="flex items-center gap-2 text-white/90 text-sm font-semibold mb-4">
                        <ShoppingCart size={14} className="text-amber-300" />
                        Sugadores
                    </div>

                    {/* Grid 3 colunas de counters */}
                    <div className="grid grid-cols-3 gap-3">
                        <CounterItem
                            valor={pendentes}
                            label="Pendentes"
                            corCls={pendentes > 0 ? 'text-amber-300' : 'text-emerald-300'}
                        />
                        <CounterItem
                            valor={resolvidos}
                            label="Resolvidos"
                            corCls="text-emerald-300"
                        />
                        <CounterItem
                            valor={nao_resolvidos}
                            label="Não resolvidos"
                            corCls={nao_resolvidos > 0 ? 'text-red-300' : 'text-white/50'}
                        />
                    </div>

                    {/* Sub-texto */}
                    <div className="text-white/30 text-[10px] mt-3 text-center">
                        empresas da carteira em análise
                    </div>
                </CardContent>
            </Card>
        );
    }

    // Estrategista com contadores de PPAs disponíveis
    if (cargoSlug === 'estrategista' && ppaCounters !== null && ppaCounters !== undefined) {
        const { em_andamento = 0, concluidos_mes = 0, total = 0 } = ppaCounters;
        return (
            <Card className="bg-ecf-card/60 border-white/[0.06]">
                <CardContent className="p-4">
                    {/* Header */}
                    <div className="flex items-center gap-2 text-white/90 text-sm font-semibold mb-4">
                        <Briefcase size={14} className="text-sky-300" />
                        PPAs
                    </div>

                    {/* Grid 3 colunas de counters */}
                    <div className="grid grid-cols-3 gap-3">
                        <CounterItem
                            valor={em_andamento}
                            label="Em andamento"
                            corCls="text-sky-300"
                        />
                        <CounterItem
                            valor={concluidos_mes}
                            label="Concluídos este mês"
                            corCls="text-emerald-300"
                        />
                        <CounterItem
                            valor={total}
                            label="Total"
                            corCls="text-white/60"
                        />
                    </div>
                </CardContent>
            </Card>
        );
    }

    // Admin ou cargo nulo / sem counters → não renderiza
    return null;
}
