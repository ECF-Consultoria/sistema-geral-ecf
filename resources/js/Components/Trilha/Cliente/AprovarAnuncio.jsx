import { useCallback, useEffect, useId, useMemo, useRef, useState } from 'react';
import { CircleCheck, ImageOff, MessageSquareText, RotateCw, Sparkles } from 'lucide-react';
import { cn } from '@/lib/utils';
import { aprovarPeloCliente, carregarPrevia, mensagemDoErro, pedirAjustePeloCliente } from '@/lib/trilhaEscrita';
import {
    DICA_DO_CAMPO,
    ESTADO_DO_CLIENTE,
    LIMITE_DO_PEDIDO,
    MSG_APROVADO,
    MSG_EM_PREPARO,
    MSG_PEDIDO_ENVIADO,
    NOTA_APROVADO,
    NOTA_PEDIDO,
    PERGUNTA_DA_SAIDA,
    PERGUNTA_DO_APROVAR,
    ROTULO_APROVANDO,
    ROTULO_CONFIRMAR_APROVACAO,
    ROTULO_DA_CRIADA,
    ROTULO_DO_APROVAR,
    ROTULO_DO_CAMPO,
    ROTULO_DO_ENVIAR_PEDIDO,
    ROTULO_DO_PEDIR_AJUSTE,
    ROTULO_ENVIANDO_PEDIDO,
    acoesDoCliente,
    anuncioDaEscolha,
    aprovacaoDoConflito,
    caminhoDaCategoria,
    conferirPedido,
    coresDoAnuncio,
    corpoDaAprovacao,
    corpoDoPedido,
    descricaoDoAnuncio,
    erroDoCliente,
    escolhaDoAnuncio,
    fichaDoAnuncio,
    fmtPreco,
    fotosDoAnuncio,
    parcelasDoAnuncio,
    precoParaOCliente,
    previaComAprovacao,
    previaDepoisDaAprovacao,
    previaDepoisDoPedido,
    publicacaoAndando,
    rotuloDaFoto,
    textoDaEntrega,
    textoDaPromocao,
    textoDasCriadas,
    textoDasParcelas,
    tiposDoAnuncio,
    urlsDoCliente,
    vistaParaOCliente,
} from '@/lib/trilhaAprovacao';
import { Botao, Caixa, Pilula } from '@/Components/Trilha/Pecas';
import { MioloDaGaveta, useGuardaDaGaveta } from '@/Components/Trilha/PainelLateral';

// ─── Trilha de produtos — o CLIENTE aprova o anúncio (4ª entrega, fatia 4e) ──
//
// §19.16 do desenho. Na porta do cliente de verdade, com `cliente_escrita.aprovacao`
// (a página decide; nunca a equipe, nem o "Ver como: Cliente"), o anúncio que a equipe
// mandou para ele abre aqui (`v:{item}`, a linha diz "Aprovar"):
// - a situação (espera a sua aprovação / você pediu ajuste / aprovado), com o pedido
//   dele citado;
// - o cartão do anúncio — uma versão ENXUTA própria: o da equipe mora em `Revisao/` e
//   na lib da prévia, que o cliente não baixa; aqui a foto que o agente criou diz
//   "criada pelo agente" e a entrega não usa as siglas do canal de venda;
// - "Preço" só leitura (o valor de cada tipo; nada da Precificação);
// - o rodapé [Pedir ajuste] (abre o campo de texto: obrigatório, até 1000, com o
//   contador) e [Aprovar anúncio] (confirmação "O anúncio vai ao ar depois da sua
//   aprovação.").
// Depois de aprovar: "Aprovado. A equipe publica em seguida." e a publicação contada
// em texto neutro. 409 (o anúncio mudou depois do envio, D55): a mensagem e
// [Recarregar]. O pedido escrito e não enviado não se perde sem perguntar (a guarda da
// gaveta).
//
// Esta pasta é do cliente e chega SÓ por `lazy` (a página a pede; o 2º caminho está em
// `Pages/Trilha/CadastroSobDemanda.jsx`). Não importa nada das pastas `Cadastro/` e
// `Revisao/` nem das libs da gaveta da equipe. Os textos são do CLIENTE: nada de
// marketplace, Portal nem Publicador; o que a IA fez é "do agente" (R9), o resto é
// "a equipe".

const BOTAO_DE_ALTERNAR = cn(
    'inline-flex items-center gap-1.5 whitespace-nowrap rounded-full border px-3 py-1 text-[12.5px] font-semibold',
    'focus-visible:[outline-style:solid] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-trilha-run',
);
const MARCADO = 'border-trilha-navy bg-trilha-navy text-trilha-on-navy';
const DESMARCADO = 'border-trilha-line bg-trilha-surface text-trilha-fg hover:border-trilha-muted';
const SUBTITULO = 'm-0 font-sans text-[12px] font-bold uppercase leading-snug tracking-[0.06em] text-trilha-fg2';
const FOCO = 'focus-visible:[outline-style:solid] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-trilha-run';

// ═══ O cartão do anúncio (versão enxuta do cliente) ══════════════════════════

