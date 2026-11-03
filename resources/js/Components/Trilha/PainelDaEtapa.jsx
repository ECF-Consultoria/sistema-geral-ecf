import { useId, useMemo } from 'react';
import { CHAVE_NOVO_PRODUTO, etapaDaTrilha, indiceDaEtapa, itensDaEtapa, todosNaOrdem, ETAPAS } from '@/lib/trilha';
import { cn } from '@/lib/utils';
import CartoesDasOndas from '@/Components/Trilha/CartoesDasOndas';
import LinhaDoItem from '@/Components/Trilha/LinhaDoItem';
import { Botao, LinkDeAcao, Sobrelinha } from '@/Components/Trilha/Pecas';
import { plural } from '@/Components/Trilha/formatos';

// ─── Trilha de produtos — o painel embaixo da barra de etapas ───────────────
//
// "Ver tudo" (`PainelGeral`) e as etapas 2 a 6 (`PainelDaEtapa`), como o
// `painelGeral()`/`painelEtapa()`/`painelLanc()` do protótipo. A etapa 1 tem a
// planilha própria (`PainelDaLista`), que reusa a moldura daqui.
//
// Na 1ª entrega era SÓ LEITURA: os botões do protótipo viraram links para as
// telas que já fazem aquilo. Cada link só aparece para quem o recebeu nas props
// (a porta do cliente não recebe o do Publicador nem o "Abrir o Portal" da
// equipe; a da equipe não recebe os do Portal do cliente).
//
// Com a trilha de trabalho (`trabalho`, 2ª entrega): as etapas 3 e 4 mostram os
// cartões das ondas no lugar da nota "ondas ainda não ligadas" (o "+ Nova onda" só no
// Planejamento e só para quem escreve), os textos das etapas são os da trilha de
// trabalho e os links das etapas 2 a 4 viram secundários — o trabalho é feito
// aqui. Sem `trabalho`, tudo como na 1ª entrega.
//
// Da 3ª entrega (o cadastro na trilha): quem escreve ganha o "+ Produto na lista"
// (a gaveta `e:novo`) no "Ver tudo" e na Lista, como a ação principal; os links
// para as outras telas viram secundários ao lado dele.

const LINKS_DO_PAINEL = {
    geral: [
        ['produtos', 'Abrir o cadastro de produtos'],
        ['portal_abrir', 'Abrir o Portal da empresa'],
        ['publicador', 'Abrir o Publicador'],
    ],
    lista: [
        ['produtos', 'Abrir o cadastro de produtos'],
        ['portal_abrir', 'Abrir o Portal da empresa'],
    ],
    mapa: [
        ['planejamento', 'Abrir o Planejamento'],
        ['portal_abrir', 'Abrir o Portal da empresa'],
    ],
    plano: [['portal_abrir', 'Abrir o Portal da empresa']],
    robo: [['publicador', 'Abrir o Publicador']],
    revisao: [['publicador', 'Abrir o Publicador']],
    lanc: [['publicador', 'Abrir o Publicador']],
};

// O texto de vazio do protótipo, ajustado ao que o sistema faz hoje (ainda não há ondas).
const VAZIO = {
    mapa: 'Nada para mapear: os produtos completos já viraram ofertas.',
    plano: 'Nenhuma oferta esperando o planejamento.',
    robo: 'O robô não tem nada na fila.',
    revisao: 'Nada para revisar agora.',
    lanc: 'Nenhuma oferta no ar ainda.',
};

/**
 * O texto da etapa sem linhas. Com a trilha de trabalho, o Planejamento vazio diz o que o dado mostra: "todas já
 * estão numa onda" só quando TODA oferta com produto tem onda (e há ao menos uma numa onda) — a que andou sem onda
 * não está em onda nenhuma. Em qualquer outro caso, o neutro: não há o que planejar.
 */
function textoDeVazio(chave, trabalho, dados) {
    if (trabalho && chave === 'plano') {
        const ofertas = dados?.ofertas ?? [];
        const todasEmOnda = ofertas.some((o) => o.onda_id != null) && ofertas.every((o) => o.onda_id != null || o.sem_produto);

        return todasEmOnda ? 'Todas as ofertas já estão numa onda.' : 'Nada para planejar agora: nenhuma oferta esperando onda.';
    }

    return VAZIO[chave] ?? 'Nada nesta etapa agora.';
}

/** As etapas que mostram os cartões das ondas (com a trilha de trabalho). */
const ETAPAS_COM_ONDAS = ['plano', 'robo'];

