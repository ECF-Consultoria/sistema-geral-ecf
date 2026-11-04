import { useCallback, useEffect, useId, useMemo, useRef, useState } from 'react';
import { LoaderCircle, RotateCw, Send } from 'lucide-react';
import { estadoDaPublicacao, mensagemDoErro, publicarAnuncio } from '@/lib/trilhaEscrita';
import {
    AVISO_PUBLICADO,
    ESTADO_DA_PUBLICACAO,
    INTERVALO_DA_CONSULTA_MS,
    MSG_PAROU,
    PERGUNTA_DA_CONFIRMACAO,
    acaoDaPublicacao,
    corpoDoPublicar,
    devePararNoErro,
    devePerguntar,
    erroDaPublicacao,
    estadoDepoisDaConsulta,
    estadoDepoisDoEnvio,
    normalizarPublicacao,
    parouDeAcompanhar,
    publicacaoDaResposta,
    situacaoDe,
    terminouDePublicar,
    travaDoPreco,
    urlDaPublicacao,
    urlDoPublicar,
    vistaDaPublicacao,
} from '@/lib/trilhaPublicar';
import { cn } from '@/lib/utils';
import { Botao, Pilula } from '@/Components/Trilha/Pecas';

// ─── Trilha de produtos — aprovar e publicar pela prévia (4ª entrega, fatia 4d) ──
//
// §19.13 do desenho. O rodapé da gaveta `v:{item}` ganha o primário [Aprovar e
// publicar]: só para a equipe que recebeu `escrita.revisao.publicar` (e a URL do
// acompanhamento), com a prévia pronta, o item na revisão, sem bloqueios e sem
// publicação andando. Nada sai sem a confirmação no rodapé ("Publicar este
// anúncio na loja agora?" [Publicar] [Cancelar]).
//
// Depois do pedido (202 `{publicacao}`), a gaveta pergunta o `GET` a cada 3 s
// enquanto ele anda (conferindo → na fila → publicando) e para ao fechar ou ao
// terminar. Cada situação tem um texto neutro; `avisos` lista os avisos da
// conferência e troca o primário por [Publicar assim mesmo] (o mesmo pedido, com
// `ciente`); `recusado`/`erro` mostram a mensagem; `publicado` diz "Publicado ·
// em análise" e recarrega a Lista. Aberta com a publicação andando (a prévia já
// traz `publicacao`), a gaveta retoma o acompanhamento sozinha.
//
// Os erros do pedido ficam no rodapé: o 409 da revisão velha ("Este anúncio mudou
// depois que você abriu" + [Recarregar], o padrão F6), os outros 409, o 403 (a loja
// não liberada) e o 422 (o Premium igual ao Clássico), com a mensagem do servidor.
// Num 409 que não é da revisão a gaveta pergunta o estado uma vez: se o pedido já
// anda (outra aba publicou), passa a acompanhá-lo.
//
// Enquanto anda (ou depois de publicado) a caixa "Preço" fica só leitura
// (`travaDoPreco`); com preço digitado e não salvo, o primário fica desligado —
// publicaria o preço velho.

/** O estado local, preso à prévia em que nasceu: chegou outra prévia (recarregar, preço salvo), ele recomeça. */
const LOCAL = Object.freeze({ ...ESTADO_DA_PUBLICACAO, previa: null });

/**
 * O pedido de publicar e o que vem dele, sem React (o teste chama com o axios de mentira): o 202 traz a publicação
 * (`conferindo`); o erro, o que ele quer dizer (`erroDaPublicacao`). No 409 que não é da revisão, uma pergunta ao
 * acompanhamento: se o pedido já anda, a gaveta passa a mostrá-lo. `publicacao` undefined = não mexe.
 *
 * @param {{publicar: ?string, publicacao: ?string}} urls
 * @returns {Promise<{publicacao: (?object|undefined), erro: ?object}>}
 */
export async function enviarPublicacao(urls, corpo) {
    try {
        const resposta = await publicarAnuncio(urls?.publicar ?? null, corpo);

        return { publicacao: publicacaoDaResposta(resposta) ?? { situacao: 'conferindo', mensagem: null, avisos: [], mlbs: [] }, erro: null };
    } catch (falha) {
        const erro = erroDaPublicacao(falha, mensagemDoErro(falha));
        let publicacao;
        if (erro.tipo === 'conflito' && urls?.publicacao) {
            try {
                publicacao = publicacaoDaResposta(await estadoDaPublicacao(urls.publicacao)) ?? undefined;
            } catch {
                // Sem o estado, fica só a mensagem do 409.
            }
        }

        return { publicacao, erro };
    }
}