/** A escolha entre poucas opções (os tipos, as cores): botões de alternar, um marcado. */
function Alternar({ rotulo, opcoes, valor, onEscolher, ...resto }) {
    return (
        <div role="group" aria-label={rotulo} className="flex flex-wrap items-center gap-1.5" {...resto}>
            {opcoes.map((o) => (
                <button
                    key={o.valor}
                    type="button"
                    aria-pressed={o.valor === valor}
                    data-opcao={o.valor}
                    onClick={() => onEscolher(o.valor)}
                    className={cn(BOTAO_DE_ALTERNAR, o.valor === valor ? MARCADO : DESMARCADO)}
                >
                    {o.capa && <img src={o.capa} alt="" loading="lazy" className="-ml-1.5 h-5 w-5 rounded-full object-cover" />}
                    {o.rotulo}
                </button>
            ))}
        </div>
    );
}

/** A galeria: a foto escolhida grande e as miniaturas — as que o agente criou, marcadas. */
function Galeria({ fotos, indice, onIndice }) {
    if (!fotos.length) {
        return (
            <div className="flex aspect-[4/3] flex-col items-center justify-center gap-1.5 bg-trilha-surface2 text-[12.5px] text-trilha-muted" data-sem-fotos="">
                <ImageOff size={20} aria-hidden="true" />
                Este anúncio ainda não tem foto.
            </div>
        );
    }
    const atual = fotos[indice] ?? fotos[0];
    const posicao = fotos.indexOf(atual);
    const criadas = textoDasCriadas(fotos);

    return (
        <div className="flex flex-col gap-2 bg-trilha-surface2 p-2" data-galeria="">
            <figure className="relative m-0 overflow-hidden rounded-lg bg-trilha-bg" data-foto-grande={atual.id}>
                <img src={atual.url} alt={rotuloDaFoto(atual, posicao, fotos.length)} loading="lazy" className="block aspect-[4/3] w-full object-contain" />
                {atual.criada && (
                    <Pilula tom="roxo" className="absolute bottom-2 left-2 border border-trilha-roxo/40" data-selo-criada="">
                        <Sparkles size={12} aria-hidden="true" />
                        {ROTULO_DA_CRIADA}
                    </Pilula>
                )}
                {posicao === 0 && (
                    <Pilula tom="neutro" className="absolute left-2 top-2 border border-trilha-line" data-capa="">
                        capa
                    </Pilula>
                )}
            </figure>
            {fotos.length > 1 && (
                <ul className="m-0 grid list-none grid-cols-[repeat(auto-fill,minmax(56px,1fr))] gap-1.5 p-0" aria-label="Fotos do anúncio">
                    {fotos.map((foto, i) => (
                        <li key={foto.id} className="min-w-0">
                            <button
                                type="button"
                                aria-pressed={i === posicao}
                                aria-label={rotuloDaFoto(foto, i, fotos.length)}
                                data-foto={foto.id}
                                data-criada={foto.criada ? '' : undefined}
                                onClick={() => onIndice(i)}
                                className={cn(
                                    'relative block w-full overflow-hidden rounded-md border-2 bg-trilha-bg',
                                    FOCO,
                                    i === posicao ? 'border-trilha-run' : foto.criada ? 'border-trilha-roxo/60' : 'border-transparent hover:border-trilha-line',
                                )}
                            >
                                <img src={foto.url} alt="" loading="lazy" className="block aspect-square w-full object-cover" />
                            </button>
                        </li>
                    ))}
                </ul>
            )}
            {criadas && (
                <span className="flex items-center gap-1.5 text-[12px] text-trilha-fg2" data-criadas="">
                    <i className="block h-2.5 w-2.5 shrink-0 rounded-[3px] bg-trilha-roxo" aria-hidden="true" />
                    {criadas}
                </span>
            )}
        </div>
    );
}

/**
 * A caixa "Anúncio": as abas (tipo e cor) e o cartão no jeito do anúncio — a galeria, a categoria, o título, o preço
 * (com as parcelas), a entrega, a descrição e a ficha. `inicial` (para o teste) abre noutra escolha.
 */
