import { useEffect, useMemo, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { AlertTriangle, ChevronLeft, ChevronRight, ClipboardPaste, Plus, Search, X } from 'lucide-react';
import PortalClienteLayout from '@/Layouts/PortalClienteLayout';
import {
    AvisoFlash, Botao, CabecalhoEstrutura, PainelEstrutura, PilulaSituacao, ProximoPasso, fmtData,
} from '@/Components/Portal/Estrutura/comum';
import Janela from '@/Components/Portal/Estrutura/Janela';
import GavetaOferta from '@/Components/Portal/Estrutura/GavetaOferta';
import FormOferta from '@/Components/Portal/Estrutura/FormOferta';
import FormAnuncio from '@/Components/Portal/Estrutura/FormAnuncio';
import AgendarDialog from '@/Components/Portal/Estrutura/AgendarDialog';
import ColarAnuncios from '@/Components/Portal/Estrutura/ColarAnuncios';
import EsperaAnuncios from '@/Components/Portal/Estrutura/EsperaAnuncios';
import ComoFunciona from '@/Components/Portal/Estrutura/ComoFunciona';
import { cn } from '@/lib/utils';

// ─── Mapeamento Estrutural — visão Ofertas ──────────────────────────────────
//
// A "Lista SKUs" e o "Mapeamento" da planilha eram a mesma tabela duas vezes —
// uma digitada, a outra espelhada. Aqui são uma coisa só: cada oferta mostra o
// próprio buraco ao lado dos próprios dados (ADR PORTAL-01).
//
// ### Uma tabela simples, como a aba Mapeamento (24/09)
// Blocos recolhíveis com resumo, cards e painel grande foram recusados:
// "acaba sendo mais complexo para o cliente que não sabe usar um sistema —
// queria algo mais comum mas funcional, assim como é na planilha". Então: uma
// linha por oferta, as colunas da planilha (SKU · Fase · Descrição · Clássico
// · Premium · Catálogo · Situação), 0 em vermelho e ≥1 em verde como a
// formatação condicional dela. A ordem continua a da aula: cada produto e,
// logo abaixo e recuados, os combos dele; depois kits e combits. Clicar na
// linha abre os detalhes.
//
// ### Tudo o que é número vem do servidor
// Painel, contadores dos filtros e situação de cada oferta saem da
// `ReguaEstrutura`. Filtro, busca e página vão pela URL: a lista pagina SEMPRE
// no servidor (25 blocos), porque o maior seller da carteira tem 2.688
// anúncios — e filtro de navegador sobre página recortada mente
// (`portal-do-cliente.md` §25).

const FILTROS = [
    { chave: 'todas',     rotulo: 'Todas' },
    { chave: 'publicar',  rotulo: 'A publicar' },
    { chave: 'falta',     rotulo: 'Falta um lado' },
    { chave: 'completas', rotulo: 'Completas' },
];

const ROTULO_FASE = (o) => ({ simples: 'Simples', combo: `Combo ${o.unidades}`, kit: 'Kit', combit: 'Combit' }[o.fase]);

/** Clássico / Premium: o número, verde quando há, vermelho quando é 0 — como a planilha. */
function Contagem({ n, cobrado = true }) {
    return (
        <span className={cn(
            'inline-flex min-w-[26px] justify-center rounded-md px-1.5 py-0.5 text-[12px] font-semibold tabular-nums',
            n > 0 ? 'bg-emerald-500/15 text-emerald-300' : cobrado ? 'bg-red-500/15 text-red-300' : 'text-white/30',
        )}>
            {n}
        </span>
    );
}

function TabelaOfertas({ blocos, vocabulario, onAbrir, onAgendar, onVariacao }) {
    // Achata os blocos (produto + combos; kit; combit) na ordem da aula.
    const linhas = blocos.flatMap((b) => b.ofertas.map((o) => ({
        o,
        bloco: b,
        recuada: b.produto && o.id !== b.principal.id,
    })));

    const th = 'px-3 py-2 text-left text-[11px] font-medium uppercase tracking-wide text-white/40';

    return (
        <div className="relative overflow-x-auto rounded-xl border border-white/[0.08] bg-ecf-card">
            <table className="w-full text-[13px]" data-tabela-ofertas>
                <thead className="border-b border-white/[0.08]">
                    <tr>
                        <th className={th}>SKU</th>
                        <th className={cn(th, 'hidden md:table-cell')}>Fase</th>
                        <th className={cn(th, 'hidden sm:table-cell')}>Descrição</th>
                        <th className={cn(th, 'text-center')}>Clássico</th>
                        <th className={cn(th, 'text-center')}>Premium</th>
                        <th className={cn(th, 'hidden md:table-cell text-center')}>Catálogo</th>
                        <th className={th}>Situação</th>
                        <th className={cn(th, 'hidden lg:table-cell')}>Agenda</th>
                        <th className={th}><span className="sr-only">Ações</span></th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-white/[0.05]">
                    {linhas.map(({ o, bloco, recuada }) => {
                        const pendente = o.agenda.find((i) => i.acao === 'publicacao' && ! i.feita);

                        return (
                            <tr key={o.id} onClick={() => onAbrir(o.id)} data-oferta={o.id} data-situacao={o.situacao}
                                className="cursor-pointer hover:bg-white/[0.03]">
                                <td className={cn('px-3 py-2 align-top', recuada && 'pl-7')}>
                                    <span className="font-mono text-white/90 whitespace-nowrap">
                                        {recuada && <span className="mr-1 text-white/25">└</span>}{o.sku}
                                    </span>
                                    {o.sku_repetido && (
                                        <span className="ml-1.5 inline-flex items-center text-amber-300" title="Outra oferta tem o mesmo SKU.">
                                            <AlertTriangle size={12} />
                                        </span>
                                    )}
                                    {/* No celular a descrição e a fase vêm aqui, sob o SKU. */}
                                    <p className="text-[11.5px] text-white/45 sm:hidden">{ROTULO_FASE(o)}{o.nome ? ` · ${o.nome}` : ''}</p>
                                </td>
                                <td className="hidden px-3 py-2 align-top text-white/60 whitespace-nowrap md:table-cell">{ROTULO_FASE(o)}</td>
                                <td className="hidden px-3 py-2 align-top text-white/75 sm:table-cell">
                                    {o.nome ?? <span className="text-white/30">—</span>}
                                    {o.componentes.length > 0 && o.fase !== 'combo' && (
                                        <p className="text-[11.5px] text-white/40">{o.componentes.map((c) => `${c.sku} ×${c.quantidade}`).join(' + ')}</p>
                                    )}
                                </td>
                                <td className="px-3 py-2 text-center align-top"><Contagem n={o.classicos} /></td>
                                <td className="px-3 py-2 text-center align-top"><Contagem n={o.premiums} /></td>
                                <td className="hidden px-3 py-2 text-center align-top md:table-cell">
                                    <Contagem n={o.catalogos} cobrado={false} />
                                    {o.kits_virtuais > 0 && <span className="ml-1 rounded bg-violet-500/10 px-1 text-[10.5px] text-violet-300" title="Kit virtual montado">KV</span>}
                                </td>
                                <td className="px-3 py-2 align-top"><PilulaSituacao situacao={o.situacao} vocabulario={vocabulario} /></td>
                                <td className="hidden px-3 py-2 align-top whitespace-nowrap lg:table-cell">
                                    {pendente
                                        ? <span className="text-white/60">{fmtData(pendente.data)}</span>
                                        : o.situacao !== 'ok'
                                            ? <button type="button" className="text-[12.5px] text-ecf-yellow hover:underline"
                                                onClick={(e) => { e.stopPropagation(); onAgendar(o); }}>Agendar</button>
                                            : <span className="text-white/25">—</span>}
                                </td>
                                <td className="px-3 py-2 text-right align-top whitespace-nowrap">
                                    {o.fase === 'simples' && (
                                        <button type="button" className="inline-flex items-center gap-0.5 text-[12.5px] text-white/55 hover:text-white"
                                            onClick={(e) => { e.stopPropagation(); onVariacao(o, bloco.quantidades_combo); }} data-acao="nova-variacao">
                                            <Plus size={13} /> Variação
                                        </button>
                                    )}
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}

function EstadoVazio({ onProduto, onColar }) {
    return (
        <section className="rounded-2xl border border-dashed border-white/[0.12] p-6 text-center space-y-4" data-vazio>
            <p className="text-white text-[15px] font-semibold">Comece listando seus produtos</p>
            <p className="text-white/50 text-[13px] max-w-lg mx-auto leading-relaxed">
                Liste TODOS os produtos em Fase 1. Depois, para cada um, pergunte: dá combo? Em quantas unidades? Combina com qual outro produto?
            </p>
            <div className="grid sm:grid-cols-4 gap-2 text-left max-w-3xl mx-auto">
                {[
                    ['Fase 1 · Simples', '1 Cadeira 01'],
                    ['Fase 2 · Combo', 'Combo 2 Cadeiras 01'],
                    ['Fase 3 · Kit', 'Mesa Marfim + 1 Cadeira 01'],
                    ['Fase 4 · Combit', 'Mesa Marfim + 4 Cadeiras 01'],
                ].map(([f, e]) => (
                    <div key={f} className="rounded-xl border border-white/[0.08] p-3">
                        <p className="text-[12.5px] font-semibold text-white/85">{f}</p>
                        <p className="text-[12px] text-white/45">{e}</p>
                    </div>
                ))}
            </div>
            <div className="flex flex-wrap justify-center gap-2">
                <Botao variante="primario" onClick={onProduto}><Plus size={14} /> Primeiro produto</Botao>
                <Botao onClick={onColar}><ClipboardPaste size={14} /> Colar anúncios que já tenho</Botao>
            </div>
        </section>
    );
}

export default function Estrutura({ empresa, modulos = [], estrutura, filtros, vocabulario, espera_linhas, opcoes_ofertas }) {
    const [gavetaId, setGavetaId] = useState(null);
    const [formOferta, setFormOferta] = useState(null);     // { modo, base, inicial }
    const [formAnuncio, setFormAnuncio] = useState(null);   // { oferta, anuncio }
    const [agendar, setAgendar] = useState(null);           // oferta
    const [variacao, setVariacao] = useState(null);         // { base, existentes }
    const [colar, setColar] = useState(false);
    const [espera, setEspera] = useState(false);
    const [aula, setAula] = useState(false);
    const [busca, setBusca] = useState(filtros.q ?? '');

    const { painel, contadores, blocos, paginacao } = estrutura;

    // A oferta da gaveta é LIDA das props a cada render — depois de uma
    // escrita, a gaveta mostra o estado novo sem cópia local para reconciliar.
    const gaveta = useMemo(() => {
        for (const b of blocos) {
            const o = b.ofertas.find((x) => x.id === gavetaId);
            if (o) return o;
        }

        return null;
    }, [blocos, gavetaId]);

    const visitar = (params) => router.get(route('portal.auth.estrutura'), params, {
        preserveState: true, preserveScroll: false, replace: true,
        only: ['estrutura', 'filtros'],
    });

    // Busca com respiro: uma ida ao servidor quando a pessoa para de digitar.
    const primeiraVez = useRef(true);
    useEffect(() => {
        if (primeiraVez.current) { primeiraVez.current = false; return; }
        const t = setTimeout(() => visitar({ situacao: filtros.situacao, q: busca || undefined }), 350);

        return () => clearTimeout(t);
    }, [busca]); // eslint-disable-line react-hooks/exhaustive-deps

    const carregarOpcoes = (extra = []) => router.reload({ only: ['opcoes_ofertas', ...extra] });

    const abrirKit = (base) => { carregarOpcoes(); setFormOferta({ modo: 'kit', base }); };
    const abrirEspera = () => { carregarOpcoes(['espera_linhas']); setEspera(true); };
    const editarOferta = (o) => {
        if (o.fase === 'kit' || o.fase === 'combit') carregarOpcoes();
        setFormOferta({ modo: 'editar', base: o });
    };

    const fecharFormOferta = () => {
        const deOndeVeio = formOferta?.daEspera;
        setFormOferta(null);
        // Criar a oferta a partir da espera: a lista da espera precisa ser
        // relida — a varredura (no servidor) pode ter absorvido a linha.
        if (deOndeVeio) carregarOpcoes(['espera_linhas']);
    };

    return (
        <PortalClienteLayout empresa={empresa} modulos={modulos} titulo="Mapeamento Estrutural">
            <div className="max-w-5xl mx-auto px-4 py-6 space-y-4">
                <CabecalhoEstrutura visao="ofertas" onColar={() => setColar(true)} onComoFunciona={() => setAula(true)} />

                <PainelEstrutura painel={painel} />

                <ProximoPasso passo={estrutura.proximo_passo} />

                {estrutura.espera > 0 && (
                    <button type="button" onClick={abrirEspera} data-aviso-espera
                        className="w-full flex items-center gap-2 rounded-xl border border-amber-500/30 bg-amber-500/[0.06] px-3 py-2 text-left text-[13px] text-amber-200 hover:bg-amber-500/10">
                        <AlertTriangle size={16} className="shrink-0" />
                        <span><strong>{estrutura.espera}</strong> anúncio(s) colado(s) aguardando oferta — eles já estão no ar, mas ainda não contam no painel.</span>
                        <span className="ml-auto font-semibold">Resolver</span>
                    </button>
                )}

                {painel.ofertas === 0 ? (
                    <EstadoVazio onProduto={() => setFormOferta({ modo: 'produto' })} onColar={() => setColar(true)} />
                ) : (
                    <>
                        <div className="flex flex-wrap items-center gap-2">
                            <div className="inline-flex flex-wrap rounded-xl border border-white/[0.08] bg-white/[0.02] p-1" role="tablist" aria-label="Situação">
                                {FILTROS.map((f) => (
                                    <button key={f.chave} type="button" role="tab" aria-selected={filtros.situacao === f.chave}
                                        onClick={() => visitar({ situacao: f.chave, q: busca || undefined })} data-filtro={f.chave}
                                        className={cn('rounded-lg px-3 py-1.5 text-[12.5px] transition-colors',
                                            (filtros.situacao ?? 'todas') === f.chave ? 'bg-white/[0.08] text-white' : 'text-white/50 hover:text-white')}>
                                        {f.rotulo} <span className="text-white/35">{contadores[f.chave]}</span>
                                    </button>
                                ))}
                            </div>
                            <div className="relative flex-1 min-w-[200px]">
                                <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-white/30" />
                                <input value={busca} onChange={(e) => setBusca(e.target.value)} placeholder="SKU, nome ou MLB"
                                    className="w-full rounded-xl border border-white/[0.10] bg-white/[0.04] pl-8 pr-8 py-2 text-[13px] text-white placeholder:text-white/25 focus:border-ecf-yellow/40 focus:outline-none focus:ring-0"
                                    data-busca />
                                {busca && (
                                    <button type="button" onClick={() => setBusca('')} className="absolute right-2.5 top-1/2 -translate-y-1/2 text-white/35 hover:text-white" aria-label="Limpar busca">
                                        <X size={14} />
                                    </button>
                                )}
                            </div>
                            <Botao variante="primario" onClick={() => setFormOferta({ modo: 'produto' })} data-acao="novo-produto">
                                <Plus size={14} /> Produto
                            </Botao>
                        </div>

                        {blocos.length === 0 && (
                            <p className="py-10 text-center text-[13px] text-white/45">Nenhuma oferta com este filtro.</p>
                        )}

                        {blocos.length > 0 && (
                            <TabelaOfertas blocos={blocos} vocabulario={vocabulario}
                                onAbrir={setGavetaId} onAgendar={setAgendar}
                                onVariacao={(base, existentes) => setVariacao({ base, existentes })} />
                        )}

                        {paginacao.paginas > 1 && (
                            <nav className="flex items-center justify-center gap-3 pt-2" aria-label="Paginação" data-paginacao>
                                <Botao disabled={paginacao.pagina <= 1}
                                    onClick={() => visitar({ situacao: filtros.situacao, q: busca || undefined, pagina: paginacao.pagina - 1 })}>
                                    <ChevronLeft size={14} /> Anterior
                                </Botao>
                                <span className="text-[12.5px] text-white/50">
                                    Página {paginacao.pagina} de {paginacao.paginas}
                                </span>
                                <Botao disabled={paginacao.pagina >= paginacao.paginas}
                                    onClick={() => visitar({ situacao: filtros.situacao, q: busca || undefined, pagina: paginacao.pagina + 1 })}>
                                    Próxima <ChevronRight size={14} />
                                </Botao>
                            </nav>
                        )}
                    </>
                )}
            </div>

            <GavetaOferta
                oferta={gaveta}
                onFechar={() => setGavetaId(null)}
                vocabulario={vocabulario}
                onEditar={editarOferta}
                onNovoAnuncio={(o) => setFormAnuncio({ oferta: o })}
                onEditarAnuncio={(o, a) => setFormAnuncio({ oferta: o, anuncio: a })}
                onAgendar={setAgendar}
            />

            <FormOferta
                aberta={!! formOferta}
                onFechar={fecharFormOferta}
                modo={formOferta?.modo ?? 'produto'}
                base={formOferta?.base}
                inicial={formOferta?.inicial}
                existentes={formOferta?.existentes ?? []}
                opcoes={opcoes_ofertas}
                vocabulario={vocabulario}
            />

            <FormAnuncio
                aberta={!! formAnuncio}
                onFechar={() => setFormAnuncio(null)}
                oferta={formAnuncio?.oferta}
                anuncio={formAnuncio?.anuncio ?? null}
                vocabulario={vocabulario}
            />

            {/* "+ Variação": a pergunta da aula em português, sem a palavra fase.
                A tela continua decidindo sozinha entre kit e combit. */}
            <Janela aberta={!! variacao} onFechar={() => setVariacao(null)}
                titulo={`Nova variação de ${variacao?.base?.nome || variacao?.base?.sku || ''}`}
                descricao="Dá combo? Combina com qual outro produto?">
                <div className="grid gap-2 sm:grid-cols-2" data-escolha-variacao>
                    {[
                        { chave: 'combo', titulo: 'Combo', exemplo: 'Mesmo produto, mais unidades. Ex.: 2 cadeiras, 4 cadeiras' },
                        { chave: 'kit', titulo: 'Kit ou Combit', exemplo: 'Junto com outro produto. Ex.: mesa + cadeira' },
                    ].map((o) => (
                        <button key={o.chave} type="button" data-escolha={o.chave}
                            onClick={() => {
                                const { base, existentes } = variacao;
                                setVariacao(null);
                                if (o.chave === 'combo') setFormOferta({ modo: 'combo', base, existentes });
                                else abrirKit(base);
                            }}
                            className="rounded-xl border border-white/[0.10] p-4 text-left hover:border-ecf-yellow/40 hover:bg-ecf-yellow/[0.05]">
                            <p className="text-[14px] font-semibold text-white">{o.titulo}</p>
                            <p className="mt-1 text-[12.5px] text-white/50">{o.exemplo}</p>
                        </button>
                    ))}
                </div>
            </Janela>

            <AgendarDialog aberta={!! agendar} onFechar={() => setAgendar(null)} oferta={agendar} vocabulario={vocabulario} />

            <ColarAnuncios aberta={colar} onFechar={() => setColar(false)} vocabulario={vocabulario} />

            <EsperaAnuncios
                aberta={espera}
                onFechar={() => setEspera(false)}
                linhas={espera_linhas}
                ofertas={opcoes_ofertas}
                vocabulario={vocabulario}
                onCriarOferta={(linha) => {
                    setEspera(false);
                    setFormOferta({ modo: 'produto', inicial: { sku: linha.sku_colado ?? '', nome: linha.titulo ?? '' }, daEspera: true });
                }}
            />

            <ComoFunciona aberta={aula} onFechar={() => setAula(false)} />

            <AvisoFlash />
        </PortalClienteLayout>
    );
}
