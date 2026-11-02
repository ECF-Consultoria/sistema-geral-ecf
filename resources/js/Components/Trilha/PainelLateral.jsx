import { createContext, useContext, useEffect, useId, useRef, useState } from 'react';
import { ETAPA_POR_CHAVE, chaveDeMapear, chaveDoProduto, ofertasDoProduto } from '@/lib/trilha';
import { textoDoMapeamento } from '@/lib/trilhaMapeamento';
import { cn } from '@/lib/utils';
import { Botao, Caixa, LinkDeAcao, OfertaNaLista, OndeEsta, Pilula, SeloDoTipo, Sobrelinha } from '@/Components/Trilha/Pecas';
import CaixaDaOnda from '@/Components/Trilha/CaixaDaOnda';
import { FOTOS_MINIMAS, composicao, fmtCusto, plural, rotuloDaFase, skuDoProduto } from '@/Components/Trilha/formatos';

// ─── Trilha de produtos — o painel lateral ──────────────────────────────────
//
// A gaveta da direita do protótipo (`desenhaLayer()`, `gavetaProd()`,
// `gavetaOferta()`): onde o item está, o que falta, com que ofertas ele anda e o
// que o Publicador já fez. Na 1ª entrega era só leitura; na 2ª (com a trilha de
// trabalho e quem escreve) o produto ganha o "Mapear" e a oferta, a caixa
// "Planejamento" com a onda dela.
//
// Três peças:
// - `Gaveta`: a casca — o diálogo modal, o fundo escurecido, Esc, o Tab preso
//   dentro e o foco que volta para quem abriu. A página monta UMA gaveta para
//   todos os painéis (item, onda, mapear): trocar de painel por dentro (da
//   oferta para a "Nova onda" e de volta) não fecha nem reabre nada.
// - `MioloDaGaveta`: cabeçalho, corpo rolável e rodapé de cada painel.
// - `PainelDoItem`: o produto ou a oferta (`p:`/`o:`).
//
// Acessibilidade: o foco vai para o painel ao abrir (ou para o campo marcado
// com `data-foco-inicial`), fica preso nele no Tab, Esc e o clique no fundo
// fecham, e ao fechar o foco volta para quem abriu — ou para a linha do item,
// se quem abriu sumiu, ou para a vista.
//
// Visão do cliente (de verdade ou na simulação da equipe): sem MLB, sem preparo
// do agente, sem lista de sugestões, sem link do Publicador e sem nenhuma
// escrita. A maior parte já chega cortada nas props; o resto é escondido aqui
// pela `visao` (e o `podeEditar` vem falso da página).

const PREPARO = [
    ['titulo', 'Título'],
    ['modelo', 'Modelo'],
    ['descricao', 'Descrição'],
    ['imagens', 'Imagens'],
];

const ESTADO_DO_PREPARO = {
    pronto: { tom: 'ok', rotulo: 'Pronto' },
    fazendo: { tom: 'run', rotulo: 'Fazendo' },
    fila: { tom: 'neutro', rotulo: 'Na fila' },
    erro: { tom: 'bad', rotulo: 'Erro' },
    pulado: { tom: 'neutro', rotulo: 'Pulado' },
};

const ESTADO_DA_FICHA = {
    completa: { tom: 'ok', rotulo: 'completa' },
    incompleta: { tom: 'warn', rotulo: 'incompleta' },
    sem_categoria: { tom: 'neutro', rotulo: 'sem categoria' },
    nao_conferida: { tom: 'neutro', rotulo: 'a conferir' },
};

/** As etapas em que a oferta mostra a caixa "Planejamento" (a da onda). */
const ETAPAS_DA_ONDA = ['plano', 'robo'];

const FOCAVEIS = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

const LINHA_DA_CAIXA = 'flex items-center justify-between gap-2.5 border-b border-trilha-line2 py-2 text-[13px] last:border-b-0';
const BOTAO_TEXTO = 'shrink-0 rounded px-0.5 text-[12.5px] font-semibold text-trilha-run hover:underline focus-visible:[outline-style:solid] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-trilha-run';

