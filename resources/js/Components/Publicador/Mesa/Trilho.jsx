import { useRef } from 'react';
import { CheckCircle2, ChevronLeft, ChevronRight } from 'lucide-react';
import { ETAPAS } from '../apoio';
import { BotaoAcao } from './botoes';
import { cn } from '@/lib/utils';

// ─── O trilho: as 7 etapas do anúncio, numa régua só (03/10/2026) ───────────
//
// É a navegação inteira da mesa — não há lateral repetindo a lista. Cada
// segmento é um botão com o nome da etapa e o estado dela; a regra de 2px no
// topo É o estado (verde completo, âmbar faltando, neutra para a revisão sem
// conferência). O segmento atual fica em amarelo translúcido: o amarelo sólido
// é só do botão primário. Avançar nunca bloqueia: qualquer segmento leva à etapa.
//
// Em tela estreita (< md) a régua vira um seletor nativo com as setas, e os 7
// traços embaixo continuam mostrando o estado de cada etapa.

const REGRA = {
    completo: 'border-emerald-400/70',
    falta: 'border-amber-400/70',
    neutro: 'border-white/[0.10]',
};

const TRACO = {
    completo: 'bg-emerald-400',
    falta: 'bg-amber-400',
    neutro: 'bg-white/20',
};

/** Texto curto do estado da revisão (a 7ª etapa lê a conferência, não as verificações). */
export function resumoDaRevisao(pub) {
    if (pub.m.estado?.rascunho?.status === 'PUBLISHED') return { tom: 'completo', texto: 'Publicado' };
    const n = pub.totalAnuncios;
    const anuncios = n === 1 ? '1 anúncio' : `${n} anúncios`;
    const conf = pub.conferencia.estado;
    if (conf === 'conferindo') return { tom: 'neutro', texto: 'Conferindo…' };
    if (conf === 'ok') return { tom: 'completo', texto: `${anuncios} prontos para publicar` };
    if (conf === 'avisos') return { tom: 'completo', texto: `${anuncios} · conferido com avisos` };
    if (conf === 'bloqueado' || conf === 'local_bloqueado') return { tom: 'falta', texto: `${pub.conferencia.pendencias} pendência(s) na conferência` };
    if (conf === 'editado') return { tom: 'neutro', texto: `${anuncios} · confira de novo` };
    if (conf === 'local') return { tom: 'neutro', texto: `${anuncios} · conferido aqui` };
    if (conf === 'erro') return { tom: 'falta', texto: 'A conferência não terminou' };

    return { tom: 'neutro', texto: `${anuncios} · ainda não conferido` };
}

/** Tom e texto de um segmento: pelas verificações (etapas de conteúdo) ou pela conferência (revisão). */
const estadoDoSegmento = (etapa, estados, revisao) => {
    if (etapa.secoes.length === 0) return revisao;
    const { faltam } = estados[etapa.chave] ?? { faltam: 0 };
    if (faltam === 0) return { tom: 'completo', texto: 'Completo' };

    return { tom: 'falta', texto: faltam === 1 ? 'Falta 1' : `Faltam ${faltam}` };
};

function Estado({ tom, texto }) {
    return (
        <span className={cn('flex min-w-0 items-center gap-1.5 text-[11px] font-normal', tom === 'completo' ? 'text-emerald-400' : (tom === 'falta' ? 'text-white/70' : 'text-white/55'))}>
            {tom === 'completo' && <CheckCircle2 size={12} className="shrink-0" aria-hidden="true" />}
            {tom === 'falta' && <span className="h-1.5 w-1.5 shrink-0 rounded-full bg-amber-400" aria-hidden="true" />}
            <span className="truncate">{texto}</span>
        </span>
    );
}

/**
 * `estados` = `estadoDasEtapas(pub.secoes)`; `revisao` = `resumoDaRevisao(pub)`;
 * `atual` = chave da etapa aberta; `onIr(chave)` troca de etapa.
 */
