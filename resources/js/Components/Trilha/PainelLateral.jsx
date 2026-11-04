import { Suspense, createContext, lazy, useContext, useEffect, useId, useRef, useState } from 'react';
import { RotateCw } from 'lucide-react';
import { ETAPA_POR_CHAVE, chaveDaPrevia, chaveDeCadastro, chaveDeMapear, chaveDoProduto, deveFecharNoEsc, estadoDaAprovacao, ofertasDoProduto, temPrevia } from '@/lib/trilha';
import { textoDoMapeamento } from '@/lib/trilhaMapeamento';
import { mensagemDoErro, tentarPreparoDeNovo } from '@/lib/trilhaEscrita';
import { cn } from '@/lib/utils';
import { Botao, Caixa, LinkDeAcao, MlbDoAnuncio, OfertaNaLista, OndeEsta, Pilula, SeloDoTipo, Sobrelinha } from '@/Components/Trilha/Pecas';
import CaixaDaOnda from '@/Components/Trilha/CaixaDaOnda';
import PilulaDaFalta from '@/Components/Trilha/PilulaDaFalta';
import { irParaOCadastro } from '@/lib/trilhaFaltas';
import { candidatasDaVolta } from '@/Components/Trilha/estadoDaTela';
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
// Da 3ª entrega (o cadastro na trilha): o produto ganha "Editar cadastro" (a
// gaveta `e:{id}`, em `Cadastro/`) para quem escreve, e a gaveta passa a ter uma
// GUARDA de saída: o painel de cadastro registra nela se há algo não salvo, e
// fechar (Esc, fundo, "Fechar") pergunta primeiro. O Esc que um campo já tratou
// (a lista aberta da família, por exemplo) não fecha a gaveta.
//
// Da 4ª entrega (4a): a oferta em revisão ou publicada ganha [Ver prévia] (a
// gaveta `v:{item}`, em `Revisao/`, por `lazy`) para a equipe com `escrita.revisao`.
//
// R10 (retorno do 1º teste, 04/11/2026): nenhum painel leva ao editor de fora —
// nem para o admin. "A trilha é o publicador."
//
// Visão do cliente (de verdade ou na simulação da equipe): sem MLB, sem preparo
// do agente, sem lista de sugestões e sem nenhuma
// escrita. A maior parte já chega cortada nas props; o resto é escondido aqui
// pela `visao` (e o `podeEditar` vem falso da página).
//
// A exceção da 4ª entrega (4f): o CLIENTE de verdade, com `cliente_escrita.fotos`,
// ganha no produto a caixa "Fotos" (envia e exclui enquanto o produto está na
// Lista). Ela mora em `Cliente/` e chega por `lazy` (o 2º caminho está em
// `Pages/Trilha/CadastroSobDemanda.jsx`); a confirmação da exclusão toma o rodapé
// do painel. A equipe e o "Ver como: Cliente" não recebem o endereço: nada muda.
//
// E a 4e: o cliente de verdade com `cliente_escrita.aprovacao` (`comAprovacao`)
// ganha, na oferta que está com ele (ou que ele já respondeu), o atalho para a
// gaveta `v:` dele — [Ver e aprovar] / [Ver o anúncio].
//
// R4 (retorno do 1º teste, 04/11/2026): com a preparação parada, a caixa
// "Preparação automática" diz a causa e o que fazer (`oferta.parada`, só da equipe)
// e, para quem escreve, tem o [Tentar de novo] (`escrita.preparo`).

// Só o cliente com `cliente_escrita.fotos` chega a desenhar esta (a página só passa `fotosDoCliente` a ele).
const FotosDoCliente = lazy(() => import('@/Components/Trilha/Cliente/FotosDoProduto'));

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