/**
 * Uma pergunta ao acompanhamento, sem React: a publicação de agora (null = nunca pedida), ou a falha (o texto comum)
 * e se ela não passa sozinha (`devePararNoErro`). `publicacao` undefined = não mexe.
 *
 * @returns {Promise<{publicacao: (?object|undefined), falha: ?string, parar: boolean}>}
 */
export async function perguntarPublicacao(url) {
    try {
        return { publicacao: publicacaoDaResposta(await estadoDaPublicacao(url)), falha: null, parar: false };
    } catch (falha) {
        return { publicacao: undefined, falha: mensagemDoErro(falha), parar: devePararNoErro(falha) };
    }
}

/**
 * O estado da publicação e as ações dela, para a gaveta `v:`. `previa` é a que a gaveta mostra (a `publicacao` dela
 * vale até a gaveta saber de outra); `precoPendente` = preço digitado e não salvo, ou salvando; `onRecarregarPrevia`
 * pede a prévia de novo (o [Recarregar] do 409 da revisão); `onRecarregar` recarrega a Lista (o publicado).
 *
 * @param {{escrita: ?object, oferta: object, previa: ?object, precoPendente?: boolean, onRecarregarPrevia?: ?(() => void),
 *   onAvisar?: (texto: string, tipo?: string) => void, onRecarregar?: ?(() => void)}} opcoes
 */
export function usePublicacaoDaPrevia({ escrita, oferta, previa, precoPendente = false, onRecarregarPrevia = null, onAvisar = () => {}, onRecarregar = null }) {
    const urls = useMemo(() => ({ publicar: urlDoPublicar(escrita, oferta?.id), publicacao: urlDaPublicacao(escrita, oferta?.id) }), [escrita, oferta?.id]);
    // A prévia de agora, lida também dentro das mudanças de estado: o retorno de um pedido vale para ela.
    const previaAgora = useRef(previa);
    previaAgora.current = previa;
    const [local, setLocal] = useState(LOCAL);
    const atual = local.previa === previa ? local : { ...LOCAL, previa };
    const daPrevia = useMemo(() => normalizarPublicacao(previa?.publicacao), [previa]);
    const publicacao = atual.publicacao !== undefined ? atual.publicacao : daPrevia;
    const situacao = situacaoDe(publicacao);
    const acao = acaoDaPublicacao({ escrita, previa, oferta, publicacao, precoPendente, enviando: atual.enviando });

    const vivo = useRef(true);
    const emVoo = useRef(false);
    useEffect(() => {
        vivo.current = true;

        return () => {
            vivo.current = false;
        };
    }, []);
    const agora = useRef({});
    agora.current = { acao, urls, onAvisar, onRecarregar, onRecarregarPrevia };

    // Muda o estado sobre a prévia de agora (`campos` = objeto, ou função do estado que vale → o estado novo inteiro).
    const mudar = useCallback((campos) => setLocal((l) => {
        const base = l.previa === previaAgora.current ? l : { ...LOCAL, previa: previaAgora.current };

        return typeof campos === 'function' ? { ...campos(base), previa: base.previa } : { ...base, ...campos };
    }), []);

    // ─── Acompanhar: a cada 3 s enquanto anda; a limpeza (fechar a gaveta, terminar) cancela a próxima ───
    const perguntar = !!urls.publicacao && devePerguntar(publicacao, atual.voltas, atual.parou);
    useEffect(() => {
        if (!perguntar) return undefined;
        let valendo = true;
        const relogio = setTimeout(async () => {
            const r = await perguntarPublicacao(agora.current.urls.publicacao);
            if (!valendo || !vivo.current) return;
            mudar((base) => estadoDepoisDaConsulta(base, r));
        }, INTERVALO_DA_CONSULTA_MS);

        return () => {
            valendo = false;
            clearTimeout(relogio);
        };
    }, [perguntar, publicacao, atual.voltas, mudar]);

    // ─── Terminou de publicar com a gaveta aberta: a Lista recarrega (a linha vai para "no ar") e a página avisa ───
    const anterior = useRef(situacao);
    useEffect(() => {
        const antes = anterior.current;
        anterior.current = situacao;
        if (!terminouDePublicar(antes, situacao)) return;
        agora.current.onRecarregar?.();
        agora.current.onAvisar?.(AVISO_PUBLICADO, 'sucesso');
    }, [situacao]);

    const enviar = async (ciente) => {
        if (emVoo.current || !agora.current.acao || agora.current.acao.desligado) return;
        emVoo.current = true;
        const corpo = corpoDoPublicar(previaAgora.current, ciente);
        mudar({ confirmando: false, enviando: true, erro: null });
        const r = await enviarPublicacao(agora.current.urls, corpo);
        emVoo.current = false;
        if (!vivo.current) return;
        mudar((base) => estadoDepoisDoEnvio(base, r));
    };

    return {
        urls,
        publicacao,
        vista: vistaDaPublicacao(publicacao),
        acao,
        confirmando: atual.confirmando && !!acao && !acao.ciente,
        enviando: atual.enviando,
        erro: atual.erro,
        falha: atual.falha,
        parou: parouDeAcompanhar(publicacao, atual.voltas, atual.parou),
        travaDoPreco: travaDoPreco(publicacao),
        pedir: () => {
            if (agora.current.acao && !agora.current.acao.desligado) mudar({ confirmando: true, erro: null });
        },
        cancelar: () => mudar({ confirmando: false }),
        confirmar: () => enviar(false),
        publicarAssimMesmo: () => enviar(true),
        recarregar: () => {
            setLocal(LOCAL);
            agora.current.onRecarregarPrevia?.();
        },
        acompanharDeNovo: () => mudar({ voltas: 0, parou: false, falha: null }),
    };
}

