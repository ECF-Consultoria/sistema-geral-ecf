import { useEffect, useMemo, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { AlertTriangle, CalendarPlus, ChevronDown, ChevronLeft, ChevronRight, ClipboardPaste, Plus, Search, X } from 'lucide-react';
import PortalClienteLayout from '@/Layouts/PortalClienteLayout';
import {
    AvisoFlash, Botao, CabecalhoEstrutura, Indicadores, Lado, PainelEstrutura, PilulaSituacao, ProximoPasso,
} from '@/Components/Portal/Estrutura/comum';
import Janela from '@/Components/Portal/Estrutura/Janela';
import GavetaOferta from '@/Components/Portal/Estrutura/GavetaOferta';
import FormOferta from '@/Components/Portal/Estrutura/FormOferta';
import FormAnuncio from '@/Components/Portal/Estrutura/FormAnuncio';
import AgendarDialog from '@/Components/Portal/Estrutura/AgendarDialog';
import ColarAnuncios from '@/Components/Portal/Estrutura/ColarAnuncios';
import EsperaAnuncios from '@/Components/Portal/Estrutura/EsperaAnuncios';
import ComoFunciona from '@/Components/Portal/Estrutura/ComoFunciona';
import ImportarDoMl from '@/Components/Portal/Estrutura/ImportarDoMl';
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
                    <Lado rotulo={vocabulario.tipos_curtos.classico} quantidade={oferta.classicos} />
                    <Lado rotulo={vocabulario.tipos_curtos.premium} quantidade={oferta.premiums} />
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

// O pior caso manda na cor: algo a publicar → vermelho; falta um lado →
// âmbar; tudo OK → verde. É o que o olho precisa com o bloco FECHADO.
const COR_PIOR = { publicar: 'bg-red-400', falta: 'bg-amber-400', ok: 'bg-emerald-400', vazio: 'bg-white/20' };

const piorCaso = (resumo, situacaoPrincipal = null) => {
    if (resumo.publicar > 0 || situacaoPrincipal === 'publicar') return 'publicar';
    if (resumo.falta > 0 || situacaoPrincipal?.startsWith('falta')) return 'falta';
    if (resumo.total > 0 || situacaoPrincipal === 'ok') return 'ok';

    return 'vazio';
};

/**
 * "5 combos: 1 falta um lado · 4 a publicar · 4 sem data". O resumo vem do
 * servidor e é do bloco INTEIRO — não muda quando um filtro esconde linhas.
 */
function ResumoContagem({ resumo, singular, plural }) {
    if (resumo.total === 0) return <span className="text-white/30">sem {plural}</span>;

    const pendentes = resumo.falta + resumo.publicar;
    const partes = [
        resumo.ok > 0 && <span key="ok" className="text-emerald-300">{resumo.ok} ok</span>,
        resumo.falta > 0 && <span key="falta" className="text-amber-300">{resumo.falta} falta um lado</span>,
        resumo.publicar > 0 && <span key="pub" className="text-red-300">{resumo.publicar} a publicar</span>,
        pendentes > 0 && (resumo.sem_agenda > 0
            ? <span key="agenda" className="text-red-300/80">{resumo.sem_agenda} sem data</span>
            : <span key="agenda" className="text-white/40">todas agendadas</span>),
    ].filter(Boolean);

    return (
        <span className="text-white/55">
            {resumo.total} {resumo.total === 1 ? singular : plural}:{' '}
            {partes.map((p, i) => <span key={p.key}>{i > 0 && ' · '}{p}</span>)}
        </span>
    );
}

const textoUso = (uso) => [
    uso.kits ? `${uso.kits} ${uso.kits === 1 ? 'kit' : 'kits'}` : null,
    uso.combits ? `${uso.combits} ${uso.combits === 1 ? 'combit' : 'combits'}` : null,
].filter(Boolean).join(' e ');

/**
 * Um produto e os combos dele. Nasce RECOLHIDO — a lista inteira aberta vira
 * um rolo com 1.500 ofertas —, e o cabeçalho diz o que há dentro e o que
 * falta. Com filtro ou busca ativos nasce aberto: quem buscou "CB4" quer ver a
 * linha, não um cabeçalho fechado.
 */
function BlocoProduto({ bloco, abertoInicial, onAbrir, onAgendar, onVariacao, vocabulario }) {
    const [aberto, setAberto] = useState(abertoInicial);
    const principalCompleto = bloco.ofertas.find((o) => o.id === bloco.principal.id) ?? bloco.principal;
    const pior = piorCaso(bloco.resumo_combos, bloco.principal.situacao);
    const uso = textoUso(bloco.uso);

    return (
        <section className="rounded-2xl border border-white/[0.08] bg-ecf-card" data-bloco={bloco.chave} data-aberto={aberto ? '1' : '0'}>
            <header className={cn('flex flex-wrap items-center gap-x-3 gap-y-1.5 px-4 py-3', aberto && 'border-b border-white/[0.06]')}>
                <button type="button" onClick={() => setAberto(! aberto)} aria-expanded={aberto} data-acao="alternar-bloco"
                    className="flex min-w-0 flex-1 basis-72 items-center gap-2 text-left">
                    {aberto ? <ChevronDown size={15} className="shrink-0 text-white/40" /> : <ChevronRight size={15} className="shrink-0 text-white/40" />}
                    <span className={cn('h-2 w-2 shrink-0 rounded-full', COR_PIOR[pior])} aria-hidden />
                    <span className="min-w-0">
                        <span className="block truncate text-[13.5px] font-semibold text-white" data-titulo-bloco>
                            <span className="font-mono">{bloco.principal.sku}</span>
                            {bloco.principal.nome && <span className="font-normal text-white/55"> · {bloco.principal.nome}</span>}
                        </span>
                        <span className="block truncate text-[12px]" data-resumo>
                            <ResumoContagem resumo={bloco.resumo_combos} singular="combo" plural="combos" />
                            {uso && <span className="text-white/40" title={bloco.tambem_em.map((k) => k.nome || k.sku).join(' · ')}> · entra em {uso}</span>}
                        </span>
                    </span>
                </button>
                <PilulaSituacao situacao={bloco.principal.situacao} vocabulario={vocabulario} />
                <Botao variante="fantasma" className="px-2 py-1 text-[12px]" onClick={() => onVariacao(principalCompleto, bloco.quantidades_combo)} data-acao="nova-variacao">
                    <Plus size={13} /> Variação
                </Botao>
            </header>
            {aberto && (
                <ul className="p-1.5">
                    {bloco.ofertas.map((o) => <LinhaOferta key={o.id} oferta={o} onAbrir={onAbrir} onAgendar={onAgendar} vocabulario={vocabulario} />)}
                </ul>
            )}
        </section>
    );
}

/** Kits e combits: uma seção só, também recolhida, com o resumo de TODOS. */
function SecaoKits({ blocos, resumo, porFase, abertoInicial, onAbrir, onAgendar, vocabulario }) {
    const [aberto, setAberto] = useState(abertoInicial);

    return (
        <section className="rounded-2xl border border-white/[0.08] bg-ecf-card" data-secao-kits data-aberto={aberto ? '1' : '0'}>
            <button type="button" onClick={() => setAberto(! aberto)} aria-expanded={aberto} data-acao="alternar-kits"
                className={cn('flex w-full items-center gap-2 px-4 py-3 text-left', aberto && 'border-b border-white/[0.06]')}>
                {aberto ? <ChevronDown size={15} className="text-white/40" /> : <ChevronRight size={15} className="text-white/40" />}
                <span className={cn('h-2 w-2 rounded-full', COR_PIOR[piorCaso(resumo)])} aria-hidden />
                <span className="text-[13.5px] font-semibold text-white">Kits e combits</span>
                <span className="text-[12px]" data-resumo>
                    <span className="text-white/55">
                        {porFase.kit} {porFase.kit === 1 ? 'kit' : 'kits'} · {porFase.combit} {porFase.combit === 1 ? 'combit' : 'combits'} ·{' '}
                    </span>
                    <ResumoContagem resumo={resumo} singular="oferta" plural="ofertas" />
                </span>
            </button>
            {aberto && (
                <ul className="p-1.5">
                    {blocos.flatMap((b) => b.ofertas).map((o) => (
                        <LinhaOferta key={o.id} oferta={o} onAbrir={onAbrir} onAgendar={onAgendar} vocabulario={vocabulario}
                            rodape={o.componentes.map((c) => `${c.nome ?? c.sku} ×${c.quantidade}`).join(' + ')} />
                    ))}
                </ul>
            )}
        </section>
    );
}

function EstadoVazio({ onProduto, onColar, onImportar }) {
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
                {onImportar && <Botao onClick={onImportar} data-acao="importar-ml-vazio">Importar do Mercado Livre</Botao>}
                <Botao onClick={onColar}><ClipboardPaste size={14} /> Colar anúncios que já tenho</Botao>
            </div>
        </section>
    );
}

