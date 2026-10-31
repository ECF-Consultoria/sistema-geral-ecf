import { useId } from 'react';
import { ETAPA_POR_CHAVE, indiceDaEtapa, itensDaEtapa, todosNaOrdem, ETAPAS } from '@/lib/trilha';
import { cn } from '@/lib/utils';
import LinhaDoItem from '@/Components/Trilha/LinhaDoItem';
import { LinkDeAcao, Sobrelinha } from '@/Components/Trilha/Pecas';
import { plural } from '@/Components/Trilha/formatos';

// ─── Trilha de produtos — o painel embaixo da barra de etapas ───────────────
//
// "Ver tudo" (`PainelGeral`) e as etapas 2 a 6 (`PainelDaEtapa`), como o
// `painelGeral()`/`painelEtapa()`/`painelLanc()` do protótipo. A etapa 1 tem a
// planilha própria (`PainelDaLista`), que reusa a moldura daqui.
//
// Esta entrega é SÓ LEITURA: os botões do protótipo ("+ Nova onda", "Colocar na
// onda"…) viram links para as telas que já fazem isso. Cada link só aparece para
// quem o recebeu nas props (a porta do cliente não recebe o do Publicador nem o
// "Abrir o Portal" da equipe; a da equipe não recebe os do Portal do cliente).

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

// No lugar dos cartões de onda (Planejamento) e das abas da Jardinagem (etapa 6) do protótipo.
const NOTA = {
    plano: {
        titulo: 'Ondas: na próxima entrega',
        texto: 'Cada oferta vai entrar numa onda com data de publicação, e o robô começa na data da onda. Por enquanto, a oferta espera aqui até a preparação do anúncio começar.',
    },
    lanc: {
        titulo: 'Protocolo e Jardinagem: em outro módulo',
        texto: 'O protocolo de lançamento e o acompanhamento de cada anúncio não entram nesta tela. Aqui fica o que já está no ar.',
    },
};

/** Os links do cabeçalho do painel; o primeiro é o destaque. */
export function AcoesDoPainel({ chave, links }) {
    const acoes = (LINKS_DO_PAINEL[chave] ?? []).filter(([campo]) => links?.[campo]);
    if (!acoes.length) return null;

    return (
        <div className="flex flex-wrap gap-2">
            {acoes.map(([campo, rotulo], i) => (
                <LinkDeAcao key={campo} href={links[campo]} primario={i === 0} data-link={campo}>
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

/** A faixa "Pronto quando: …" da etapa. */
export function RegraDePronto({ chave }) {
    return (
        <div className="flex flex-wrap gap-x-[18px] gap-y-1.5 border-b border-trilha-line bg-trilha-surface2 px-[18px] py-2.5 text-[12.5px] text-trilha-fg2">
            <span>
                <b className="text-trilha-fg">Pronto quando:</b> {ETAPA_POR_CHAVE[chave]?.pronto}
            </span>
        </div>
    );
}

/** "Etapa 2 de 6 · Estrategista". */
export const sobrelinhaDaEtapa = (chave) => `Etapa ${indiceDaEtapa(chave) + 1} de ${ETAPAS.length} · ${ETAPA_POR_CHAVE[chave]?.dono ?? ''}`;

function Linhas({ entradas, visao, onAbrir, vazio }) {
    if (!entradas.length) return <div className="px-[18px] py-[30px] text-center text-trilha-muted">{vazio}</div>;

    return (
        <div className="flex flex-col">
            {entradas.map((entrada) => (
                <LinhaDoItem key={entrada.chave} entrada={entrada} visao={visao} onAbrir={onAbrir} />
            ))}
        </div>
    );
}

/** "Ver tudo": a loja inteira na ordem da trilha. */
export function PainelGeral({ dados, visao, links, onAbrir }) {
    const nProdutos = dados.produtos.length;
    const nOfertas = dados.ofertas.length;
    const noAr = dados.ofertas.filter((o) => o.etapa === 'lanc').length;

    return (
        <MolduraDoPainel
            data-painel="geral"
            titulo="Tudo da loja, na ordem da trilha"
            texto={`${plural(nProdutos, 'produto na lista', 'produtos na lista')} · ${plural(nOfertas, 'oferta', 'ofertas')} · ${noAr} no ar. Clique numa etapa acima para ver só o que está nela.`}
            acoes={<AcoesDoPainel chave="geral" links={links} />}
        >
            <Linhas
                entradas={todosNaOrdem(dados)}
                visao={visao}
                onAbrir={onAbrir}
                vazio="A trilha ainda está vazia: comece pela lista de produtos."
            />
        </MolduraDoPainel>
    );
}

/** As etapas 2 a 6: cabeçalho da etapa, a regra de "pronto", a nota (quando há) e as linhas. */
export function PainelDaEtapa({ chave, dados, visao, links, onAbrir }) {
    const etapa = ETAPA_POR_CHAVE[chave];
    const nota = NOTA[chave];

    return (
        <MolduraDoPainel
            data-painel={chave}
            sobrelinha={sobrelinhaDaEtapa(chave)}
            titulo={etapa.titulo}
            texto={etapa.faz}
            acoes={<AcoesDoPainel chave={chave} links={links} />}
        >
            <RegraDePronto chave={chave} />
            {nota && (
                <div className="border-b border-trilha-line px-[18px] py-4">
                    <div className="flex flex-col gap-1.5 rounded-xl border border-dashed border-trilha-line px-[14px] py-3" data-nota={chave}>
                        <b className="text-[14px] text-trilha-fg">{nota.titulo}</b>
                        <span className="text-[12.5px] text-trilha-muted">{nota.texto}</span>
                    </div>
                </div>
            )}
            <Linhas entradas={itensDaEtapa(dados, chave)} visao={visao} onAbrir={onAbrir} vazio={VAZIO[chave] ?? 'Nada nesta etapa agora.'} />
        </MolduraDoPainel>
    );
}
