import { useCallback, useEffect, useRef, useState } from 'react';
import { ImagePlus, LoaderCircle, Trash2 } from 'lucide-react';
import { cn } from '@/lib/utils';
import { carregarFotosDoCliente, enviarFotosDoCliente, excluirFotoDoCliente } from '@/lib/trilhaEscrita';
import {
    MOTIVO_PADRAO,
    MSG_SEM_QUADRO,
    aceitaNoSeletor,
    cabemNaCor,
    conferirArquivos,
    depoisDaExclusao,
    depoisDaFalha,
    depoisDoEnvio,
    erroDasFotos,
    lerQuadro,
    nomeDaFoto,
    ondeDaCor,
    percentualDoProgresso,
    resumoDasFotos,
    textoDaConfirmacao,
    textoDaFalta,
    textoDasRegras,
    textoDoAndamento,
    textoDoEnvio,
    tituloDaCor,
    urlDaExclusao,
    urlDaFotoGrande,
    urlDoEnvio,
    urlDoQuadro,
} from '@/lib/trilhaFotosCliente';
import { Botao, Caixa, Etiqueta, Pilula } from '@/Components/Trilha/Pecas';

// ─── Trilha de produtos — as fotos que o CLIENTE envia (4ª entrega, fatia 4f) ─
//
// §19.15 do desenho: a 1ª escrita da porta do cliente. No painel do produto
// (`p:`), a caixa "Fotos" mostra as fotos de cada cor (a mesma régua da equipe:
// com cor e tamanho, a foto é da cor) e, enquanto o produto está na Lista
// (`produto.editavel` do quadro), deixa enviar várias de uma vez (conferidas
// antes pelos `limites`) e excluir uma a uma, com a confirmação no RODAPÉ da
// gaveta. Depois disso, só leitura com o motivo ("fale com a equipe").
//
// Esta pasta (`Components/Trilha/Cliente/`) é do cliente e chega SÓ por `lazy`
// (o painel do produto a pede quando há `cliente_escrita.fotos`; o 2º caminho
// está em `Pages/Trilha/CadastroSobDemanda.jsx`). Não importa nada das pastas
// `Cadastro/` e `Revisao/` nem das libs da gaveta da equipe: o pacote do
// cliente não pode levá-las. Os textos são do cliente: nada de marketplace,
// Portal, Publicador nem "agente".
//
// O foco não cai no <body>: cada ação diz para onde ele vai (o [Enviar fotos]
// da cor, o [Excluir] da foto, a cor, a caixa), DEPOIS de a tela trocar.

const FOCO = 'focus-visible:[outline-style:solid] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-trilha-run';

const SEM_MENSAGENS = { erros: [], avisos: [], status: null };