export function CartaoDoAnuncio({ previa, inicial = null }) {
    const tipos = useMemo(() => tiposDoAnuncio(previa), [previa]);
    const [escolha, setEscolha] = useState(() => escolhaDoAnuncio(previa, inicial ?? {}));
    const [indiceDaFoto, setIndiceDaFoto] = useState(0);
    const cores = coresDoAnuncio(previa, escolha.tipo);
    const anuncio = anuncioDaEscolha(previa, escolha);
    const fotos = fotosDoAnuncio(anuncio);
    const categoria = caminhoDaCategoria(previa);
    const descricao = descricaoDoAnuncio(previa);
    const grupos = fichaDoAnuncio(anuncio);
    const entrega = textoDaEntrega(anuncio?.envio);
    const parcelas = textoDasParcelas(parcelasDoAnuncio(anuncio));
    const promocao = textoDaPromocao(anuncio);
    const nomeDoTipo = tipos.find((t) => t.chave === escolha.tipo)?.nome ?? '';
    const rotuloDaCor = cores.length > 1 ? cores.find((v) => v.chave === escolha.variante)?.rotulo ?? '' : '';

    // Trocar de tipo ou de cor volta para a capa daquele anúncio.
    const escolher = (mudanca) => {
        setEscolha((atual) => escolhaDoAnuncio(previa, { ...atual, ...mudanca }));
        setIndiceDaFoto(0);
    };

    return (
        <Caixa titulo="Anúncio" data-caixa="anuncio">
            {tipos.length > 1 && (
                <Alternar
                    rotulo="Tipo do anúncio"
                    data-escolha="tipo"
                    opcoes={tipos.map((t) => ({ valor: t.chave, rotulo: t.nome }))}
                    valor={escolha.tipo}
                    onEscolher={(tipo) => escolher({ tipo })}
                />
            )}
            {cores.length > 1 && (
                <Alternar
                    rotulo="Cor"
                    data-escolha="variante"
                    opcoes={cores.map((v) => ({
                        valor: v.chave,
                        rotulo: v.rotulo,
                        capa: fotosDoAnuncio(anuncioDaEscolha(previa, { tipo: escolha.tipo, variante: v.chave }))[0]?.url ?? null,
                    }))}
                    valor={escolha.variante}
                    onEscolher={(variante) => escolher({ variante })}
                />
            )}

            <article
                className="overflow-hidden rounded-xl border border-trilha-line bg-trilha-surface"
                aria-label={['Anúncio', nomeDoTipo, rotuloDaCor].filter(Boolean).join(' · ')}
                data-anuncio={`${escolha.tipo}:${escolha.variante}`}
            >
                <Galeria fotos={fotos} indice={indiceDaFoto} onIndice={setIndiceDaFoto} />

                <div className="flex flex-col gap-2 border-t border-trilha-line px-[14px] py-3">
                    {categoria && (
                        <span className="text-[12px] text-trilha-muted [overflow-wrap:anywhere]" data-categoria="">
                            {categoria}
                        </span>
                    )}
                    {/* Inter e entrelinha explícitas: o app.css põe h1–h5 em Manrope 800, 1.08. */}
                    <h4 className="m-0 font-sans text-[16px] font-semibold leading-snug tracking-normal text-trilha-fg [overflow-wrap:anywhere]" data-titulo-do-anuncio="">
                        {anuncio?.titulo || <span className="font-normal text-trilha-muted">Sem título ainda</span>}
                    </h4>
                    <div className="flex flex-col gap-0.5" data-preco-do-anuncio="">
                        <span className="text-[24px] font-semibold leading-tight tabular-nums text-trilha-fg">{fmtPreco(anuncio?.preco)}</span>
                        {parcelas && (
                            <span className="text-[13px] font-semibold text-trilha-ok" data-parcelas="">
                                {parcelas}
                            </span>
                        )}
                        {promocao && (
                            <span className="text-[12px] text-trilha-muted" data-promocao="">
                                {promocao}
                            </span>
                        )}
                    </div>
                    {entrega && (
                        <span className="text-[12.5px] text-trilha-fg2" data-entrega="">
                            {entrega}
                        </span>
                    )}
                </div>

                <section className="flex flex-col gap-1.5 border-t border-trilha-line px-[14px] py-3" data-descricao="">
                    <h5 className={SUBTITULO}>Descrição</h5>
                    {descricao ? (
                        // Rola sozinha (descrição longa); focável para rolar pelo teclado.
                        <div
                            tabIndex={0}
                            role="region"
                            aria-label="Descrição do anúncio"
                            className={cn('max-h-[240px] overflow-auto whitespace-pre-wrap rounded-lg bg-trilha-surface2 px-2.5 py-2 text-[12.5px] leading-relaxed text-trilha-fg [overflow-wrap:anywhere]', FOCO)}
                        >
                            {descricao}
                        </div>
                    ) : (
                        <span className="text-[12.5px] text-trilha-muted">Sem descrição ainda.</span>
                    )}
                </section>

                {grupos.length > 0 && (
                    <section className="flex flex-col gap-2 border-t border-trilha-line px-[14px] py-3" data-ficha="">
                        <h5 className={SUBTITULO}>Ficha técnica</h5>
                        {grupos.map((g) => (
                            <div key={g.grupo} className="flex flex-col gap-1" data-grupo-da-ficha={g.grupo}>
                                <span className="text-[12.5px] font-semibold text-trilha-fg">{g.grupo}</span>
                                <dl className="m-0 flex flex-col overflow-hidden rounded-lg border border-trilha-line2 text-[12.5px]">
                                    {g.itens.map((item, i) => (
                                        <div key={`${item.nome}-${i}`} className="grid grid-cols-[minmax(0,1fr)_minmax(0,1.3fr)] border-b border-trilha-line2 last:border-b-0">
                                            <dt className="bg-trilha-surface2 px-2.5 py-1.5 text-trilha-fg2 [overflow-wrap:anywhere]">{item.nome}</dt>
                                            <dd className="m-0 px-2.5 py-1.5 text-trilha-fg [overflow-wrap:anywhere]">{item.valor}</dd>
                                        </div>
                                    ))}
                                </dl>
                            </div>
                        ))}
                    </section>
                )}
            </article>
        </Caixa>
    );
}

/** A caixa "Preço", só leitura: o valor de cada tipo (o da 1ª cor) e, quando muda por cor, o aviso. */
function CaixaDoPreco({ previa }) {
    const { linhas, porCor } = precoParaOCliente(previa);
    if (!linhas.length) return null;

    return (
        <Caixa titulo="Preço" data-caixa="preco-do-cliente">
            <dl className="m-0 flex flex-col">
                {linhas.map((l) => (
                    <div key={l.chave} className="flex items-baseline justify-between gap-2.5 border-b border-trilha-line2 py-2 text-[13px] last:border-b-0" data-preco-do-tipo={l.chave}>
                        <dt className="text-trilha-fg2">{l.nome}</dt>
                        <dd className="m-0 font-semibold tabular-nums text-trilha-fg">{fmtPreco(l.valor)}</dd>
                    </div>
                ))}
            </dl>
            {porCor && (
                <span className="text-[12.5px] text-trilha-muted" data-preco-por-cor="">
                    O preço muda conforme a cor: veja cada cor no anúncio acima.
                </span>
            )}
        </Caixa>
    );
}

