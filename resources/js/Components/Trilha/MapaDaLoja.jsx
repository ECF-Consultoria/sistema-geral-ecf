import { useEffect, useId, useMemo, useRef, useState } from 'react';
import { ExternalLink, Waves } from 'lucide-react';
import { cn } from '@/lib/utils';
import {
    cabecalhosDoMapa,
    caixaDoNo,
    corta,
    legendaDoMapa,
    ligacaoAcesa,
    montarGrafo,
    posicionar,
    relacionados,
    resumoDoFluxo,
} from '@/lib/trilhaMapa';

// ─── Mapa da loja — a loja inteira numa imagem ──────────────────────────────
//
// A aba "Mapa da loja" da trilha (port de `painelMapa()` do protótipo do Erlon):
// cada produto da lista liga às ofertas que gerou, e cada oferta aos anúncios
// que virou. A cor da caixa é a da etapa em que a coisa está agora. Escolher
// uma caixa acende de onde ela veio e o que ela gerou; o painel ao lado lista
// esses itens e leva de volta à trilha ("Abrir na trilha" → `onAbrir`).
//
// O grafo, as posições e o que acende vêm de `lib/trilhaMapa.js` (puro, com
// teste); aqui só se desenha. As cores das caixas e linhas vêm de lá como
// `rgb(var(--t-…))` e entram por `style` — no SVG, `var()` em atributo de
// apresentação não é garantido em todo navegador; em `style`, é.
//
// A `visao` decide o que o texto mostra (o MLB só para a equipe) e chega daqui
// mesmo quando a equipe usa o "Ver como: Cliente": o dado ainda é o da equipe,
// e quem tira o MLB é o `montarGrafo`.

const VAZIO = [];

/**
 * @param {{
 *   dados: {produtos?: Array, ofertas?: Array, ondas?: Array},
 *   visao?: 'equipe'|'cliente',
 *   onAbrir?: (chave: string) => void,
 *   trabalho?: boolean,
 * }} props  `trabalho`: a trilha de trabalho está ligada (as ondas existem) — o aviso "Ainda não há ondas" sai.
 */
