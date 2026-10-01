import { formatCurrency, formatCurrencyCompact } from '@/lib/utils';

// Rampa ORDINAL de um matiz só (amarelo ECF): M1 mais escuro → M4 pleno. A ordem das fases
// é o dado, então a cor mostra a ordem — não é paleta categórica. Validada contra a superfície
// do card (#12141a): luminosidade monotônica, degraus visíveis, M1 a 2,86:1.
const COR_FASE = { M1: '#6b5f00', M2: '#9c8b00', M3: '#cdb800', M4: '#ffe600' };
const COR_OUTRA = 'rgba(255,255,255,0.35)'; // Fechamento: fora da rampa M1→M4

const dica = (f, pct) => `${f.fase} · ${formatCurrency(f.faturamento)} · ${pct}% · ${f.empresas} ${f.empresas === 1 ? 'empresa' : 'empresas'}`;

/**
 * Quanto cada M vende no mês: barra empilhada (parte do todo) + legenda com valor e %.
 * `fases` vem pronto do backend (`faturamentoPorFase`), M1–M4 sempre presentes.
 */
export default function FaturamentoPorFase({ fases, total }) {
    // M1–M4 sempre; outra fase só se tiver empresa (senão vira linha "R$ 0" sem sentido).
    const linhas = fases
        .filter((f) => f.fase in COR_FASE || f.empresas > 0)
        .map((f) => ({ ...f, cor: COR_FASE[f.fase] ?? COR_OUTRA, pct: total > 0 ? Math.round(f.faturamento / total * 100) : 0 }));

    return (
        <div className="space-y-2">
            {/* flex-grow proporcional em vez de width %: o gap de 2px entre fatias não estoura 100%. */}
            <div className="flex h-1.5 gap-[2px] overflow-hidden rounded-full bg-white/[0.06]">
                {linhas.filter((f) => f.faturamento > 0).map((f) => (
                    <div key={f.fase} title={dica(f, f.pct)} className="h-full"
                         style={{ flexGrow: f.faturamento, flexBasis: 0, background: f.cor }} />
                ))}
            </div>
            {/* Colunas pela largura do CARD (auto-fit), não da tela: com a sidebar, um card em
                viewport "lg" é mais estreito que um em "sm" e truncava o valor. No celular o %
                sai — a barra acima já mostra a proporção. */}
            <div className="grid gap-x-4 gap-y-1" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(7.5rem, 1fr))' }}>
                {linhas.map((f) => (
                    <div key={f.fase} title={dica(f, f.pct)} className="flex items-center gap-1.5 whitespace-nowrap text-[11px]">
                        <span aria-hidden="true" className="h-2 w-2 shrink-0 rounded-sm" style={{ background: f.cor }} />
                        <span className="font-semibold text-white/55">{f.fase}</span>
                        <span className="tabular-nums text-white/85">{formatCurrencyCompact(f.faturamento)}</span>
                        <span className="ml-auto pl-1 tabular-nums text-white/35 max-[479px]:hidden">{f.pct}%</span>
                    </div>
                ))}
            </div>
        </div>
    );
}