const TOM_DA_SITUACAO = {
    run: 'border-trilha-run/30 bg-trilha-run-bg',
    ok: 'border-trilha-ok/40 bg-trilha-ok-bg',
    neutro: '',
};
const COR_DO_TITULO = { run: 'text-trilha-run', ok: 'text-trilha-ok', neutro: 'text-trilha-fg' };

/** A caixa de situação: em que pé está a aprovação, o pedido dele citado e, depois de aprovar, a publicação em texto neutro. */
function CaixaDaSituacao({ previa, onVerDeNovo = null }) {
    const vista = vistaParaOCliente(previa);
    const andando = vista.estado === 'aprovado' && publicacaoAndando(previa?.publicacao);

    return (
        <Caixa titulo="Situação" className={TOM_DA_SITUACAO[vista.tom]} data-caixa="situacao-do-cliente" data-estado-da-aprovacao={vista.estado ?? ''}>
            <span className={cn('flex items-center gap-1.5 text-[13px] font-semibold', COR_DO_TITULO[vista.tom])} data-titulo-da-situacao="">
                {vista.estado === 'aprovado' ? <CircleCheck size={15} aria-hidden="true" className="shrink-0" /> : <MessageSquareText size={15} aria-hidden="true" className="shrink-0" />}
                {vista.titulo}
            </span>
            {vista.pedido && (
                <figure className="m-0 flex flex-col gap-1" data-pedido-do-cliente="">
                    <figcaption className="text-[12px] font-semibold text-trilha-fg2">O que você escreveu:</figcaption>
                    <blockquote className="m-0 whitespace-pre-wrap rounded-lg border-l-[3px] border-trilha-line bg-trilha-surface px-3 py-2 text-[13px] leading-relaxed text-trilha-fg [overflow-wrap:anywhere]">
                        {vista.pedido}
                    </blockquote>
                </figure>
            )}
            {vista.detalhe && (
                <span className="text-[12.5px] text-trilha-fg2 [overflow-wrap:anywhere]" data-detalhe-da-situacao="">
                    {vista.detalhe}
                </span>
            )}
            {andando && onVerDeNovo && (
                <span>
                    <Botao data-acao="ver-situacao-de-novo" onClick={onVerDeNovo}>
                        <RotateCw size={14} aria-hidden="true" />
                        Ver como está agora
                    </Botao>
                </span>
            )}
        </Caixa>
    );
}

// ═══ O rodapé: [Pedir ajuste] e [Aprovar anúncio] ════════════════════════════

/**
 * A confirmação do [Aprovar anúncio], no RODAPÉ da gaveta: "O anúncio vai ao ar depois da sua aprovação." [Aprovar]
 * [Cancelar], com o foco em [Cancelar] (aprovar não se desfaz por aqui). O Esc é o mesmo que ele (o `preventDefault`
 * avisa a gaveta para não fechar). Aprovando, os dois ficam focáveis e ignoram o clique (`ocupado`).
 */
export function ConfirmacaoDaAprovacao({ cliente }) {
    const cancelar = useRef(null);
    const aprovando = cliente.enviando === 'aprovar';

    useEffect(() => {
        cancelar.current?.focus();
    }, []);

    return (
        <div
            className="flex w-full flex-wrap items-center justify-end gap-2"
            data-confirmacao-da-aprovacao=""
            onKeyDown={(evento) => {
                if (evento.key !== 'Escape') return;
                evento.preventDefault();
                if (!aprovando) cliente.cancelar();
            }}
        >
            <span role="alert" className="mr-auto min-w-0 self-center text-[12.5px] font-semibold text-trilha-fg [overflow-wrap:anywhere]">
                {PERGUNTA_DO_APROVAR}
            </span>
            <Botao primario ocupado={aprovando} data-acao="confirmar-aprovacao" onClick={cliente.aprovar}>
                <CircleCheck size={14} aria-hidden="true" />
                {aprovando ? ROTULO_APROVANDO : ROTULO_CONFIRMAR_APROVACAO}
            </Botao>
            <Botao ref={cancelar} ocupado={aprovando} data-acao="cancelar-aprovacao" onClick={cliente.cancelar}>
                Cancelar
            </Botao>
        </div>
    );
}

/**
 * O pedido de ajuste, no RODAPÉ: o campo de texto (obrigatório, até 1000, com o contador), a dica, o erro (o da
 * conferência daqui ou o 422 `errors.texto`) e [Enviar pedido] [Cancelar]. O foco vai para o campo; o Esc é o
 * [Cancelar] — o texto fica guardado (abrir de novo traz o que foi escrito).
 */