export default function MapaDaLoja({ dados, visao = 'equipe', onAbrir, trabalho = false }) {
    const [familia, setFamilia] = useState(null);
    const [escolhido, setEscolhido] = useState(null);
    const idDoTitulo = useId();
    const caixas = useRef(new Map());
    const tituloDoDetalhe = useRef(null);
    // Para onde o foco vai depois da troca: 'detalhe' (escolheu no painel) ou o id
    // da caixa (Limpar). O botão clicado some da tela nos dois casos.
    const focoDepois = useRef(null);

    const produtos = dados?.produtos ?? VAZIO;
    const ofertas = dados?.ofertas ?? VAZIO;
    const ondas = dados?.ondas ?? VAZIO;
    const base = useMemo(() => ({ produtos, ofertas, ondas }), [produtos, ofertas, ondas]);
    const grafo = useMemo(() => montarGrafo(base, { visao, familia }), [base, visao, familia]);
    const desenho = useMemo(() => posicionar(grafo), [grafo]);
    const fluxo = useMemo(() => resumoDoFluxo(base), [base]);
    const cabecalhos = useMemo(() => cabecalhosDoMapa(grafo), [grafo]);
    // A caixa escolhida pode ter saído do desenho (filtro, dado novo): aí nada está escolhido.
    const sel = escolhido && desenho.pos[escolhido] ? escolhido : null;
    const acesos = useMemo(() => relacionados(grafo, sel), [grafo, sel]);

    useEffect(() => {
        const alvo = focoDepois.current;
        focoDepois.current = null;
        if (alvo === 'detalhe') tituloDoDetalhe.current?.focus();
        else if (alvo) caixas.current.get(alvo)?.focus();
    }, [sel]);

    // Clicar de novo na caixa escolhida volta a mostrar tudo (como no protótipo).
    const alternar = (id) => setEscolhido(sel === id ? null : id);
    const escolherNoPainel = (id) => {
        focoDepois.current = 'detalhe';
        setEscolhido(id);
    };
    const limpar = () => {
        focoDepois.current = sel;
        setEscolhido(null);
    };
    const trocarFamilia = (nome) => {
        setFamilia(nome);
        setEscolhido(null);
    };
    const registrar = (id) => (el) => {
        if (el) caixas.current.set(id, el);
        else caixas.current.delete(id);
    };

    const temItens = produtos.length > 0 || ofertas.length > 0;
    const comOndas = grafo.temOndas;

    return (
        <section
            aria-labelledby={idDoTitulo}
            className="min-w-0 rounded-[14px] border border-trilha-line bg-trilha-surface"
            onKeyDown={(ev) => {
                if (ev.key === 'Escape' && sel) limpar();
            }}
        >
            <div className="flex flex-wrap items-start justify-between gap-x-4 gap-y-3 border-b border-trilha-line px-4 py-4 sm:px-[18px]">
                <div className="min-w-0">
                    {/* Inter, entrelinha e espaçamento explícitos: o app.css põe h1–h5 em Manrope 800, 1.08. */}
                    <h2 id={idDoTitulo} className="font-sans text-[16px] font-bold leading-snug tracking-normal text-trilha-fg">
                        Mapa da loja
                    </h2>
                    <p className="mt-1 max-w-[72ch] text-[13px] text-trilha-fg2">
                        A loja inteira numa imagem: cada produto da lista, as ofertas que ele gerou no mapeamento (simples, combo, kit e
                        combit){comOndas ? ', a onda em que elas entraram' : ''} e os anúncios que elas já viraram. A cor diz em que etapa
                        cada coisa está agora.
                    </p>
                </div>
                {grafo.familias.length > 1 && <FiltroDeFamilia familias={grafo.familias} atual={grafo.familia} onTrocar={trocarFamilia} />}
            </div>

            <FaixaDoFluxo passos={fluxo} />

            {!temItens ? (
                <p className="px-4 py-[30px] text-center text-trilha-muted sm:px-[18px]">
                    A loja ainda não tem produtos na lista nem ofertas: nada para desenhar por enquanto.
                </p>
            ) : (
                <div className="grid grid-cols-1 min-[1000px]:grid-cols-[minmax(0,1fr)_300px]">
                    <figure className="m-0 min-w-0 border-b border-trilha-line px-4 pb-4 pt-3.5 sm:px-[18px] min-[1000px]:border-b-0 min-[1000px]:border-r">
                        {!comOndas && !trabalho && (
                            <p
                                data-aviso-ondas
                                className="mb-3 flex items-start gap-2 rounded-lg border border-dashed border-trilha-line bg-trilha-surface2 px-3 py-2 text-[12.5px] text-trilha-fg2"
                            >
                                <Waves size={14} className="mt-0.5 shrink-0 text-trilha-s-plano" aria-hidden="true" />
                                <span>
                                    Ainda não há ondas: cada oferta liga direto aos seus anúncios. Quando o Planejamento tiver ondas, elas
                                    entram aqui, entre as ofertas e os anúncios.
                                </span>
                            </p>
                        )}

                        {/* A figura tem largura mínima: no celular ela rola aqui dentro, e a página não. */}
                        <div className="overflow-x-auto">
                            <div style={{ minWidth: desenho.minimo }}>
                                <div className="relative h-[38px]">
                                    {cabecalhos.map((c, i) => (
                                        <div
                                            key={c.titulo}
                                            className="absolute top-0 flex flex-col text-[12px]"
                                            style={{
                                                left: `${(desenho.colunas[i].x / desenho.largura) * 100}%`,
                                                width: `${(desenho.colunas[i].w / desenho.largura) * 100}%`,
                                            }}
                                        >
                                            <b className="text-[13px] text-trilha-fg">{c.titulo}</b>
                                            <span className="text-trilha-muted">{c.detalhe}</span>
                                        </div>
                                    ))}
                                </div>

                                <svg
                                    viewBox={`0 0 ${desenho.largura} ${desenho.altura}`}
                                    role="group"
                                    aria-label={`Mapa da loja: cada produto da lista liga às ofertas que gerou${comOndas ? ', às ondas do planejamento' : ''} e aos anúncios delas.`}
                                    data-colunas={grafo.colunas}
                                    className="block h-auto w-full"
                                >
                                    <g aria-hidden="true">
                                        {desenho.rotulos.map((r) => (
                                            <text
                                                key={`${r.texto}-${r.x}-${r.y}`}
                                                x={r.x}
                                                y={r.y}
                                                className="fill-trilha-muted font-pub-mono text-[10.5px] font-bold tracking-[.08em]"
                                            >
                                                {r.texto}
                                            </text>
                                        ))}
                                    </g>
                                    <g aria-hidden="true">
                                        {desenho.ligacoes.map((l) => (
                                            <Ligacao key={`${l.de}>${l.para}`} ligacao={l} acesos={acesos} />
                                        ))}
                                    </g>
                                    <g>
                                        {desenho.nos.map((n) => (
                                            <NoDoMapa
                                                key={n.id}
                                                no={n}
                                                aceso={!acesos || acesos.has(n.id)}
                                                escolhido={n.id === sel}
                                                onAlternar={alternar}
                                                registrar={registrar(n.id)}
                                            />
                                        ))}
                                    </g>
                                </svg>
                            </div>
                        </div>

                        <figcaption className="mt-2 text-[12.5px] text-trilha-muted">
                            Linhas: de qual produto saiu cada oferta (×6 = 6 unidades){comOndas ? ', em que onda ela está' : ''} e quais
                            anúncios ela virou.
                        </figcaption>
                    </figure>

                    {/* No computador o painel acompanha a rolagem: o mapa de uma loja grande é bem mais alto que a tela. */}
                    <aside
                        aria-label="Detalhes do mapa"
                        className="flex min-w-0 flex-col gap-3.5 px-4 py-4 sm:px-[18px] min-[1000px]:sticky min-[1000px]:top-4 min-[1000px]:max-h-[calc(100vh-2rem)] min-[1000px]:self-start min-[1000px]:overflow-y-auto"
                    >
                        <div aria-live="polite" className="flex min-w-0 flex-col gap-3.5">
                            <DetalheDoMapa
                                desenho={desenho}
                                sel={sel}
                                acesos={acesos}
                                onEscolher={escolherNoPainel}
                                onAbrir={onAbrir}
                                onLimpar={limpar}
                                tituloRef={tituloDoDetalhe}
                            />
                        </div>
                        <Legenda legenda={legendaDoMapa(comOndas)} />
                    </aside>
                </div>
            )}
        </section>
    );
}

