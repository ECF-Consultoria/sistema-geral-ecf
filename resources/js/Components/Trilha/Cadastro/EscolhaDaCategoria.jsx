import { useEffect, useId, useRef, useState } from 'react';
import { ChevronRight } from 'lucide-react';
import useArvoreDeCategorias from '@/Components/Categorias/useArvoreDeCategorias';
import { ehFinal, escolhaDaCategoria, filtrarFilhas, nivelEhFinal, podeEscolher, trilha } from '@/lib/arvoreDeCategorias';
import { MAXIMO_DA_BUSCA, caminhoParaTela, candidataUsavel, cliqueValeNaLista, porQueNaoUsar } from '@/lib/trilhaCadastro';
import { cn } from '@/lib/utils';
import { Botao, CAMPO, Etiqueta, LinkDeAcao } from '@/Components/Trilha/Pecas';
import { ConfirmacaoNaGaveta } from '@/Components/Trilha/Cadastro/PecasDoCadastro';

// ─── Trilha de produtos — a categoria do Mercado Livre na gaveta (F2) ───────
//
// §5.4 do desenho: SUGERIDA + 1 CLIQUE. O nome do produto sugere a categoria
// ("Categoria sugerida: Casa › Móveis › Poltronas [Usar esta] [Ver outras]
// [Navegar]") e nada é aplicado sozinho (D-06). "Usar esta" põe a categoria na
// gaveta — os campos da ficha e os termos do título carregam — e ela grava no
// "Salvar". Sem candidata, ou com o catálogo fora: "Não achei pelo nome.
// [Buscar] [Navegar] [Tentar de novo]".
//
// - "Ver outras" e "Buscar" são a mesma lista (o texto começa pelo nome). A
//   candidata que não é a última do caminho diz "Escolha uma mais específica"
//   e abre a navegação naquele nível.
// - "Navegar" desce a árvore nível a nível (`useArvoreDeCategorias`, com a rota
//   da trilha); "Usar esta" só aparece na última do caminho que aceita produto
//   novo.
// - As listas não aceitam clique nos 300 ms depois de mudar (o clique mirado na
//   lista de antes caía na nova — demo de 23/10).
// - Tudo abre DENTRO da gaveta, no fluxo da página (sem portal). Esc com uma
//   lista aberta fecha só a lista (`preventDefault`: a gaveta respeita).
// - Trocar a categoria que já estava na gaveta pede confirmação aqui mesmo.
// - Com o produto no Publicador a categoria fica só para leitura: a troca é no
//   editor de lá (D12; o servidor também recusa).

const FILTRO_A_PARTIR_DE = 9;

/**
 * A marca do botão do mouse que desceu num item: o clique só vale no MESMO item, com a lista já parada.
 * `mudouEm`: o instante em que a lista mudou (número, ou a `ref` que o guarda).
 */
function useCliqueNaLista(mudouEm) {
    const desceu = useRef(null);
    const quando = () => (typeof mudouEm === 'object' && mudouEm !== null ? mudouEm.current : mudouEm) ?? 0;

    return {
        aoDescer: (id) => {
            desceu.current = { id, em: Date.now() };
        },
        vale: (id) => {
            const d = desceu.current;
            desceu.current = null;
            if (d && d.id !== id) return false;

            return cliqueValeNaLista({ mudouEm: quando(), desceuEm: d?.em ?? null, agora: Date.now() });
        },
    };
}

/** Os rótulos da gaveta do produto; a planilha (F7) troca por "Adicionar" e "escolhida". */
const ROTULOS_DA_GAVETA = { usar: 'Usar esta', marcada: 'atual' };

/** A categoria `id` está marcada? `selecionada`: o id da gaveta do produto, ou os ids já escolhidos (a planilha, F7). */
const estaMarcada = (selecionada, id) => (Array.isArray(selecionada)
    ? selecionada.some((s) => String(s) === String(id))
    : !!selecionada && String(selecionada) === String(id));

const BOTAO_DA_LISTA = 'flex w-full min-w-0 items-center gap-2 rounded-lg px-2 py-2 text-left text-[13px] focus-visible:[outline-style:solid] focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-trilha-run';

