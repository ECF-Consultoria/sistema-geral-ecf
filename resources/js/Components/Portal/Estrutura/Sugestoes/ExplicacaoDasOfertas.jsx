import { useState } from 'react';
import { ChevronDown } from 'lucide-react';
import { ROTULO_FASE } from '@/lib/sugestoesEstrutura';

// ─── "Os três tipos de oferta" (UI-SPEC, estrutura vertical item 3) ─────────
//
// Aberto na primeira visita e depois recolhido; o estado fica no navegador.

const CHAVE = 'ecf.sugestoes.explicacao';

const LINHAS = [
    ['combo', 'o mesmo produto em mais unidades — Kit 4 cadeiras.'],
    ['kit', 'produtos diferentes juntos — mesa + banco.'],
    ['combit', 'um kit com mais unidades de um item — mesa + 4 cadeiras.'],
];

const lerAberta = () => {
    try {
        return window.localStorage.getItem(CHAVE) !== 'recolhida';
    } catch {
        return true;
    }
};

export default function ExplicacaoDasOfertas() {
    const [aberta, setAberta] = useState(lerAberta);

    const alternar = () => {
        const proxima = ! aberta;
        setAberta(proxima);
        try {
            window.localStorage.setItem(CHAVE, proxima ? 'aberta' : 'recolhida');
        } catch {
            // navegador sem armazenamento: a escolha vale só nesta visita
        }
    };

    return (
        <section className="mt-5 rounded-2xl border border-white/[0.08] bg-ecf-card p-4" data-explicacao>
            <button type="button" onClick={alternar} aria-expanded={aberta}
                className="flex min-h-[44px] w-full items-center justify-between gap-3 text-left text-[14px] font-semibold text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow/40">
                Os três tipos de oferta
                <ChevronDown size={16} aria-hidden="true" className={aberta ? 'rotate-180' : ''} />
            </button>
            {aberta && (
                <ul className="mt-2 space-y-2">
                    {LINHAS.map(([fase, frase]) => (
                        <li key={fase} className="flex flex-wrap items-baseline gap-x-3 gap-y-1 text-[14px] text-white/80">
                            <span className="inline-flex h-7 items-center rounded-lg bg-white/[0.06] px-2.5 text-[13px] font-semibold text-white/80">{ROTULO_FASE[fase]}</span>
                            <span className="min-w-0">{frase}</span>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