/** "Todas" + uma por família, com quantos produtos cada uma tem. Quebra linha em vez de rolar a página. */
function FiltroDeFamilia({ familias, atual, onTrocar }) {
    const total = familias.reduce((t, f) => t + f.produtos, 0);
    const botao = (valor, rotulo, quantidade) => {
        const ligado = (atual ?? null) === valor;

        return (
            <button
                key={valor ?? ''}
                type="button"
                aria-pressed={ligado}
                onClick={() => onTrocar(valor)}
                className={cn(
                    'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[12.5px] font-semibold focus-visible:[outline-style:solid] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-trilha-run',
                    ligado
                        ? 'border-trilha-navy bg-trilha-navy text-trilha-on-navy'
                        : 'border-trilha-line bg-trilha-surface text-trilha-fg2 hover:border-trilha-muted',
                )}
            >
                {rotulo}
                <span className={cn('tabular-nums', ligado ? 'text-trilha-on-navy/70' : 'text-trilha-muted')}>{quantidade}</span>
            </button>
        );
    };

    return (
        <div role="group" aria-label="Família" className="flex max-w-full flex-wrap gap-1.5">
            {botao(null, 'Todas', total)}
            {familias.map((f) => botao(f.nome, f.nome, f.produtos))}
        </div>
    );
}

