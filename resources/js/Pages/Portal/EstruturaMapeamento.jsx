import { useEffect, useRef, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { AlertTriangle, ArrowRight, CalendarPlus, ChevronRight, Maximize2, Plus, Search, X } from 'lucide-react';
import PortalClienteLayout from '@/Layouts/PortalClienteLayout';
import {
    AvisoFlash, Botao, CabecalhoEstrutura, Paginacao, EstoqueOferta, FotoProduto, Indicadores, Lado, Vendas, PilulaProduto, PilulaSituacao, ProximoPasso, ResumoOperacional, fmtData,
} from '@/Components/Portal/Estrutura/comum';
import Janela from '@/Components/Portal/Estrutura/Janela';
import EstacaoProduto from '@/Components/Portal/Estrutura/EstacaoProduto';
import FormOferta from '@/Components/Portal/Estrutura/FormOferta';
import FormAnuncio from '@/Components/Portal/Estrutura/FormAnuncio';
import AgendarDialog from '@/Components/Portal/Estrutura/AgendarDialog';
import ComoFunciona from '@/Components/Portal/Estrutura/ComoFunciona';
import AgendaLateral from '@/Components/Portal/Estrutura/AgendaLateral';
import { cn } from '@/lib/utils';

// ─── Mapeamento Estrutural — submódulo Mapeamento (o pós-publicação) ────────
//
// Até 29/09 esta era a página única do módulo (a "visão Ofertas"). O usuário a
// achou poluída e o módulo virou submódulos na ordem de quem começa do zero:
// Lista SKUs → Precificação → Anúncios → Planejamento → Mapeamento. Cadastrar
// produto, colar e importar anúncios saíram daqui para a Lista SKUs e os
// Anúncios; aqui fica o que se olha DEPOIS de publicar: a situação de cada
// oferta, a estação do produto com as métricas, a Jardinagem.
//
// Uma CENTRAL DE TRABALHO, não uma planilha (redesign de 25/09). A ordem da
// tela é a ordem da pergunta do seller: quanto falta (resumo) → o que faço
// agora (próximo passo) → quais produtos estão pendentes (lista) → onde está o
// buraco de cada um (produto aberto). A agenda fica ao lado, com cada tarefa
// apontando para a sua oferta.
//
// A tela se organiza POR PRODUTO, porque é assim que a aula ensina a pensar
// ("para cada um, pergunte: dá combo? combina com qual?"): cada produto simples
// com os combos dele; kits e combits numa seção própria — composição não tem
// produto principal, e pendurá-los num dos componentes seria arbitrário.
//
// ### Status ≠ ação
// A pílula diz o ESTADO (Falta Premium); o botão diz o que ACONTECE ao clicar
// (Completar abre o anúncio do lado que falta; Publicar abre o anúncio novo).
// Decisão do usuário em 25/09: a ação abre o anúncio — quem já publicou no ML
// liga aqui; "Agendar" fica como ação secundária.
//
// ### Tudo o que é número vem do servidor
// Resumo, contadores dos filtros, situação de cada oferta e o resumo de cada
// produto saem da `ReguaEstrutura`. Filtro, busca e página vão pela URL: a
// lista pagina SEMPRE no servidor (25 blocos), porque o maior seller da
// carteira tem milhares de anúncios — e filtro de navegador sobre página
// recortada mente (`portal-do-cliente.md` §25).

const FILTROS = [
    { chave: 'todas',     rotulo: 'Todas' },
    { chave: 'publicar',  rotulo: 'A publicar' },
    { chave: 'falta',     rotulo: 'Falta um lado' },
    { chave: 'completas', rotulo: 'Completas' },
];

// O pior caso manda na cor do produto fechado: algo a publicar → vermelho;
// falta um lado → âmbar; tudo OK → verde.
const COR_PIOR = { publicar: 'bg-red-400', falta: 'bg-amber-400', ok: 'bg-emerald-400', vazio: 'bg-white/20' };

const piorCaso = (resumo) => {
    if (resumo.publicar > 0) return 'publicar';
    if (resumo.falta > 0) return 'falta';

    return resumo.total > 0 ? 'ok' : 'vazio';
};

const unidades = (n) => `${n} ${n === 1 ? 'unidade' : 'unidades'}`;
const ROTULO_OFERTA = (o) => ({ simples: unidades(o.unidades), combo: unidades(o.unidades), kit: 'Kit', combit: 'Combit' }[o.fase]);

/** O lado que falta — é nele que "Completar" abre o anúncio. */
const LADO_QUE_FALTA = { falta_classico: 'classico', falta_premium: 'premium' };

/**
 * Uma oferta dentro do produto aberto: o que é, os dois lados, o estado e a
 * ação. A linha inteira abre a estação do produto.
 */
function LinhaOferta({ oferta, onAbrir, onAnuncio, onAgendar, vocabulario, rodape = null }) {
    const publicacao = oferta.agenda.find((i) => i.acao === 'publicacao' && ! i.feita);
    const pendente = oferta.situacao !== 'ok';

    const acao = (e, fn) => { e.stopPropagation(); fn(); };

    return (
        <li>
            <div role="button" tabIndex={0} onClick={() => onAbrir(oferta.id)}
                onKeyDown={(e) => (e.key === 'Enter' || e.key === ' ') && (e.preventDefault(), onAbrir(oferta.id))}
                className="grid cursor-pointer grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 gap-y-2 rounded-xl px-3 py-2.5 hover:bg-white/[0.03] focus:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow/40 md:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)_auto]"
                data-oferta={oferta.id} data-situacao={oferta.situacao}>
                <div className="min-w-0">
                    <p className="truncate text-[13px] text-white/90">
                        {ROTULO_OFERTA(oferta)}
                        <span className="ml-2 font-mono text-[11.5px] text-white/40">{oferta.sku}</span>
                        {oferta.sku_repetido && (
                            <span className="ml-1.5 inline-flex items-center gap-0.5 text-[10.5px] text-amber-300" title="Outra oferta tem o mesmo SKU. Os anúncios colados não sabem em qual das duas entrar.">
                                <AlertTriangle size={11} /> SKU repetido
                            </span>
                        )}
                    </p>
                    {(oferta.estoque || oferta.vendas > 0 || oferta.precos?.invertido) && (
                        <p className="flex flex-wrap items-center gap-x-1.5 text-[11.5px]">
                            {oferta.vendas > 0 && <Vendas quantidade={oferta.vendas} />}
                            {oferta.vendas > 0 && oferta.estoque && <span className="text-white/25">·</span>}
                            <EstoqueOferta estoque={oferta.estoque} />
                            {oferta.precos?.invertido && (
                                <span className="inline-flex items-center gap-0.5 text-amber-300" title="A aula: Clássico para o melhor preço à vista, Premium para o parcelado." data-preco-invertido>
                                    <AlertTriangle size={11} /> Premium mais barato
                                </span>
                            )}
                        </p>
                    )}
                    {rodape && <p className="truncate text-[11.5px] text-white/40">{rodape}</p>}
                </div>
                <div className="order-3 col-span-2 flex flex-wrap items-center gap-3 md:order-none md:col-span-1">
                    <Lado rotulo={vocabulario.tipos_curtos.classico} quantidade={oferta.classicos} />
                    <Lado rotulo={vocabulario.tipos_curtos.premium} quantidade={oferta.premiums} />
                    <Indicadores catalogos={oferta.catalogos} kitsVirtuais={oferta.kits_virtuais} />
                </div>
                <div className="flex items-center justify-end gap-2">
                    <PilulaSituacao situacao={oferta.situacao} longa={oferta.situacao === 'publicar'} vocabulario={vocabulario} />
                    {pendente && (
                        <Botao className="border-ecf-yellow/40 px-2.5 py-1 text-[12px] text-ecf-yellow hover:bg-ecf-yellow/10 hover:text-ecf-yellow"
                            onClick={(e) => acao(e, () => onAnuncio(oferta, LADO_QUE_FALTA[oferta.situacao] ?? null))}
                            data-acao={oferta.situacao === 'publicar' ? 'publicar' : 'completar'}>
                            {oferta.situacao === 'publicar' ? 'Publicar' : 'Completar'}
                        </Botao>
                    )}
                    {pendente && (publicacao ? (
                        <Link href={route('portal.auth.estrutura.agenda')} onClick={(e) => e.stopPropagation()}
                            className="whitespace-nowrap text-[11.5px] text-white/45 underline decoration-dotted underline-offset-2 hover:text-white" data-agendada>
                            agendada {fmtData(publicacao.data)}
                        </Link>
                    ) : (
                        <button type="button" onClick={(e) => acao(e, () => onAgendar(oferta))} title="Agendar a publicação"
                            className="rounded-lg p-1.5 text-white/40 hover:bg-white/[0.05] hover:text-white" aria-label="Agendar a publicação" data-acao="agendar">
                            <CalendarPlus size={15} />
                        </button>
                    ))}
                </div>
            </div>
        </li>
    );
}

function Grupo({ titulo, children }) {
    return (
        <div className="py-1.5">
            <p className="px-3 pb-1 text-[10.5px] font-semibold uppercase tracking-wider text-white/35">{titulo}</p>
            <ul>{children}</ul>
        </div>
    );
}

/** "4 ofertas · 1 OK · 3 pendentes" — do bloco INTEIRO, não do que o filtro deixou. */
function ContagemProduto({ resumo }) {
    return (
        <span className="text-white/70">
            {resumo.total} {resumo.total === 1 ? 'oferta' : 'ofertas'}
            {resumo.ok > 0 && <> · <span className="text-emerald-300">{resumo.ok} OK</span></>}
            {resumo.pendentes > 0 && <> · <span className={resumo.publicar > 0 ? 'text-red-300' : 'text-amber-300'}>{resumo.pendentes} {resumo.pendentes === 1 ? 'pendente' : 'pendentes'}</span></>}
        </span>
    );
}

function Composicao({ bloco }) {
    const partes = [`Simples 1`, `Combos ${bloco.combos}`, `Kits ${bloco.uso.kits}`];
    if (bloco.uso.combits > 0) partes.push(`Combits ${bloco.uso.combits}`);

    return <span className="text-white/40">{partes.join(' · ')}</span>;
}

/**
 * Um produto e os combos dele. Fechado, responde "está tudo certo?" (cor,
 * contagem, pílula); aberto, "onde está o buraco?" (cada oferta com os dois
 * lados e a ação). Nasce RECOLHIDO — com centenas de produtos, aberto vira um
 * rolo —, salvo com filtro/busca ativos ou com poucos produtos.
 */
function BlocoProduto({ bloco, abertoInicial, onAbrir, onAnuncio, onAgendar, onVariacao, vocabulario }) {
    const [aberto, setAberto] = useState(abertoInicial);
    const principalCompleto = bloco.ofertas.find((o) => o.id === bloco.principal.id) ?? bloco.principal;
    const simples = bloco.ofertas.filter((o) => o.fase === 'simples');
    const combos = bloco.ofertas.filter((o) => o.fase !== 'simples');
    const props = { onAbrir, onAnuncio, onAgendar, vocabulario };

    return (
        <section className="relative overflow-hidden rounded-2xl border border-white/[0.08] bg-ecf-card" data-bloco={bloco.chave} data-aberto={aberto ? '1' : '0'}>
            <button type="button" onClick={() => setAberto(! aberto)} aria-expanded={aberto} data-acao="alternar-bloco"
                className="flex w-full items-center gap-3 px-3 py-3 text-left hover:bg-white/[0.02] sm:px-4 sm:pr-20">
                <span className={cn('h-2.5 w-2.5 shrink-0 rounded-full', COR_PIOR[piorCaso(bloco.resumo_bloco)])} aria-hidden />
                <FotoProduto url={bloco.foto} />
                <span className="min-w-0 flex-1">
                    <span className="block truncate font-mono text-[14px] font-semibold text-white" data-titulo-bloco>{bloco.principal.sku}</span>
                    {bloco.principal.nome && <span className="block truncate text-[12.5px] text-white/55">{bloco.principal.nome}</span>}
                    <span className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-[12px] md:hidden" data-resumo>
                        <ContagemProduto resumo={bloco.resumo_bloco} />
                        {bloco.vendas > 0 && <Vendas quantidade={bloco.vendas} />}
                        <EstoqueOferta estoque={bloco.estoque} />
                        <PilulaProduto resumo={bloco.resumo_bloco} />
                    </span>
                </span>
                <span className="hidden w-64 shrink-0 text-[12.5px] md:block" data-resumo>
                    <span className="block"><ContagemProduto resumo={bloco.resumo_bloco} /></span>
                    <span className="block text-[11.5px]"><Composicao bloco={bloco} /></span>
                    {(bloco.vendas > 0 || bloco.estoque) && (
                        <span className="block text-[11.5px]">
                            {bloco.vendas > 0 && <Vendas quantidade={bloco.vendas} />}
                            {bloco.vendas > 0 && bloco.estoque && ' · '}
                            <EstoqueOferta estoque={bloco.estoque} />
                        </span>
                    )}
                </span>
                <span className="hidden md:inline-flex"><PilulaProduto resumo={bloco.resumo_bloco} /></span>
                <ChevronRight size={16} className={cn('shrink-0 text-white/30 transition-transform', aberto && 'rotate-90')} />
            </button>
            {/* Abrir a estação direto do produto fechado — sem expandir e clicar na linha. */}
            <button type="button" onClick={() => onAbrir(bloco.principal.id)} title="Abrir a estação do produto" aria-label="Abrir a estação do produto"
                className="absolute right-11 top-1/2 hidden -translate-y-1/2 rounded-lg p-1.5 text-white/35 hover:bg-white/[0.06] hover:text-ecf-yellow sm:block" data-acao="abrir-estacao">
                <Maximize2 size={15} />
            </button>

            {aberto && (
                <div className="border-t border-white/[0.06] px-1 pb-2 sm:px-2">
                    {simples.length > 0 && (
                        <Grupo titulo="Simples">{simples.map((o) => <LinhaOferta key={o.id} oferta={o} {...props} />)}</Grupo>
                    )}
                    {combos.length > 0 && (
                        <Grupo titulo="Combos">{combos.map((o) => <LinhaOferta key={o.id} oferta={o} {...props} />)}</Grupo>
                    )}
                    <div className="flex flex-wrap items-center justify-between gap-2 px-3 pt-1">
                        <span className="text-[11.5px] text-white/40" title={bloco.tambem_em.map((k) => k.nome || k.sku).join(' · ')}>
                            {bloco.tambem_em.length > 0 && `Também entra em: ${bloco.tambem_em.map((k) => k.nome || k.sku).join(' · ')}`}
                        </span>
                        <Botao variante="fantasma" className="px-2 py-1 text-[12px]" onClick={() => onVariacao(principalCompleto, bloco.quantidades_combo)} data-acao="nova-variacao">
                            <Plus size={13} /> Variação (combo, kit)
                        </Botao>
                    </div>
                </div>
            )}
        </section>
    );
}

/** Kits e combits: uma seção só, também recolhida, com o resumo de TODOS. */
function SecaoKits({ blocos, resumo, porFase, abertoInicial, onAbrir, onAnuncio, onAgendar, vocabulario }) {
    const [aberto, setAberto] = useState(abertoInicial);
    const pendentes = resumo.falta + resumo.publicar;

    return (
        <section className="overflow-hidden rounded-2xl border border-white/[0.08] bg-ecf-card" data-secao-kits data-aberto={aberto ? '1' : '0'}>
            <button type="button" onClick={() => setAberto(! aberto)} aria-expanded={aberto} data-acao="alternar-kits"
                className="flex w-full items-center gap-3 px-3 py-3 text-left hover:bg-white/[0.02] sm:px-4">
                <span className={cn('h-2.5 w-2.5 shrink-0 rounded-full', COR_PIOR[piorCaso(resumo)])} aria-hidden />
                <span className="min-w-0 flex-1">
                    <span className="block text-[14px] font-semibold text-white">Kits e combits</span>
                    <span className="block text-[12px] text-white/45" data-resumo>
                        {porFase.kit} {porFase.kit === 1 ? 'kit' : 'kits'} · {porFase.combit} {porFase.combit === 1 ? 'combit' : 'combits'}
                        {' · '}<ContagemProduto resumo={{ ...resumo, pendentes }} />
                    </span>
                </span>
                <span className="hidden md:inline-flex"><PilulaProduto resumo={{ ...resumo, pendentes, unica_situacao: null }} /></span>
                <ChevronRight size={16} className={cn('shrink-0 text-white/30 transition-transform', aberto && 'rotate-90')} />
            </button>
            {aberto && (
                <ul className="border-t border-white/[0.06] px-1 py-2 sm:px-2">
                    {blocos.flatMap((b) => b.ofertas).map((o) => (
                        <LinhaOferta key={o.id} oferta={o} onAbrir={onAbrir} onAnuncio={onAnuncio} onAgendar={onAgendar} vocabulario={vocabulario}
                            rodape={o.componentes.map((c) => `${c.nome ?? c.sku} ×${c.quantidade}`).join(' + ')} />
                    ))}
                </ul>
            )}
        </section>
    );
}

/** Nada cadastrado ainda: o Mapeamento é o pós-publicação — o começo é a Lista SKUs. */
function EstadoVazio() {
    return (
        <section className="rounded-2xl border border-dashed border-white/[0.12] p-6 text-center space-y-3" data-vazio>
            <p className="text-white text-[15px] font-semibold">Nada para acompanhar ainda</p>
            <p className="text-white/50 text-[13px] max-w-lg mx-auto leading-relaxed">
                O Mapeamento mostra a situação de cada oferta depois de publicada. Comece listando os seus produtos.
            </p>
            <Link href={route('portal.auth.estrutura.lista')} className="inline-flex items-center gap-1.5 rounded-xl bg-ecf-yellow px-4 py-2.5 text-[13px] font-semibold text-black hover:bg-ecf-yellow/90" data-acao="ir-lista">
                Ir para a Lista SKUs <ArrowRight size={14} />
            </Link>
        </section>
    );
}

export default function EstruturaMapeamento({ empresa, modulos = [], estrutura, filtros, vocabulario, ml_conectado = false, opcoes_ofertas }) {
    const [estacaoId, setEstacaoId] = useState(null);       // a oferta que abre a estação do produto
    const [formOferta, setFormOferta] = useState(null);     // { modo, base, inicial }
    const [formAnuncio, setFormAnuncio] = useState(null);   // { oferta, anuncio, tipoFixo, viaAgenda }
    const [agendar, setAgendar] = useState(null);           // oferta
    const [variacao, setVariacao] = useState(null);         // { base, existentes }
    const [aula, setAula] = useState(false);
    const [busca, setBusca] = useState(filtros.q ?? '');
    const [autoMetricas, setAutoMetricas] = useState(false);

    const { painel, contadores, blocos, paginacao } = estrutura;

    // `?abrir=<id>`: a agenda aponta para a oferta — a lista chega filtrada
    // pelo SKU e a estação já aberta. Tirado da URL depois, para um F5 não
    // reabrir.
    useEffect(() => {
        const url = new URL(window.location.href);
        const id = Number(url.searchParams.get('abrir'));
        if (id) {
            setEstacaoId(id);
            // Vindo do "Ver métricas" da Jardinagem: a estação já abre lendo as métricas.
            setAutoMetricas(url.searchParams.get('metricas') === '1');
            url.searchParams.delete('abrir');
            url.searchParams.delete('metricas');
            // Depois do Inertia gravar o estado inicial (ele regrava a URL logo
            // após a montagem): sem o adiamento, a limpeza era desfeita.
            setTimeout(() => window.history.replaceState(window.history.state, '', url), 100);
        }
    }, []);

    const visitar = (params) => router.get(route('portal.auth.estrutura.mapeamento'), params, {
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
    const editarOferta = (o) => {
        if (o.fase === 'kit' || o.fase === 'combit') carregarOpcoes();
        setFormOferta({ modo: 'editar', base: o });
    };

    const fecharFormOferta = () => setFormOferta(null);

    // Sem filtro, tudo recolhido — salvo quem tem pouca coisa: com até 3
    // produtos a tela abre respirada, sem clique para ver o buraco. Com filtro
    // ou busca, o que casou abre. A chave remonta os blocos quando o filtro muda.
    const filtroAtivo = (filtros.situacao ?? 'todas') !== 'todas' || !! filtros.q;
    const abertoInicial = filtroAtivo || paginacao.blocos <= 3;
    const chaveFiltro = `${filtros.situacao ?? 'todas'}|${filtros.q ?? ''}`;
    const blocosProduto = blocos.filter((b) => b.produto);
    const blocosKits = blocos.filter((b) => ! b.produto);
    const filtroAtual = filtros.situacao ?? 'todas';

    const acoes = {
        vocabulario,
        onAbrir: setEstacaoId,
        onAgendar: setAgendar,
        onAnuncio: (oferta, tipoFixo) => setFormAnuncio({ oferta, tipoFixo }),
    };

    return (
        <PortalClienteLayout empresa={empresa} modulos={modulos} titulo="Mapeamento Estrutural">
            <div className="mx-auto max-w-7xl space-y-4 px-4 py-6">
                <CabecalhoEstrutura etapa="mapeamento" onComoFunciona={() => setAula(true)}
                    descricao="Depois de publicar: a situação de cada oferta, as métricas de cada anúncio e a Jardinagem." />

                <ResumoOperacional painel={painel} contagem={estrutura.agenda.contagem} />

                {painel.ofertas === 0 ? (
                    <EstadoVazio />
                ) : (
                    <div className="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_300px] xl:grid-cols-[minmax(0,1fr)_320px]">
                        <div className="min-w-0 space-y-4">
                            <ProximoPasso passo={estrutura.proximo_passo} />

                            <div className="flex flex-wrap items-center gap-2">
                                <div className="hidden flex-wrap gap-2 sm:flex" role="tablist" aria-label="Situação">
                                    {FILTROS.map((f) => (
                                        <button key={f.chave} type="button" role="tab" aria-selected={filtroAtual === f.chave}
                                            onClick={() => visitar({ situacao: f.chave, q: busca || undefined })} data-filtro={f.chave}
                                            className={cn('rounded-full border px-3.5 py-1.5 text-[12.5px] transition-colors',
                                                filtroAtual === f.chave
                                                    ? 'border-ecf-yellow bg-ecf-yellow font-semibold text-black'
                                                    : 'border-white/[0.10] text-white/65 hover:border-white/[0.2] hover:text-white')}>
                                            {f.rotulo} <span className={filtroAtual === f.chave ? 'text-black/60' : 'text-white/35'}>{contadores[f.chave]}</span>
                                        </button>
                                    ))}
                                </div>
                                {/* No celular os quatro filtros viram um seletor — os mesmos, do servidor. */}
                                <select value={filtroAtual} onChange={(e) => visitar({ situacao: e.target.value, q: busca || undefined })}
                                    className="rounded-xl border border-white/[0.10] bg-white/[0.04] px-3 py-2 text-[13px] text-white sm:hidden [&>option]:bg-ecf-card"
                                    aria-label="Filtrar por situação" data-filtro-celular>
                                    {FILTROS.map((f) => <option key={f.chave} value={f.chave}>{f.rotulo} ({contadores[f.chave]})</option>)}
                                </select>
                                <div className="relative min-w-[180px] flex-1">
                                    <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-white/30" />
                                    <input value={busca} onChange={(e) => setBusca(e.target.value)} placeholder="Buscar SKU, nome ou MLB…"
                                        className="w-full rounded-xl border border-white/[0.10] bg-white/[0.04] py-2 pl-8 pr-8 text-[13px] text-white placeholder:text-white/30 focus:border-ecf-yellow/40 focus:outline-none focus:ring-0"
                                        data-busca />
                                    {busca && (
                                        <button type="button" onClick={() => setBusca('')} className="absolute right-2.5 top-1/2 -translate-y-1/2 text-white/35 hover:text-white" aria-label="Limpar busca">
                                            <X size={14} />
                                        </button>
                                    )}
                                </div>
                            </div>

                            {blocos.length === 0 && (
                                <p className="py-10 text-center text-[13px] text-white/45">Nenhuma oferta com este filtro.</p>
                            )}

                            <div className="space-y-2.5">
                                {blocosProduto.map((b) => (
                                    <BlocoProduto key={`${b.chave}-${chaveFiltro}`} bloco={b} abertoInicial={abertoInicial} {...acoes}
                                        onVariacao={(base, existentes) => setVariacao({ base, existentes })} />
                                ))}
                                {blocosKits.length > 0 && (
                                    <SecaoKits key={`kits-${chaveFiltro}`} blocos={blocosKits} resumo={estrutura.resumo_kits} porFase={painel.por_fase}
                                        abertoInicial={abertoInicial} {...acoes} />
                                )}
                            </div>

                            {paginacao.paginas > 1 && (
                                <Paginacao paginacao={paginacao} onIr={(pagina) => visitar({ situacao: filtros.situacao, q: busca || undefined, pagina })} />
                            )}
                        </div>

                        <div className="hidden lg:sticky lg:top-4 lg:block">
                            <AgendaLateral agenda={estrutura.agenda} aPublicar={painel.a_publicar} vocabulario={vocabulario}
                                onConcluir={(oferta, tipo) => setFormAnuncio({ oferta, tipoFixo: tipo, viaAgenda: true })} />
                        </div>
                    </div>
                )}
            </div>

            {/* A estação relê a família a cada escrita: `versao` muda quando o
                Inertia recarrega `estrutura`. Os formulários abrem por cima dela. */}
            <EstacaoProduto
                ofertaId={estacaoId}
                versao={estrutura}
                onFechar={() => { setEstacaoId(null); setAutoMetricas(false); }}
                onTrocar={(id) => { setAutoMetricas(false); setEstacaoId(id); }}
                vocabulario={vocabulario}
                mlConectado={ml_conectado}
                autoMetricas={autoMetricas}
                acoes={{
                    editarOferta,
                    novoAnuncio: (o, tipoFixo) => setFormAnuncio({ oferta: o, tipoFixo }),
                    editarAnuncio: (o, a) => setFormAnuncio({ oferta: o, anuncio: a }),
                    agendar: setAgendar,
                    variacao: (base, existentes) => setVariacao({ base, existentes }),
                    concluir: (o, tipo) => setFormAnuncio({ oferta: o, tipoFixo: tipo, viaAgenda: true }),
                }}
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
                mlConectado={ml_conectado}
            />

            <FormAnuncio
                aberta={!! formAnuncio}
                onFechar={() => setFormAnuncio(null)}
                oferta={formAnuncio?.oferta}
                anuncio={formAnuncio?.anuncio ?? null}
                tipoFixo={formAnuncio?.tipoFixo ?? null}
                viaAgenda={!! formAnuncio?.viaAgenda}
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

            <ComoFunciona aberta={aula} onFechar={() => setAula(false)} />

            <AvisoFlash />
        </PortalClienteLayout>
    );
}
