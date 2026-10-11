import { AlertTriangle, X } from 'lucide-react';
import { tituloDaEtapa } from '../apoio';
import { chaveDoProblema, seletoresDoProblema } from '../destaque';
import { LINK } from './comum';
import { cn } from '@/lib/utils';

// ─── "Corrigir em…": a lista do que acendeu na etapa (10/10/2026) ───────────
//
// Fica colada no topo enquanto a pessoa rola, para a mensagem nunca sair de perto do campo
// aceso: aviso do Mercado Livre não escreve nada embaixo do campo (só o bloqueio escreve).
// Cada linha é um problema da etapa; "Mostrar" pisca e leva ao campo dele. O que é da conta
// ou da conferência não tem campo e diz isso, em vez de um link que não leva a nada.

export const ID_DA_LISTA = 'o-que-corrigir';

export default function OQueCorrigir({ etapa, problemas, onMostrar, onFechar }) {
    if (problemas.length === 0) return null;
    const algumCampo = problemas.some((p) => seletoresDoProblema(p).length > 0);

    return (
        // top-10: logo abaixo da barra do editor (56px, colada em -24px).
        <div id={ID_DA_LISTA} role="status" data-o-que-corrigir={problemas.length}
            className="sticky top-10 z-10 scroll-mt-24 rounded-xl border border-ecf-yellow/50 bg-ecf-card/95 p-4 shadow-[0_0_0_6px_rgba(255,230,0,0.08)] backdrop-blur">
            <div className="flex items-start justify-between gap-3">
                <p className="text-[15px] font-bold text-ecf-yellow">
                    {problemas.length === 1 ? 'Um ponto para corrigir' : `${problemas.length} pontos para corrigir`} em {tituloDaEtapa(etapa)}
                </p>
                <button type="button" onClick={onFechar} aria-label="Fechar a lista do que corrigir" data-acao="fechar-o-que-corrigir"
                    className="shrink-0 rounded text-white/60 hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow">
                    <X size={16} aria-hidden="true" />
                </button>
            </div>
            {algumCampo && <p className="mt-0.5 text-[13px] text-white/55">O que está aceso em amarelo é onde corrigir.</p>}
            <ul className="mt-3 max-h-[30vh] space-y-2 overflow-y-auto pr-1">
                {problemas.map((p) => {
                    const bloqueio = p.severidade === 'BLOCKER';
                    const temCampo = seletoresDoProblema(p).length > 0;

                    return (
                        <li key={chaveDoProblema(p)} className="flex items-start gap-2 text-[13px]" data-ponto={p.regra} data-severidade={p.severidade}>
                            <AlertTriangle size={14} className={cn('mt-0.5 shrink-0', bloqueio ? 'text-red-300' : 'text-amber-300')} aria-hidden="true" />
                            <span className="min-w-0 flex-1 text-white/85">
                                {p.mensagem}
                                <span className="text-white/45"> · {bloqueio ? 'impede a publicação' : 'aviso'}</span>
                            </span>
                            {temCampo
                                ? <button type="button" onClick={() => onMostrar(p)} className={cn(LINK, 'shrink-0')} data-acao="mostrar-ponto">Mostrar</button>
                                : <span className="shrink-0 text-white/45">não é de um campo</span>}
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}