/**
 * R4 (retorno do 1º teste, 04/11/2026): a causa de a preparação ter parado e o que fazer — pelo `parada.motivo` que o
 * servidor manda (`SituacaoDasOfertas::parada`; a causa do erro vem do preparo, `MotivoDaFalhaDaIa`). Mudou o código lá,
 * muda aqui. Só a equipe recebe (`parada` é nula na porta do cliente).
 */
export const CAUSA_DA_PARADA = {
    sem_credito: { causa: 'A IA está sem crédito:', fazer: 'avise o responsável pela conta do Gemini.' },
    chave: { causa: 'A chave da IA foi recusada:', fazer: 'avise o responsável pela conta do Gemini.' },
    limite: { causa: 'A IA atingiu o limite de uso por agora.', fazer: 'Tente de novo em alguns minutos.' },
    indisponivel: { causa: 'A IA não respondeu a tempo.', fazer: 'Tente de novo.' },
    nao_terminou: { causa: 'A preparação parou no meio.', fazer: 'Tente de novo.' },
    desistiu: { causa: 'O anúncio ficou em uso e a preparação não gravou.', fazer: 'Tente de novo.' },
    outro: { causa: 'A preparação falhou.', fazer: 'Tente de novo.' },
    imagens: { causa: 'As imagens automáticas não saíram depois de duas tentativas.', fazer: null },
};

/** O texto inteiro da causa (o que a caixa diz). */
export const textoDaParada = (parada) => {
    const c = CAUSA_DA_PARADA[parada?.motivo] ?? CAUSA_DA_PARADA.outro;

    return c.fazer ? `${c.causa} ${c.fazer}` : c.causa;
};

/**
 * R4: a causa de a preparação ter parado, o que fazer e o [Tentar de novo] — só com `parada.tentar` (alguma etapa do
 * TEXTO parou; o preparo não refaz as imagens) e o endereço `escrita.preparo` (quem escreve). O pedido refaz SÓ as etapas
 * que pararam, pelo próprio preparo, na fila da IA; depois a Lista recarrega ("Na fila do agente"). O 409 (já pedido,
 * nada parado) também recarrega: a linha passa a dizer o que vale agora.
 */
/**
 * O endereço do [Tentar de novo] do item: o `escrita.preparo` com o id no lugar do marcador (o mesmo `__ITEM__` de
 * `ApresentacaoDaTrilha::MARCADOR_DO_ITEM`). Como na prévia, quem monta é a caixa; `trilhaEscrita` recebe pronto.
 */
export const urlDoPreparo = (escrita, itemId) => (escrita?.preparo ? String(escrita.preparo).replace('__ITEM__', encodeURIComponent(itemId)) : null);

function ParadaDoPreparo({ parada, itemId, escrita = null, onAvisar = () => {}, onRecarregar = null }) {
    const [pedindo, setPedindo] = useState(false);
    const [erro, setErro] = useState(null);
    const podeTentar = !!parada?.tentar && !!escrita?.preparo;

    const tentar = async () => {
        if (pedindo) return;
        setPedindo(true);
        setErro(null);
        try {
            const r = await tentarPreparoDeNovo(urlDoPreparo(escrita, itemId));
            onAvisar?.(r?.preparo?.mensagem ?? 'Pedido feito.', 'sucesso');
            await onRecarregar?.();
        } catch (falha) {
            setErro(mensagemDoErro(falha));
            if (falha?.response?.status === 409) await onRecarregar?.();
        } finally {
            setPedindo(false);
        }
    };

    return (
        <div className="mt-2 flex flex-col items-start gap-2 rounded-lg border border-trilha-bad/30 bg-trilha-bad-bg px-3 py-2.5 text-[12.5px]" data-parada={parada?.motivo ?? ''}>
            <span className="text-trilha-fg [overflow-wrap:anywhere]" data-causa-da-parada="">
                {textoDaParada(parada)}
            </span>
            {podeTentar && (
                <Botao data-acao="tentar-preparo-de-novo" ocupado={pedindo} onClick={tentar}>
                    <RotateCw size={14} aria-hidden="true" className={cn(pedindo && 'animate-spin motion-reduce:animate-none')} />
                    {pedindo ? 'Pedindo…' : 'Tentar de novo'}
                </Botao>
            )}
            <span role="status" className="text-trilha-bad empty:hidden" data-erro-da-parada="">
                {erro ?? ''}
            </span>
        </div>
    );
}