export function PedidoDeAjuste({ cliente }) {
    const campo = useRef(null);
    const idDoCampo = useId();
    const idDaDica = useId();
    const idDoErro = useId();
    const enviando = cliente.enviando === 'pedido';
    const conferido = conferirPedido(cliente.texto);

    useEffect(() => {
        campo.current?.focus();
    }, []);
    // O erro do pedido (o vazio daqui, o 422 do servidor) devolve o foco ao campo.
    useEffect(() => {
        if (cliente.erroDoTexto) campo.current?.focus();
    }, [cliente.erroDoTexto]);

    return (
        <div
            className="flex w-full flex-col gap-2"
            data-pedido-de-ajuste=""
            onKeyDown={(evento) => {
                if (evento.key !== 'Escape') return;
                evento.preventDefault();
                if (!enviando) cliente.cancelar();
            }}
        >
            <label htmlFor={idDoCampo} className="text-[12.5px] font-semibold text-trilha-fg">
                {ROTULO_DO_CAMPO}
            </label>
            <textarea
                ref={campo}
                id={idDoCampo}
                rows={4}
                maxLength={LIMITE_DO_PEDIDO}
                required
                aria-required="true"
                aria-invalid={cliente.erroDoTexto ? 'true' : undefined}
                aria-describedby={[idDaDica, cliente.erroDoTexto ? idDoErro : null].filter(Boolean).join(' ')}
                readOnly={enviando}
                value={cliente.texto}
                onChange={(evento) => cliente.digitar(evento.target.value)}
                data-campo-do-pedido=""
                className={cn(
                    'block w-full min-w-0 resize-y rounded-lg border bg-trilha-surface px-2.5 py-2 text-[13px] text-trilha-fg shadow-none [color-scheme:inherit]',
                    'placeholder:text-trilha-muted focus:border-trilha-run focus:outline-none focus:ring-2 focus:ring-trilha-run/30 focus:ring-offset-0',
                    cliente.erroDoTexto ? 'border-trilha-bad' : 'border-trilha-line',
                )}
            />
            <div className="flex flex-wrap items-start justify-between gap-2 text-[12px]">
                <span id={idDaDica} className="min-w-0 text-trilha-muted">
                    {DICA_DO_CAMPO}
                </span>
                <span className={cn('shrink-0 tabular-nums', conferido.restantes < 0 ? 'text-trilha-bad' : 'text-trilha-muted')} aria-live="polite" data-contador-do-pedido="">
                    {conferido.contador}
                </span>
            </div>
            {cliente.erroDoTexto && (
                <span id={idDoErro} role="alert" className="text-[12.5px] font-semibold text-trilha-bad [overflow-wrap:anywhere]" data-erro-do-pedido="">
                    {cliente.erroDoTexto}
                </span>
            )}
            <div className="flex flex-wrap items-center justify-end gap-2">
                <Botao primario ocupado={enviando} data-acao="enviar-pedido-de-ajuste" onClick={cliente.enviarPedido}>
                    <MessageSquareText size={14} aria-hidden="true" />
                    {enviando ? ROTULO_ENVIANDO_PEDIDO : ROTULO_DO_ENVIAR_PEDIDO}
                </Botao>
                <Botao ocupado={enviando} data-acao="cancelar-pedido-de-ajuste" onClick={cliente.cancelar}>
                    Cancelar
                </Botao>
            </div>
        </div>
    );
}

/** A guarda da gaveta: o pedido escrito e não enviado — [Descartar] ou [Continuar escrevendo] (o foco). */
function ConfirmacaoDaSaida({ cliente }) {
    const continuar = useRef(null);

    useEffect(() => {
        continuar.current?.focus();
    }, []);

    return (
        <div
            className="flex w-full flex-wrap items-center justify-end gap-2"
            data-confirmacao-da-saida=""
            onKeyDown={(evento) => {
                if (evento.key !== 'Escape') return;
                evento.preventDefault();
                cliente.continuarEscrevendo();
            }}
        >
            <span role="alert" className="mr-auto min-w-0 self-center text-[12.5px] font-semibold text-trilha-fg">
                {PERGUNTA_DA_SAIDA}
            </span>
            <Botao perigo data-acao="descartar-pedido" onClick={cliente.descartar}>
                Descartar
            </Botao>
            <Botao ref={continuar} primario data-acao="continuar-pedido" onClick={cliente.continuarEscrevendo}>
                Continuar escrevendo
            </Botao>
        </div>
    );
}

/**
 * O rodapé de sempre: a nota da última resposta, o erro (409: [Recarregar]) e, com o anúncio com ele, [Pedir ajuste]
 * e [Aprovar anúncio] (o primário, por último). O foco não cai no <body>: depois de cada resposta ou de cancelar, ele vai
 * para o [Recarregar], o [Aprovar anúncio] ou a nota.
 */