/** As etapas cujo trabalho passou a ser feito na trilha: os links para as outras telas viram secundários. */
const ETAPAS_DE_TRABALHO = ['mapa', 'plano', 'robo'];

// No lugar dos cartões de onda (Planejamento) e das abas da Jardinagem (etapa 6) do protótipo.
const NOTA = {
    // Sem a trilha de trabalho (a loja fora da chave): nada de "prazo", "data de publicação" nem "próxima
    // entrega" — o cliente também lê esta nota.
    plano: {
        titulo: 'Ondas: ainda não ligadas nesta loja',
        texto: 'Com as ondas ligadas, cada oferta entra numa onda com data, e o robô começa no dia dela. Por enquanto, a oferta espera aqui até a preparação do anúncio começar.',
    },
    lanc: {
        titulo: 'Protocolo e Jardinagem: em outro módulo',
        texto: 'O protocolo de lançamento e o acompanhamento de cada anúncio não entram nesta tela. Aqui fica o que já está no ar.',
    },
};

/**
 * "+ Produto na lista" (3ª entrega): abre a gaveta de criar (`e:novo`). Só para quem escreve — quem desenha
 * decide. O `data-chave` é por onde o foco volta quando a gaveta fecha sem o produto ter nascido.
 */
export function BotaoNovoProduto({ onAbrir, className }) {
    return (
        <Botao primario data-chave={CHAVE_NOVO_PRODUTO} data-acao="novo-produto" className={className} onClick={(evento) => onAbrir(CHAVE_NOVO_PRODUTO, evento.currentTarget)}>
            + Produto na lista
        </Botao>
    );
}

/** As ações do cabeçalho de um painel de quem escreve: o "+ Produto na lista" na frente e os links, secundários. */
export function AcoesComNovoProduto({ chave, links, onAbrir }) {
    return (
        <div className="flex flex-wrap gap-2">
            <BotaoNovoProduto onAbrir={onAbrir} />
            <AcoesDoPainel chave={chave} links={links} secundarios />
        </div>
    );
}

/** Os links do cabeçalho do painel; o primeiro é o destaque (menos quando todos são `secundarios`). */
export function AcoesDoPainel({ chave, links, secundarios = false }) {
    const acoes = (LINKS_DO_PAINEL[chave] ?? []).filter(([campo]) => links?.[campo]);
    if (!acoes.length) return null;

    return (
        <div className="flex flex-wrap gap-2">
            {acoes.map(([campo, rotulo], i) => (
                <LinkDeAcao key={campo} href={links[campo]} primario={!secundarios && i === 0} data-link={campo}>
                    {rotulo}
                </LinkDeAcao>
            ))}
        </div>
    );
}

/** A moldura de um painel: borda, cabeçalho com título e explicação, ações à direita. */
export function MolduraDoPainel({ sobrelinha = null, titulo, texto, acoes = null, children, ...resto }) {
    const idDoTitulo = useId();

    return (
        <section aria-labelledby={idDoTitulo} className="min-w-0 rounded-[14px] border border-trilha-line bg-trilha-surface" {...resto}>
            <div className="flex flex-wrap items-start justify-between gap-x-4 gap-y-2 border-b border-trilha-line px-[18px] py-4">
                <div className="min-w-0">
                    {sobrelinha && <Sobrelinha>{sobrelinha}</Sobrelinha>}
                    {/* Inter, entrelinha e espaçamento explícitos: o app.css põe h1–h5 em Manrope 800, 1.08. */}
                    <h2 id={idDoTitulo} className={cn('font-sans text-[16px] font-bold leading-snug tracking-normal text-trilha-fg [text-wrap:balance]', sobrelinha && 'mt-1')}>
                        {titulo}
                    </h2>
                    {texto && <p className="mt-1 max-w-[72ch] text-[13px] text-trilha-fg2">{texto}</p>}
                </div>
                {acoes}
            </div>
            {children}
        </section>
    );
}

/** A faixa "Pronto quando: …" da etapa (com os textos da trilha de trabalho, quando é ela). */
export function RegraDePronto({ chave, trabalho = false }) {
    return (
        <div className="flex flex-wrap gap-x-[18px] gap-y-1.5 border-b border-trilha-line bg-trilha-surface2 px-[18px] py-2.5 text-[12.5px] text-trilha-fg2">
            <span>
                <b className="text-trilha-fg">Pronto quando:</b> {etapaDaTrilha(chave, trabalho)?.pronto}
            </span>
        </div>
    );
}

