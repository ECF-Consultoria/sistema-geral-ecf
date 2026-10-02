import { useEffect, useState } from 'react';
import { Link } from '@inertiajs/react';
import { Check, CheckCircle2, Loader2, Lock, RefreshCw, Rocket } from 'lucide-react';
import { cn } from '@/lib/utils';
import BotaoAnunciarPorIa from './BotaoAnunciarPorIa';

// ─── Barra superior do editor (UI-SPEC §8.1) ────────────────────────────────
//
// 56px, nunca lista pendências (elas moram na lateral). Ordem: trilha → chip da
// empresa → salvamento → ações. Só há UM amarelo sólido por vez: o "Publicar"
// daqui só é primário quando a lateral (≥ 1360px) não está visível.

export const TITLE_CONTA_TRAVADA = 'A publicação é liberada conta a conta. Peça ao time de desenvolvimento.';
export const TITLE_CONFERIR_LOCAL = 'Nesta conta a conferência é só local: a validação no Mercado Livre espera a liberação da conta.';

const BASE_BOTAO = 'inline-flex h-10 shrink-0 items-center justify-center gap-2 rounded-lg px-4 text-[13px] font-bold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow disabled:pointer-events-none disabled:opacity-40';
const SECUNDARIO = 'border border-white/[0.10] bg-white/[0.03] text-white/80 hover:bg-white/[0.06]';
// O único amarelo sólido da fase: gradiente do botão primário, texto escuro.
const PRIMARIO = 'bg-gradient-to-r from-[#FFE600] to-[#F5D400] text-[#252525] hover:brightness-95';

/** Rótulo do publicar conforme o estado: andamento, parcial anterior ou N anúncios. */
export function rotuloDoPublicar(pub) {
    if (pub.aguardando?.tipo === 'publicacao' || pub.publicacao?.status === 'RUNNING') return 'Publicando…';
    if (pub.m.estado?.rascunho?.status === 'PARTIALLY_PUBLISHED') return 'Publicar o que faltou';
    const n = pub.totalAnuncios;

    return n === 1 ? 'Publicar 1 anúncio' : `Publicar ${n} anúncios`;
}

/** Botão "Publicar …" (barra ou lateral). `primario` = amarelo; senão secundário. */
export function BotaoPublicar({ pub, primario, className }) {
    const andamento = pub.aguardando?.tipo === 'publicacao' || pub.publicacao?.status === 'RUNNING';
    const Icone = andamento ? Loader2 : (pub.liberada ? Rocket : Lock);

    return (
        <button
            type="button"
            onClick={pub.publicar}
            disabled={! pub.podePublicar}
            title={pub.liberada ? undefined : TITLE_CONTA_TRAVADA}
            aria-describedby={pub.liberada ? undefined : 'nota-conta-travada'}
            data-acao="publicar"
            className={cn(BASE_BOTAO, primario ? PRIMARIO : SECUNDARIO, className)}
        >
            <Icone size={16} className={andamento ? 'animate-spin' : undefined} aria-hidden="true" />
            {rotuloDoPublicar(pub)}
        </button>
    );
}

/** "Salvo há Ns", atualizado a cada 10 s a partir de `salvoEm`. */
function Salvamento({ pub }) {
    const [, setTick] = useState(0);
    useEffect(() => {
        const t = setInterval(() => setTick((n) => n + 1), 10000);

        return () => clearInterval(t);
    }, []);

    const segundos = pub.salvoEm ? Math.max(0, Math.round((Date.now() - pub.salvoEm.getTime()) / 1000)) : null;
    const texto = segundos === null ? null : (segundos < 60 ? `Salvo há ${segundos}s` : `Salvo há ${Math.floor(segundos / 60)} min`);

    return (
        <p aria-live="polite" className="flex items-center gap-1 text-[11px] font-normal text-white/55" data-salvamento>
            {pub.salvando > 0 ? (
                <><Loader2 size={12} className="animate-spin" aria-hidden="true" /> Salvando…</>
            ) : texto && (
                <><Check size={12} className="text-emerald-400" aria-hidden="true" /> {texto}</>
            )}
        </p>
    );
}