function RodapeDoCliente({ cliente }) {
    const { acoes, erro, nota } = cliente;
    const daNota = useRef(null);
    const doAprovar = useRef(null);
    const doRecarregar = useRef(null);
    const marca = [erro?.tipo, erro?.mensagem, nota, !!acoes].join('|');

    useEffect(() => {
        if (typeof document === 'undefined') return;
        const pedido = cliente.voltarFoco?.current;
        if (cliente.voltarFoco) cliente.voltarFoco.current = false;
        const ativo = document.activeElement;
        if (!pedido && ativo && ativo !== document.body && !ativo.disabled) return;
        (doRecarregar.current ?? doAprovar.current ?? (nota ? daNota.current : null))?.focus();
    }, [marca]);

    return (
        <>
            <div className={cn('flex min-w-0 flex-col gap-1.5 text-[12.5px]', erro || nota ? 'basis-full' : 'mr-auto')} data-rodape-do-cliente="">
                {/* Sempre presente: o leitor de tela anuncia a nota quando ela chega. */}
                <span ref={daNota} tabIndex={-1} role="status" className="font-semibold text-trilha-ok outline-none empty:hidden" data-nota-do-cliente="">
                    {nota ?? ''}
                </span>
                {erro && (
                    <div role="alert" className="flex flex-wrap items-center gap-2 rounded-xl border border-trilha-warn/40 bg-trilha-warn-bg px-3 py-2 text-[12.5px] text-trilha-warn" data-erro-do-cliente={erro.tipo}>
                        <b className="mr-auto min-w-0 font-semibold [overflow-wrap:anywhere]">{erro.mensagem}</b>
                        {erro.tipo === 'conflito' && (
                            <Botao ref={doRecarregar} data-acao="recarregar-anuncio-do-cliente" onClick={cliente.recarregar}>
                                <RotateCw size={14} aria-hidden="true" />
                                Recarregar
                            </Botao>
                        )}
                    </div>
                )}
            </div>
            {acoes && (
                <>
                    <Botao data-acao="pedir-ajuste-do-cliente" onClick={cliente.abrirPedido}>
                        <MessageSquareText size={14} aria-hidden="true" />
                        {ROTULO_DO_PEDIR_AJUSTE}
                    </Botao>
                    <Botao ref={doAprovar} primario data-acao="aprovar-anuncio" onClick={cliente.pedirAprovacao}>
                        <CircleCheck size={14} aria-hidden="true" />
                        {ROTULO_DO_APROVAR}
                    </Botao>
                </>
            )}
        </>
    );
}

/** O rodapé da gaveta, conforme o que está aberto: a guarda, a confirmação, o pedido ou o de sempre (null = nenhum). */
function rodapeDaAprovacao(cliente) {
    if (cliente.saida) return <ConfirmacaoDaSaida cliente={cliente} />;
    if (cliente.modo === 'confirmando' && cliente.acoes) return <ConfirmacaoDaAprovacao cliente={cliente} />;
    if (cliente.modo === 'pedido' && cliente.acoes) return <PedidoDeAjuste cliente={cliente} />;
    if (!cliente.acoes && !cliente.erro && !cliente.nota) return null;

    return <RodapeDoCliente cliente={cliente} />;
}

// ═══ A vista e a gaveta ══════════════════════════════════════════════════════

/** O controle "parado" (o teste desenha a vista com ele e o que mudar por cima): nada aberto, ações que não fazem nada. */
export const CLIENTE_PARADO = Object.freeze({
    ...ESTADO_DO_CLIENTE,
    acoes: null,
    saida: null,
    voltarFoco: null,
    pedirAprovacao: () => {},
    aprovar: () => {},
    abrirPedido: () => {},
    digitar: () => {},
    enviarPedido: () => {},
    cancelar: () => {},
    recarregar: () => {},
    verDeNovo: () => {},
    descartar: () => {},
    continuarEscrevendo: () => {},
});

/**
 * A gaveta DESENHADA a partir do que se sabe — sem pedido nem estado (é o que o teste desenha).
 *
 * @param {{oferta: object, previa?: ?object, carregando?: boolean, erro?: ?{tipo: string, mensagem: string},
 *   cliente?: object, inicial?: ?{tipo: string, variante: string}}} props  `cliente` = o que `useAprovacaoDoCliente`
 *   devolve (o estado do rodapé e as ações).
 */
export function VistaDaAprovacao({ oferta, previa = null, carregando = false, erro = null, cliente = CLIENTE_PARADO, inicial = null }) {
    const pronta = !carregando && !erro && previa?.pronta === true && Array.isArray(previa?.anuncios) && previa.anuncios.length > 0;
    const naoPronta = !carregando && !erro && !!previa && !pronta;

    return (
        <MioloDaGaveta
            sobrelinha={`${oferta?.tipo ?? 'Oferta'} · Seu anúncio`}
            titulo={oferta?.nome || 'Oferta sem nome'}
            sub={oferta?.sku ? <span className="font-pub-mono font-semibold text-trilha-fg">{oferta.sku}</span> : null}
            rodape={pronta ? rodapeDaAprovacao(cliente) : null}
            ocupado={carregando}
        >
            {carregando && (
                <p className="m-0 text-[13px] text-trilha-muted" data-carregando-anuncio="">
                    Abrindo o anúncio…
                </p>
            )}

            {erro && (
                <div
                    role={erro.tipo === 'indisponivel' ? 'status' : 'alert'}
                    className={cn(
                        'flex flex-col items-start gap-2 rounded-xl border px-[14px] py-3 text-[12.5px]',
                        erro.tipo === 'indisponivel' ? 'border-trilha-line bg-trilha-surface2 text-trilha-fg2' : 'border-trilha-bad/40 bg-trilha-bad-bg text-trilha-bad',
                    )}
                    data-erro-do-anuncio={erro.tipo}
                >
                    {erro.tipo !== 'indisponivel' && <b className="text-[13px]">Não deu para abrir o anúncio.</b>}
                    <span className="[overflow-wrap:anywhere]">{erro.mensagem}</span>
                    {erro.tipo !== 'indisponivel' && (
                        <Botao data-acao="tentar-anuncio-de-novo" onClick={cliente.recarregar}>
                            <RotateCw size={14} aria-hidden="true" />
                            Tentar de novo
                        </Botao>
                    )}
                </div>
            )}

            {naoPronta && (
                <Caixa titulo="Anúncio" data-caixa="anuncio">
                    <span className="text-[12.5px] text-trilha-fg2" data-anuncio-em-preparo="">
                        {MSG_EM_PREPARO}
                    </span>
                </Caixa>
            )}

            {pronta && <CaixaDaSituacao previa={previa} onVerDeNovo={cliente.verDeNovo} />}
            {pronta && <CartaoDoAnuncio previa={previa} inicial={inicial} />}
            {pronta && <CaixaDoPreco previa={previa} />}
        </MioloDaGaveta>
    );
}

