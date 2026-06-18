import { formatCurrency, cn } from '@/lib/utils';
import Pie3D from './Pie3D';

// Mapa de status → cor da fatia atingida + rótulo (Phase 38 — novo modelo D-11/D-13).
// Status vem do PolosController como string: 'Sim' | 'Em progresso' | 'Não' | 'Problema'.
const STATUS = {
    'Sim':          { cor: '#22c55e', label: 'No alvo' },      // fat >= limiar
    'Em progresso': { cor: '#ffe600', label: 'Em progresso' }, // 0 < fat < limiar
    'Não':          { cor: '#ef4444', label: 'Não' },          // fat = 0 (sem CSV)
    'Problema':     { cor: '#a855f7', label: 'Problema' },     // empresa.problema = true (precedência máxima)
};

// Cor da fatia "restante" (faltante para a meta) — cinza sutil no tema dark
const COR_RESTANTE = '#2a2d36';

/**
 * DonutCard — card de % da meta por polo, como pizza 3D (Phase 38).
 *
 * Recebe o objeto polo agregado (de UM mês) pelo PolosController:
 *   { polo, pct, faturamento, meta, ativos, status }
 *
 * Mostra uma pizza 3D (atingido = cor de status · restante = cinza), o % da meta
 * em destaque e os KPIs faturamento/meta/empresas ativas. `cor` opcional pinta
 * o ponto de identidade do polo (mesma cor da pizza de distribuição).
 */
export default function DonutCard({ polo: dados, cor: corPolo }) {
    const {
        polo: nome,
        pct         = 0,
        faturamento = 0,
        meta        = 0,
        ativos      = 0,
        status      = 'Não',
    } = dados;

    // Fallback para 'Não' caso status desconhecido (defesa contra prop malformada — T-38-11)
    const { cor, label } = STATUS[status] ?? STATUS['Não'];

    // Fatias da pizza: atingido (clampado 0–100) vs restante até a meta
    const atingido = Math.max(Math.min(pct, 100), 0);
    const slices = [
        { color: cor,          value: atingido },
        { color: COR_RESTANTE, value: Math.max(100 - atingido, 0) },
    ];

    return (
        <div className={cn(
            'group relative overflow-hidden rounded-2xl p-5 flex flex-col gap-3',
            'border border-white/[0.08] bg-white/[0.02] transition-colors hover:bg-white/[0.04]',
        )}>
            {/* Faixa de status no topo do card */}
            <span className="absolute inset-x-0 top-0 h-[3px]" style={{ background: cor }} />

            {/* Cabeçalho: nome do polo + selo de status */}
            <div className="flex items-center justify-between gap-2">
                <span className="flex items-center gap-2 min-w-0">
                    {corPolo && <span className="h-2.5 w-2.5 rounded-full shrink-0" style={{ background: corPolo }} />}
                    <span className="text-white font-semibold text-sm tracking-wide uppercase truncate">{nome}</span>
                </span>
                <span className="flex items-center gap-1.5 shrink-0">
                    <span className="h-2 w-2 rounded-full" style={{ background: cor }} />
                    <span className="text-[10px] uppercase tracking-wider text-white/40">{label}</span>
                </span>
            </div>

            {/* Pizza 3D (% da meta) */}
            <div className="flex items-center justify-center py-2" style={{ minHeight: 150 }}>
                <Pie3D slices={slices} size={170} depth={22} tilt={56} gap={false} />
            </div>

            {/* % da meta em destaque */}
            <div className="text-center -mt-1">
                <span className="font-display font-extrabold leading-none text-3xl" style={{ color: cor }}>
                    {pct.toFixed(0)}%
                </span>
                <span className="text-[10px] uppercase tracking-wider text-white/35 ml-1.5">da meta</span>
            </div>

            {/* KPIs */}
            <div className="space-y-1.5 pt-1">
                <div className="flex items-center justify-between text-xs">
                    <span className="text-white/40">Faturamento</span>
                    <span className="text-white font-semibold">{formatCurrency(faturamento)}</span>
                </div>
                <div className="flex items-center justify-between text-xs">
                    <span className="text-white/40">Meta</span>
                    <span className="text-white/70 font-medium">{formatCurrency(meta)}</span>
                </div>
                <div className="flex items-center justify-between text-xs pt-1.5 border-t border-white/[0.06]">
                    <span className="text-white/40">Empresas ativas</span>
                    <span className="text-white/70 font-medium">{ativos}</span>
                </div>
            </div>
        </div>
    );
}