export default function BarraDoEditor({ pub, empresa, primarioNaLateral, ia, onVoltar }) {
    const publicado = pub.m.estado?.rascunho?.status === 'PUBLISHED';
    const conferindo = pub.aguardando?.tipo === 'conferencia';
    const rotuloPrograma = empresa.programa_rotulo ?? empresa.programa;
    const reconectar = empresa.token !== 'ativo';
    const voltar = () => onVoltar?.();

    // -top-6: o <main> do AppLayout tem p-6 e o sticky cola na borda do CONTEÚDO, não do padding; sem isso sobra uma faixa de 24px por onde a página rola.
    return (
        <div className="sticky -top-6 z-20 flex h-14 items-center gap-4 border-b max-sm:h-auto max-sm:flex-wrap max-sm:gap-y-2 max-sm:py-2 border-white/[0.06] bg-ecf-bg px-6" data-barra-editor>
            <nav aria-label="Trilha" className="flex max-sm:hidden min-w-0 items-center gap-2 text-[13px] font-normal text-white/55">
                <Link href={route('mlb.anuncios.index', { programa: empresa.programa })} onClick={voltar} className="shrink-0 hover:text-ecf-yellow">
                    Publicador MLB
                </Link>
                <span aria-hidden="true">/</span>
                <span className="rounded-full border border-white/[0.08] bg-white/[0.04] px-2 py-1 text-[11px] font-bold text-white/70">{rotuloPrograma}</span>
            </nav>

            <Link
                href={route('mlb.anuncios.publicador.produtos', { conta: empresa.chave })}
                onClick={voltar}
                title={empresa.nome}
                className="flex min-w-0 items-center gap-2 rounded-lg border border-white/[0.08] bg-white/[0.03] px-2 py-1 hover:bg-white/[0.06] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow"
                data-chip-empresa
            >
                {! pub.liberada && <Lock size={12} className="shrink-0 text-white/55" aria-hidden="true" />}
                <span className="max-w-[160px] truncate text-[13px] font-bold text-white min-[1360px]:max-w-[240px]">{empresa.nome}</span>
                {empresa.identificador && <span className="hidden font-mono text-[11px] font-normal text-white/55 min-[1360px]:inline">{empresa.identificador}</span>}
                <span
                    className={cn('hidden items-center gap-1 rounded-full border px-2 py-1 text-[11px] font-bold min-[1360px]:inline-flex',
                        reconectar ? 'border-amber-300/25 bg-amber-300/10 text-amber-300' : 'border-emerald-500/30 bg-emerald-500/10 text-emerald-400')}
                >
                    <span aria-hidden="true" className={cn('h-[6px] w-[6px] rounded-full', reconectar ? 'bg-amber-300' : 'bg-emerald-400')} />
                    {reconectar ? 'Reconectar' : 'ML conectado'}
                </span>
            </Link>

            <div className="min-w-[90px] flex-1"><Salvamento pub={pub} /></div>

            <div className="flex shrink-0 items-center gap-2 max-sm:w-full max-sm:flex-wrap">
                {publicado ? (
                    <>
                        <span className="inline-flex items-center gap-2 text-[13px] font-bold text-emerald-400">
                            <CheckCircle2 size={16} aria-hidden="true" /> Publicado no Mercado Livre
                        </span>
                        <Link
                            href={route('mlb.anuncios.publicador.produtos', { conta: empresa.chave })}
                            className={cn(BASE_BOTAO, SECUNDARIO)}
                        >
                            Voltar aos produtos
                        </Link>
                    </>
                ) : (
                    <>
                        <BotaoAnunciarPorIa ia={ia} pub={pub} compacto />
                        <button
                            type="button"
                            onClick={pub.conferir}
                            disabled={! pub.podeConferir}
                            title={pub.liberada ? undefined : TITLE_CONFERIR_LOCAL}
                            aria-describedby={pub.liberada ? undefined : 'nota-conta-travada'}
                            data-acao="conferir"
                            className={cn(BASE_BOTAO, SECUNDARIO)}
                        >
                            {conferindo ? <Loader2 size={16} className="animate-spin" aria-hidden="true" /> : <RefreshCw size={16} aria-hidden="true" />}
                            {conferindo ? 'Conferindo…' : (pub.liberada ? 'Conferir no Mercado Livre' : 'Conferir dados')}
                        </button>
                        <BotaoPublicar pub={pub} primario={! primarioNaLateral} />
                    </>
                )}
            </div>
        </div>
    );
}
