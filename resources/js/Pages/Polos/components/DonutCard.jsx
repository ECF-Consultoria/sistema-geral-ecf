import { formatCurrency, cn } from '@/lib/utils';
import CityGauge from './CityGauge';
import { shapeForPolo } from './cityShapes';

// Cor + rótulo do card pela CONQUISTA DA META do polo (não pelo status "pior caso"
// agregado, que pintava o polo de roxo "Problema" se UMA única empresa tivesse o
// flag — mesmo o polo tendo batido a meta). O "Problema" vira um selo (abaixo).
function metaVisual(pct) {
    const p = Number(pct) || 0;
    if (p >= 100) return { cor: '#22c55e', label: 'No alvo' };       // bateu a meta
    if (p > 0)    return { cor: '#ffe600', label: 'Em progresso' };  // 0 < x < 100
    return { cor: '#ef4444', label: 'Não faturou' };                 // 0%
}

// Cor do anel "restante" (faltante para a meta) — cinza sutil no tema dark
const COR_RESTANTE = '#2a2d36';

// Cor do selo de problema (empresas do polo com flag) — NÃO pinta a silhueta
const COR_PROBLEMA = '#a855f7';

/**
 * DonutCard — card de % da meta por polo (Phase 38 · quick 260619-dce).
 *
 * O CARD É O POLO: sem caixa retangular — a silhueta da cidade (CityGauge) é o
 * próprio card, enchendo de baixo até o % da meta. Nome em cima, % no centro da
 * cidade, status + KPIs compactos embaixo. Fallback para anel flat quando o polo
 * não tem contorno mapeado.
 *
 * Recebe o objeto polo agregado (de UM mês) pelo PolosController:
 *   { polo, pct, faturamento, meta, ativos, status }
 */
export default function DonutCard({ polo: dados, cor: corPolo }) {
    const {
        polo: nome,
        pct         = 0,
        faturamento = 0,
        meta        = 0,
        ativos      = 0,
        empresas    = [],
    } = dados;

    // Cor/rótulo pela conquista da meta (verde >=100, amarelo <100, vermelho 0)
    const { cor, label } = metaVisual(pct);

    // Empresas deste polo marcadas com problema → vira selo, não pinta a silhueta
    const nProblema = (empresas ?? []).filter((e) => e?.problema).length;

    const temShape = !!shapeForPolo(nome);

    // ── Anel de progresso flat (fallback p/ polos sem contorno) ───────────────
    const atingido = Math.max(Math.min(Number(pct) || 0, 100), 0);
    const size     = 168;
    const stroke   = 13;
    const c        = size / 2;
    const r        = (size - stroke) / 2 - 6;
    const circ     = 2 * Math.PI * r;
    const dash     = (atingido / 100) * circ;

    return (
        <div className="flex flex-col items-center gap-2 px-2 py-3">
            {/* Nome do polo + ponto de identidade */}
            <div className="flex items-center gap-2 min-w-0">
                {corPolo && <span className="h-2.5 w-2.5 rounded-full shrink-0" style={{ background: corPolo }} />}
                <span className="text-white font-semibold text-sm tracking-wide uppercase truncate">{nome}</span>
            </div>

            {/* O polo em si: silhueta da cidade (ou anel, se não houver contorno) */}
            <div className="flex items-center justify-center" style={{ height: 190 }}>
                {temShape ? (
                    <CityGauge nome={nome} pct={pct} cor={cor} height={190} />
                ) : (
                    <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`} aria-hidden="true">
                        {/* Sem glow: o halo do feDropShadow era recortado pelo viewport do SVG
                            e virava um quadrado luminoso em volta do anel. */}
                        <circle cx={c} cy={c} r={r} fill="none" stroke={COR_RESTANTE} strokeWidth={stroke} />
                        {dash > 0 && (
                            <circle
                                cx={c} cy={c} r={r} fill="none"
                                stroke={cor} strokeWidth={stroke} strokeLinecap="round"
                                strokeDasharray={`${dash} ${circ - dash}`}
                                transform={`rotate(-90 ${c} ${c})`}
                            />
                        )}
                        <text x={c} y={c - 2} textAnchor="middle" dominantBaseline="middle"
                              fontSize="32" fontWeight="800" fill={cor} fontFamily="inherit">
                            {(Number(pct) || 0).toFixed(0)}%
                        </text>
                        <text x={c} y={c + 22} textAnchor="middle"
                              fontSize="9" letterSpacing="1" fill="rgba(255,255,255,0.35)" fontFamily="inherit">
                            DA META
                        </text>
                    </svg>
                )}
            </div>

            {/* Status pela meta + selo de problema (se houver empresas sinalizadas) */}
            <div className="flex flex-col items-center gap-1">
                <div className="flex items-center gap-1.5">
                    <span className="h-2 w-2 rounded-full" style={{ background: cor }} />
                    <span className="text-[11px] uppercase tracking-wider" style={{ color: cor }}>{label}</span>
                </div>
                {nProblema > 0 && (
                    <span
                        className="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold"
                        style={{ background: `${COR_PROBLEMA}22`, color: COR_PROBLEMA }}
                        title={`${nProblema} de ${ativos} ${nProblema === 1 ? 'empresa' : 'empresas'} deste polo com problema sinalizado — não afeta a cor da meta`}
                    >
                        <span className="h-1.5 w-1.5 rounded-full" style={{ background: COR_PROBLEMA }} />
                        {nProblema} com problema
                    </span>
                )}
            </div>

            {/* KPIs compactos (sem caixa) */}
            <div className="flex items-center gap-2 text-[11px] text-white/45">
                <span className="text-white/85 font-semibold tabular-nums">{formatCurrency(faturamento)}</span>
                <span className="text-white/20">·</span>
                <span className="tabular-nums">meta {formatCurrency(meta)}</span>
                <span className="text-white/20">·</span>
                <span className="tabular-nums">{ativos} {ativos === 1 ? 'empresa' : 'empresas'}</span>
            </div>
        </div>
    );
}