/**
 * O [Aprovar anúncio] e o que vem dele, sem React (o teste chama com o axios de mentira): a prévia com a aprovação e a
 * publicação da resposta; ou o erro e — no 409 que diz onde o anúncio está agora — a prévia com a aprovação nova.
 *
 * @returns {Promise<{previa: object, erro: ?{tipo: string, mensagem: string}}>}
 */
export async function enviarAprovacao(url, previa) {
    try {
        return { previa: previaDepoisDaAprovacao(previa, await aprovarPeloCliente(url, corpoDaAprovacao(previa))), erro: null };
    } catch (falha) {
        return { previa: previaComAprovacao(previa, aprovacaoDoConflito(falha)), erro: erroDoCliente(falha, mensagemDoErro(falha)) };
    }
}

/**
 * O [Enviar pedido] e o que vem dele, sem React: a prévia com a aprovação da resposta (`ajuste`, com o texto); ou o
 * erro (o 422 `errors.texto` vira `tipo: 'texto'`) e, no 409 com a aprovação, a prévia com ela.
 *
 * @returns {Promise<{previa: object, erro: ?{tipo: string, mensagem: string}}>}
 */
export async function enviarPedido(url, previa, texto) {
    try {
        return { previa: previaDepoisDoPedido(previa, await pedirAjustePeloCliente(url, corpoDoPedido(previa, texto)), texto), erro: null };
    } catch (falha) {
        return { previa: previaComAprovacao(previa, aprovacaoDoConflito(falha)), erro: erroDoCliente(falha, mensagemDoErro(falha)) };
    }
}

const CARREGANDO = Object.freeze({ carregando: true, erro: null, previa: null });
const SEM_ENDERECO = Object.freeze({ carregando: false, erro: { tipo: 'outro', mensagem: 'O anúncio não está disponível nesta tela: recarregue a página.' }, previa: null });

/**
 * O estado da gaveta do cliente e as ações: ler a prévia (ao abrir e no [Recarregar]), aprovar (com a confirmação),
 * pedir ajuste (com o texto) — uma ação por vez. A resposta troca a prévia na hora (a aprovação e a publicação que
 * vieram), a Lista recarrega e a página avisa (mesmo com a gaveta fechada no meio).
 *
 * @param {{oferta: object, enderecos: ?{previa: string, aprovar: string, ajuste: string},
 *   onAvisar?: (texto: string, tipo?: string) => void, onRecarregar?: ?(() => any)}} opcoes
 */