export default function Trilho({ estados, revisao, atual, onIr }) {
    const lista = useRef(null);
    const indice = ETAPAS.findIndex((e) => e.chave === atual);
    const anterior = ETAPAS[indice - 1] ?? null;
    const proxima = ETAPAS[indice + 1] ?? null;

    // Setas do teclado movem o foco entre os segmentos (Home/End vão às pontas).
    const teclado = (e) => {
        if (! ['ArrowRight', 'ArrowLeft', 'Home', 'End'].includes(e.key)) return;
        const itens = [...(lista.current?.querySelectorAll('button[data-etapa-trilho]') ?? [])];
        const i = itens.indexOf(document.activeElement);
        if (i === -1) return;
        e.preventDefault();
        const destino = e.key === 'Home' ? 0 : (e.key === 'End' ? itens.length - 1 : (i + (e.key === 'ArrowRight' ? 1 : -1) + itens.length) % itens.length);
        itens[destino]?.focus();
    };

    return (
        <nav aria-label="Etapas do anúncio" className="z-10 border-b border-white/[0.06] bg-ecf-bg px-6 py-3 sm:sticky sm:top-8 max-sm:px-4" data-trilho={atual}>
            {/* ≥ md: a régua de 7 segmentos. */}
            <ol ref={lista} onKeyDown={teclado} className="hidden grid-cols-7 divide-x divide-white/[0.06] overflow-hidden rounded-xl border border-white/[0.08] bg-ecf-card md:grid">
                {ETAPAS.map((etapa, i) => {
                    const ativo = etapa.chave === atual;
                    const estado = estadoDoSegmento(etapa, estados, revisao);

                    return (
                        <li key={etapa.chave} className="min-w-0">
                            <button
                                type="button"
                                onClick={() => onIr(etapa.chave)}
                                aria-current={ativo ? 'step' : undefined}
                                data-etapa-trilho={etapa.chave}
                                data-estado={estado.tom}
                                title={etapa.titulo}
                                className={cn(
                                    'flex h-[60px] w-full flex-col justify-center border-t-2 px-3 text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ecf-yellow',
                                    REGRA[estado.tom],
                                    ativo ? 'bg-ecf-yellow/[0.08]' : 'hover:bg-white/[0.03]',
                                )}
                            >
                                <span className="flex min-w-0 items-baseline gap-2">
                                    <span className={cn('shrink-0 text-[11px] font-bold tabular-nums', ativo ? 'text-ecf-yellow' : 'text-white/40')}>{i + 1}</span>
                                    <span className={cn('truncate text-[13px] font-bold', ativo ? 'text-white' : 'text-white/70')}>
                                        <span className="hidden xl:inline">{etapa.titulo}</span>
                                        <span className="xl:hidden">{etapa.curto}</span>
                                    </span>
                                </span>
                                <span className="mt-0.5 pl-[18px]"><Estado {...estado} /></span>
                            </button>
                        </li>
                    );
                })}
            </ol>

            {/* < md: seletor nativo com as setas; embaixo, um traço por etapa. */}
            <div className="md:hidden">
                <div className="flex items-center gap-2">
                    <button type="button" onClick={() => anterior && onIr(anterior.chave)} disabled={! anterior} aria-label="Etapa anterior"
                        className="grid h-11 w-11 shrink-0 place-items-center rounded-lg border border-white/[0.10] bg-white/[0.03] text-white/80 hover:bg-white/[0.06] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow disabled:opacity-40">
                        <ChevronLeft size={16} aria-hidden="true" />
                    </button>
                    <label className="relative block min-w-0 flex-1">
                        <span className="sr-only">Etapa do anúncio</span>
                        <select value={atual} onChange={(e) => onIr(e.target.value)} data-etapa-seletor
                            className="h-11 w-full appearance-auto rounded-lg border border-white/[0.10] bg-white/[0.04] px-3 text-[13px] font-bold text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow [&>option]:bg-ecf-card">
                            {ETAPAS.map((etapa, i) => {
                                const estado = estadoDoSegmento(etapa, estados, revisao);

                                return <option key={etapa.chave} value={etapa.chave}>{i + 1}. {etapa.titulo} — {estado.texto}</option>;
                            })}
                        </select>
                    </label>
                    <button type="button" onClick={() => proxima && onIr(proxima.chave)} disabled={! proxima} aria-label="Próxima etapa"
                        className="grid h-11 w-11 shrink-0 place-items-center rounded-lg border border-white/[0.10] bg-white/[0.03] text-white/80 hover:bg-white/[0.06] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow disabled:opacity-40">
                        <ChevronRight size={16} aria-hidden="true" />
                    </button>
                </div>
                <ol className="mt-2 grid grid-cols-7 gap-1" aria-hidden="true">
                    {ETAPAS.map((etapa) => {
                        const estado = estadoDoSegmento(etapa, estados, revisao);

                        return <li key={etapa.chave} className={cn('h-1 rounded-full', TRACO[estado.tom], etapa.chave !== atual && 'opacity-40')} />;
                    })}
                </ol>
            </div>
        </nav>
    );
}

/**
 * Rodapé de uma etapa: Voltar (secundário) e Continuar (o primário da tela), com o
 * nome da próxima etapa ao lado. Na revisão não há Continuar: o primário é Conferir
 * ou Publicar, do próprio painel.
 */
export function RodapeDaEtapa({ atual, onIr }) {
    const indice = ETAPAS.findIndex((e) => e.chave === atual);
    const anterior = ETAPAS[indice - 1] ?? null;
    const proxima = ETAPAS[indice + 1] ?? null;
    if (! anterior && ! proxima) return null;

    return (
        <footer className="mt-8 flex flex-wrap items-center justify-between gap-3 border-t border-white/[0.06] pt-5" data-rodape-etapa={atual}>
            {anterior ? (
                <BotaoAcao onClick={() => onIr(anterior.chave)} data-acao="voltar-etapa" title={anterior.titulo}>
                    <ChevronLeft size={16} aria-hidden="true" /> Voltar
                </BotaoAcao>
            ) : <span />}
            {proxima && (
                <span className="flex min-w-0 flex-wrap items-center justify-end gap-x-4 gap-y-2 max-sm:w-full">
                    <span className="min-w-0 truncate text-[13px] font-normal text-white/55">Próxima: <span className="text-white/80">{proxima.titulo}</span></span>
                    <BotaoAcao primario onClick={() => onIr(proxima.chave)} data-acao="continuar-etapa" className="max-sm:w-full">
                        Continuar <ChevronRight size={16} aria-hidden="true" />
                    </BotaoAcao>
                </span>
            )}
        </footer>
    );
}