/** Lista → Mapeamento → Planejamento → Agente → Revisão → No ar, com os números da loja inteira. */
function FaixaDoFluxo({ passos }) {
    return (
        <ol
            aria-label="O fluxo da loja"
            className="flex flex-wrap items-stretch gap-x-1 gap-y-1.5 border-b border-trilha-line px-4 py-3.5 sm:px-[18px]"
        >
            {passos.map((p, i) => (
                <li key={p.chave} className="flex items-stretch gap-1" data-fluxo={p.chave}>
                    <div className="relative flex min-w-[118px] flex-col gap-0.5 rounded-[10px] border border-trilha-line px-3 py-2">
                        <span className="absolute bottom-2 left-0 top-2 w-[3px] rounded-sm" style={{ background: p.cor }} aria-hidden="true" />
                        <span className="text-[12.5px] text-trilha-muted">{p.titulo}</span>
                        <b className="text-[20px] font-bold tabular-nums leading-tight text-trilha-fg">
                            {p.numero} <small className="text-[12px] font-semibold text-trilha-muted">{p.unidade}</small>
                        </b>
                        {p.detalhe && <span className="text-[12.5px] text-trilha-muted">{p.detalhe}</span>}
                    </div>
                    {i < passos.length - 1 && (
                        <span className="self-center text-[16px] text-trilha-muted" aria-hidden="true">
                            →
                        </span>
                    )}
                </li>
            ))}
        </ol>
    );
}

/** A linha entre duas caixas (curva do protótipo) e o "×N" de quantas unidades do produto a oferta leva. */
function Ligacao({ ligacao: l, acesos }) {
    // Com algo escolhido, a linha onda → anúncio de OUTRA oferta some de vez: da mesma
    // onda sairiam dezenas, e nem apagadas elas deixariam ver o caminho escolhido.
    if (acesos && l.via && !acesos.has(l.via)) return null;
    const acesa = ligacaoAcesa(l, acesos);
    let opacidade = 0.38;
    if (acesos) opacidade = acesa ? 0.95 : 0.06;

    return (
        <>
            <path d={l.d} fill="none" style={{ stroke: l.cor }} strokeWidth={acesa ? 2.4 : 1.4} strokeOpacity={opacidade} />
            {l.quantidade > 1 && (!acesos || acesa) && (
                <text x={l.x2 - 8} y={l.y2 - 4} textAnchor="end" className="fill-trilha-fg2 font-pub-mono text-[10.5px] font-bold">
                    {`×${l.quantidade}`}
                </text>
            )}
        </>
    );
}

/**
 * Uma caixa do mapa. É um botão de verdade para o teclado (Tab chega, Enter e
 * Espaço escolhem) e alterna: `aria-pressed` diz se é a escolhida. Exportada
 * para o teste do teclado.
 */
export function NoDoMapa({ no, aceso, escolhido, onAlternar, registrar }) {
    const caixa = caixaDoNo(no);
    const alta = no.h > 40;
    const tecla = (ev) => {
        if (ev.key === 'Enter' || ev.key === ' ') {
            ev.preventDefault();
            onAlternar(no.id);
        }
    };

    return (
        <g
            ref={registrar}
            data-no={no.id}
            role="button"
            tabIndex={0}
            aria-pressed={escolhido}
            aria-label={no.rotulo}
            opacity={aceso ? 1 : 0.18}
            onClick={() => onAlternar(no.id)}
            onKeyDown={tecla}
            className="group cursor-pointer outline-none transition-opacity duration-150"
        >
            <title>{no.rotulo}</title>
            <rect
                x={no.x}
                y={no.y}
                width={no.w}
                height={no.h}
                rx={9}
                strokeDasharray={no.tracejado ? '4 3' : undefined}
                className={cn(
                    'fill-trilha-surface stroke-trilha-line group-hover:stroke-trilha-muted group-focus-visible:stroke-trilha-run group-focus-visible:stroke-2',
                    escolhido && 'stroke-trilha-fg stroke-2',
                )}
            />
            <rect x={no.x} y={no.y} width={4} height={no.h} rx={2} style={{ fill: no.cor }} />
            {no.sigla && (
                <>
                    <rect x={no.x + 8} y={no.y + 9} width={caixa.larguraDoSelo} height={16} rx={4} style={{ fill: no.cor, fillOpacity: 0.16 }} />
                    <text x={no.x + 12} y={no.y + 21} className="font-pub-mono text-[10px] font-bold" style={{ fill: no.cor }}>
                        {no.sigla}
                    </text>
                </>
            )}
            <text x={no.x + caixa.recuo} y={no.y + (alta ? 20 : 15)} className="fill-trilha-fg text-[12px] font-semibold">
                {caixa.titulo}
            </text>
            <text x={no.x + caixa.recuo} y={no.y + (alta ? 36 : 28)} className="fill-trilha-muted font-pub-mono text-[10.5px] font-medium">
                {caixa.sub}
            </text>
            {no.tipo === 'onda' && (
                <text x={no.x + caixa.recuo} y={no.y + 48} className="fill-trilha-muted font-pub-mono text-[10.5px] font-medium">
                    {no.situacao}
                </text>
            )}
        </g>
    );
}