function Dado({ rotulo, falta = false, cheio = false, children }) {
    return (
        <div className={cn('flex min-w-0 flex-col gap-0.5', cheio && 'col-span-full')}>
            <dt className="text-[12px] font-semibold text-trilha-fg2">{rotulo}</dt>
            <dd className={cn('m-0 text-[13px] [overflow-wrap:anywhere]', falta ? 'font-semibold text-trilha-warn' : 'text-trilha-fg')}>{children}</dd>
        </div>
    );
}

function TextoDaFicha({ produto }) {
    const categoria = produto.categoria ? `Categoria: ${produto.categoria}. ` : '';
    const camposQueFaltam = (produto.faltas ?? []).filter((f) => f.startsWith('ficha: ')).map((f) => f.slice('ficha: '.length));

    if (produto.ficha === 'sem_categoria') {
        return <span className="text-[12.5px] text-trilha-muted">Sem categoria: a ficha técnica aparece quando a categoria do produto é escolhida.</span>;
    }
    if (produto.ficha === 'completa') {
        return <span className="text-[12.5px] text-trilha-fg2">{categoria}Os campos obrigatórios estão preenchidos.</span>;
    }
    if (produto.ficha === 'incompleta') {
        return (
            <>
                <span className="text-[12.5px] text-trilha-fg2">{categoria}Faltam campos obrigatórios:</span>
                {camposQueFaltam.length > 0 && (
                    <ul className="m-0 flex list-none flex-wrap gap-1.5 p-0">
                        {camposQueFaltam.map((campo) => (
                            <li key={campo}>
                                <Pilula tom="warn">{campo}</Pilula>
                            </li>
                        ))}
                    </ul>
                )}
            </>
        );
    }

    // 'nao_conferida': a definição da categoria não está guardada, e a trilha não pergunta à plataforma.
    return <span className="text-[12.5px] text-trilha-muted">{categoria}Ainda não conferida: os campos desta categoria não estão guardados no sistema.</span>;
}