/** O valor de um atributo dentro do seletor (aspas e barras escapadas). */
const noSeletor = (valor) => String(valor).replace(/["\\]/g, '\\$&');

/** Os alvos do foco, dentro da caixa. */
const ALVO = {
    enviar: (variacaoId) => `[data-enviar-fotos="${noSeletor(variacaoId)}"]`,
    cor: (variacaoId) => `[data-cor-das-fotos="${noSeletor(variacaoId)}"]`,
    excluir: (imagemId) => `[data-excluir-foto="${noSeletor(imagemId)}"]`,
};

const mesmoId = (a, b) => a != null && b != null && String(a) === String(b);

/** A pergunta da confirmação para a foto marcada (null se ela não está mais no quadro). */
export function perguntaDaExclusao(quadro, confirmando) {
    if (!quadro || !confirmando) return null;
    const cor = quadro.cores.find((c) => mesmoId(c.variacaoId, confirmando.variacaoId));
    const indice = cor ? cor.fotos.findIndex((f) => mesmoId(f.id, confirmando.imagemId)) : -1;

    return indice < 0 ? null : textoDaConfirmacao(indice, cor.fotos.length, cor.rotulo);
}

// ═══ A vista: desenhada a partir do que se sabe (sem pedido nem estado) ═══════

/** O andamento do envio de uma cor: a barra (com o número, quando o navegador o dá). */
function Andamento({ acao, rotulo }) {
    const pct = acao.progresso;

    return (
        <div className="flex min-w-0 items-center gap-2" data-andamento-do-envio={acao.variacaoId}>
            <div
                role="progressbar"
                aria-label={`Envio das fotos${rotulo ? ` de ${rotulo}` : ''}`}
                aria-valuemin={0}
                aria-valuemax={100}
                aria-valuenow={pct ?? undefined}
                className="h-1.5 min-w-0 flex-1 overflow-hidden rounded-full bg-trilha-line2"
            >
                <div
                    className={cn('h-full rounded-full bg-trilha-run transition-[width] duration-200', pct == null && 'w-1/3 animate-pulse motion-reduce:animate-none')}
                    style={pct == null ? undefined : { width: `${pct}%` }}
                />
            </div>
            {pct != null && (
                <span aria-hidden="true" className="shrink-0 font-pub-mono text-[11.5px] font-semibold text-trilha-run" data-percentual-do-envio="">
                    {pct}%
                </span>
            )}
        </div>
    );
}

/** As fotos de UMA cor: as miniaturas, o que falta, [Enviar fotos], [Excluir] em cada uma e o que a última ação disse. */
function FotosDaCor({ cor, quantasCores, limites, editavel, acao, mensagens, confirmando, onEscolher, onArquivos, onPedirExclusao }) {
    const id = String(cor.variacaoId);
    const ocupada = acao != null;
    const enviandoAqui = acao?.tipo === 'envio' && mesmoId(acao.variacaoId, cor.variacaoId);
    const cabem = cabemNaCor(cor);
    const completa = cor.faltam <= 0;
    const onde = ondeDaCor(cor, quantasCores);
    const { erros, avisos, status } = { ...SEM_MENSAGENS, ...(mensagens ?? {}) };
    // Enquanto sobe, a região de status diz o andamento ("Enviando 3 fotos…"); depois, o que aconteceu.
    const situacao = enviandoAqui ? textoDoAndamento(acao.quantas, acao.progresso) : status;

    return (
        <div
            tabIndex={-1}
            className="flex min-w-0 flex-col gap-2 rounded-xl border border-trilha-line2 bg-trilha-surface px-3 py-2.5 outline-none"
            data-cor-das-fotos={id}
            aria-busy={enviandoAqui || undefined}
        >
            <div className="flex flex-wrap items-center justify-between gap-x-2 gap-y-1">
                <span className="min-w-0 text-[12.5px] font-semibold text-trilha-fg [overflow-wrap:anywhere]">{tituloDaCor(cor, quantasCores)}</span>
                <Pilula tom={completa ? 'ok' : 'warn'} data-falta-da-cor={cor.faltam}>
                    {textoDaFalta(cor, quantasCores)}
                </Pilula>
            </div>

            {cor.fotos.length > 0 ? (
                <ul className="m-0 grid list-none grid-cols-[repeat(auto-fill,minmax(84px,1fr))] gap-2 p-0">
                    {cor.fotos.map((foto, i) => {
                        const nome = nomeDaFoto(i, cor.rotulo);
                        const marcada = mesmoId(confirmando?.variacaoId, cor.variacaoId) && mesmoId(confirmando?.imagemId, foto.id);
                        const excluindo = acao?.tipo === 'exclusao' && mesmoId(acao.imagemId, foto.id);

                        return (
                            <li key={foto.id} className="flex min-w-0 flex-col gap-1" data-foto={foto.id}>
                                <a
                                    href={urlDaFotoGrande(foto.url) ?? undefined}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    aria-label={`Abrir a ${nome}${i === 0 ? ' (capa)' : ''} em outra aba`}
                                    className={cn(
                                        'relative block aspect-square w-full overflow-hidden rounded-lg border bg-trilha-surface2',
                                        marcada ? 'border-trilha-bad' : 'border-trilha-line',
                                        FOCO,
                                    )}
                                >
                                    <img src={foto.url} alt="" loading="lazy" decoding="async" className="h-full w-full object-cover" />
                                    {i === 0 && (
                                        <Etiqueta tom="roxo" className="absolute left-1 top-1">
                                            capa
                                        </Etiqueta>
                                    )}
                                </a>
                                {editavel && (
                                    <button
                                        type="button"
                                        data-acao="excluir-foto"
                                        data-excluir-foto={foto.id}
                                        disabled={ocupada}
                                        onClick={() => onPedirExclusao(cor.variacaoId, foto.id)}
                                        className={cn(
                                            'inline-flex items-center justify-center gap-1 rounded-md px-1.5 py-0.5 text-[11.5px] font-semibold text-trilha-bad hover:bg-trilha-bad-bg',
                                            'disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:bg-transparent',
                                            marcada && 'bg-trilha-bad-bg',
                                            FOCO,
                                        )}
                                    >
                                        <Trash2 size={12} aria-hidden="true" />
                                        {excluindo ? 'Excluindo…' : 'Excluir'}
                                        <span className="sr-only"> a {nome}</span>
                                    </button>
                                )}
                            </li>
                        );
                    })}
                </ul>
            ) : (
                <span className="text-[12.5px] text-trilha-muted" data-sem-fotos="">
                    Nenhuma foto {onde} ainda.
                </span>
            )}

            {editavel && cabem > 0 && (
                <div className="flex flex-wrap items-center gap-2">
                    {/* O que está enviando fica focável (`ocupado` = aria-disabled); os outros, desligados de verdade. */}
                    <Botao
                        primario={!completa}
                        ocupado={enviandoAqui}
                        disabled={ocupada && !enviandoAqui}
                        data-acao="enviar-fotos"
                        data-enviar-fotos={id}
                        onClick={() => onEscolher(cor.variacaoId)}
                    >
                        {enviandoAqui ? (
                            <LoaderCircle size={15} aria-hidden="true" className="animate-spin motion-reduce:animate-none" />
                        ) : (
                            <ImagePlus size={15} aria-hidden="true" />
                        )}
                        {enviandoAqui ? 'Enviando…' : 'Enviar fotos'}
                        {cor.rotulo && <span className="sr-only"> de {cor.rotulo}</span>}
                    </Botao>
                    <input
                        type="file"
                        multiple
                        hidden
                        accept={aceitaNoSeletor(limites.tipos)}
                        data-arquivos-da-cor={id}
                        onChange={(evento) => {
                            const arquivos = Array.from(evento.target.files ?? []);
                            // O mesmo arquivo pode ser escolhido de novo (depois de um aviso, por exemplo).
                            evento.target.value = '';
                            if (arquivos.length) onArquivos(cor.variacaoId, arquivos);
                        }}
                    />
                </div>
            )}
            {editavel && cabem === 0 && (
                <span className="text-[12px] text-trilha-muted" data-cor-cheia="">
                    Já há {cor.maximo} fotos {onde}, o máximo. Exclua alguma para enviar outra.
                </span>
            )}

            {enviandoAqui && <Andamento acao={acao} rotulo={cor.rotulo} />}

            {erros.length > 0 && (
                <ul role="alert" className="m-0 flex list-none flex-col gap-0.5 p-0 text-[12px] font-semibold text-trilha-bad" data-erros-da-cor={id}>
                    {erros.map((erro) => (
                        <li key={erro} className="[overflow-wrap:anywhere]">
                            {erro}
                        </li>
                    ))}
                </ul>
            )}
            {avisos.length > 0 && (
                // Neutro: o servidor avisa tanto o que é bom ("o produto seguiu") quanto o que pede atenção.
                <ul className="m-0 flex list-none flex-col gap-0.5 p-0 text-[12px] font-semibold text-trilha-fg2" data-avisos-da-cor={id}>
                    {avisos.map((aviso) => (
                        <li key={aviso} className="[overflow-wrap:anywhere]">
                            {aviso}
                        </li>
                    ))}
                </ul>
            )}
            {/* Sempre presente (vazia quando não há nada): o leitor de tela só anuncia a mudança de uma região que já existia. */}
            <span
                role="status"
                className={cn('text-[12px] font-semibold empty:hidden', enviandoAqui ? 'text-trilha-run' : 'text-trilha-ok')}
                data-status-da-cor={id}
            >
                {situacao ?? ''}
            </span>
        </div>
    );
}

/**
 * A caixa "Fotos" DESENHADA a partir do que se sabe — sem pedido nem estado (é o que o teste de render desenha).
 *
 * @param {{
 *   quadro?: ?object, carregando?: boolean, erroDaCarga?: ?string,
 *   acao?: ?{tipo: 'envio'|'exclusao', variacaoId: *, quantas?: number, progresso?: ?number, imagemId?: *},
 *   mensagens?: Record<string, {erros?: string[], avisos?: string[], status?: ?string}>,
 *   confirmando?: ?{variacaoId: *, imagemId: *},
 *   onTentarDeNovo?: () => void, onEscolher?: (variacaoId) => void, onArquivos?: (variacaoId, arquivos: File[]) => void,
 *   onPedirExclusao?: (variacaoId, imagemId) => void,
 * }} props  `quadro` = o de `lerQuadro`; `mensagens` por variação (a chave é o id em texto).
 */
export function VistaDasFotos({
    quadro = null, carregando = false, erroDaCarga = null, acao = null, mensagens = {}, confirmando = null,
    onTentarDeNovo = () => {}, onEscolher = () => {}, onArquivos = () => {}, onPedirExclusao = () => {},
}) {
    const resumo = quadro ? resumoDasFotos(quadro) : null;
    const editavel = !!quadro?.produto.editavel;
    const quantasCores = quadro?.cores.length ?? 0;

    return (
        <Caixa
            titulo="Fotos"
            extra={resumo && (
                <Pilula tom={resumo.tom} data-resumo-das-fotos={resumo.tom}>
                    {resumo.texto}
                </Pilula>
            )}
            data-caixa="fotos-do-cliente"
            data-fotos-editaveis={quadro ? String(editavel) : undefined}
            aria-busy={(carregando && !quadro) || acao != null || undefined}
        >
            {!quadro && carregando && (
                <span className="text-[12.5px] text-trilha-muted" data-carregando-fotos="">
                    Carregando as fotos…
                </span>
            )}
            {!quadro && !carregando && erroDaCarga && (
                <div role="alert" className="flex flex-wrap items-center gap-2 text-[12.5px] font-semibold text-trilha-bad" data-erro-das-fotos="">
                    <span className="mr-auto min-w-0 [overflow-wrap:anywhere]">{erroDaCarga}</span>
                    <Botao data-acao="tentar-fotos-de-novo" onClick={onTentarDeNovo}>
                        Tentar de novo
                    </Botao>
                </div>
            )}

            {quadro && (
                // Sempre presente com o quadro: quando a caixa vira só leitura (o 409), o motivo é anunciado.
                <p
                    role="status"
                    className="m-0 rounded-lg bg-trilha-surface2 px-3 py-2 text-[12.5px] text-trilha-fg2 empty:hidden"
                    data-fotos-so-leitura={editavel ? undefined : ''}
                >
                    {editavel ? '' : quadro.produto.motivo || MOTIVO_PADRAO}
                </p>
            )}
            {quadro && quantasCores === 0 && (
                <span className="text-[12.5px] text-trilha-muted" data-sem-cores="">
                    Este produto ainda não tem onde guardar fotos.
                </span>
            )}
            {quadro?.cores.map((cor) => (
                <FotosDaCor
                    key={String(cor.variacaoId)}
                    cor={cor}
                    quantasCores={quantasCores}
                    limites={quadro.limites}
                    editavel={editavel}
                    acao={acao}
                    mensagens={mensagens?.[String(cor.variacaoId)]}
                    confirmando={confirmando}
                    onEscolher={onEscolher}
                    onArquivos={onArquivos}
                    onPedirExclusao={onPedirExclusao}
                />
            ))}
            {quadro && editavel && quantasCores > 0 && (
                <p className="m-0 text-[12px] text-trilha-muted" data-regras-das-fotos="">
                    {textoDasRegras(quadro.limites)}
                </p>
            )}
        </Caixa>
    );
}

/**
 * A confirmação da exclusão, no RODAPÉ da gaveta (§19.15; como a do publicar, 4d): a pergunta e [Excluir foto]
 * [Cancelar], com o foco em [Cancelar] — excluir não se desfaz. O Esc é o mesmo que ele (o `preventDefault` avisa a
 * gaveta para não fechar). Excluindo, os dois ficam focáveis e ignoram o clique (`ocupado`): o foco não cai no <body>.
 */
export function ConfirmacaoDaExclusao({ pergunta, excluindo = false, onConfirmar = () => {}, onCancelar = () => {} }) {
    const cancelar = useRef(null);

    useEffect(() => {
        cancelar.current?.focus();
    }, []);

    return (
        <div
            className="flex w-full flex-wrap items-center justify-end gap-2"
            data-confirmacao-da-foto=""
            onKeyDown={(evento) => {
                if (evento.key !== 'Escape') return;
                evento.preventDefault();
                if (!excluindo) onCancelar();
            }}
        >
            <span role="alert" className="mr-auto min-w-0 self-center text-[12.5px] font-semibold text-trilha-fg [overflow-wrap:anywhere]">
                {pergunta}
            </span>
            <Botao perigo primario ocupado={excluindo} data-acao="confirmar-exclusao-da-foto" onClick={onConfirmar}>
                {excluindo ? 'Excluindo…' : 'Excluir foto'}
            </Botao>
            <Botao ref={cancelar} ocupado={excluindo} data-acao="cancelar-exclusao-da-foto" onClick={onCancelar}>
                Cancelar
            </Botao>
        </div>
    );
}

// ═══ O estado e os pedidos ═══════════════════════════════════════════════════

/**
 * O estado da caixa e as ações: ler o quadro (na montagem e, em silêncio, quando a etapa do produto muda), enviar,
 * pedir/cancelar/confirmar a exclusão. Uma ação de cada vez. A resposta de cada ação é o quadro NOVO; uma leitura
 * que saiu antes dela e chega depois não passa por cima (`escrita`).
 */
export function useFotosDoCliente({ produtoId, etapa = null, base, onRecarregar = () => {}, onAvisar = () => {} }) {
    const url = urlDoQuadro(base, produtoId);
    const [quadro, setQuadro] = useState(null);
    const [carga, setCarga] = useState(() => ({ carregando: !!url, erro: url ? null : 'As fotos não estão disponíveis nesta tela: recarregue a página.' }));
    const [acao, setAcao] = useState(null);
    const [mensagens, setMensagens] = useState({});
    const [confirmando, setConfirmando] = useState(null);
    const [foco, setFoco] = useState(null);

    const caixa = useRef(null);
    const vivo = useRef(true);
    const focoDentro = useRef(false);
    // O que vale AGORA (as ações leem daqui; o estado só chega no próximo render).
    const quadroAgora = useRef(null);
    const confirmandoAgora = useRef(null);
    const emCurso = useRef(null);
    const leitura = useRef(0);
    const escrita = useRef(0);
    const fora = useRef({ onRecarregar, onAvisar });
    fora.current = { onRecarregar, onAvisar };

    const trocarQuadro = useCallback((novo) => {
        quadroAgora.current = typeof novo === 'function' ? novo(quadroAgora.current) : novo;
        setQuadro(quadroAgora.current);
    }, []);
    const trocarConfirmando = useCallback((valor) => {
        confirmandoAgora.current = valor;
        setConfirmando(valor);
    }, []);
    const mudarCor = useCallback((variacaoId, parte) => {
        setMensagens((atual) => ({ ...atual, [String(variacaoId)]: { ...SEM_MENSAGENS, ...parte } }));
    }, []);
    /** Para onde o foco vai DEPOIS de a tela trocar: o 1º seletor que existir e não estiver desligado; senão, a caixa. */
    const focar = useCallback((...seletores) => setFoco({ seletores: seletores.filter(Boolean) }), []);

    useEffect(() => {
        vivo.current = true;

        return () => {
            vivo.current = false;
        };
    }, []);

    useEffect(() => {
        if (!foco || typeof document === 'undefined') return;
        const raiz = caixa.current;
        if (!raiz?.isConnected) return;
        const achado = foco.seletores.map((s) => raiz.querySelector(s)).find((el) => el && !el.disabled);
        (achado ?? raiz).focus();
    }, [foco]);

    // Uma releitura que tirou o elemento com o foco (a caixa virou só leitura, a foto sumiu): a caixa, não o <body>.
    useEffect(() => {
        if (typeof document === 'undefined' || !focoDentro.current) return;
        const ativo = document.activeElement;
        if (!ativo || ativo === document.body) caixa.current?.focus();
    }, [quadro]);

    const carregar = useCallback(async ({ silencioso = false } = {}) => {
        if (!url) return;
        const minha = ++leitura.current;
        const escritaAntes = escrita.current;
        if (!silencioso) setCarga({ carregando: true, erro: null });
        try {
            const lido = lerQuadro(await carregarFotosDoCliente(url));
            if (!vivo.current || minha !== leitura.current || escritaAntes !== escrita.current) return;
            if (!lido) {
                if (!quadroAgora.current) setCarga({ carregando: false, erro: MSG_SEM_QUADRO });

                return;
            }
            trocarQuadro(lido);
            setCarga({ carregando: false, erro: null });
        } catch (e) {
            if (!vivo.current || minha !== leitura.current) return;
            // A releitura silenciosa que falha não apaga o que está na tela.
            if (silencioso && quadroAgora.current) return;
            setCarga({ carregando: false, erro: erroDasFotos(e, { carga: true }).mensagens[0] });
        }
    }, [url, trocarQuadro]);

    // Na montagem, lê o quadro. Quando a ETAPA do produto muda (a recarga da trilha depois de um envio que completou
    // o produto, ou outra pessoa), relê em silêncio: saindo da Lista, a caixa vira só leitura sem esperar um 409.
    const etapaVista = useRef(undefined);
    useEffect(() => {
        const primeira = etapaVista.current === undefined;
        etapaVista.current = etapa;
        carregar({ silencioso: !primeira });
    }, [carregar, etapa]);

    // A foto marcada para excluir sumiu do quadro (outra aba, uma releitura): a confirmação sai.
    useEffect(() => {
        if (confirmando && !perguntaDaExclusao(quadro, confirmando) && !emCurso.current) trocarConfirmando(null);
    }, [quadro, confirmando, trocarConfirmando]);

    /** A resposta que deu errado (`depoisDaFalha`): só leitura (409), releitura (404) ou as mensagens na cor. */
    const falhou = useCallback((erro, variacaoId, antes = [], imagemId = null) => {
        const r = depoisDaFalha(quadroAgora.current, erro, antes);
        // O produto andou (ou saiu da Lista): a trilha mostra onde ele está agora.
        if (r.recarregarTrilha) fora.current.onRecarregar();
        if (!vivo.current) {
            // A gaveta fechou no meio: o aviso da página diz o que aconteceu.
            fora.current.onAvisar(r.falha.mensagens[0], 'erro');

            return;
        }
        if (r.quadro !== quadroAgora.current) {
            escrita.current += 1;
            trocarQuadro(r.quadro);
        }
        mudarCor(variacaoId, r.mensagens);
        if (r.reler) carregar({ silencioso: true });
        // Só leitura: os botões sumiram — o foco vai para a caixa (o motivo é anunciado nela).
        if (r.falha.tipo === 'leitura') focar();
        else focar(imagemId != null ? ALVO.excluir(imagemId) : null, ALVO.enviar(variacaoId), ALVO.cor(variacaoId));
    }, [carregar, focar, mudarCor, trocarQuadro]);

    /** Os arquivos escolhidos para uma cor: confere pelos `limites` e, o que servir, envia numa remessa só. */
    const enviar = useCallback(async (variacaoId, escolhidos) => {
        const q = quadroAgora.current;
        const cor = q?.cores.find((c) => mesmoId(c.variacaoId, variacaoId));
        if (!q?.produto.editavel || !cor || emCurso.current) return;
        const { arquivos, avisos: recusados } = conferirArquivos(escolhidos, cor, q.limites, q.cores.length);
        mudarCor(variacaoId, { erros: recusados });
        if (!arquivos.length) {
            focar(ALVO.enviar(variacaoId), ALVO.cor(variacaoId));

            return;
        }

        const descricao = { tipo: 'envio', variacaoId, quantas: arquivos.length, progresso: null };
        emCurso.current = descricao;
        setAcao(descricao);
        trocarConfirmando(null);
        try {
            const dados = await enviarFotosDoCliente(urlDoEnvio(base, produtoId, variacaoId), arquivos, {
                aoProgredir: (evento) => {
                    if (!vivo.current) return;
                    const progresso = percentualDoProgresso(evento);
                    setAcao((atual) => (atual?.tipo === 'envio' ? { ...atual, progresso } : atual));
                },
            });
            escrita.current += 1;
            // As faltas do produto (e talvez a etapa) mudaram: a trilha recarrega.
            fora.current.onRecarregar();
            if (!vivo.current) {
                fora.current.onAvisar(textoDoEnvio(arquivos.length));

                return;
            }
            const r = depoisDoEnvio(dados, arquivos.length, recusados);
            if (r.quadro) trocarQuadro(r.quadro);
            else carregar({ silencioso: true });
            mudarCor(variacaoId, r.mensagens);
            focar(ALVO.enviar(variacaoId), ALVO.cor(variacaoId));
        } catch (e) {
            falhou(e, variacaoId, recusados);
        } finally {
            emCurso.current = null;
            if (vivo.current) setAcao(null);
        }
    }, [base, produtoId, carregar, falhou, focar, mudarCor, trocarConfirmando, trocarQuadro]);

    /** [Excluir] de uma foto: só marca — a confirmação aparece no rodapé da gaveta. */
    const pedirExclusao = useCallback((variacaoId, imagemId) => {
        if (emCurso.current || !quadroAgora.current?.produto.editavel) return;
        mudarCor(variacaoId, {});
        trocarConfirmando({ variacaoId, imagemId });
    }, [mudarCor, trocarConfirmando]);

    /** [Cancelar] (ou Esc): o foco volta para o [Excluir] daquela foto. */
    const cancelarExclusao = useCallback(() => {
        const marcada = confirmandoAgora.current;
        if (!marcada || emCurso.current) return;
        trocarConfirmando(null);
        focar(ALVO.excluir(marcada.imagemId), ALVO.cor(marcada.variacaoId));
    }, [focar, trocarConfirmando]);

    /** [Excluir foto] da confirmação: exclui na hora; o foco vai para o [Enviar fotos] da cor. */
    const confirmarExclusao = useCallback(async () => {
        const marcada = confirmandoAgora.current;
        if (!marcada || emCurso.current) return;
        const descricao = { tipo: 'exclusao', variacaoId: marcada.variacaoId, imagemId: marcada.imagemId };
        emCurso.current = descricao;
        setAcao(descricao);
        try {
            const dados = await excluirFotoDoCliente(urlDaExclusao(base, produtoId, marcada.variacaoId, marcada.imagemId));
            escrita.current += 1;
            fora.current.onRecarregar();
            const r = depoisDaExclusao(dados);
            if (!vivo.current) {
                fora.current.onAvisar(r.mensagens.status);

                return;
            }
            if (r.quadro) trocarQuadro(r.quadro);
            else carregar({ silencioso: true });
            trocarConfirmando(null);
            mudarCor(marcada.variacaoId, r.mensagens);
            focar(ALVO.enviar(marcada.variacaoId), ALVO.cor(marcada.variacaoId));
        } catch (e) {
            if (vivo.current) trocarConfirmando(null);
            falhou(e, marcada.variacaoId, [], marcada.imagemId);
        } finally {
            emCurso.current = null;
            if (vivo.current) setAcao(null);
        }
    }, [base, produtoId, carregar, falhou, focar, mudarCor, trocarConfirmando, trocarQuadro]);

    /** [Enviar fotos]: abre o seletor de arquivos da cor. */
    const escolher = useCallback((variacaoId) => {
        if (emCurso.current) return;
        caixa.current?.querySelector(`[data-arquivos-da-cor="${noSeletor(variacaoId)}"]`)?.click();
    }, []);

    const marcarFoco = {
        onFocus: () => {
            focoDentro.current = true;
        },
        onBlur: (evento) => {
            // Só sai quando o foco foi PARA outro lugar (um elemento que sumiu não diz para onde).
            if (evento.relatedTarget && !evento.currentTarget.contains(evento.relatedTarget)) focoDentro.current = false;
        },
    };

    return {
        caixa,
        marcarFoco,
        quadro,
        carregando: carga.carregando,
        erroDaCarga: carga.erro,
        acao,
        mensagens,
        confirmando,
        tentarDeNovo: () => carregar(),
        escolher,
        enviar,
        pedirExclusao,
        cancelarExclusao,
        confirmarExclusao,
    };
}

/**
 * A caixa "Fotos" do produto, na porta do cliente (4f).
 *
 * @param {{
 *   produto: {id: number, etapa: ?string},
 *   base: string,
 *   onRecarregar?: () => any,
 *   onAvisar?: (texto: string, tipo?: 'sucesso'|'erro') => void,
 *   onRodape?: (conteudo: ?React.ReactNode) => void,
 * }} props  `base` = `cliente_escrita.fotos` (com o marcador); `onRodape` troca o rodapé da gaveta pela confirmação
 *   da exclusão (null devolve o de sempre).
 */
export default function FotosDoProduto({ produto, base, onRecarregar = () => {}, onAvisar = () => {}, onRodape = () => {} }) {
    const f = useFotosDoCliente({ produtoId: produto?.id ?? null, etapa: produto?.etapa ?? null, base, onRecarregar, onAvisar });

    // A confirmação vai para o rodapé da gaveta: as ações dela leem as de AGORA (a referência), e a chave troca a cada
    // foto marcada (o foco volta para o [Cancelar] da pergunta nova).
    const daConfirmacao = useRef(null);
    daConfirmacao.current = { confirmar: f.confirmarExclusao, cancelar: f.cancelarExclusao };
    const pergunta = perguntaDaExclusao(f.quadro, f.confirmando);
    const excluindo = f.acao?.tipo === 'exclusao';
    const chave = f.confirmando ? `${f.confirmando.variacaoId}:${f.confirmando.imagemId}` : null;

    useEffect(() => {
        onRodape(pergunta
            ? (
                <ConfirmacaoDaExclusao
                    key={chave}
                    pergunta={pergunta}
                    excluindo={excluindo}
                    onConfirmar={() => daConfirmacao.current.confirmar()}
                    onCancelar={() => daConfirmacao.current.cancelar()}
                />
            )
            : null);
    }, [pergunta, excluindo, chave, onRodape]);
    useEffect(() => () => onRodape(null), [onRodape]);

    return (
        <div ref={f.caixa} tabIndex={-1} role="group" aria-label="Fotos do produto" className="relative min-w-0 outline-none" data-fotos-do-cliente="" {...f.marcarFoco}>
            <VistaDasFotos
                quadro={f.quadro}
                carregando={f.carregando}
                erroDaCarga={f.erroDaCarga}
                acao={f.acao}
                mensagens={f.mensagens}
                confirmando={f.confirmando}
                onTentarDeNovo={f.tentarDeNovo}
                onEscolher={f.escolher}
                onArquivos={f.enviar}
                onPedirExclusao={f.pedirExclusao}
            />
        </div>
    );
}