/**
 * O painel ao lado do mapa: sem escolha, a instrução; com uma caixa escolhida,
 * ela, as listas do que está ligado a ela (cada item escolhe aquele item) e os
 * botões "Abrir na trilha" e "Limpar". Exportado para o teste de render.
 */
export function DetalheDoMapa({ desenho, sel, acesos, onEscolher, onAbrir, onLimpar, tituloRef }) {
    const no = sel ? desenho.pos[sel] : null;
    if (!no) {
        return (
            <p className="m-0 text-[12.5px] text-trilha-muted">
                Clique em qualquer caixa para ver de onde ela veio e o que ela gerou. Clique de novo (ou em “Limpar”) para ver tudo.
            </p>
        );
    }

    const ligados = [...(acesos ?? [])]
        .map((id) => desenho.pos[id])
        .filter((n) => n && n.id !== sel)
        .sort((a, b) => a.y - b.y);
    const doTipo = (tipo) => ligados.filter((n) => n.tipo === tipo);

    // O anúncio não tem linha própria na trilha: leva à oferta dele. A onda abre o painel dela (o id do nó é a
    // chave da tela, `w:7`); "Sem onda", "Começou sem onda" e "Fora das ondas" não são ondas de verdade e não abrem nada.
    let abrir = null;
    if (no.tipo === 'produto' || no.tipo === 'oferta') abrir = { chave: no.id, rotulo: 'Abrir na trilha' };
    else if (no.tipo === 'anuncio') abrir = { chave: no.origem, rotulo: 'Abrir a oferta na trilha' };
    else if (no.tipo === 'onda' && !no.pseudo) abrir = { chave: no.id, rotulo: 'Abrir a onda' };

    return (
        <>
            <div className="flex items-start gap-2.5">
                <i className="mt-[5px] h-2.5 w-2.5 shrink-0 rounded-[3px]" style={{ background: no.cor }} aria-hidden="true" />
                <div className="min-w-0">
                    <h3
                        ref={tituloRef}
                        tabIndex={-1}
                        className="font-sans text-[14px] font-bold leading-snug tracking-normal text-trilha-fg outline-none [overflow-wrap:anywhere]"
                    >
                        {no.titulo}
                    </h3>
                    <div className="text-[12.5px] text-trilha-muted">
                        {no.tipoDeOferta && <>{no.tipoDeOferta} · </>}
                        <span className="font-pub-mono">{no.sub}</span> · {no.situacao}
                    </div>
                    {no.status && (
                        <div className={cn('mt-1 text-[12.5px]', no.alerta ? 'font-semibold text-trilha-warn' : 'text-trilha-fg2')}>{no.status}</div>
                    )}
                    {no.tipo === 'oferta' && no.semProduto && (
                        <span className="mt-1.5 inline-flex rounded-full bg-trilha-surface2 px-2 py-0.5 text-[11.5px] font-semibold text-trilha-fg2">
                            sem produto na lista
                        </span>
                    )}
                </div>
            </div>

            <ListaDoDetalhe titulo="Produtos da lista" nos={doTipo('produto')} onEscolher={onEscolher} />
            <ListaDoDetalhe titulo="Ofertas" nos={doTipo('oferta')} onEscolher={onEscolher} />
            <ListaDoDetalhe titulo="Ondas" nos={doTipo('onda')} onEscolher={onEscolher} />
            <ListaDoDetalhe titulo="Anúncios" nos={doTipo('anuncio')} onEscolher={onEscolher} />

            <div className="flex flex-wrap gap-2">
                {abrir && onAbrir && (
                    <button
                        type="button"
                        data-acao="abrir-na-trilha"
                        onClick={() => onAbrir(abrir.chave)}
                        className="inline-flex items-center gap-1.5 whitespace-nowrap rounded-[9px] border border-trilha-navy bg-trilha-navy px-3 py-[7px] text-[13px] font-semibold text-trilha-on-navy focus-visible:[outline-style:solid] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-trilha-run"
                    >
                        {abrir.rotulo}
                    </button>
                )}
                {/* O anúncio publicado (só a equipe recebe o link): abre o anúncio em outra aba. */}
                {no.tipo === 'anuncio' && no.link && (
                    <a
                        href={no.link}
                        target="_blank"
                        rel="noopener noreferrer"
                        aria-label={no.rotuloDoLink}
                        data-acao="abrir-o-anuncio"
                        className="inline-flex items-center gap-1.5 whitespace-nowrap rounded-[9px] border border-trilha-line bg-trilha-surface px-3 py-[7px] text-[13px] font-semibold text-trilha-fg hover:border-trilha-muted focus-visible:[outline-style:solid] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-trilha-run"
                    >
                        <ExternalLink size={14} aria-hidden="true" />
                        Abrir o anúncio
                    </a>
                )}
                <button
                    type="button"
                    data-acao="limpar-mapa"
                    onClick={onLimpar}
                    className="inline-flex items-center gap-1.5 whitespace-nowrap rounded-[9px] border border-trilha-line bg-trilha-surface px-3 py-[7px] text-[13px] font-semibold text-trilha-fg hover:border-trilha-muted focus-visible:[outline-style:solid] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-trilha-run"
                >
                    Limpar
                </button>
            </div>
        </>
    );
}

