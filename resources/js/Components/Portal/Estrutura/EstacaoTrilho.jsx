import { ArrowUpRight, Plus } from 'lucide-react';
import { Botao, PilulaSituacao } from './comum';
import { cn } from '@/lib/utils';

// ─── O trilho da família ────────────────────────────────────────────────────
//
// O produto e as variações dele (ou o kit e os componentes), cada uma com a
// situação. É a lista da aula — "para cada produto: dá combo? dá kit?" — vista
// de dentro do produto. Clicar troca a oferta em foco; clicar num componente
// ou num kit abre a estação DELE (é outra família).

const ROTULO = (o) => ({ simples: 'Produto', combo: `Combo ${o.unidades}`, kit: 'Kit', combit: 'Combit' }[o.fase]);

function Item({ oferta, ativo, onClick, vocabulario, rodape = null }) {
    return (
        <li>
            <button type="button" onClick={onClick} data-trilho-oferta={oferta.id} aria-current={ativo ? 'true' : undefined}
                className={cn('flex w-full flex-col gap-0.5 rounded-xl px-3 py-2 text-left hover:bg-white/[0.04]',
                    ativo && 'bg-ecf-yellow/[0.07] ring-1 ring-ecf-yellow/40')}>
                <span className="flex items-center justify-between gap-2">
                    <span className="text-[12px] text-white/50">{ROTULO(oferta)}</span>
                    <PilulaSituacao situacao={oferta.situacao} />
                </span>
                <span className="truncate font-mono text-[13px] text-white">{oferta.sku}</span>
                {rodape && <span className="truncate text-[11.5px] text-white/40">{rodape}</span>}
            </button>
        </li>
    );
}

function Titulo({ children }) {
    return <p className="px-3 pb-1 pt-4 text-[10.5px] font-semibold uppercase tracking-wider text-white/35">{children}</p>;
}

export default function EstacaoTrilho({ dados, foco, onFoco, onTrocar, onVariacao, vocabulario }) {
    const [principal, ...combos] = dados.ofertas;

    return (
        <nav className="pb-4" aria-label="Família do produto" data-trilho>
            <Titulo>{dados.kit ? vocabulario.fases[principal.fase] : 'Produto'}</Titulo>
            <ul className="px-1.5">
                <Item oferta={principal} ativo={foco === principal.id} onClick={() => onFoco(principal.id)} vocabulario={vocabulario}
                    rodape={`${principal.classicos} Clássico · ${principal.premiums} Premium`} />
            </ul>

            {dados.kit ? (
                <>
                    <Titulo>Componentes</Titulo>
                    <ul className="px-1.5">
                        {dados.componentes.map((c) => (
                            <li key={c.id}>
                                <button type="button" onClick={() => onTrocar(c.id)} data-trilho-componente={c.id}
                                    className="flex w-full items-center gap-2 rounded-xl px-3 py-2 text-left hover:bg-white/[0.04]">
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate text-[12.5px] text-white/85">{c.nome ?? c.sku} <span className="text-white/40">×{c.quantidade}</span></span>
                                        <span className="block truncate font-mono text-[11.5px] text-white/40">{c.sku}</span>
                                    </span>
                                    <PilulaSituacao situacao={c.situacao} />
                                    <ArrowUpRight size={13} className="shrink-0 text-white/30" />
                                </button>
                            </li>
                        ))}
                    </ul>
                </>
            ) : (
                <>
                    <Titulo>Combos</Titulo>
                    <ul className="px-1.5">
                        {combos.map((o) => <Item key={o.id} oferta={o} ativo={foco === o.id} onClick={() => onFoco(o.id)} vocabulario={vocabulario} />)}
                        {combos.length === 0 && <li className="px-3 py-1 text-[12px] text-white/35">Nenhum combo ainda.</li>}
                    </ul>
                    <div className="px-3 pt-2">
                        <Botao variante="fantasma" className="w-full justify-start px-2 py-1.5 text-[12.5px]" onClick={onVariacao} data-acao="nova-variacao-trilho">
                            <Plus size={14} /> Variação (combo, kit)
                        </Botao>
                    </div>
                    {dados.tambem_em.length > 0 && (
                        <>
                            <Titulo>Também entra em</Titulo>
                            <ul className="px-1.5">
                                {dados.tambem_em.map((k) => (
                                    <li key={k.id}>
                                        <button type="button" onClick={() => onTrocar(k.id)} data-trilho-kit={k.id}
                                            className="flex w-full items-center gap-2 rounded-xl px-3 py-2 text-left hover:bg-white/[0.04]">
                                            <span className="min-w-0 flex-1">
                                                <span className="block truncate text-[12.5px] text-white/85">{k.nome ?? k.sku}</span>
                                                <span className="block truncate text-[11.5px] text-white/40">{vocabulario.fases[k.fase]}</span>
                                            </span>
                                            <PilulaSituacao situacao={k.situacao} />
                                            <ArrowUpRight size={13} className="shrink-0 text-white/30" />
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        </>
                    )}
                </>
            )}
        </nav>
    );
}