function conteudoDoProduto({ produto, dados, visao, links, onAbrir, trabalho, podeEditar }) {
    const cliente = visao === 'cliente';
    const faltas = produto.faltas ?? [];
    const ofertas = ofertasDoProduto(dados, produto.id);
    const sku = skuDoProduto(produto);
    const custo = fmtCusto(produto);
    const fotos = produto.fotos_min ?? 0;
    const variasCores = (produto.variacoes ?? 0) > 1;
    const ambientes = produto.ambientes ?? [];
    const ficha = ESTADO_DA_FICHA[produto.ficha] ?? ESTADO_DA_FICHA.nao_conferida;
    const sugestoes = cliente ? [] : produto.sugestoes_pendentes ?? [];
    const totalDeSugestoes = produto.sugestoes_pendentes_total ?? sugestoes.length;
    // Com a trilha de trabalho quem mapeia é a equipe, aqui: o cliente acompanha, não decide sugestão.
    const mostraSugestoes = produto.etapa === 'mapa' && totalDeSugestoes > 0 && !(cliente && trabalho);
    const mapeado = textoDoMapeamento(produto.mapeado_em, produto.mapeado_por);
    // "Mapear" (ou "Mapear de novo", que só acrescenta ofertas): o produto fora da Lista, para quem escreve.
    const podeMapear = podeEditar && produto.etapa !== 'lista';

    const corpo = (
        <>
            <Caixa titulo="Onde está" data-caixa="onde-esta">
                {/* Produto mapeado (etapa null): passou pelas duas primeiras; quem anda agora são as ofertas. */}
                <OndeEsta etapa={produto.etapa} feitas={produto.etapa ? null : 2} />
                {!produto.etapa && (
                    <span className="text-[12.5px] text-trilha-muted">Já mapeado: daqui em diante, cada oferta deste produto segue a sua trilha (veja abaixo).</span>
                )}
                {mapeado && (
                    <span className="text-[12.5px] font-semibold text-trilha-fg2" data-mapeado="">
                        {mapeado}
                    </span>
                )}
            </Caixa>

            <Caixa
                titulo="Lista de produtos"
                extra={faltas.length ? <Pilula tom="warn">{faltas.length === 1 ? 'falta 1' : `faltam ${faltas.length}`}</Pilula> : <Pilula tom="ok">completa</Pilula>}
                data-caixa="lista"
            >
                {faltas.length > 0 && (
                    <ul className="m-0 flex list-none flex-wrap gap-1.5 p-0" aria-label="O que falta">
                        {faltas.map((falta) => (
                            <li key={falta}>
                                <Pilula tom="warn">{falta}</Pilula>
                            </li>
                        ))}
                    </ul>
                )}
                <dl className="m-0 grid grid-cols-2 gap-x-3 gap-y-2.5 max-[480px]:grid-cols-1">
                    <Dado rotulo={variasCores ? `SKUs das ${produto.variacoes} variações` : 'SKU'} cheio={variasCores}>
                        <span className="font-pub-mono">{(produto.skus?.length ? produto.skus : [sku ?? '—']).join(' · ')}</span>
                    </Dado>
                    <Dado rotulo="Custo" falta={!custo}>
                        {custo ?? 'falta'}
                    </Dado>
                    <Dado rotulo={variasCores ? 'Fotos (na cor com menos)' : 'Fotos'} falta={fotos < FOTOS_MINIMAS}>
                        {fotos < FOTOS_MINIMAS ? `${fotos} de ${FOTOS_MINIMAS}` : plural(fotos, 'foto', 'fotos')}
                    </Dado>
                    <Dado rotulo="Descrição" falta={!produto.tem_descricao}>
                        {produto.tem_descricao ? 'preenchida' : 'falta'}
                    </Dado>
                    <Dado rotulo="Medidas do produto">{produto.medidas ? <span className="font-pub-mono">{produto.medidas}</span> : '—'}</Dado>
                    <Dado rotulo="Embalagem" falta={!produto.embalagem}>
                        {produto.embalagem ? <span className="font-pub-mono">{produto.embalagem}</span> : 'falta'}
                    </Dado>
                    <Dado rotulo="Família" falta={!produto.familia}>
                        {produto.familia || 'sem família'}
                    </Dado>
                    <Dado rotulo="Ambientes" falta={!ambientes.length}>
                        {ambientes.length ? ambientes.join(', ') : 'nenhum'}
                    </Dado>
                </dl>
            </Caixa>

            <Caixa titulo="Ficha técnica" extra={<Pilula tom={ficha.tom}>{ficha.rotulo}</Pilula>} data-caixa="ficha">
                <TextoDaFicha produto={produto} />
            </Caixa>

            <Caixa titulo="Ofertas com este produto" data-caixa="ofertas">
                {ofertas.length ? (
                    <div className="flex flex-col">
                        {ofertas.map((oferta) => (
                            <OfertaNaLista key={oferta.id} oferta={oferta} onAbrir={onAbrir} />
                        ))}
                    </div>
                ) : (
                    <span className="text-[12.5px] text-trilha-muted">Ainda nenhuma oferta: o produto vira oferta depois do mapeamento.</span>
                )}
            </Caixa>

            {mostraSugestoes && (
                <Caixa titulo="Sugestões esperando decisão" extra={<Pilula tom="roxo">{totalDeSugestoes}</Pilula>} data-caixa="sugestoes">
                    {sugestoes.length > 0 ? (
                        <>
                            <ul className="m-0 flex list-none flex-col p-0">
                                {sugestoes.map((s, i) => (
                                    <li key={`${s.sku ?? s.nome}-${i}`} className={LINHA_DA_CAIXA}>
                                        <span className="min-w-0 [overflow-wrap:anywhere]">
                                            <SeloDoTipo tipo={rotuloDaFase(s.fase)} />
                                            {s.nome}
                                        </span>
                                        {s.sku && <span className="font-pub-mono text-[12px] text-trilha-fg2 [overflow-wrap:anywhere]">{s.sku}</span>}
                                    </li>
                                ))}
                            </ul>
                            {totalDeSugestoes > sugestoes.length && (
                                <span className="text-[12.5px] text-trilha-muted">e mais {totalDeSugestoes - sugestoes.length}.</span>
                            )}
                        </>
                    ) : (
                        // Cliente: a lista não vem (é da equipe); o número vem, e a decisão é dele no Planejamento.
                        <span className="text-[12.5px] text-trilha-muted">
                            {plural(totalDeSugestoes, 'combinação sugerida espera', 'combinações sugeridas esperam')} decisão no Planejamento.
                        </span>
                    )}
                </Caixa>
            )}
        </>
    );

    const rodape = [];
    if (produto.etapa === 'lista') {
        rodape.push(
            <span key="dica" className="mr-auto self-center text-[12.5px] text-trilha-muted">
                Ao completar, segue sozinho para o mapeamento.
            </span>,
        );
    }
    if (cliente && produto.url_ficha) {
        rodape.push(
            <LinkDeAcao key="ficha" href={produto.url_ficha} primario={produto.etapa === 'lista'} data-link="ficha">
                {produto.etapa === 'lista' ? 'Completar no cadastro' : 'Ver no cadastro'}
            </LinkDeAcao>,
        );
    }
    if (produto.etapa === 'mapa') {
        const destino = cliente ? links?.planejamento : links?.portal_abrir;
        if (destino) {
            rodape.push(
                <LinkDeAcao key="mapa" href={destino} primario={cliente} data-link={cliente ? 'planejamento' : 'portal'}>
                    {cliente ? 'Decidir no Planejamento' : 'Abrir o Portal da empresa'}
                </LinkDeAcao>,
            );
        }
    }
    // O produto ainda no mapeamento tem o "Mapear" como ação principal; o já mapeado, o Publicador.
    const mapearEhPrincipal = podeMapear && produto.etapa === 'mapa';
    const botaoMapear = podeMapear && (
        <Botao key="mapear" primario={mapearEhPrincipal} data-acao="mapear" onClick={() => onAbrir(chaveDeMapear(produto))}>
            {produto.mapeado_em ? 'Mapear de novo' : 'Mapear'}
        </Botao>
    );
    if (botaoMapear && !mapearEhPrincipal) rodape.push(botaoMapear);
    if (!cliente && produto.url_publicador) {
        rodape.push(
            <LinkDeAcao key="publicador" href={produto.url_publicador} primario={!mapearEhPrincipal} data-link="publicador">
                Abrir no Publicador
            </LinkDeAcao>,
        );
    }
    if (botaoMapear && mapearEhPrincipal) rodape.push(botaoMapear);

    return {
        sobrelinha: cliente ? 'Seu produto' : 'Produto da lista',
        titulo: produto.nome || 'Produto sem nome',
        sub: (
            <>
                <span className="font-pub-mono font-semibold text-trilha-fg">{sku ?? 'sem SKU'}</span> · {produto.familia || 'sem família'}
            </>
        ),
        corpo,
        rodape,
    };
}

