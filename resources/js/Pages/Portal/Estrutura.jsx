import { useEffect, useMemo, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { AlertTriangle, CalendarPlus, ChevronLeft, ChevronRight, ClipboardPaste, Package, Plus, Search, X } from 'lucide-react';
import PortalClienteLayout from '@/Layouts/PortalClienteLayout';
import {
    AvisoFlash, Botao, CabecalhoEstrutura, Indicadores, Lado, PainelEstrutura, PilulaSituacao,
} from '@/Components/Portal/Estrutura/comum';
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
// A tela se organiza POR PRODUTO, porque é assim que a aula ensina a pensar
// ("para cada um, pergunte: dá combo? combina com qual?"): cada produto simples
// com os combos dele; kits e combits numa seção própria — composição não tem
// produto principal, e pendurá-los num dos componentes seria arbitrário.
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

const ROTULO_LINHA = (o) => ({ simples: 'Produto', combo: `Combo ${o.unidades}`, kit: 'Kit', combit: 'Combit' }[o.fase]);

function LinhaOferta({ oferta, onAbrir, onAgendar, vocabulario, rodape = null }) {
    const temPublicacaoPendente = oferta.agenda.some((i) => i.acao === 'publicacao' && ! i.feita);

    return (
        <li>
            <div role="button" tabIndex={0} onClick={() => onAbrir(oferta.id)}
                onKeyDown={(e) => (e.key === 'Enter' || e.key === ' ') && (e.preventDefault(), onAbrir(oferta.id))}
                className="flex flex-wrap items-center gap-x-4 gap-y-1.5 rounded-xl px-3 py-2.5 hover:bg-white/[0.03] cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow/40"
                data-oferta={oferta.id} data-situacao={oferta.situacao}>
                <div className="min-w-0 flex-1 basis-56">
                    <p className="text-[13px] text-white/90 truncate">
                        <span className="text-white/40 text-[11.5px] mr-1.5">{ROTULO_LINHA(oferta)}</span>
                        <span className="font-mono">{oferta.sku}</span>
                        {oferta.sku_repetido && (
                            <span className="ml-1.5 inline-flex items-center gap-0.5 text-[10.5px] text-amber-300" title="Outra oferta tem o mesmo SKU. Os anúncios colados não sabem em qual das duas entrar.">
                                <AlertTriangle size={11} /> SKU repetido
                            </span>
                        )}
                    </p>
                    {oferta.nome && <p className="text-[12px] text-white/45 truncate">{oferta.nome} · {oferta.unidades} un.</p>}
                </div>
                <div className="flex items-center gap-3">
                    <Lado rotulo="Clássico" quantidade={oferta.classicos} />
                    <Lado rotulo="Premium" quantidade={oferta.premiums} />
                    <Indicadores catalogos={oferta.catalogos} kitsVirtuais={oferta.kits_virtuais} />
                </div>
                <div className="flex items-center gap-2 ml-auto">
                    <PilulaSituacao situacao={oferta.situacao} vocabulario={vocabulario} />
                    {oferta.situacao !== 'ok' && ! temPublicacaoPendente && (
                        <Botao variante="fantasma" className="px-2 py-1 text-[12px]"
                            onClick={(e) => { e.stopPropagation(); onAgendar(oferta); }}>
                            <CalendarPlus size={13} /> Agendar
                        </Botao>
                    )}
                    {temPublicacaoPendente && (
                        <span className="text-[11.5px] text-white/40">
                            agendada {oferta.agenda.find((i) => i.acao === 'publicacao' && ! i.feita)?.data.split('-').reverse().slice(0, 2).join('/')}
                        </span>
                    )}
                </div>
                {rodape && <p className="basis-full text-[11.5px] text-white/40">{rodape}</p>}
            </div>
        </li>
    );
}

function BlocoProduto({ bloco, onAbrir, onAgendar, onCombo, onKit, vocabulario }) {
    const principalCompleto = bloco.ofertas.find((o) => o.id === bloco.principal.id) ?? bloco.principal;

    return (
        <section className="rounded-2xl border border-white/[0.08] bg-ecf-card" data-bloco={bloco.chave}>
            <header className="flex flex-wrap items-center gap-2 border-b border-white/[0.06] px-4 py-3">
                <Package size={15} className="text-white/35" />
                <p className="text-[13.5px] text-white font-semibold min-w-0 truncate">
                    <span className="font-mono">{bloco.principal.sku}</span>
                    {bloco.principal.nome && <span className="text-white/55 font-normal"> · {bloco.principal.nome}</span>}
                </p>
                <div className="ml-auto flex gap-1.5">
                    <Botao variante="fantasma" className="px-2 py-1 text-[12px]" onClick={() => onCombo(principalCompleto)} data-acao="novo-combo">
                        <Plus size={13} /> Combo
                    </Botao>
                    <Botao variante="fantasma" className="px-2 py-1 text-[12px]" onClick={() => onKit(principalCompleto)} data-acao="novo-kit">
                        <Plus size={13} /> Kit/Combit
                    </Botao>
                </div>
            </header>
            <ul className="p-1.5">
                {bloco.ofertas.map((o) => <LinhaOferta key={o.id} oferta={o} onAbrir={onAbrir} onAgendar={onAgendar} vocabulario={vocabulario} />)}
            </ul>
            {bloco.tambem_em.length > 0 && (
                <p className="border-t border-white/[0.05] px-4 py-2 text-[11.5px] text-white/40">
                    Também em: {bloco.tambem_em.map((k) => k.nome || k.sku).join(' · ')}
                </p>
            )}
        </section>
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

    const primeiraDeKits = blocos.findIndex((b) => ! b.produto);

    return (
        <PortalClienteLayout empresa={empresa} modulos={modulos} titulo="Mapeamento Estrutural">
            <div className="max-w-5xl mx-auto px-4 py-6 space-y-4">
                <CabecalhoEstrutura visao="ofertas" onColar={() => setColar(true)} onComoFunciona={() => setAula(true)} />

                <PainelEstrutura painel={painel} />

                {estrutura.espera > 0 && (
                    <button type="button" onClick={abrirEspera} data-aviso-espera
                        className="w-full flex items-center gap-2 rounded-xl border border-amber-500/30 bg-amber-500/[0.06] px-4 py-3 text-left text-[13px] text-amber-200 hover:bg-amber-500/10">
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

                        <div className="space-y-3">
                            {blocos.map((b, i) => (
                                <div key={b.chave}>
                                    {i === primeiraDeKits && (
                                        <h2 className="mt-2 mb-2 text-[12px] uppercase tracking-wide text-white/40">Kits e combits</h2>
                                    )}
                                    {b.produto ? (
                                        <BlocoProduto bloco={b} vocabulario={vocabulario} onAbrir={setGavetaId} onAgendar={setAgendar}
                                            onCombo={(base) => setFormOferta({ modo: 'combo', base })} onKit={abrirKit} />
                                    ) : (
                                        <section className="rounded-2xl border border-white/[0.08] bg-ecf-card" data-bloco={b.chave}>
                                            <ul className="p-1.5">
                                                {b.ofertas.map((o) => (
                                                    <LinhaOferta key={o.id} oferta={o} onAbrir={setGavetaId} onAgendar={setAgendar} vocabulario={vocabulario}
                                                        rodape={o.componentes.map((c) => `${c.nome ?? c.sku} ×${c.quantidade}`).join(' + ')} />
                                                ))}
                                            </ul>
                                        </section>
                                    )}
                                </div>
                            ))}
                        </div>

                        {paginacao.paginas > 1 && (
                            <nav className="flex items-center justify-center gap-3 pt-2" aria-label="Paginação" data-paginacao>
                                <Botao disabled={paginacao.pagina <= 1}
                                    onClick={() => visitar({ situacao: filtros.situacao, q: busca || undefined, pagina: paginacao.pagina - 1 })}>
                                    <ChevronLeft size={14} /> Anterior
                                </Botao>
                                <span className="text-[12.5px] text-white/50">
                                    Página {paginacao.pagina} de {paginacao.paginas} · {paginacao.blocos} grupos
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