/**
 * A lista de "Buscar"/"Ver outras": o texto (começa pelo nome) e as candidatas. Exportada para a planilha por
 * categoria (F7), que escolhe várias (`selecionada` = a lista de ids) com os seus `rotulos`.
 */
export function BuscaDaCategoria({ cat, selecionada, rotulos = ROTULOS_DA_GAVETA }) {
    const clique = useCliqueNaLista(cat.lista.mudouEm);
    const { situacao, candidatas } = cat.lista;
    const campo = useRef(null);

    // Abre com o texto selecionado: quem digita troca em vez de emendar no fim.
    useEffect(() => {
        campo.current?.focus();
        campo.current?.select?.();
    }, []);

    const aoClicar = (c) => {
        if (!clique.vale(c.id)) return;
        if (candidataUsavel(c)) cat.escolher(c);
        else if (c.folha !== true || c.anunciavel !== false) cat.abrirNavegacao(c.id);
    };

    return (
        <div className="flex min-w-0 flex-col gap-2 rounded-xl border border-trilha-line bg-trilha-surface2 p-2.5" data-painel-da-categoria="buscar">
            <div className="flex min-w-0 items-center gap-2">
                <input
                    ref={campo}
                    className={cn(CAMPO, 'flex-1')}
                    value={cat.consulta}
                    maxLength={MAXIMO_DA_BUSCA}
                    placeholder="O tipo do produto: poltrona, mesa de centro…"
                    aria-label="Buscar a categoria pelo tipo do produto"
                    data-busca-de-categoria=""
                    onChange={(e) => cat.mudarConsulta(e.target.value)}
                />
                <Botao onClick={cat.fechar} data-acao="fechar-busca-de-categoria" className="px-2.5 py-1 text-[12.5px]">
                    Fechar
                </Botao>
            </div>
            {situacao === 'buscando' && (
                <p role="status" className="m-0 text-[12.5px] text-trilha-muted">
                    Buscando categorias…
                </p>
            )}
            {(situacao === 'curta' || situacao === 'parada') && <p className="m-0 text-[12.5px] text-trilha-muted">Digite ao menos 2 letras.</p>}
            {situacao === 'recusada' && <p className="m-0 text-[12.5px] text-trilha-muted">Não deu para buscar com esse texto. Use de 2 a 120 letras, como o tipo do produto.</p>}
            {situacao === 'nada' && (
                <div role="status" className="flex flex-wrap items-center gap-2 text-[12.5px] text-trilha-fg2">
                    <span className="min-w-0 flex-1">Nada encontrado com esse texto.</span>
                    <Botao onClick={cat.tentarListaDeNovo} data-acao="tentar-busca-de-novo" className="px-2.5 py-1 text-[12.5px]">
                        Tentar de novo
                    </Botao>
                </div>
            )}
            {situacao === 'pronta' && (
                <ul className="m-0 flex max-h-72 list-none flex-col overflow-auto p-0" data-candidatas="">
                    {candidatas.map((c) => {
                        const usavel = candidataUsavel(c);
                        const fechada = c.folha === true && c.anunciavel === false;
                        const marcada = estaMarcada(selecionada, c.id);

                        return (
                            <li key={c.id}>
                                <button
                                    type="button"
                                    disabled={fechada}
                                    data-candidata={c.id}
                                    data-usavel={usavel ? 'sim' : 'nao'}
                                    onMouseDown={() => clique.aoDescer(c.id)}
                                    onClick={() => aoClicar(c)}
                                    className={cn(BOTAO_DA_LISTA, fechada ? 'cursor-not-allowed text-trilha-muted' : 'text-trilha-fg hover:bg-trilha-surface', marcada && 'bg-trilha-surface')}
                                >
                                    <span className="min-w-0 flex-1">
                                        <span className="block font-semibold [overflow-wrap:anywhere]">{c.nome}</span>
                                        <span className="block text-[12px] text-trilha-muted [overflow-wrap:anywhere]">{caminhoParaTela(c)}</span>
                                        {!usavel && <span className="block text-[12px] text-trilha-warn">{porQueNaoUsar(c)}</span>}
                                    </span>
                                    {marcada ? (
                                        <Etiqueta tom="ok">{rotulos.marcada}</Etiqueta>
                                    ) : usavel ? (
                                        <Etiqueta tom="run">{rotulos.usar}</Etiqueta>
                                    ) : (
                                        !fechada && <ChevronRight size={14} aria-hidden="true" className="shrink-0 text-trilha-muted" />
                                    )}
                                </button>
                            </li>
                        );
                    })}
                </ul>
            )}
        </div>
    );
}