function conteudoDaOferta({ oferta, dados, visao, onAbrir, trabalho, planejamento, manterCaixa = false }) {
    const cliente = visao === 'cliente';
    const anuncios = oferta.anuncios ?? [];
    const componentes = oferta.componentes ?? [];
    const produtosDaLoja = new Set((dados.produtos ?? []).map((p) => p.id));
    // A oferta sem produto na Lista fica fora das ondas nesta entrega.
    // A caixa da onda: a oferta das etapas 3 e 4 com produto na Lista e, em qualquer etapa, a que está numa onda
    // (a que já andou ou não tem produto numa onda por começar só pode sair dela — e é ali que sai).
    const comOnda = trabalho && (manterCaixa || oferta.onda_id != null || (ETAPAS_DA_ONDA.includes(oferta.etapa) && !oferta.sem_produto));

    const corpo = (
        <>
            {oferta.sem_produto && (
                <div className="rounded-xl bg-trilha-surface2 px-[14px] py-3 text-[12.5px] text-trilha-fg2" data-caixa="sem-produto">
                    <b className="text-trilha-fg">Sem produto na lista.</b>{' '}
                    {cliente
                        ? 'Esta oferta não está ligada a nenhum produto da sua lista.'
                        : 'Esta oferta não está ligada a um produto do cadastro: veio da Lista SKUs ou de uma importação.'}
                </div>
            )}

            <Caixa titulo="Onde está" data-caixa="onde-esta">
                <OndeEsta etapa={oferta.etapa} />
            </Caixa>

            <Caixa titulo="Composição" extra={<span className="font-pub-mono text-[12.5px] font-semibold text-trilha-fg">{oferta.sku}</span>} data-caixa="composicao">
                <div className="flex flex-col">
                    {componentes.map((c, i) => (
                        <div key={`${c.sku}-${i}`} className={LINHA_DA_CAIXA}>
                            <span className="min-w-0 [overflow-wrap:anywhere]">
                                {c.quantidade}× {c.nome} <span className="font-pub-mono text-[12.5px] font-semibold text-trilha-fg2">{c.sku}</span>
                            </span>
                            {produtosDaLoja.has(c.produto_id) && (
                                <button type="button" className={BOTAO_TEXTO} onClick={() => onAbrir(chaveDoProduto({ id: c.produto_id }))}>
                                    Ver produto
                                </button>
                            )}
                        </div>
                    ))}
                </div>
                {(oferta.skus?.length ?? 0) > 1 && (
                    <span className="text-[12.5px] text-trilha-muted">
                        {oferta.skus.length} variações: <span className="font-pub-mono">{oferta.skus.join(' · ')}</span>
                    </span>
                )}
            </Caixa>

            <Caixa titulo="Situação" className={cn(oferta.alerta && 'border-trilha-warn/40 bg-trilha-warn-bg')} data-caixa="situacao">
                <span className={cn('text-[13px]', oferta.alerta ? 'font-semibold text-trilha-warn' : 'text-trilha-fg')}>{oferta.status}</span>
            </Caixa>

            {comOnda && <CaixaDaOnda oferta={oferta} dados={dados} visao={visao} {...(planejamento ?? {})} />}

            {!cliente && oferta.preparo && (
                <Caixa titulo="Preparação automática" data-caixa="preparo">
                    <div className="flex flex-col">
                        {PREPARO.map(([campo, rotulo]) => {
                            const estado = ESTADO_DO_PREPARO[oferta.preparo[campo]];

                            return (
                                <div key={campo} className={LINHA_DA_CAIXA}>
                                    <span>{rotulo}</span>
                                    {estado ? (
                                        <Pilula tom={estado.tom}>{estado.rotulo}</Pilula>
                                    ) : (
                                        // null: não se aplica a esta oferta, ou o Publicador não deixou dado para ler.
                                        <span className="text-[12.5px] text-trilha-muted" title="Não se aplica ou sem dado">
                                            —
                                        </span>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                </Caixa>
            )}

            <Caixa titulo="Anúncios" data-caixa="anuncios">
                {anuncios.length ? (
                    <div className="flex flex-col">
                        {anuncios.map((anuncio, i) => (
                            <div key={`${anuncio.tipo}-${anuncio.mlb ?? i}`} className={LINHA_DA_CAIXA}>
                                <span className="min-w-0 [overflow-wrap:anywhere]">
                                    <b className="font-semibold">{anuncio.tipo}</b>
                                    {!cliente && anuncio.mlb && <span className="ml-2 font-pub-mono text-[12.5px] text-trilha-fg2">{anuncio.mlb}</span>}
                                </span>
                                <Pilula tom={anuncio.situacao ? 'warn' : 'ok'}>{anuncio.situacao ?? 'No ar'}</Pilula>
                            </div>
                        ))}
                    </div>
                ) : (
                    <span className="text-[12.5px] text-trilha-muted">Nenhum anúncio publicado ainda.</span>
                )}
            </Caixa>
        </>
    );

    const rodape = [];
    if (!cliente && oferta.url_publicador) {
        rodape.push(
            <LinkDeAcao key="publicador" href={oferta.url_publicador} primario data-link="publicador">
                Abrir no Publicador
            </LinkDeAcao>,
        );
    }

    return {
        sobrelinha: `${oferta.tipo} · ${ETAPA_POR_CHAVE[oferta.etapa]?.titulo ?? ''}`,
        titulo: oferta.nome,
        sub: (
            <>
                <span className="font-pub-mono font-semibold text-trilha-fg">{oferta.sku}</span> = {composicao(oferta)}
            </>
        ),
        corpo,
        rodape,
    };
}

// ═══ A gaveta: a casca de todos os painéis ═══════════════════════════════════

const GavetaContexto = createContext({ idDoTitulo: undefined, onFechar: () => {} });

/**
 * O clique no fundo escurecido fecha o painel — menos o 2º clique de um duplo clique: ele cai no fundo que o
 * 1º clique (na linha ou no cartão) acabou de abrir, e fecharia o que a pessoa quis abrir.
 */
export const fecharNoFundo = (onFechar) => (evento) => {
    if (evento?.detail > 1) return;
    onFechar();
};

/**
 * Para onde o foco volta quando quem abriu sumiu: a linha do item (o painel de
 * mapear volta para a linha do produto; a onda, para o cartão dela — e, se a
 * onda foi apagada e o cartão sumiu, para o "+ Nova onda") ou, sem linha na
 * vista, a própria vista (achado T3 da revisão de 31/10).
 */
function alvoDaVolta(chave) {
    const candidatas = [chave];
    if (typeof chave === 'string' && chave.startsWith('m:')) candidatas.push(`p:${chave.slice(2)}`);
    if (typeof chave === 'string' && chave.startsWith('w:')) candidatas.push('w:nova');
    const comChave = [...document.querySelectorAll('[data-chave]')];
    for (const c of candidatas) {
        const achado = comChave.find((el) => el.dataset.chave === c);
        if (achado) return achado;
    }

    return document.getElementById('trilha-vista');
}

/**
 * @param {{
 *   chave: string,
 *   origem: {current: ?HTMLElement},
 *   onFechar: () => void,
 *   children: React.ReactNode,
 * }} props
 */
export function Gaveta({ chave, origem, onFechar, children }) {
    const painel = useRef(null);
    const idDoTitulo = useId();
    // A chave de agora, para a limpeza ao fechar (o painel pode ter trocado de item por dentro).
    const chaveAtual = useRef(chave);
    chaveAtual.current = chave;

    // Uma vez por abertura (dependências vazias de propósito): a página de trás para de
    // rolar e, ao fechar, o foco volta para quem abriu — ou, se ele sumiu (o "Abrir na
    // trilha" do Mapa da loja some quando a aba troca), para a linha do item.
    useEffect(() => {
        const rolagemAntes = document.body.style.overflow;
        document.body.style.overflow = 'hidden';

        return () => {
            document.body.style.overflow = rolagemAntes;
            const quemAbriu = origem?.current;
            const alvo = quemAbriu?.isConnected ? quemAbriu : alvoDaVolta(chaveAtual.current);
            alvo?.focus?.();
        };
    }, []);

    // A cada painel mostrado (ao abrir e ao trocar por dentro), o foco vai para ele — o botão
    // clicado sumiu com o conteúdo velho. O painel com formulário marca o campo do começo.
    useEffect(() => {
        const inicial = painel.current?.querySelector('[data-foco-inicial]');
        (inicial ?? painel.current)?.focus();
    }, [chave]);

    useEffect(() => {
        const aoTeclar = (evento) => {
            if (evento.key !== 'Escape') return;
            evento.preventDefault();
            onFechar();
        };
        document.addEventListener('keydown', aoTeclar);

        return () => document.removeEventListener('keydown', aoTeclar);
    }, [onFechar]);

    // Tab e Shift+Tab giram dentro do painel (é modal: o fundo não pode receber foco).
    const prenderFoco = (evento) => {
        if (evento.key !== 'Tab' || !painel.current) return;
        const focaveis = [...painel.current.querySelectorAll(FOCAVEIS)];
        if (!focaveis.length) return;
        const primeiro = focaveis[0];
        const ultimo = focaveis[focaveis.length - 1];
        const ativo = document.activeElement;
        if (evento.shiftKey && (ativo === primeiro || ativo === painel.current)) {
            evento.preventDefault();
            ultimo.focus();
        } else if (!evento.shiftKey && ativo === ultimo) {
            evento.preventDefault();
            primeiro.focus();
        }
    };

    return (
        <div data-painel-lateral={chave}>
            <div aria-hidden="true" className="trilha-sombra fixed inset-0 z-40" onClick={fecharNoFundo(onFechar)} />
            <aside
                ref={painel}
                role="dialog"
                aria-modal="true"
                aria-labelledby={idDoTitulo}
                tabIndex={-1}
                onKeyDown={prenderFoco}
                className={cn(
                    'trilha-painel-entra fixed bottom-0 right-0 top-0 z-[41] flex w-[min(560px,100vw)] flex-col border-l border-trilha-line bg-trilha-bg text-trilha-fg outline-none',
                    'pb-[env(safe-area-inset-bottom,0px)] pr-[env(safe-area-inset-right,0px)] pt-[env(safe-area-inset-top,0px)]',
                )}
            >
                <GavetaContexto.Provider value={{ idDoTitulo, onFechar }}>{children}</GavetaContexto.Provider>
            </aside>
        </div>
    );
}

/**
 * O miolo de um painel: sobrelinha, título (é o rótulo do diálogo) e o "Fechar"
 * no alto; o corpo rola; o rodapé, quando há, leva as ações.
 */
export function MioloDaGaveta({ sobrelinha, titulo, sub = null, rodape = null, ocupado = false, children }) {
    const { idDoTitulo, onFechar } = useContext(GavetaContexto);
    const temRodape = Array.isArray(rodape) ? rodape.filter(Boolean).length > 0 : !!rodape;

    return (
        <>
            <div className="flex items-start justify-between gap-2.5 border-b border-trilha-line px-5 pb-[14px] pt-[18px]">
                <div className="min-w-0">
                    <Sobrelinha>{sobrelinha}</Sobrelinha>
                    <h2 id={idDoTitulo} className="mt-1 font-sans text-[18px] font-bold leading-snug tracking-normal [overflow-wrap:anywhere]">
                        {titulo}
                    </h2>
                    {sub && <div className="mt-1 text-[12.5px] text-trilha-muted [overflow-wrap:anywhere]">{sub}</div>}
                </div>
                <Botao onClick={onFechar}>Fechar</Botao>
            </div>

            <div className="flex min-h-0 flex-1 flex-col gap-4 overflow-auto px-5 py-4" aria-busy={ocupado || undefined}>
                {children}
            </div>

            {temRodape && <div className="flex flex-wrap items-center justify-end gap-2 border-t border-trilha-line px-5 py-3">{rodape}</div>}
        </>
    );
}

/**
 * O painel do produto ou da oferta (`p:`/`o:`).
 *
 * @param {{
 *   entrada: {tipo: 'p'|'o', chave: string, etapa: ?string, item: object},
 *   dados: {produtos: Array, ofertas: Array, ondas?: Array},
 *   visao: 'equipe'|'cliente',
 *   links: object,
 *   onAbrir: (chave: string) => void,
 *   trabalho?: boolean,
 *   podeEditar?: boolean,
 *   planejamento?: object,
 * }} props  `planejamento`: o que a caixa da onda precisa para gravar (`CaixaDaOnda`).
 */
export function PainelDoItem({ entrada, dados, visao, links, onAbrir, trabalho = false, podeEditar = false, planejamento = null }) {
    // Depois de uma gravação na caixa da onda ela fica na tela, mesmo que a oferta deixe de ter onda (o "Tirar da
    // onda" da que já andou ou não tem produto): o resultado continua à vista e o foco tem onde ficar. A chave do
    // painel troca a cada item, e isto começa do zero.
    const [caixaGravou, setCaixaGravou] = useState(false);
    const conteudo = entrada.tipo === 'p'
        ? conteudoDoProduto({ produto: entrada.item, dados, visao, links, onAbrir, trabalho, podeEditar })
        : conteudoDaOferta({
            oferta: entrada.item,
            dados,
            visao,
            onAbrir,
            trabalho,
            manterCaixa: caixaGravou,
            planejamento: { ...(planejamento ?? {}), podeEditar, onGravou: () => setCaixaGravou(true) },
        });

    return (
        <MioloDaGaveta sobrelinha={conteudo.sobrelinha} titulo={conteudo.titulo} sub={conteudo.sub} rodape={conteudo.rodape}>
            {conteudo.corpo}
        </MioloDaGaveta>
    );
}