export function useAprovacaoDoCliente({ oferta, enderecos, onAvisar = () => {}, onRecarregar = null }) {
    // Pelos TEXTOS dos endereços (um objeto novo a cada render da página não pede a prévia de novo).
    const { previa: daPrevia, aprovar: deAprovar, ajuste: doAjuste } = enderecos ?? {};
    const urls = useMemo(
        () => urlsDoCliente({ previa: daPrevia, aprovar: deAprovar, ajuste: doAjuste }, oferta?.id),
        [daPrevia, deAprovar, doAjuste, oferta?.id],
    );
    const [estado, setEstado] = useState(() => (urls ? CARREGANDO : SEM_ENDERECO));
    const [tentativa, setTentativa] = useState(0);
    const [local, setLocal] = useState(ESTADO_DO_CLIENTE);
    const [saida, setSaida] = useState(null);
    const acoes = acoesDoCliente({ previa: estado.previa, urls });

    const vivo = useRef(true);
    const emVoo = useRef(false);
    const voltarFoco = useRef(false);
    useEffect(() => {
        vivo.current = true;

        return () => {
            vivo.current = false;
        };
    }, []);
    const agora = useRef({});
    agora.current = { urls, previa: estado.previa, local, acoes, onAvisar, onRecarregar };

    useEffect(() => {
        if (!urls) return undefined;
        // A resposta que chega depois de fechar (ou de outro [Recarregar]) não vale mais.
        let valendo = true;
        setEstado(CARREGANDO);
        carregarPrevia(urls.previa)
            .then((previa) => {
                if (valendo) setEstado({ carregando: false, erro: null, previa });
            })
            .catch((falha) => {
                if (valendo) setEstado({ carregando: false, erro: erroDoCliente(falha, mensagemDoErro(falha)), previa: null });
            });

        return () => {
            valendo = false;
        };
    }, [urls, tentativa]);

    // A guarda da gaveta: o pedido escrito e não enviado pergunta antes de sair (fechar, abrir outro item).
    const guardiao = useMemo(() => ({
        mexido: () => !!agora.current.local.texto.trim(),
        confirmar: (seguir) => setSaida({ seguir }),
    }), []);
    useGuardaDaGaveta(guardiao);

    /**
     * Depois de uma resposta (`{previa, erro}`): a página e a Lista sabem mesmo com a gaveta fechada; aberta, a prévia
     * (a aprovação nova — também a do 409) e o rodapé mudam. O texto do pedido que não foi fica (abrir o pedido de novo o
     * traz de volta); o 422 do texto deixa o campo aberto, com a mensagem nele.
     */
    const aplicar = (r, nota, aviso) => {
        const fora = agora.current;
        if (!r.erro) {
            fora.onRecarregar?.();
            fora.onAvisar?.(aviso, 'sucesso');
        } else if (r.erro.tipo === 'conflito') {
            // O anúncio mudou (D55) ou saiu dele: a Lista mostra onde ele está agora.
            fora.onRecarregar?.();
        }
        if (!vivo.current) {
            if (r.erro) fora.onAvisar?.(r.erro.mensagem, 'erro');

            return;
        }
        voltarFoco.current = true;
        setEstado((e) => ({ ...e, previa: r.previa ?? e.previa }));
        if (!r.erro) setLocal({ ...ESTADO_DO_CLIENTE, nota });
        else if (r.erro.tipo === 'texto') setLocal((l) => ({ ...l, enviando: null, erroDoTexto: r.erro.mensagem }));
        else setLocal((l) => ({ ...ESTADO_DO_CLIENTE, texto: l.texto, erro: r.erro }));
    };

    const aprovar = async () => {
        if (emVoo.current || !agora.current.acoes) return;
        emVoo.current = true;
        setLocal((l) => ({ ...l, enviando: 'aprovar', erro: null, nota: null }));
        const r = await enviarAprovacao(agora.current.urls.aprovar, agora.current.previa);
        emVoo.current = false;
        aplicar(r, NOTA_APROVADO, MSG_APROVADO);
    };

    const pedirAjuste = async () => {
        if (emVoo.current || !agora.current.acoes) return;
        const { texto } = agora.current.local;
        const conferido = conferirPedido(texto);
        if (!conferido.ok) {
            setLocal((l) => ({ ...l, erroDoTexto: conferido.erro }));

            return;
        }
        emVoo.current = true;
        setLocal((l) => ({ ...l, enviando: 'pedido', erro: null, erroDoTexto: null, nota: null }));
        const r = await enviarPedido(agora.current.urls.ajuste, agora.current.previa, texto);
        emVoo.current = false;
        aplicar(r, NOTA_PEDIDO, MSG_PEDIDO_ENVIADO);
    };

    const recarregar = () => {
        if (emVoo.current) return;
        setLocal((l) => ({ ...ESTADO_DO_CLIENTE, texto: l.texto }));
        setTentativa((n) => n + 1);
        agora.current.onRecarregar?.();
    };

    return {
        ...local,
        previa: estado.previa,
        carregando: estado.carregando,
        erroDaCarga: estado.erro,
        acoes,
        saida,
        voltarFoco,
        pedirAprovacao: () => {
            if (!emVoo.current && agora.current.acoes) setLocal((l) => ({ ...l, modo: 'confirmando', erro: null, nota: null }));
        },
        aprovar,
        abrirPedido: () => {
            if (!emVoo.current && agora.current.acoes) setLocal((l) => ({ ...l, modo: 'pedido', erro: null, erroDoTexto: null, nota: null }));
        },
        digitar: (texto) => setLocal((l) => (l.enviando ? l : { ...l, texto: String(texto ?? ''), erroDoTexto: null })),
        enviarPedido: pedirAjuste,
        cancelar: () => {
            if (emVoo.current) return;
            voltarFoco.current = true;
            setLocal((l) => ({ ...l, modo: null, erroDoTexto: null }));
        },
        recarregar,
        // Depois de aprovar, com a publicação andando: a prévia de novo (a publicação de agora).
        verDeNovo: recarregar,
        descartar: () => {
            const seguir = saida?.seguir;
            setSaida(null);
            setLocal(ESTADO_DO_CLIENTE);
            agora.current.local = ESTADO_DO_CLIENTE;
            seguir?.();
        },
        continuarEscrevendo: () => {
            setSaida(null);
            setLocal((l) => ({ ...l, modo: 'pedido' }));
        },
    };
}

/**
 * A gaveta `v:{item}` do CLIENTE (4e): pede a prévia na versão dele ao abrir e desenha a vista. Só chega aqui o cliente
 * de verdade com `cliente_escrita.aprovacao` (`painelDaChave`); a chave da página troca o conteúdo a cada item.
 *
 * @param {{oferta: object, enderecos: ?{previa: string, aprovar: string, ajuste: string},
 *   onAvisar?: (texto: string, tipo?: string) => void, onRecarregar?: ?(() => any)}} props
 */
export default function AprovarAnuncio({ oferta, enderecos, onAvisar = () => {}, onRecarregar = null }) {
    const cliente = useAprovacaoDoCliente({ oferta, enderecos, onAvisar, onRecarregar });

    return <VistaDaAprovacao oferta={oferta} previa={cliente.previa} carregando={cliente.carregando} erro={cliente.erroDaCarga} cliente={cliente} />;
}