function ListaDoDetalhe({ titulo, nos, onEscolher }) {
    if (!nos.length) return null;

    return (
        <div className="flex flex-col gap-1">
            <b className="text-[12px] font-semibold text-trilha-muted">{titulo}</b>
            {nos.map((n) => (
                <button
                    key={n.id}
                    type="button"
                    onClick={() => onEscolher(n.id)}
                    className="grid grid-cols-[10px_minmax(0,1fr)] gap-x-2 gap-y-0.5 rounded-lg border border-trilha-line2 px-2 py-1.5 text-left text-[12.5px] text-trilha-fg hover:border-trilha-muted focus-visible:[outline-style:solid] focus-visible:outline-2 focus-visible:outline-trilha-run"
                >
                    <i className="mt-1 h-2 w-2 rounded-[2px]" style={{ background: n.cor }} aria-hidden="true" />
                    <span className="min-w-0 [overflow-wrap:anywhere]">{corta(n.titulo, 46)}</span>
                    <span className="col-start-2 font-pub-mono text-[11px] text-trilha-muted">{corta(n.sub, 30)}</span>
                </button>
            ))}
        </div>
    );
}

function Legenda({ legenda }) {
    const item = ({ rotulo, cor }) => (
        <span key={rotulo} className="flex items-center gap-2 text-[12px] text-trilha-fg2">
            <i className="h-2.5 w-2.5 shrink-0 rounded-[3px]" style={{ background: cor }} aria-hidden="true" />
            {rotulo}
        </span>
    );

    return (
        <div className="flex flex-col gap-1 border-t border-trilha-line pt-3" data-legenda>
            <b className="text-[12.5px] text-trilha-fg">Etapa (cor da caixa)</b>
            {legenda.etapas.map(item)}
            <b className="mt-1.5 text-[12.5px] text-trilha-fg">Anúncio</b>
            {legenda.anuncios.map(item)}
        </div>
    );
}