/** O que a caixa "Fotos" do cliente mostra enquanto o JS dela chega. */
function AbrindoAsFotos() {
    return (
        <Caixa titulo="Fotos" data-caixa="fotos-do-cliente" aria-busy="true">
            <span className="text-[12.5px] text-trilha-muted" data-abrindo-fotos="">
                Abrindo as fotos…
            </span>
        </Caixa>
    );
}

function conteudoDoProduto({ produto, dados, visao, links, onAbrir, trabalho, podeEditar, fotosDoCliente = null }) {
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
                <OndeEsta etapa={produto.etapa} feitas={produto.etapa ? null : 2} trabalho={trabalho} />
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
                                {/* R11: quem escreve vai da falta ao campo dela, na gaveta de cadastro; os outros leem. */}
                                <PilulaDaFalta
                                    falta={falta}
                                    onIr={irParaOCadastro(onAbrir, produto, podeEditar)}
                                />
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

            {/* 4f: as fotos que o cliente envia — só o cliente de verdade, com o endereço (a página decide). */}
            {cliente && fotosDoCliente?.base && (
                <Suspense fallback={<AbrindoAsFotos />}>
                    <FotosDoCliente
                        produto={produto}
                        base={fotosDoCliente.base}
                        onRecarregar={fotosDoCliente.onRecarregar}
                        onAvisar={fotosDoCliente.onAvisar}
                        onRodape={fotosDoCliente.onRodape}
                    />
                </Suspense>
            )}

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
    // Com a trilha de trabalho a trilha substitui o Portal (3ª entrega, F5): nenhum atalho para o cadastro de lá — o
    // cliente acompanha, quem cadastra é a equipe, aqui.
    if (cliente && produto.url_ficha && !trabalho) {
        rodape.push(
            <LinkDeAcao key="ficha" href={produto.url_ficha} primario={produto.etapa === 'lista'} data-link="ficha">
                {produto.etapa === 'lista' ? 'Completar no cadastro' : 'Ver no cadastro'}
            </LinkDeAcao>,
        );
    }
    // No mapeamento, sem a trilha de trabalho, o caminho era o Portal (o Planejamento do cliente, o Portal da empresa
    // para a equipe). Com ela, o "Mapear" já está aqui e nenhum link leva ao Portal (F5).
    if (produto.etapa === 'mapa' && !trabalho) {
        const destino = cliente ? links?.planejamento : links?.portal_abrir;
        if (destino) {
            rodape.push(
                <LinkDeAcao key="mapa" href={destino} primario={cliente} data-link={cliente ? 'planejamento' : 'portal'}>
                    {cliente ? 'Decidir no Planejamento' : 'Abrir o Portal da empresa'}
                </LinkDeAcao>,
            );
        }
    }
    // "Editar cadastro" (3ª entrega): a gaveta de cadastro, para quem escreve e com o produto que ainda não andou
    // (o travado só se lê — aqui mesmo). Na Lista é a ação principal; fora dela, secundária.
    const podeEditarCadastro = podeEditar && !produto.cadastro_travado;
    if (podeEditarCadastro) {
        rodape.push(
            <Botao key="cadastro" primario={produto.etapa === 'lista'} data-acao="editar-cadastro" onClick={() => onAbrir(chaveDeCadastro(produto))}>
                Editar cadastro
            </Botao>,
        );
    }
    // O produto ainda no mapeamento tem o "Mapear" como ação principal (o último); o já mapeado, "Mapear de novo" como
    // secundária. R10 (04/11/2026): nenhum link leva ao editor de fora — "a trilha é o publicador", inclusive para o admin.
    const mapearEhPrincipal = podeMapear && produto.etapa === 'mapa';
    if (podeMapear) {
        rodape.push(
            <Botao key="mapear" primario={mapearEhPrincipal} data-acao="mapear" onClick={() => onAbrir(chaveDeMapear(produto))}>
                {produto.mapeado_em ? 'Mapear de novo' : 'Mapear'}
            </Botao>,
        );
    }

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

function conteudoDaOferta({ oferta, dados, visao, onAbrir, trabalho, planejamento, manterCaixa = false, comPrevia = false, comAprovacao = false }) {
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
                <OndeEsta etapa={oferta.etapa} trabalho={trabalho} />
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
                    {/* R4: parou — a causa, o que fazer e (para quem escreve) o [Tentar de novo]. */}
                    {oferta.parada && (
                        <ParadaDoPreparo
                            parada={oferta.parada}
                            itemId={oferta.id}
                            escrita={planejamento?.podeEditar ? planejamento?.escrita : null}
                            onAvisar={planejamento?.onAvisar}
                            onRecarregar={planejamento?.onRecarregar}
                        />
                    )}
                </Caixa>
            )}

            <Caixa titulo="Anúncios" data-caixa="anuncios">
                {anuncios.length ? (
                    <div className="flex flex-col">
                        {anuncios.map((anuncio, i) => (
                            <div key={`${anuncio.tipo}-${anuncio.mlb ?? i}`} className={LINHA_DA_CAIXA}>
                                <span className="min-w-0 [overflow-wrap:anywhere]">
                                    <b className="font-semibold">{anuncio.tipo}</b>
                                    {/* O MLB é da equipe; com o link (só a porta da equipe o recebe), abre o anúncio em outra aba. */}
                                    {!cliente && <MlbDoAnuncio anuncio={anuncio} className="ml-2" />}
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
    // A prévia do anúncio (4a): na revisão é a ação principal (o "Revisar" da linha abre ela); publicada, conferência.
    const verPrevia = !cliente && comPrevia && temPrevia(oferta);
    const previaEhPrincipal = verPrevia && oferta.etapa === 'revisao';
    if (verPrevia) {
        rodape.push(
            <Botao key="previa" primario={previaEhPrincipal} data-acao="ver-previa" onClick={() => onAbrir(chaveDaPrevia(oferta))}>
                Ver prévia
            </Botao>,
        );
    }
    // 4e: o cliente abre a gaveta dele (`v:`) no anúncio que está com ele — a ação principal enquanto ele não responde.
    const aprovacao = cliente && comAprovacao ? estadoDaAprovacao(oferta) : null;
    if (aprovacao) {
        rodape.push(
            <Botao key="aprovacao" primario={aprovacao === 'com_cliente'} data-acao="ver-anuncio-do-cliente" onClick={() => onAbrir(chaveDaPrevia(oferta))}>
                {aprovacao === 'com_cliente' ? 'Ver e aprovar' : 'Ver o anúncio'}
            </Botao>,
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

const GavetaContexto = createContext({ idDoTitulo: undefined, onFechar: () => {}, guarda: null });

/**
 * A guarda de saída da gaveta (3ª entrega): o painel com formulário registra `{mexido(), confirmar(seguir)}` e a
 * página pergunta a ela antes de fechar, de abrir outro item por dentro e de trocar o "Ver como". Com algo mexido,
 * o painel mostra a confirmação no rodapé e chama `seguir` se a pessoa descartar (ou salvar). `guardiao` precisa
 * ser estável (as funções leem o estado de agora por referência).
 */
export function useGuardaDaGaveta(guardiao) {
    const { guarda } = useContext(GavetaContexto);
    useEffect(() => {
        if (!guarda) return undefined;
        guarda.current = guardiao;

        return () => {
            if (guarda.current === guardiao) guarda.current = null;
        };
    }, [guarda, guardiao]);
}

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
 * mapear e o de cadastro voltam para a linha do produto; a onda, para o cartão
 * dela — e, se a onda foi apagada e o cartão sumiu, para o "+ Nova onda"; o
 * produto novo, para o "+ Produto na lista") ou, sem linha na vista, a própria
 * vista (achado T3 da revisão de 31/10). A ordem é `candidatasDaVolta`.
 */
function alvoDaVolta(chave) {
    const comChave = [...document.querySelectorAll('[data-chave]')];
    for (const c of candidatasDaVolta(chave)) {
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
 *   guarda?: ?{current: ?{mexido: () => boolean, confirmar: (seguir: () => void) => void}},
 *   children: React.ReactNode,
 * }} props  `onFechar` já passa pela guarda (a página monta); `guarda` é onde o painel com formulário se registra.
 */
export function Gaveta({ chave, origem, onFechar, guarda = null, children }) {
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
            // O Esc que um campo de dentro já tratou (fechou a lista dele) não fecha a gaveta.
            if (!deveFecharNoEsc(evento)) return;
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
                <GavetaContexto.Provider value={{ idDoTitulo, onFechar, guarda }}>{children}</GavetaContexto.Provider>
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
 *   comPrevia?: boolean,
 *   comAprovacao?: boolean,
 *   planejamento?: object,
 *   fotosDoCliente?: ?{base: string, onRecarregar?: () => any, onAvisar?: (texto: string, tipo?: string) => void},
 * }} props  `planejamento`: o que a caixa da onda precisa para gravar (`CaixaDaOnda`). `comPrevia`: a equipe com
 *   `escrita.revisao` — a oferta em revisão ou publicada ganha [Ver prévia] (4a). `fotosDoCliente`: o cliente de
 *   verdade com `cliente_escrita.fotos` (4f) — o produto ganha a caixa "Fotos". `comAprovacao`: o cliente de verdade
 *   com `cliente_escrita.aprovacao` (4e) — a oferta que está com ele ganha o atalho para a gaveta dele.
 */
export function PainelDoItem({ entrada, dados, visao, links, onAbrir, trabalho = false, podeEditar = false, comPrevia = false, comAprovacao = false, planejamento = null, fotosDoCliente = null }) {
    // Depois de uma gravação na caixa da onda ela fica na tela, mesmo que a oferta deixe de ter onda (o "Tirar da
    // onda" da que já andou ou não tem produto): o resultado continua à vista e o foco tem onde ficar. A chave do
    // painel troca a cada item, e isto começa do zero.
    const [caixaGravou, setCaixaGravou] = useState(false);
    // 4f: a confirmação da exclusão de uma foto toma o rodapé do painel enquanto está aberta (a caixa a entrega aqui).
    const [rodapeDasFotos, setRodapeDasFotos] = useState(null);
    const conteudo = entrada.tipo === 'p'
        ? conteudoDoProduto({
            produto: entrada.item,
            dados,
            visao,
            links,
            onAbrir,
            trabalho,
            podeEditar,
            fotosDoCliente: fotosDoCliente?.base ? { ...fotosDoCliente, onRodape: setRodapeDasFotos } : null,
        })
        : conteudoDaOferta({
            oferta: entrada.item,
            dados,
            visao,
            onAbrir,
            trabalho,
            comPrevia: podeEditar && comPrevia,
            comAprovacao: !podeEditar && comAprovacao,
            manterCaixa: caixaGravou,
            planejamento: { ...(planejamento ?? {}), podeEditar, onGravou: () => setCaixaGravou(true) },
        });

    return (
        <MioloDaGaveta sobrelinha={conteudo.sobrelinha} titulo={conteudo.titulo} sub={conteudo.sub} rodape={rodapeDasFotos ?? conteudo.rodape}>
            {conteudo.corpo}
        </MioloDaGaveta>
    );
}
