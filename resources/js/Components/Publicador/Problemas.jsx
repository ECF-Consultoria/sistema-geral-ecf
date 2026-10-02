import { AlertTriangle, ArrowRight, Info } from 'lucide-react';
import { NOME_ETAPA, NOME_TIPO } from './apoio';
import { cn } from '@/lib/utils';

// ─── Os problemas, por etapa (`08` §3) ──────────────────────────────────────
//
// Agrupados pela etapa onde se resolvem, cada um com "ir para" (troca de aba
// e rola até a seção). O que veio do Mercado Livre mostra, recolhido, o código
// e a mensagem original — o suporte precisa deles e a pessoa não.

const ORDEM = ['E0', 'E2', 'E3', 'E4', 'E5', 'E6', 'E7', 'E8', 'E9', 'E10', 'E11', 'E13', 'OUTROS'];

const ESTILO = {
    BLOCKER: 'text-amber-200',
    WARNING: 'text-white/60',
    INFO: 'text-sky-200/80',
};

function Linha({ p }) {
    const Icone = p.severidade === 'INFO' ? Info : AlertTriangle;
    const onde = [p.alvo?.listing_type && NOME_TIPO[p.alvo.listing_type], p.alvo?.itens?.length > 1 && `${p.alvo.itens.length} anúncios`].filter(Boolean).join(' · ');

    return (
        <li className={cn('text-[13px]', ESTILO[p.severidade] ?? ESTILO.WARNING)} data-problema={p.regra} data-severidade={p.severidade}>
            <span className="flex items-start gap-1.5">
                <Icone size={13} className="mt-0.5 shrink-0" />
                <span>{p.mensagem}{onde && <span className="text-white/35"> ({onde})</span>}</span>
            </span>
            {p.ml_causa && (
                <details className="ml-5 mt-0.5 text-[11px] text-white/35">
                    <summary className="cursor-pointer">detalhe do Mercado Livre</summary>
                    <p className="font-mono">{p.ml_causa.code}{p.ml_causa.cause_id ? ` · ${p.ml_causa.cause_id}` : ''}</p>
                    <p>{p.ml_causa.message}</p>
                </details>
            )}
        </li>
    );
}

export default function Problemas({ problemas, onIr, vazio = null, titulo = null }) {
    if (! problemas?.length) return vazio;

    const grupos = {};
    problemas.forEach((p) => { (grupos[p.alvo?.etapa ?? 'OUTROS'] ??= []).push(p); });
    const etapas = Object.keys(grupos).sort((a, b) => ORDEM.indexOf(a) - ORDEM.indexOf(b));

    return (
        <div className="space-y-2" data-problemas={problemas.length}>
            {titulo && <p className="text-[13px] font-bold text-white/80">{titulo}</p>}
            {etapas.map((e) => (
                <div key={e} data-etapa-problemas={e}>
                    <div className="mb-0.5 flex items-center gap-2 text-[11px] font-bold uppercase tracking-wide text-white/40">
                        {NOME_ETAPA[e] ?? e}
                        {onIr && e !== 'OUTROS' && (
                            <button type="button" onClick={() => onIr(e)} className="inline-flex items-center gap-0.5 normal-case tracking-normal text-ecf-yellow/80 hover:text-ecf-yellow" data-ir-para={e}>
                                ir para <ArrowRight size={11} />
                            </button>
                        )}
                    </div>
                    <ul className="space-y-1">{grupos[e].map((p, i) => <Linha key={`${p.regra}-${i}`} p={p} />)}</ul>
                </div>
            ))}
        </div>
    );
}
