import { useEffect, useState } from 'react';
import { Check } from 'lucide-react';
import { cn } from '@/lib/utils';

// ─── Índice da ficha (29/10/2026) ───────────────────────────────────────────
//
// O mapa da ficha: cada seção com o estado dela (pronta, falta algo, com erro) numa
// linha curta. Clicar leva até a seção; rolando, a seção da vez fica marcada. O pé diz
// o que ainda falta. Os estados vêm de `estadoDasSecoes` (lib/fichaLayout), só leitura.

const ICONE = {
    ok: 'bg-emerald-400/10 text-emerald-300',
    aviso: 'bg-amber-300/10 text-amber-300',
    erro: 'bg-red-400/10 text-red-300',
    neutro: 'bg-white/[0.06] text-white/40',
};
const SUB = { ok: 'text-white/45', aviso: 'text-amber-300/90', erro: 'text-red-300', neutro: 'text-white/40' };

export default function IndiceDaFicha({ secoes }) {
    const [atual, setAtual] = useState(secoes[0]?.id ?? null);
    const chave = secoes.map((s) => s.id).join('|');

    useEffect(() => {
        if (typeof IntersectionObserver === 'undefined') return undefined;
        const obs = new IntersectionObserver((entradas) => {
            entradas.forEach((e) => { if (e.isIntersecting) setAtual(e.target.id); });
        }, { rootMargin: '-20% 0px -70% 0px' });
        secoes.forEach((s) => { const el = document.getElementById(s.id); if (el) obs.observe(el); });

        return () => obs.disconnect();
    }, [chave]); // eslint-disable-line react-hooks/exhaustive-deps

    const ir = (id) => {
        setAtual(id);
        document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    const contam = secoes.filter((s) => s.tipo !== 'neutro');
    const prontas = contam.filter((s) => s.tipo === 'ok').length;
    const faltam = secoes.filter((s) => s.tipo === 'aviso' || s.tipo === 'erro').map((s) => s.nome.toLowerCase());

    return (
        <nav aria-label="Seções da ficha" className="rounded-[14px] border border-white/[0.08] bg-ecf-card p-2.5" data-indice-ficha>
            <div className="flex items-baseline justify-between border-b border-white/[0.08] px-2 pb-2.5 pt-1">
                <span className="text-[11.5px] font-semibold uppercase tracking-[0.07em] text-white/40">Nesta ficha</span>
                <span className="text-[12px] text-white/55" data-indice-prontas>{prontas} de {contam.length} prontas</span>
            </div>
            <ol className="mt-1.5 space-y-0.5">
                {secoes.map((s) => (
                    <li key={s.id}>
                        <button type="button" onClick={() => ir(s.id)} aria-current={atual === s.id ? 'location' : undefined} data-indice-secao={s.id} data-estado={s.tipo}
                            className={cn('grid w-full grid-cols-[18px_minmax(0,1fr)] items-center gap-x-2.5 gap-y-0.5 rounded-[10px] border px-2.5 py-2 text-left',
                                atual === s.id ? 'border-white/[0.14] bg-white/[0.06]' : 'border-transparent hover:bg-white/[0.04]')}>
                            <span aria-hidden="true" className={cn('grid h-[18px] w-[18px] place-items-center rounded-full text-[11px] font-bold', ICONE[s.tipo] ?? ICONE.neutro)}>
                                {s.tipo === 'ok' ? <Check size={11} strokeWidth={3} /> : (s.tipo === 'erro' ? '!' : '•')}
                            </span>
                            <span className="text-[13.5px] font-semibold text-white">{s.nome}</span>
                            <span className={cn('col-start-2 truncate text-[12px]', SUB[s.tipo] ?? SUB.neutro)}>{s.sub}</span>
                        </button>
                    </li>
                ))}
            </ol>
            <div className="mt-2 border-t border-white/[0.08] px-2 pb-1 pt-3">
                <div className="h-1.5 overflow-hidden rounded-full bg-white/[0.08]">
                    <div className="h-full rounded-full bg-ecf-yellow transition-[width]" style={{ width: `${contam.length ? (prontas / contam.length) * 100 : 0}%` }} />
                </div>
                <p className="mt-2 text-[12px] text-white/55" data-indice-falta>{faltam.length ? `Falta: ${faltam.join(', ')}` : 'Tudo preenchido.'}</p>
            </div>
        </nav>
    );
}
