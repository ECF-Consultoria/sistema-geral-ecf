import { useEffect, useId, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { ImageOff, MapPin, Maximize2, Search, ShieldCheck, Truck, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Botao } from '@/Components/Trilha/Pecas';
import {
    anuncioDaPagina,
    capaDaVariacao,
    caminhoDaPagina,
    condicaoDaPagina,
    descricaoDaPagina,
    destaquesDaPagina,
    escolhaDaPagina,
    estoqueDaPagina,
    fotosDaPagina,
    freteDaPagina,
    garantiaDaPagina,
    gruposDaPagina,
    miniaturaDaFoto,
    nomeDaEscolha,
    nomeDaVariacao,
    partesDoPreco,
    precoDaPagina,
    textoDasParcelasDaPagina,
    textoDoEstoque,
    tiposDaPagina,
    tituloDaPagina,
    variacoesDaPagina,
    vendedorDaPagina,
} from '@/lib/trilhaPaginaDoAnuncio';

// ─── Trilha de produtos — o anúncio "como o comprador vê", em tela cheia (05/11/2026) ──
//
// O usuário achou a prévia da gaveta "ruim" e pediu a página do item igualzinha à do
// canal de venda, como a do Publicador. A gaveta tem no máximo 560px e a página tem
// três colunas: por isso ela abre em TELA CHEIA, pelo [Ver como o comprador vê] das
// duas portas — a prévia da equipe (`Revisao/PreviaDoAnuncio.jsx`) e a aprovação do
// cliente (`Cliente/AprovarAnuncio.jsx`; o cliente também vê assim, decisão do
// usuário). A gaveta continua com preço, publicar, ajuste e aprovação.
//
// O desenho é CÓPIA da página do Publicador (`Publicador/Mesa/AnuncioNoMl.jsx`): a
// trilha não importa nada de lá (ela vai para outro sistema). As cores são as do
// canal, fixas, nos dois temas: é a página do comprador, não a da trilha. Só a barra
// de cima (o nome da escolha, Clássico/Premium e [Fechar]) usa as cores da trilha.
//
// Nada aqui grava nem compra: só as fotos, a variação e o tipo respondem ao clique.
// A tela vai para o `body` por portal (a gaveta tem animação com `transform`, que
// prenderia o `fixed` dentro dela); o Esc fecha só a tela cheia (a gaveta fica) e o
// Tab gira dentro dela.

const AZUL = 'text-[#3483fa]';
const VERDE = 'text-[#00a650]';
const FOCO = 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#3483fa]';
// Família, peso e entrelinha explícitos: o app.css põe h1–h5 em Manrope 800, 1.08.
const TITULO = 'm-0 font-sans tracking-normal';
const FOCAVEIS = 'a[href], button:not([disabled]), input:not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])';

/**
 * O preço como o comprador lê: "R$ 1.234" com os centavos pequenos no alto; ",00" não aparece.
 */
function Preco({ valor, className, centavos = 'ml-0.5 mt-[0.15em] text-[0.5em] leading-none' }) {
    const p = partesDoPreco(valor);
    if (!p) return null;

    return (
        <span className={cn('inline-flex items-start tabular-nums', className)}>
            <span>R$ {p.inteiro}</span>
            {p.centavos !== '00' && <span className={centavos}>{p.centavos}</span>}
        </span>
    );
}

/** As fotos: as miniaturas à esquerda (na ordem do anúncio, a 1ª é a capa) e a escolhida grande. */
function Galeria({ fotos }) {
    const [ativa, setAtiva] = useState(0);
    if (fotos.length === 0) {
        return (
            <div className="grid aspect-square w-full place-items-center rounded bg-black/[0.03] text-black/40" data-pagina-sem-foto="">
                <span className="flex flex-col items-center gap-2 text-[14px]">
                    <ImageOff size={32} aria-hidden="true" />
                    Sem foto
                </span>
            </div>
        );
    }
    const indice = Math.min(ativa, fotos.length - 1);
    const atual = fotos[indice];

    return (
        <div className="flex gap-3" data-pagina-galeria="">
            <ol className="m-0 flex w-12 shrink-0 list-none flex-col gap-2 p-0" aria-label="Fotos, na ordem do anúncio">
                {fotos.map((foto, i) => (
                    <li key={`${foto.id}-${i}`}>
                        <button
                            type="button"
                            onMouseEnter={() => setAtiva(i)}
                            onFocus={() => setAtiva(i)}
                            onClick={() => setAtiva(i)}
                            aria-label={i === 0 ? 'Foto 1, a capa' : `Foto ${i + 1}`}
                            aria-current={i === indice ? 'true' : undefined}
                            className={cn(
                                'block h-12 w-12 overflow-hidden rounded bg-white',
                                FOCO,
                                i === indice ? 'border-2 border-[#3483fa]' : 'border border-black/15 hover:border-[#3483fa]',
                            )}
                            data-pagina-miniatura={i + 1}
                        >
                            <img src={miniaturaDaFoto(foto)} alt="" loading="lazy" decoding="async" className="h-full w-full object-contain" />
                        </button>
                    </li>
                ))}
            </ol>
            <div className="relative flex min-h-[320px] min-w-0 flex-1 items-start justify-center" data-pagina-foto-grande={atual.id}>
                <img
                    src={atual.url}
                    alt={indice === 0 ? 'Capa do anúncio' : `Foto ${indice + 1} do anúncio`}
                    className="max-h-[500px] w-full object-contain"
                />
                <span className="absolute bottom-1 right-1 rounded-full bg-black/[0.06] px-2 py-0.5 text-[12px] tabular-nums text-black/55" data-pagina-posicao="">
                    {indice + 1}/{fotos.length}
                </span>
            </div>
        </div>
    );
}

/** O frete e a retirada na caixa de compra (prazo e valor só o canal sabe, pelo CEP). */
function Frete({ envio }) {
    const frete = freteDaPagina(envio);

    return (
        <div className="space-y-3">
            <div data-pagina-frete={frete.chave}>
                <p className={cn('m-0 flex items-center gap-2 text-[16px]', frete.gratis ? cn('font-semibold', VERDE) : 'text-black/90')}>
                    <Truck size={18} aria-hidden="true" />
                    {frete.chave === 'comprador' ? <span className={AZUL}>{frete.titulo}</span> : frete.titulo}
                </p>
                <p className={cn('m-0 mt-0.5 text-[14px]', frete.gratis ? AZUL : 'text-black/55')}>{frete.detalhe}</p>
            </div>
            {envio?.retirada === true && (
                <p className="m-0 flex items-center gap-2 text-[14px] text-black/90" data-pagina-retirada="">
                    <MapPin size={16} aria-hidden="true" />
                    Retirada no endereço do vendedor
                </p>
            )}
        </div>
    );
}

/** O seletor de variação como o do canal: "Cor: Natural" e as capas de cada uma. Uma variação só: some. */
function SeletorDaVariacao({ previa, escolha, onEscolha }) {
    const variacoes = variacoesDaPagina(previa, escolha.tipo);
    if (variacoes.length < 2) return null;
    const atual = variacoes.find((v) => v.chave === escolha.variante);

    return (
        <div className="mt-6" data-pagina-variacoes="">
            <p className="m-0 text-[14px] text-black/90">
                {nomeDaVariacao(previa)}: <span className="font-semibold">{atual?.rotulo ?? ''}</span>
            </p>
            <div className="mt-2 flex flex-wrap gap-2">
                {variacoes.map((v) => {
                    const capa = capaDaVariacao(previa, escolha.tipo, v.chave);
                    const marcada = v.chave === escolha.variante;

                    return (
                        <button
                            key={v.chave}
                            type="button"
                            onClick={() => onEscolha({ variante: v.chave })}
                            title={v.rotulo}
                            aria-label={v.rotulo}
                            aria-pressed={marcada}
                            className={cn(
                                'grid h-12 min-w-12 place-items-center overflow-hidden rounded-md bg-white px-1 text-[13px] text-black/90',
                                FOCO,
                                marcada ? 'border-2 border-[#3483fa]' : 'border border-black/20 hover:border-[#3483fa]',
                            )}
                            data-pagina-variacao={v.chave}
                        >
                            {capa ? <img src={miniaturaDaFoto(capa)} alt="" className="h-10 w-10 object-contain" /> : <span className="px-1">{v.rotulo}</span>}
                        </button>
                    );
                })}
            </div>
        </div>
    );
}

/** "Características do produto": a ficha em tabelas, um grupo por bloco, com as linhas em cinza alternado. */
function Caracteristicas({ grupos }) {
    return (
        <section className="border-t border-black/10 px-6 py-8" data-pagina-caracteristicas="">
            <h2 className={cn(TITULO, 'text-[24px] font-normal leading-tight text-black/90')}>Características do produto</h2>
            {grupos.length === 0 ? (
                <p className="m-0 mt-3 text-[14px] text-black/40">Nenhuma característica preenchida.</p>
            ) : (
                <div className="mt-6 grid gap-x-8 gap-y-6 lg:grid-cols-2">
                    {grupos.map((g, gi) => (
                        <div key={`${g.grupo}-${gi}`} data-pagina-grupo={g.grupo}>
                            <h3 className={cn(TITULO, 'mb-3 text-[16px] font-semibold leading-snug text-black/90')}>{g.grupo}</h3>
                            <table className="w-full border-collapse overflow-hidden rounded text-[14px]">
                                <tbody>
                                    {g.itens.map((item, i) => (
                                        <tr key={`${item.nome}-${i}`}>
                                            <th scope="row" className={cn('w-[45%] px-4 py-3 text-left align-top font-semibold text-black/90', i % 2 === 0 ? 'bg-[#ebebeb]' : 'bg-[#f5f5f5]')}>
                                                {item.nome}
                                            </th>
                                            <td className={cn('px-4 py-3 align-top text-black/90 [overflow-wrap:anywhere]', i % 2 === 0 ? 'bg-[#f5f5f5]' : 'bg-white')}>{item.valor}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ))}
                </div>
            )}
        </section>
    );
}

/** A faixa amarela de cima, só desenho (sem marca): a busca não busca. */
function Cabecalho() {
    return (
        <div aria-hidden="true" className="bg-[#fff159] px-4 py-2.5 shadow-[0_1px_0_rgba(0,0,0,0.1)]" data-pagina-cabecalho="">
            <div className="mx-auto flex max-w-[1200px] items-center">
                <span className="flex h-9 w-full max-w-[600px] items-center justify-between rounded-sm bg-white px-3 text-[14px] text-black/35 shadow-[0_1px_2px_rgba(0,0,0,0.2)]">
                    Buscar produtos, marcas e muito mais…
                    <Search size={16} className="text-black/45" />
                </span>
            </div>
        </div>
    );
}

/**
 * A página do item como o comprador vê: as fotos à esquerda, condição, título e preço no meio, a caixa de compra à
 * direita e, embaixo, "Características do produto" e "Descrição". O que só existe depois de publicar (vendas,
 * avaliações, prazo de entrega, perguntas) não aparece; os botões de compra são só desenho.
 *
 * @param {object} props
 * @param {object} props.previa a prévia da trilha (a da equipe ou a do cliente: os campos que a página usa são os mesmos)
 * @param {{tipo: ?string, variante: ?string}} props.escolha o anúncio mostrado (a que não existe vira a 1ª que existe)
 * @param {(mudanca: {variante: string}) => void} props.onEscolha troca a variação (o seletor da página)
 */
export function PaginaDoAnuncio({ previa, escolha = {}, onEscolha = () => {} }) {
    const e = escolhaDaPagina(previa, escolha);
    const anuncio = anuncioDaPagina(previa, e);
    const fotos = fotosDaPagina(anuncio);
    const titulo = tituloDaPagina(anuncio);
    const preco = precoDaPagina(anuncio);
    const parcelas = textoDasParcelasDaPagina(preco.parcelas);
    const grupos = gruposDaPagina(anuncio);
    const destaques = destaquesDaPagina(grupos);
    const caminho = caminhoDaPagina(previa);
    const estoque = estoqueDaPagina(anuncio);
    const vendedor = vendedorDaPagina(previa);
    const garantia = garantiaDaPagina(anuncio);
    const descricao = descricaoDaPagina(previa);

    return (
        <div className="min-h-full bg-[#ebebeb] font-sans text-black/90" data-pagina-do-anuncio={`${e.tipo ?? ''}:${e.variante ?? ''}`}>
            <Cabecalho />
            <div className="mx-auto max-w-[1200px] px-4 pb-10 pt-4">
                {caminho.length > 0 && (
                    <p className={cn('m-0 mb-3 truncate text-[14px]', AZUL)} data-pagina-categoria="">
                        {caminho.join(' > ')}
                    </p>
                )}

                <div className="overflow-hidden rounded-md bg-white shadow-[0_1px_2px_rgba(0,0,0,0.12)]">
                    <div className="grid gap-6 p-6 lg:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)_280px]">
                        {/* A chave troca com o anúncio: a galeria volta para a capa dele. */}
                        <Galeria key={`${e.tipo}:${e.variante}`} fotos={fotos} />

                        <div className="min-w-0" data-pagina-info="">
                            <p className="m-0 text-[14px] text-black/55" data-pagina-condicao="">
                                {condicaoDaPagina(anuncio)}
                            </p>
                            <h1
                                className={cn(TITULO, 'mt-1 text-[22px] font-semibold leading-[1.25] [overflow-wrap:anywhere]', titulo ? 'text-black/90' : 'italic text-black/40')}
                                data-pagina-titulo=""
                            >
                                {titulo ?? 'Sem título'}
                            </h1>

                            <div className="mt-5" data-pagina-preco="">
                                {preco.atual !== null ? (
                                    <Preco valor={preco.atual} className="text-[36px] font-light leading-none text-black/90" />
                                ) : (
                                    <p className="m-0 text-[24px] font-light text-black/40">Sem preço</p>
                                )}
                                {parcelas ? (
                                    <p className={cn('m-0 mt-1.5 text-[16px]', VERDE)} data-pagina-parcelas="">
                                        {parcelas}
                                    </p>
                                ) : (
                                    preco.atual !== null && (
                                        <p className={cn('m-0 mt-1.5 text-[14px]', AZUL)} data-pagina-pagamento="">
                                            Ver os meios de pagamento
                                        </p>
                                    )
                                )}
                            </div>

                            <SeletorDaVariacao previa={previa} escolha={e} onEscolha={onEscolha} />

                            {destaques.length > 0 && (
                                <div className="mt-6" data-pagina-destaques="">
                                    <p className="m-0 text-[16px] font-semibold text-black/90">O que você precisa saber sobre este produto</p>
                                    <ul className="m-0 mt-2 list-disc space-y-1 pl-5 text-[14px] text-black/90">
                                        {destaques.map((d, i) => (
                                            <li key={`${d.nome}-${i}`}>
                                                {d.nome}: {d.valor}
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            )}
                        </div>

                        <aside className="h-fit rounded-md border border-black/10 p-4" aria-label="Compra" data-pagina-compra="">
                            <Frete envio={anuncio?.envio} />
                            {estoque && (
                                <div data-pagina-estoque={estoque.disponivel ? 'sim' : 'nao'}>
                                    <p className="m-0 mt-5 text-[16px] font-semibold text-black/90">{estoque.disponivel ? 'Estoque disponível' : 'Sem estoque'}</p>
                                    {estoque.disponivel && (
                                        <p className="m-0 mt-1 text-[14px] text-black/90">
                                            Quantidade: <span className="font-semibold">1 unidade</span> <span className="text-black/55">{textoDoEstoque(estoque)}</span>
                                        </p>
                                    )}
                                </div>
                            )}
                            {/* Só desenho: na prévia ninguém compra. */}
                            <div aria-hidden="true" className="mt-5 space-y-2">
                                <span className="grid h-12 place-items-center rounded-md bg-[#3483fa] text-[16px] font-semibold text-white">Comprar agora</span>
                                <span className="grid h-12 place-items-center rounded-md bg-[#4189e6]/[0.15] text-[16px] font-semibold text-[#3483fa]">Adicionar ao carrinho</span>
                            </div>
                            {vendedor && (
                                <p className="m-0 mt-5 text-[14px] text-black/55" data-pagina-vendedor="">
                                    Vendido por <span className={AZUL}>{vendedor}</span>
                                </p>
                            )}
                            {garantia && (
                                <p className="m-0 mt-3 flex items-start gap-2 text-[14px] text-black/55" data-pagina-garantia="">
                                    <ShieldCheck size={16} className="mt-0.5 shrink-0" aria-hidden="true" />
                                    {garantia}
                                </p>
                            )}
                        </aside>
                    </div>

                    <Caracteristicas grupos={grupos} />

                    <section className="border-t border-black/10 px-6 py-8" data-pagina-descricao="">
                        <h2 className={cn(TITULO, 'text-[24px] font-normal leading-tight text-black/90')}>Descrição</h2>
                        {descricao ? (
                            <p className="m-0 mt-6 max-w-[80ch] whitespace-pre-line text-[16px] leading-relaxed text-black/60 [overflow-wrap:anywhere]">{descricao}</p>
                        ) : (
                            <p className="m-0 mt-3 text-[14px] text-black/40">Sem descrição.</p>
                        )}
                    </section>
                </div>
            </div>
        </div>
    );
}

/**
 * A tela cheia: a barra de cima (o nome da escolha, Clássico/Premium e [Fechar]) e a página rolando embaixo. Abre na
 * escolha da gaveta (`inicial`); o Esc e o [Fechar] chamam `onFechar` — a gaveta fica aberta.
 */
export function TelaCheiaDoAnuncio({ previa, inicial = null, onFechar = () => {} }) {
    const [escolha, setEscolha] = useState(() => escolhaDaPagina(previa, inicial ?? {}));
    const tipos = tiposDaPagina(previa);
    const idDoTitulo = useId();
    const caixa = useRef(null);
    const fechar = useRef(null);
    const escolher = (mudanca) => setEscolha((atual) => escolhaDaPagina(previa, { ...atual, ...mudanca }));

    useEffect(() => {
        fechar.current?.focus();
        // O fundo não rola por baixo da tela cheia.
        const antes = document.body.style.overflow;
        document.body.style.overflow = 'hidden';

        return () => {
            document.body.style.overflow = antes;
        };
    }, []);

    const aoTeclar = (evento) => {
        if (evento.key === 'Escape') {
            // O Esc é desta tela: a gaveta de baixo (que escuta no `document`) não fecha junto.
            evento.preventDefault();
            evento.stopPropagation();
            onFechar();

            return;
        }
        if (evento.key !== 'Tab' || !caixa.current) return;
        // O Tab gira aqui dentro; o giro da gaveta de baixo não entra.
        evento.stopPropagation();
        const focaveis = [...caixa.current.querySelectorAll(FOCAVEIS)];
        if (!focaveis.length) return;
        const primeiro = focaveis[0];
        const ultimo = focaveis[focaveis.length - 1];
        const ativo = document.activeElement;
        if (evento.shiftKey && (ativo === primeiro || ativo === caixa.current)) {
            evento.preventDefault();
            ultimo.focus();
        } else if (!evento.shiftKey && ativo === ultimo) {
            evento.preventDefault();
            primeiro.focus();
        }
    };

    const tela = (
        <div
            ref={caixa}
            role="dialog"
            aria-modal="true"
            aria-labelledby={idDoTitulo}
            tabIndex={-1}
            onKeyDown={aoTeclar}
            className="fixed inset-0 z-[60] flex flex-col bg-[#ebebeb] outline-none"
            data-tela-cheia-do-anuncio=""
        >
            <div className="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-trilha-line bg-trilha-bg px-4 py-2.5 text-trilha-fg pt-[max(0.625rem,env(safe-area-inset-top,0px))]">
                <h2 id={idDoTitulo} className={cn(TITULO, 'text-[14px] font-bold leading-snug text-trilha-fg')}>
                    Como o comprador vê
                </h2>
                <span className="text-[12.5px] text-trilha-fg2" data-nome-da-escolha="">
                    {nomeDaEscolha(previa, escolha)}
                </span>
                {tipos.length > 1 && (
                    <div role="group" aria-label="Tipo do anúncio" className="flex overflow-hidden rounded-lg border border-trilha-line" data-escolha-da-pagina="tipo">
                        {tipos.map((t) => {
                            const marcado = t.chave === escolha.tipo;

                            return (
                                <button
                                    key={t.chave}
                                    type="button"
                                    aria-pressed={marcado}
                                    onClick={() => escolher({ tipo: t.chave })}
                                    className={cn(
                                        'px-3 py-1 text-[12.5px] font-semibold',
                                        'focus-visible:[outline-style:solid] focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-trilha-run',
                                        marcado ? 'bg-trilha-navy text-trilha-on-navy' : 'bg-trilha-surface text-trilha-fg hover:bg-trilha-surface2',
                                    )}
                                    data-tipo-da-pagina={t.chave}
                                >
                                    {t.nome}
                                </button>
                            );
                        })}
                    </div>
                )}
                <Botao ref={fechar} className="ml-auto" onClick={onFechar} data-acao="fechar-pagina-do-anuncio">
                    <X size={14} aria-hidden="true" />
                    Fechar
                </Botao>
            </div>
            <div className="min-h-0 flex-1 overflow-auto">
                <PaginaDoAnuncio previa={previa} escolha={escolha} onEscolha={escolher} />
            </div>
        </div>
    );

    // Sem `document` (o React no servidor, nos testes) a tela sai no lugar; no navegador, no `body`.
    return typeof document === 'undefined' ? tela : createPortal(tela, document.body);
}

/**
 * O [Ver como o comprador vê] da gaveta e a tela cheia que ele abre, na escolha que a gaveta mostra. Ao fechar, o foco
 * volta para o botão.
 */
export function BotaoDaPagina({ previa, escolha = null, className }) {
    const [aberta, setAberta] = useState(false);
    const botao = useRef(null);
    const voltarOFoco = useRef(false);

    useEffect(() => {
        if (!aberta && voltarOFoco.current) {
            voltarOFoco.current = false;
            botao.current?.focus();
        }
    }, [aberta]);

    return (
        <>
            <Botao ref={botao} className={className} onClick={() => setAberta(true)} aria-haspopup="dialog" data-acao="ver-como-o-comprador">
                <Maximize2 size={14} aria-hidden="true" />
                Ver como o comprador vê
            </Botao>
            {aberta && (
                <TelaCheiaDoAnuncio
                    previa={previa}
                    inicial={escolha}
                    onFechar={() => {
                        voltarOFoco.current = true;
                        setAberta(false);
                    }}
                />
            )}
        </>
    );
}
