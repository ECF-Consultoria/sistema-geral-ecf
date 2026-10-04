import { useEffect, useState } from 'react';
import { Link } from '@inertiajs/react';
import { AlertTriangle, Check, CheckCircle2, Loader2, Lock } from 'lucide-react';
import { cn } from '@/lib/utils';
import BotaoAnunciarPorIa from './BotaoAnunciarPorIa';
import SeletorDeProdutos from './SeletorDeProdutos';
import { BASE_BOTAO, SECUNDARIO } from './botoes';

// ─── Barra superior do editor (UI-SPEC §8.1; Conceito E, 03/10/2026) ────────
//
// 56px, nunca lista pendências. É a trilha "Publicador MLB / empresa / produto":
// o produto é o seletor (troca de produto e "+ Produto"), depois o indicador
// real do salvamento e "Anunciar por IA". Conferir e Publicar moram no
// Inspetor, então a barra não tem amarelo sólido nenhum.

/**
 * Indicador do salvamento (WR-F02): "Salvo há Ns" (atualizado a cada 10 s) só quando nada
 * sobra por salvar; salvamento que falhou mostra "Não salvo" — tentando de novo ou a mensagem.
 */
function Salvamento({ pub }) {
    const [, setTick] = useState(0);
    useEffect(() => {
        const t = setInterval(() => setTick((n) => n + 1), 10000);

        return () => clearInterval(t);
    }, []);

    const { estado, mensagem } = pub.salvamento;
    const segundos = pub.salvoEm ? Math.max(0, Math.round((Date.now() - pub.salvoEm.getTime()) / 1000)) : null;
    const texto = segundos === null ? null : (segundos < 60 ? `Salvo há ${segundos}s` : `Salvo há ${Math.floor(segundos / 60)} min`);

    return (
        <p aria-live="polite" title={estado === 'falhou' ? mensagem : undefined} className="flex min-w-0 items-center gap-1 text-[11px] font-normal text-white/55" data-salvamento={estado ?? 'nada'}>
            {(estado === 'salvando' || estado === 'pendente') && (
                <><Loader2 size={12} className="shrink-0 animate-spin" aria-hidden="true" /> Salvando…</>
            )}
            {estado === 'tentando' && (
                <><AlertTriangle size={12} className="shrink-0 text-amber-300" aria-hidden="true" /> <span className="truncate text-amber-300">Não salvo — tentando de novo</span></>
            )}
            {estado === 'falhou' && (
                <><AlertTriangle size={12} className="shrink-0 text-red-300" aria-hidden="true" /> <span className="truncate text-red-300">Não salvo — {mensagem}</span></>
            )}
            {estado === 'pausado' && <span className="truncate">Salva quando a IA terminar</span>}
            {estado === 'salvo' && texto && (
                <><Check size={12} className="shrink-0 text-emerald-400" aria-hidden="true" /> {texto}</>
            )}
        </p>
    );
}

export default function BarraDoEditor({ pub, empresa, produto, produtos = [], onTrocar, prontas = 0, total = 0, ia, onVoltar }) {
    const publicado = pub.m.estado?.rascunho?.status === 'PUBLISHED';
    const rotuloPrograma = empresa.programa_rotulo ?? empresa.programa;
    const reconectar = empresa.token !== 'ativo';
    const voltar = () => onVoltar?.();

    // -top-6: o <main> do AppLayout tem p-6 e o sticky cola na borda do CONTEÚDO, não do padding; sem isso sobra uma faixa de 24px por onde a página rola.
    return (
        <div className="sticky -top-6 z-20 flex h-14 items-center gap-2 border-b max-sm:h-auto max-sm:flex-wrap max-sm:gap-y-2 max-sm:py-2 max-sm:px-4 border-white/[0.06] bg-ecf-bg px-6" data-barra-editor>
            <nav aria-label="Trilha" className="flex max-lg:hidden min-w-0 shrink-0 items-center gap-2 text-[13px] font-normal text-white/55">
                <Link href={route('mlb.anuncios.index', { programa: empresa.programa })} onClick={voltar} className="shrink-0 rounded hover:text-ecf-yellow focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow">
                    Publicador MLB
                </Link>
                <span className="rounded-full border border-white/[0.08] bg-white/[0.04] px-2 py-1 text-[11px] font-bold text-white/70">{rotuloPrograma}</span>
                <span aria-hidden="true">/</span>
            </nav>

            <Link
                href={route('mlb.anuncios.publicador.produtos', { conta: empresa.chave })}
                onClick={voltar}
                title={empresa.nome}
                className="flex min-w-0 shrink-0 items-center gap-2 rounded-lg border border-white/[0.08] bg-white/[0.03] px-2 py-1 hover:bg-white/[0.06] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow"
                data-chip-empresa
            >
                {! pub.liberada && <Lock size={12} className="shrink-0 text-white/55" aria-hidden="true" />}
                <span className="max-w-[140px] truncate text-[13px] font-bold text-white min-[1360px]:max-w-[220px]">{empresa.nome}</span>
                {empresa.identificador && <span className="hidden font-mono text-[11px] font-normal text-white/55 min-[1600px]:inline">{empresa.identificador}</span>}
                <span
                    className={cn('hidden items-center gap-1 rounded-full border px-2 py-1 text-[11px] font-bold min-[1360px]:inline-flex',
                        reconectar ? 'border-amber-300/25 bg-amber-300/10 text-amber-300' : 'border-emerald-500/30 bg-emerald-500/10 text-emerald-400')}
                >
                    <span aria-hidden="true" className={cn('h-[6px] w-[6px] rounded-full', reconectar ? 'bg-amber-300' : 'bg-emerald-400')} />
                    {reconectar ? 'Reconectar' : 'ML conectado'}
                </span>
            </Link>

            <span aria-hidden="true" className="max-sm:hidden text-white/30">/</span>

            {/* O produto em edição é o seletor: abre a lista da empresa para trocar ou criar. */}
            <div className="min-w-0 flex-1 max-sm:order-last max-sm:w-full max-sm:flex-none">
                <SeletorDeProdutos produtos={produtos} produtoId={produto.id} produtoNome={produto.nome} prontas={prontas} total={total} conta={empresa.chave} onTrocar={onTrocar} />
            </div>

            <div className="min-w-[90px] max-w-[220px] shrink-0 max-sm:min-w-0 max-sm:flex-1"><Salvamento pub={pub} /></div>

            <div className="flex shrink-0 items-center gap-2">
                {publicado ? (
                    <>
                        <span className="inline-flex items-center gap-2 text-[13px] font-bold text-emerald-400">
                            <CheckCircle2 size={16} aria-hidden="true" /> <span className="max-sm:hidden">Publicado no Mercado Livre</span>
                        </span>
                        <Link
                            href={route('mlb.anuncios.publicador.produtos', { conta: empresa.chave })}
                            className={cn(BASE_BOTAO, SECUNDARIO)}
                        >
                            Voltar aos produtos
                        </Link>
                    </>
                ) : (
                    <BotaoAnunciarPorIa ia={ia} pub={pub} compacto />
                )}
            </div>
        </div>
    );
}