/** "Navegar": a árvore, um nível por vez; "Usar esta" só na última do caminho que aceita produto novo. Exportada para a F7. */
export function ArvoreDaCategoria({ cat, selecionada, rotulos = ROTULOS_DA_GAVETA }) {
    const { nivel, estado, ir, tentarDeNovo } = useArvoreDeCategorias(cat.buscarNivel, { inicio: cat.inicioDaArvore });
    const [filtro, setFiltro] = useState('');
    const mudouEm = useRef(0);
    const atualId = nivel.atual?.id ?? null;

    // Cada nível começa sem filtro; e a lista nova não aceita clique nos primeiros 300 ms.
    useEffect(() => {
        setFiltro('');
        mudouEm.current = Date.now();
    }, [atualId, estado]);
    const clique = useCliqueNaLista(mudouEm);
    const niveis = trilha(nivel);
    const filhas = filtrarFilhas(nivel.filhas, filtro);

    return (
        <div className="flex min-w-0 flex-col gap-2 rounded-xl border border-trilha-line bg-trilha-surface2 p-2.5" data-painel-da-categoria="navegar" data-estado={estado}>
            <div className="flex min-w-0 items-start gap-2">
                <nav aria-label="Caminho das categorias" className="flex min-w-0 flex-1 flex-wrap items-center gap-x-1 gap-y-0.5 text-[12.5px]">
                    {niveis.map((n, i) => {
                        const ultimo = i === niveis.length - 1;

                        return (
                            <span key={n.id ?? 'inicio'} className="inline-flex min-w-0 items-center gap-1">
                                {i > 0 && <ChevronRight size={12} aria-hidden="true" className="shrink-0 text-trilha-muted" />}
                                {ultimo ? (
                                    <span aria-current="location" className="font-semibold text-trilha-fg [overflow-wrap:anywhere]">
                                        {n.nome}
                                    </span>
                                ) : (
                                    <button type="button" onClick={() => ir(n.id)} data-trilha-da-categoria={n.id ?? 'inicio'} className="text-trilha-fg2 underline-offset-2 hover:text-trilha-fg hover:underline">
                                        {n.nome}
                                    </button>
                                )}
                            </span>
                        );
                    })}
                </nav>
                <Botao onClick={cat.fechar} data-acao="fechar-navegacao" className="px-2.5 py-1 text-[12.5px]">
                    Fechar
                </Botao>
            </div>

            {estado === 'carregando' && (
                <p role="status" className="m-0 text-[12.5px] text-trilha-muted">
                    Carregando as categorias…
                </p>
            )}
            {estado === 'indisponivel' && (
                <div role="status" className="flex flex-wrap items-center gap-2 text-[12.5px] text-trilha-fg2">
                    <span className="min-w-0 flex-1">Não deu para carregar as categorias agora.</span>
                    <Botao onClick={tentarDeNovo} data-acao="tentar-arvore-de-novo" className="px-2.5 py-1 text-[12.5px]">
                        Tentar de novo
                    </Botao>
                </div>
            )}

            {estado === 'pronto' && nivelEhFinal(nivel) && (
                <div className="flex flex-col gap-2 rounded-lg border border-trilha-line bg-trilha-surface p-2.5" data-nivel-final="">
                    <p className="m-0 text-[13px] text-trilha-fg">
                        <b>{nivel.atual.nome}</b> é a última do caminho.
                    </p>
                    {podeEscolher(nivel.atual) ? (
                        <span>
                            <Botao primario data-acao="usar-esta-categoria" onClick={() => cat.escolher(escolhaDaCategoria(nivel, nivel.atual))}>
                                {rotulos.usar}
                            </Botao>
                        </span>
                    ) : (
                        <p className="m-0 text-[12.5px] text-trilha-warn">Ela não aceita produto novo. Volte um nível e escolha outra.</p>
                    )}
                </div>
            )}

            {estado === 'pronto' && !nivelEhFinal(nivel) && (
                <>
                    {nivel.filhas.length >= FILTRO_A_PARTIR_DE && (
                        <input
                            className={CAMPO}
                            value={filtro}
                            placeholder="Filtrar neste nível"
                            aria-label="Filtrar as categorias deste nível"
                            onChange={(e) => setFiltro(e.target.value)}
                        />
                    )}
                    {filhas.length === 0 ? (
                        <p className="m-0 text-[12.5px] text-trilha-muted">{nivel.filhas.length === 0 ? 'Não há categorias neste nível.' : 'Nenhuma categoria deste nível tem esse nome.'}</p>
                    ) : (
                        <ul className="m-0 flex max-h-72 list-none flex-col overflow-auto p-0" data-filhas="">
                            {filhas.map((f) => {
                                const final = ehFinal(f);
                                const usavel = final && podeEscolher(f);
                                const fechada = final && !usavel;
                                const marcada = estaMarcada(selecionada, f.id);

                                return (
                                    <li key={f.id}>
                                        <button
                                            type="button"
                                            disabled={fechada}
                                            data-categoria={f.id}
                                            data-final={final ? 'sim' : 'nao'}
                                            onMouseDown={() => clique.aoDescer(f.id)}
                                            onClick={() => {
                                                if (!clique.vale(f.id)) return;
                                                if (final) cat.escolher(escolhaDaCategoria(nivel, f));
                                                else ir(f.id);
                                            }}
                                            className={cn(BOTAO_DA_LISTA, fechada ? 'cursor-not-allowed text-trilha-muted' : 'text-trilha-fg hover:bg-trilha-surface', marcada && 'bg-trilha-surface')}
                                        >
                                            <span className="min-w-0 flex-1 [overflow-wrap:anywhere]">{f.nome}</span>
                                            {marcada && <Etiqueta tom="ok">{rotulos.marcada}</Etiqueta>}
                                            {usavel && !marcada && <Etiqueta tom="run">{rotulos.usar}</Etiqueta>}
                                            {fechada && <span className="shrink-0 text-[12px]">Não aceita produto novo</span>}
                                            {!final && <ChevronRight size={14} aria-hidden="true" className="shrink-0 text-trilha-muted" />}
                                        </button>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </>
            )}
        </div>
    );
}

/** As saídas de quando não há sugestão (ou para trocar): Buscar, Navegar e, quando cabe, Tentar de novo / Ver outras. */
function Saidas({ cat, nome, verOutras = false, tentar = false }) {
    return (
        <span className="flex flex-wrap gap-1.5">
            {verOutras && (
                <Botao data-acao="ver-outras-categorias" onClick={() => cat.abrirBusca(nome)} className="px-2.5 py-1 text-[12.5px]">
                    Ver outras
                </Botao>
            )}
            {!verOutras && (
                <Botao data-acao="buscar-categoria" onClick={() => cat.abrirBusca(nome)} className="px-2.5 py-1 text-[12.5px]">
                    Buscar
                </Botao>
            )}
            <Botao data-acao="navegar-categorias" onClick={() => cat.abrirNavegacao(null)} className="px-2.5 py-1 text-[12.5px]">
                Navegar
            </Botao>
            {tentar && (
                <Botao data-acao="tentar-sugestao-de-novo" onClick={cat.tentarSugestaoDeNovo} className="px-2.5 py-1 text-[12.5px]">
                    Tentar de novo
                </Botao>
            )}
        </span>
    );
}

/** Sem categoria na gaveta: a sugestão pelo nome, ou as saídas. */
function SemCategoria({ cat, nome, textoAntigo, refDoUsarEsta = null }) {
    const { situacao, candidatas } = cat.sugestao;
    const sugerida = cat.sugerida;

    return (
        <div className="flex min-w-0 flex-col gap-1.5">
            {textoAntigo && (
                <span className="text-[12.5px] text-trilha-muted" data-categoria-em-texto="">
                    O cadastro tinha “{textoAntigo}” escrito como categoria: escolha a do Mercado Livre.
                </span>
            )}
            {sugerida ? (
                <div className="flex flex-col gap-1.5 rounded-lg bg-trilha-run-bg px-2.5 py-2" data-categoria-sugerida={sugerida.id}>
                    <span className="text-[13px] text-trilha-fg [overflow-wrap:anywhere]">
                        <span className="text-trilha-fg2">Categoria sugerida:</span> <b className="font-semibold">{caminhoParaTela(sugerida)}</b>
                    </span>
                    <span className="flex flex-wrap gap-1.5">
                        <Botao ref={refDoUsarEsta} primario data-acao="usar-categoria-sugerida" onClick={() => cat.escolher(sugerida)} className="px-2.5 py-1 text-[12.5px]">
                            Usar esta
                        </Botao>
                        <Saidas cat={cat} nome={nome} verOutras />
                    </span>
                </div>
            ) : situacao === 'buscando' ? (
                <span className="flex flex-wrap items-center gap-2">
                    <span role="status" className="text-[12.5px] text-trilha-muted" data-sugestao-buscando="">
                        Procurando a categoria pelo nome…
                    </span>
                    <Saidas cat={cat} nome={nome} />
                </span>
            ) : situacao === 'pronta' ? (
                <span className="flex flex-wrap items-center gap-2" data-sem-sugestao="">
                    <span className="text-[12.5px] text-trilha-fg2">Não achei uma categoria certa pelo nome ({candidatas.length === 1 ? '1 parecida' : `${candidatas.length} parecidas`}).</span>
                    <Saidas cat={cat} nome={nome} verOutras />
                </span>
            ) : situacao === 'nada' ? (
                <span className="flex flex-wrap items-center gap-2" data-nao-achei="">
                    <span className="text-[12.5px] text-trilha-fg2">Não achei pelo nome.</span>
                    <Saidas cat={cat} nome={nome} tentar />
                </span>
            ) : situacao === 'recusada' ? (
                <span className="flex flex-wrap items-center gap-2">
                    <span className="text-[12.5px] text-trilha-fg2">Não deu para buscar com esse nome (use de 2 a 120 letras).</span>
                    <Saidas cat={cat} nome={nome} />
                </span>
            ) : (
                <span className="flex flex-wrap items-center gap-2">
                    <span className="text-[12.5px] text-trilha-muted">Digite o nome do item: o sistema sugere a categoria.</span>
                    <Saidas cat={cat} nome={nome} />
                </span>
            )}
        </div>
    );
}

/**
 * A categoria do Mercado Livre do produto, logo abaixo do nome.
 *
 * @param {{cat: object, categoria: ?{id: string, nome: string, caminho_texto: string}, salva: ?{id: string},
 *   textoAntigo?: ?string, travada?: boolean, leitura?: boolean, urlPublicador?: ?string, erro?: ?string,
 *   nome: string}} props
 *   `cat`: o retorno de `useCategoriaDaTrilha`; `categoria`: a do estado da gaveta; `salva`: a gravada;
 *   `travada`: o produto já está no Publicador (a troca é no editor de lá); `refDoUsarEsta`: o "Usar esta" da sugestão (o foco logo depois de criar, §5.2).
 */
export default function EscolhaDaCategoria({ cat, categoria, salva = null, textoAntigo = null, travada = false, leitura = false, urlPublicador = null, erro = null, nome, refDoUsarEsta = null }) {
    const idDoRotulo = useId();
    const idDoErro = useId();
    const nova = categoria && categoria.id !== salva?.id;

    // Esc com uma lista aberta fecha só ela (a confirmação da troca trata o Esc dela antes, e marca o evento).
    const aoTeclar = (evento) => {
        if (evento.key !== 'Escape' || evento.defaultPrevented || !cat.aberto) return;
        evento.preventDefault();
        cat.fechar();
    };

    let corpo;
    if (travada || leitura) {
        corpo = (
            <div className="flex min-w-0 flex-col gap-1.5" data-categoria-travada="">
                <span className="text-[13px] text-trilha-fg [overflow-wrap:anywhere]">{categoria ? caminhoParaTela(categoria) : textoAntigo || 'Sem categoria'}</span>
                {travada && !leitura && (
                    <span className="flex flex-wrap items-center gap-2 text-[12.5px] text-trilha-muted">
                        Para trocar, use o editor do Publicador.
                        <LinkDeAcao href={urlPublicador} className="px-2.5 py-1 text-[12.5px]" data-link="publicador-categoria">
                            Abrir no Publicador
                        </LinkDeAcao>
                    </span>
                )}
            </div>
        );
    } else if (categoria) {
        corpo = (
            <div className="flex min-w-0 flex-wrap items-center gap-2" data-categoria-escolhida={categoria.id}>
                <span className="min-w-0 text-[13px] font-semibold text-trilha-fg [overflow-wrap:anywhere]">{caminhoParaTela(categoria)}</span>
                {nova && (
                    <Etiqueta tom="roxo" data-categoria-nova="">
                        nova · grava ao salvar
                    </Etiqueta>
                )}
                <span className="flex flex-wrap gap-1.5">
                    <Botao data-acao="trocar-categoria" onClick={() => cat.abrirBusca(nome)} className="px-2.5 py-1 text-[12.5px]">
                        Trocar
                    </Botao>
                    <Botao data-acao="navegar-categorias" onClick={() => cat.abrirNavegacao(categoria.id)} className="px-2.5 py-1 text-[12.5px]">
                        Navegar
                    </Botao>
                </span>
            </div>
        );
    } else {
        corpo = <SemCategoria cat={cat} nome={nome} textoAntigo={textoAntigo} refDoUsarEsta={refDoUsarEsta} />;
    }

    return (
        <div
            role="group"
            aria-labelledby={idDoRotulo}
            aria-describedby={erro ? idDoErro : undefined}
            className="flex min-w-0 flex-col gap-1.5"
            data-campo="categoria"
            onKeyDown={aoTeclar}
        >
            <span id={idDoRotulo} className="text-[12.5px] font-semibold text-trilha-fg2">
                Categoria do Mercado Livre
                <span aria-hidden="true" className="text-trilha-bad">
                    {' '}*
                </span>
            </span>
            {corpo}
            {!travada && !leitura && cat.troca && (
                <div className="flex flex-col gap-1.5 rounded-lg border border-trilha-warn/40 bg-trilha-warn-bg px-2.5 py-2" data-troca-de-categoria={cat.troca.id}>
                    <span className="text-[12.5px] text-trilha-fg [overflow-wrap:anywhere]">
                        Trocar para <b>{caminhoParaTela(cat.troca)}</b>?
                    </span>
                    <ConfirmacaoNaGaveta
                        texto="Os campos da ficha que a categoria nova não tem saem quando você salvar."
                        acoes={[
                            { rotulo: 'Trocar a categoria', acao: 'confirmar-troca-de-categoria', primario: true, onClick: cat.confirmarTroca },
                            { rotulo: 'Manter a atual', acao: 'manter-categoria', foco: true, onClick: cat.manterAtual },
                        ]}
                    />
                </div>
            )}
            {!travada && !leitura && !cat.troca && cat.aberto === 'buscar' && <BuscaDaCategoria cat={cat} selecionada={categoria?.id ?? null} />}
            {!travada && !leitura && !cat.troca && cat.aberto === 'navegar' && (
                <ArvoreDaCategoria key={cat.aberturaDaArvore} cat={cat} selecionada={categoria?.id ?? null} />
            )}
            {erro && (
                <span id={idDoErro} className="text-[12px] font-semibold text-trilha-bad" data-erro-do-campo="categoria">
                    {erro}
                </span>
            )}
        </div>
    );
}
