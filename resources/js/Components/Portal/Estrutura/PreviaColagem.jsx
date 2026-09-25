import { useState } from 'react';
import { ChevronDown, ChevronRight } from 'lucide-react';

// ─── A prévia de uma colagem de anúncios ────────────────────────────────────
//
// Uma só, para as duas portas que trazem anúncios que já existem: colar do
// Excel e importar do Mercado Livre. As duas passam pelo MESMO plano no
// servidor (`ColagemAnunciosService`), então desenham a MESMA prévia — duas
// cópias deste bloco divergiriam na primeira mudança feita de um lado só.

const GRUPOS = [
    { chave: 'novos',       rotulo: 'novos',              cor: 'text-emerald-300' },
    { chave: 'atualizados', rotulo: 'já cadastrados (serão atualizados)', cor: 'text-sky-300' },
    { chave: 'espera',      rotulo: 'aguardando oferta',  cor: 'text-amber-300' },
    { chave: 'erros',       rotulo: 'com erro (não serão gravados)', cor: 'text-red-300' },
    { chave: 'removidos',   rotulo: 'serão REMOVIDOS',    cor: 'text-red-400' },
];

function LinhaPrevia({ grupo, item, vocabulario }) {
    if (grupo === 'erros') {
        return (
            <li className="text-[12px]">
                <span className="text-white/40">linha {item.numero}:</span> <span className="text-red-300">{item.motivo}</span>
                <div className="font-mono text-white/30 truncate">{item.texto}</div>
            </li>
        );
    }

    return (
        <li className="text-[12px] text-white/70 flex flex-wrap gap-x-2">
            {item.numero && <span className="text-white/35">l.{item.numero}</span>}
            <span className="font-mono">{item.sku ?? item.oferta_sku ?? '—'}</span>
            {item.oferta_sku && item.sku && item.oferta_sku !== item.sku && <span className="text-white/40">→ {item.oferta_sku}</span>}
            <span>{vocabulario.tipos[item.tipo]}</span>
            {item.codigo_mlb && <span className="font-mono text-white/45">{item.codigo_mlb}</span>}
            {item.motivo && <span className="text-amber-300/80">({vocabulario.motivos[item.motivo] ?? item.motivo_texto})</span>}
            {item.mudou_de_oferta && <span className="text-sky-300/80">(muda de oferta)</span>}
        </li>
    );
}

export default function PreviaColagem({ previa, vocabulario, children }) {
    const [abertos, setAbertos] = useState({ espera: true, erros: true, removidos: true });

    return (
        <div className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-3 space-y-2" data-previa>
            <p className="text-[11.5px] uppercase tracking-wide text-white/35">Prévia — nada foi gravado ainda</p>
            {previa.erro_geral && <p className="text-[13px] text-red-400">{previa.erro_geral}</p>}
            {! previa.erro_geral && GRUPOS.filter((g) => (previa.totais[g.chave] ?? 0) > 0).map((g) => (
                <div key={g.chave} data-grupo={g.chave}>
                    <button type="button" onClick={() => setAbertos((a) => ({ ...a, [g.chave]: ! a[g.chave] }))}
                        className="flex items-center gap-1.5 text-[13px]">
                        {abertos[g.chave] ? <ChevronDown size={14} /> : <ChevronRight size={14} />}
                        <strong className={g.cor}>{previa.totais[g.chave]}</strong>
                        <span className="text-white/70">{g.rotulo}</span>
                    </button>
                    {abertos[g.chave] && (
                        <ul className="mt-1 ml-5 space-y-0.5 max-h-44 overflow-y-auto">
                            {previa.grupos[g.chave].map((item, i) => <LinhaPrevia key={i} grupo={g.chave} item={item} vocabulario={vocabulario} />)}
                            {previa.totais[g.chave] > previa.grupos[g.chave].length && (
                                <li className="text-[11.5px] text-white/35">e mais {previa.totais[g.chave] - previa.grupos[g.chave].length}…</li>
                            )}
                        </ul>
                    )}
                </div>
            ))}
            {! previa.erro_geral && previa.totais.espera > 0 && (
                <p className="text-[12px] text-white/45">
                    Os que aguardam oferta ficam guardados e aparecem no aviso da tela. Assim que você criar a oferta com aquele SKU, eles entram nela sozinhos — ou procure o anúncio no "+ Anúncio" da oferta e ligue à mão.
                </p>
            )}
            {children}
        </div>
    );
}