/** "Etapa 2 de 6 · Estrategista" ("Etapa 1 de 6 · Equipe ECF" na Lista da trilha de trabalho). */
export const sobrelinhaDaEtapa = (chave, trabalho = false) => `Etapa ${indiceDaEtapa(chave) + 1} de ${ETAPAS.length} · ${etapaDaTrilha(chave, trabalho)?.dono ?? ''}`;

function Linhas({ entradas, dados, visao, onAbrir, vazio, trabalho = false, podeEditar = false, comPrevia = false }) {
    // A onda de cada oferta, para a segunda linha "{onda} · começa 05/11".
    const ondaPorId = useMemo(() => new Map((dados?.ondas ?? []).map((w) => [w.id, w])), [dados?.ondas]);
    if (!entradas.length) return <div className="px-[18px] py-[30px] text-center text-trilha-muted">{vazio}</div>;

    return (
        <div className="flex flex-col">
            {entradas.map((entrada) => (
                <LinhaDoItem
                    key={entrada.chave}
                    entrada={entrada}
                    visao={visao}
                    onAbrir={onAbrir}
                    trabalho={trabalho}
                    podeEditar={podeEditar}
                    comPrevia={comPrevia}
                    ondaPorId={ondaPorId}
                />
            ))}
        </div>
    );
}

/** "Ver tudo": a loja inteira na ordem da trilha. */
export function PainelGeral({ dados, visao, links, onAbrir, trabalho = false, podeEditar = false, comPrevia = false }) {
    const nProdutos = dados.produtos.length;
    const nOfertas = dados.ofertas.length;
    const noAr = dados.ofertas.filter((o) => o.etapa === 'lanc').length;

    return (
        <MolduraDoPainel
            data-painel="geral"
            titulo="Tudo da loja, na ordem da trilha"
            texto={`${plural(nProdutos, 'produto na lista', 'produtos na lista')} · ${plural(nOfertas, 'oferta', 'ofertas')} · ${noAr} no ar. Clique numa etapa acima para ver só o que está nela.`}
            acoes={podeEditar ? <AcoesComNovoProduto chave="geral" links={links} onAbrir={onAbrir} /> : <AcoesDoPainel chave="geral" links={links} />}
        >
            <Linhas
                entradas={todosNaOrdem(dados)}
                dados={dados}
                visao={visao}
                onAbrir={onAbrir}
                trabalho={trabalho}
                podeEditar={podeEditar}
                comPrevia={comPrevia}
                vazio="A trilha ainda está vazia: comece pela lista de produtos."
            />
        </MolduraDoPainel>
    );
}

/**
 * As etapas 2 a 6: cabeçalho da etapa, a regra de "pronto", a nota (quando há) ou
 * os cartões das ondas (etapas 3 e 4 com a trilha de trabalho) e as linhas.
 */
export function PainelDaEtapa({ chave, dados, visao, links, onAbrir, trabalho = false, podeEditar = false, comPrevia = false }) {
    const etapa = etapaDaTrilha(chave, trabalho);
    const comOndas = trabalho && ETAPAS_COM_ONDAS.includes(chave);
    // Com os cartões das ondas, a nota "ondas ainda não ligadas" sai; a da etapa 6 fica.
    const nota = comOndas ? null : NOTA[chave];
    const vazio = textoDeVazio(chave, trabalho, dados);

    return (
        <MolduraDoPainel
            data-painel={chave}
            sobrelinha={sobrelinhaDaEtapa(chave, trabalho)}
            titulo={etapa.titulo}
            texto={etapa.faz}
            acoes={<AcoesDoPainel chave={chave} links={links} secundarios={trabalho && ETAPAS_DE_TRABALHO.includes(chave)} />}
        >
            <RegraDePronto chave={chave} trabalho={trabalho} />
            {comOndas && <CartoesDasOndas dados={dados} podeCriar={podeEditar && chave === 'plano'} onAbrir={onAbrir} />}
            {nota && (
                <div className="border-b border-trilha-line px-[18px] py-4">
                    <div className="flex flex-col gap-1.5 rounded-xl border border-dashed border-trilha-line px-[14px] py-3" data-nota={chave}>
                        <b className="text-[14px] text-trilha-fg">{nota.titulo}</b>
                        <span className="text-[12.5px] text-trilha-muted">{nota.texto}</span>
                    </div>
                </div>
            )}
            <Linhas
                entradas={itensDaEtapa(dados, chave)}
                dados={dados}
                visao={visao}
                onAbrir={onAbrir}
                trabalho={trabalho}
                podeEditar={podeEditar}
                comPrevia={comPrevia}
                vazio={vazio}
            />
        </MolduraDoPainel>
    );
}