export default function Estrutura({ empresa, modulos = [], estrutura, filtros, vocabulario, ml_conectado = false, espera_linhas, opcoes_ofertas }) {
    const [gavetaId, setGavetaId] = useState(null);
    const [formOferta, setFormOferta] = useState(null);     // { modo, base, inicial }
    const [formAnuncio, setFormAnuncio] = useState(null);   // { oferta, anuncio }
    const [agendar, setAgendar] = useState(null);           // oferta
    const [variacao, setVariacao] = useState(null);         // { base, existentes }
    const [colar, setColar] = useState(false);
    const [espera, setEspera] = useState(false);
    const [aula, setAula] = useState(false);
    const [importar, setImportar] = useState(false);
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

    // Sem filtro, tudo recolhido; com filtro ou busca, o que casou abre. A
    // chave remonta os blocos quando o filtro muda, para valer o novo padrão.
    const filtroAtivo = (filtros.situacao ?? 'todas') !== 'todas' || !! filtros.q;
    const chaveFiltro = `${filtros.situacao ?? 'todas'}|${filtros.q ?? ''}`;
    const blocosProduto = blocos.filter((b) => b.produto);
    const blocosKits = blocos.filter((b) => ! b.produto);

    return (
        <PortalClienteLayout empresa={empresa} modulos={modulos} titulo="Mapeamento Estrutural">
            <div className="max-w-5xl mx-auto px-4 py-6 space-y-4">
                <CabecalhoEstrutura visao="ofertas" onColar={() => setColar(true)} onImportar={() => setImportar(true)} onComoFunciona={() => setAula(true)} />

                <PainelEstrutura painel={painel} />

                <ProximoPasso passo={estrutura.proximo_passo} />

                {estrutura.espera > 0 && (
                    <button type="button" onClick={abrirEspera} data-aviso-espera
                        className="w-full flex items-center gap-2 rounded-xl border border-amber-500/30 bg-amber-500/[0.06] px-4 py-3 text-left text-[13px] text-amber-200 hover:bg-amber-500/10">
                        <AlertTriangle size={16} className="shrink-0" />
                        <span><strong>{estrutura.espera}</strong> anúncio(s) colado(s) aguardando oferta — eles já estão no ar, mas ainda não contam no painel.</span>
                        <span className="ml-auto font-semibold">Resolver</span>
                    </button>
                )}

                {painel.ofertas === 0 ? (
                    <EstadoVazio onProduto={() => setFormOferta({ modo: 'produto' })} onColar={() => setColar(true)}
                        onImportar={ml_conectado ? () => setImportar(true) : null} />
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
                            {blocosProduto.map((b) => (
                                <BlocoProduto key={`${b.chave}-${chaveFiltro}`} bloco={b} abertoInicial={filtroAtivo} vocabulario={vocabulario}
                                    onAbrir={setGavetaId} onAgendar={setAgendar}
                                    onVariacao={(base, existentes) => setVariacao({ base, existentes })} />
                            ))}
                            {blocosKits.length > 0 && (
                                <SecaoKits key={`kits-${chaveFiltro}`} blocos={blocosKits} resumo={estrutura.resumo_kits} porFase={painel.por_fase}
                                    abertoInicial={filtroAtivo} onAbrir={setGavetaId} onAgendar={setAgendar} vocabulario={vocabulario} />
                            )}
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

            <ImportarDoMl aberta={importar} onFechar={() => setImportar(false)} conectado={ml_conectado} vocabulario={vocabulario} />

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