/** As ações da confirmação: [Publicar] (o primário) e [Cancelar] (a que não faz nada, com o foco e o Esc). */
export const acoesDaConfirmacao = (publicar) => [
    { rotulo: 'Publicar', acao: 'confirmar-publicar', primario: true, onClick: publicar.confirmar },
    { rotulo: 'Cancelar', acao: 'cancelar-publicar', foco: true, onClick: publicar.cancelar },
];

/**
 * A confirmação no RODAPÉ da gaveta (como a do preço, 4c): "Publicar este anúncio na loja agora?", com o foco em
 * [Cancelar] — publicar não se desfaz; o Esc aqui é o mesmo que ele (o `preventDefault` avisa a gaveta para não fechar).
 */
export function ConfirmacaoDaPublicacao({ publicar }) {
    const focado = useRef(null);
    const acoes = acoesDaConfirmacao(publicar);
    const doFoco = acoes.find((a) => a.foco);

    useEffect(() => {
        focado.current?.focus();
    }, []);

    return (
        <div
            className="flex w-full flex-wrap items-center justify-end gap-2"
            data-confirmacao-da-publicacao=""
            onKeyDown={(evento) => {
                if (evento.key !== 'Escape') return;
                evento.preventDefault();
                doFoco.onClick();
            }}
        >
            <span role="alert" className="mr-auto min-w-0 self-center text-[12.5px] font-semibold text-trilha-fg">
                {PERGUNTA_DA_CONFIRMACAO}
            </span>
            {acoes.map((a) => (
                <Botao key={a.acao} ref={a === doFoco ? focado : undefined} primario={!!a.primario} data-acao={a.acao} onClick={a.onClick}>
                    {a.rotulo}
                </Botao>
            ))}
        </div>
    );
}

const CAIXA_DE_AVISO = 'flex flex-col gap-1 rounded-xl border px-3 py-2 text-[12.5px]';
const TOM_DA_CAIXA = {
    warn: 'border-trilha-warn/40 bg-trilha-warn-bg text-trilha-warn',
    bad: 'border-trilha-bad/40 bg-trilha-bad-bg text-trilha-bad',
};

/**
 * O rodapé da gaveta com a publicação (4d): a situação (uma região `status`, sempre presente, para o leitor de tela
 * ler cada troca), a lista dos avisos ou dos erros da conferência, o erro do pedido, o "parei de acompanhar", as ações
 * que já existiam (`children`: "Ver a oferta" e o link de admin) e o primário. Na confirmação, só ela.
 *
 * O foco não cai no <body>: a cada troca do rodapé (o [Aprovar e publicar] que vira confirmação e volta, o "Enviando…"
 * que some quando o pedido anda, o erro), se o foco se perdeu ele vai para o [Recarregar], o primário ou a situação.
 *
 * @param {{publicar: object, children?: React.ReactNode}} props  `publicar` = o que `usePublicacaoDaPrevia` devolve.
 */
export default function RodapeDaPublicacao({ publicar, children = null }) {
    const { vista, acao, erro, falha, parou, confirmando, enviando } = publicar;
    const idDaDica = useId();
    const daSituacao = useRef(null);
    const doBotao = useRef(null);
    const doRecarregar = useRef(null);
    const marca = [confirmando, enviando, vista?.situacao, erro?.tipo, erro?.mensagem, acao?.acao, acao?.desligado, parou].join('|');

    useEffect(() => {
        if (confirmando || typeof document === 'undefined') return;
        const ativo = document.activeElement;
        if (ativo && ativo !== document.body && !ativo.disabled) return;
        const botao = doBotao.current && !doBotao.current.disabled ? doBotao.current : null;
        (doRecarregar.current ?? botao ?? daSituacao.current)?.focus();
    }, [marca]);

    if (confirmando) return <ConfirmacaoDaPublicacao publicar={publicar} />;

    const comBloco = !!(vista?.lista.length || erro || falha || parou);

    return (
        <>
            <div className={cn('flex min-w-0 flex-col gap-1.5 text-[12.5px]', comBloco ? 'basis-full' : 'mr-auto')} data-publicacao={vista?.situacao ?? ''}>
                <span
                    ref={daSituacao}
                    tabIndex={-1}
                    role="status"
                    className="flex min-w-0 flex-wrap items-center gap-1.5 outline-none"
                    data-situacao-da-publicacao={vista?.situacao ?? ''}
                >
                    {vista && (
                        <>
                            <Pilula tom={vista.tom} className="whitespace-normal" data-pilula-da-publicacao="">
                                {vista.andando && <LoaderCircle size={12} aria-hidden="true" className="shrink-0 animate-spin motion-reduce:animate-none" />}
                                {vista.titulo}
                            </Pilula>
                            {vista.detalhe && (
                                <span className="text-trilha-fg2 [overflow-wrap:anywhere]" data-detalhe-da-publicacao="">
                                    {vista.detalhe}
                                </span>
                            )}
                        </>
                    )}
                </span>

                {vista?.lista.length > 0 && (
                    <div className={cn(CAIXA_DE_AVISO, TOM_DA_CAIXA[vista.tom] ?? TOM_DA_CAIXA.warn)} data-lista-da-publicacao={vista.situacao}>
                        <b className="font-semibold">{vista.rotuloDaLista}</b>
                        {/* Rola sozinha (lista longa); focável para rolar pelo teclado. */}
                        <div
                            tabIndex={0}
                            role="region"
                            aria-label={vista.rotuloDaLista}
                            className="max-h-[140px] overflow-auto rounded focus-visible:[outline-style:solid] focus-visible:outline-2 focus-visible:outline-trilha-run"
                        >
                            <ul className="m-0 flex list-disc flex-col gap-0.5 pl-4">
                                {vista.lista.map((item, i) => (
                                    <li key={`${i}-${item}`} className="[overflow-wrap:anywhere]">
                                        {item}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    </div>
                )}

                {parou && (
                    <span className="flex flex-wrap items-center gap-2 text-trilha-fg2" data-parou-de-acompanhar="">
                        <span className="min-w-0 [overflow-wrap:anywhere]">{falha ? `${falha} ${MSG_PAROU}` : MSG_PAROU}</span>
                        <Botao data-acao="acompanhar-de-novo" onClick={publicar.acompanharDeNovo}>
                            <RotateCw size={14} aria-hidden="true" />
                            Acompanhar de novo
                        </Botao>
                    </span>
                )}
                {falha && !parou && (
                    <span className="text-trilha-warn" data-falha-da-consulta="">
                        Não consegui conferir agora; tento de novo em instantes.
                    </span>
                )}

                {erro && (
                    <div
                        role="alert"
                        className={cn('flex flex-wrap items-center gap-2 rounded-xl border px-3 py-2 text-[12.5px]', erro.tipo === 'revisao' ? TOM_DA_CAIXA.warn : TOM_DA_CAIXA.bad)}
                        data-erro-da-publicacao={erro.tipo}
                    >
                        <b className="mr-auto min-w-0 font-semibold [overflow-wrap:anywhere]">{erro.mensagem}</b>
                        {erro.tipo === 'revisao' && (
                            <Botao ref={doRecarregar} data-acao="recarregar-anuncio" onClick={publicar.recarregar}>
                                <RotateCw size={14} aria-hidden="true" />
                                Recarregar
                            </Botao>
                        )}
                    </div>
                )}

                {acao?.dica && (
                    <span id={idDaDica} className="text-trilha-muted" data-dica-da-publicacao="">
                        {acao.dica}
                    </span>
                )}
            </div>

            {children}

            {acao && (
                <Botao
                    ref={doBotao}
                    primario
                    data-acao={acao.ciente ? 'publicar-assim-mesmo' : 'aprovar-e-publicar'}
                    disabled={acao.desligado}
                    ocupado={acao.ocupado}
                    aria-describedby={acao.dica ? idDaDica : undefined}
                    onClick={acao.ciente ? publicar.publicarAssimMesmo : publicar.pedir}
                >
                    <Send size={14} aria-hidden="true" />
                    {acao.rotulo}
                </Botao>
            )}
        </>
    );
}
