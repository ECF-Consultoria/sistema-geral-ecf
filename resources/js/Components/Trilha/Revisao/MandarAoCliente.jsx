import { useCallback, useEffect, useId, useMemo, useRef, useState } from 'react';
import { RotateCw, UserCheck } from 'lucide-react';
import { mandarAoCliente, mensagemDoErro } from '@/lib/trilhaEscrita';
import {
    AVISO_ENVIADO,
    ESTADO_DO_ENVIO,
    PERGUNTA_DO_ENVIO,
    ROTULO_CONFIRMAR_ENVIO,
    acaoDoEnvio,
    corpoDoEnvio,
    equipePublicaDireto,
    erroDoEnvio,
    estadoDepoisDoEnvio,
    previaDaResposta,
    urlDoEnvio,
    vistaDaAprovacao,
} from '@/lib/trilhaAprovacao';
import { cn } from '@/lib/utils';
import { Botao, Caixa, Pilula } from '@/Components/Trilha/Pecas';

// ─── Trilha de produtos — mandar a prévia ao cliente (4ª entrega, fatia 4e) ──
//
// §19.16 do desenho. Com a chave da loja (o servidor manda `escrita.revisao.cliente`,
// com `__ITEM__`), a equipe NÃO publica direto: o primário da gaveta `v:{item}` vira
// [Mandar ao cliente] (confirmação no rodapé — "Mandar este anúncio para o cliente
// aprovar?"), e o [Aprovar e publicar] da 4d só volta para o anúncio que o cliente
// aprovou e ainda falta publicar (D56). Sem a chave, nada daqui aparece.
//
// A caixa "Aprovação do cliente" (no corpo, logo depois do "Onde está") diz em que pé
// está: com o cliente desde… (o primário desligado: espera a resposta), o pedido de
// ajuste dele citado como ele escreveu (o primário vira [Mandar ao cliente de novo];
// o preço e o ajuste pelo robô seguem como sempre), o anúncio que voltou para a equipe
// porque mudou depois do envio (D55), o aprovado (que falta publicar, ou segue pela
// publicação no rodapé).
//
// O `POST {revisao}` responde a prévia NOVA (com `aprovacao` em `com_cliente`): a
// gaveta passa a mostrá-la, a Lista recarrega ("Com o cliente para aprovar") e a
// página avisa. Os erros ficam no rodapé: o 409 da revisão velha ("Este anúncio mudou
// depois que você abriu" + [Recarregar], o padrão F6), os outros 409 (a chave
// desligada, já com o cliente, o robô ajustando…) e o 422 (o Premium igual ao
// Clássico), com a mensagem do servidor.
//
// Nenhum texto daqui cita o marketplace, o Portal, o Publicador nem o "agente" (D59).

/**
 * O pedido de mandar ao cliente e o que vem dele, sem React (o teste chama com o axios de mentira): o 200 traz a prévia
 * nova (null quando o corpo não parece uma prévia — a gaveta pede de novo); o erro, o que ele quer dizer.
 *
 * @returns {Promise<{previa: (?object|undefined), erro: ?object}>}
 */
export async function enviarAoCliente(url, corpo) {
    try {
        return { previa: previaDaResposta(await mandarAoCliente(url, corpo)), erro: null };
    } catch (falha) {
        return { previa: undefined, erro: erroDoEnvio(falha, mensagemDoErro(falha)) };
    }
}

/**
 * O envio ao cliente e as ações dele, para a gaveta `v:`. `previa` é a que a gaveta mostra; `publicacao` = a que vale
 * agora (`usePublicacaoDaPrevia`); `precoPendente` = preço digitado e não salvo, ou salvando; `onPreviaNova` troca a
 * prévia da gaveta pela da resposta; `onRecarregarPrevia` pede a prévia de novo (o [Recarregar] do 409 da revisão);
 * `onRecarregar` recarrega a Lista (o status da linha).
 *
 * O estado fica preso à prévia em que nasceu (como o da publicação, 4d): chegou outra, ele recomeça — menos a nota do
 * envio, que nasce já presa à prévia da resposta.
 *
 * @param {{escrita: ?object, oferta: object, previa: ?object, publicacao?: ?object, precoPendente?: boolean,
 *   onPreviaNova?: ?((previa: object) => void), onRecarregarPrevia?: ?(() => void),
 *   onAvisar?: (texto: string, tipo?: string) => void, onRecarregar?: ?(() => void)}} opcoes
 */
export function useEnvioAoCliente({ escrita, oferta, previa, publicacao = undefined, precoPendente = false, onPreviaNova = null, onRecarregarPrevia = null, onAvisar = () => {}, onRecarregar = null }) {
    const url = useMemo(() => urlDoEnvio(escrita, oferta?.id), [escrita, oferta?.id]);
    const previaAgora = useRef(previa);
    previaAgora.current = previa;
    const [local, setLocal] = useState(() => ({ ...ESTADO_DO_ENVIO, previa }));
    const atual = local.previa === previa ? local : { ...ESTADO_DO_ENVIO, previa };
    const acao = acaoDoEnvio({ escrita, previa, oferta, publicacao, precoPendente, enviando: atual.enviando });
    // Depois de mandar (ou de cancelar), o foco volta ao primário — o rodapé o pede uma vez.
    const voltarFoco = useRef(false);

    const vivo = useRef(true);
    const emVoo = useRef(false);
    useEffect(() => {
        vivo.current = true;

        return () => {
            vivo.current = false;
        };
    }, []);
    const agora = useRef({});
    agora.current = { acao, url, onPreviaNova, onRecarregarPrevia, onAvisar, onRecarregar };

    const mudar = useCallback((campos) => setLocal((l) => {
        const base = l.previa === previaAgora.current ? l : { ...ESTADO_DO_ENVIO, previa: previaAgora.current };

        return typeof campos === 'function' ? { ...campos(base), previa: base.previa } : { ...base, ...campos };
    }), []);

    const confirmar = async () => {
        if (emVoo.current || !agora.current.acao || agora.current.acao.desligado) return;
        emVoo.current = true;
        const corpo = corpoDoEnvio(previaAgora.current);
        // A confirmação sai do rodapé: o foco vai para o "Enviando…" (ocupado, focável).
        voltarFoco.current = true;
        mudar({ confirmando: false, enviando: true, erro: null, nota: null });
        const r = await enviarAoCliente(agora.current.url, corpo);
        emVoo.current = false;
        const fora = agora.current;
        if (!r.erro) {
            // Mandou: a linha passa a dizer "Com o cliente para aprovar", e a página avisa (mesmo com a gaveta fechada).
            fora.onRecarregar?.();
            fora.onAvisar?.(AVISO_ENVIADO, 'sucesso');
        } else if (r.erro.tipo === 'conflito') {
            // Outra aba já mandou (ou a chave mudou): a linha mostra onde ele está.
            fora.onRecarregar?.();
        }
        if (!vivo.current) {
            if (r.erro) fora.onAvisar?.(r.erro.mensagem, 'erro');

            return;
        }
        voltarFoco.current = true;
        if (!r.erro && r.previa) {
            // A nota nasce presa à prévia da resposta: a troca da prévia não a apaga.
            setLocal({ ...estadoDepoisDoEnvio(ESTADO_DO_ENVIO, r), previa: r.previa });
            fora.onPreviaNova?.(r.previa);

            return;
        }
        mudar((base) => estadoDepoisDoEnvio(base, r));
        // 200 sem uma prévia no corpo: pede a prévia de novo para mostrar o estado novo.
        if (!r.erro) fora.onRecarregarPrevia?.();
    };

    return {
        url,
        // Com a chave, só o aprovado que falta publicar volta a ter [Aprovar e publicar] (D56).
        publicaDireto: equipePublicaDireto({ escrita, previa, oferta }),
        acao,
        vista: url ? vistaDaAprovacao(previa, publicacao) : null,
        confirmando: atual.confirmando && !!acao && !acao.desligado,
        enviando: atual.enviando,
        erro: atual.erro,
        nota: atual.nota,
        voltarFoco,
        pedir: () => {
            if (agora.current.acao && !agora.current.acao.desligado) mudar({ confirmando: true, erro: null, nota: null });
        },
        cancelar: () => {
            voltarFoco.current = true;
            mudar({ confirmando: false });
        },
        confirmar,
        recarregar: () => {
            setLocal({ ...ESTADO_DO_ENVIO, previa: null });
            agora.current.onRecarregarPrevia?.();
        },
    };
}

const TOM_DA_CAIXA = {
    run: 'border-trilha-run/30 bg-trilha-run-bg',
    warn: 'border-trilha-warn/40 bg-trilha-warn-bg',
    ok: 'border-trilha-ok/40 bg-trilha-ok-bg',
    neutro: '',
};
const COR_DO_TITULO = { run: 'text-trilha-run', warn: 'text-trilha-warn', ok: 'text-trilha-ok', neutro: 'text-trilha-fg' };

/**
 * A caixa "Aprovação do cliente" no corpo da gaveta (só com a chave da loja): o estado, o pedido de ajuste do cliente
 * citado como ele escreveu (é texto para a equipe, D52) e o que fazer agora.
 *
 * @param {{envio: ?object}} props  `envio` = o que `useEnvioAoCliente` devolve.
 */
export function CaixaDaAprovacao({ envio }) {
    const vista = envio?.vista;
    if (!vista) return null;

    return (
        <Caixa
            titulo="Aprovação do cliente"
            extra={vista.rodada && <Pilula tom="neutro" data-rodada="">{vista.rodada}</Pilula>}
            className={TOM_DA_CAIXA[vista.tom]}
            data-caixa="aprovacao-do-cliente"
            data-estado-da-aprovacao={vista.estado ?? ''}
        >
            <span className={cn('flex items-center gap-1.5 text-[13px] font-semibold', COR_DO_TITULO[vista.tom])} data-titulo-da-aprovacao="">
                <UserCheck size={15} aria-hidden="true" className="shrink-0" />
                {vista.titulo}
            </span>
            {vista.quem && (
                <span className="text-[12px] text-trilha-fg2 [overflow-wrap:anywhere]" data-quem-da-aprovacao="">
                    {vista.quem}
                </span>
            )}
            {vista.pedido && (
                <figure className="m-0 flex flex-col gap-1" data-pedido-do-cliente="">
                    <figcaption className="text-[12px] font-semibold text-trilha-fg2">O que o cliente escreveu:</figcaption>
                    {/* Rola sozinho (até 1000 caracteres); focável para rolar pelo teclado. */}
                    <blockquote
                        tabIndex={0}
                        className="m-0 max-h-[180px] overflow-auto whitespace-pre-wrap rounded-lg border-l-[3px] border-trilha-warn bg-trilha-surface px-3 py-2 text-[13px] leading-relaxed text-trilha-fg [overflow-wrap:anywhere] focus-visible:[outline-style:solid] focus-visible:outline-2 focus-visible:outline-trilha-run"
                    >
                        {vista.pedido}
                    </blockquote>
                </figure>
            )}
            {vista.detalhe && (
                <span className="text-[12.5px] text-trilha-fg2 [overflow-wrap:anywhere]" data-detalhe-da-aprovacao="">
                    {vista.detalhe}
                </span>
            )}
        </Caixa>
    );
}

/** As ações da confirmação: [Mandar] (o primário) e [Cancelar] (com o foco e o Esc). */
export const acoesDaConfirmacao = (envio) => [
    { rotulo: ROTULO_CONFIRMAR_ENVIO, acao: 'confirmar-mandar-ao-cliente', primario: true, onClick: envio.confirmar },
    { rotulo: 'Cancelar', acao: 'cancelar-mandar-ao-cliente', foco: true, onClick: envio.cancelar },
];

/**
 * A confirmação no RODAPÉ da gaveta (como a da publicação, 4d): a pergunta e [Mandar] [Cancelar], com o foco em
 * [Cancelar]; o Esc é o mesmo que ele (o `preventDefault` avisa a gaveta para não fechar).
 */
export function ConfirmacaoDoEnvio({ envio }) {
    const focado = useRef(null);
    const acoes = acoesDaConfirmacao(envio);
    const doFoco = acoes.find((a) => a.foco);

    useEffect(() => {
        focado.current?.focus();
    }, []);

    return (
        <div
            className="flex w-full flex-wrap items-center justify-end gap-2"
            data-confirmacao-do-envio=""
            onKeyDown={(evento) => {
                if (evento.key !== 'Escape') return;
                evento.preventDefault();
                doFoco.onClick();
            }}
        >
            <span role="alert" className="mr-auto min-w-0 self-center text-[12.5px] font-semibold text-trilha-fg [overflow-wrap:anywhere]">
                {PERGUNTA_DO_ENVIO}
            </span>
            {acoes.map((a) => (
                <Botao key={a.acao} ref={a === doFoco ? focado : undefined} primario={!!a.primario} data-acao={a.acao} onClick={a.onClick}>
                    {a.rotulo}
                </Botao>
            ))}
        </div>
    );
}

const CAIXA_DO_ERRO = 'flex flex-wrap items-center gap-2 rounded-xl border px-3 py-2 text-[12.5px]';
const TOM_DO_ERRO = {
    revisao: 'border-trilha-warn/40 bg-trilha-warn-bg text-trilha-warn',
    outro: 'border-trilha-bad/40 bg-trilha-bad-bg text-trilha-bad',
};

/**
 * O rodapé com o envio ao cliente (4e), por FORA dos outros (o da publicação, o do ajuste): o erro do pedido, a nota do
 * envio e a dica em cima, as ações de sempre (`children`) e o primário [Mandar ao cliente] por último. Na confirmação,
 * só ela. `escondido` = outra pergunta tomou o rodapé (a guarda do preço, a escolha do ajuste, a confirmação da
 * publicação): só as de dentro.
 *
 * O foco não cai no <body>: depois de mandar ou de cancelar, ele volta para o [Recarregar], o primário (se não ficou
 * desligado) ou a nota; a cada outra troca, só se ele se perdeu. Este efeito roda DEPOIS dos de dentro (é o pai): o
 * pedido de voltar ao primário vence o deles.
 *
 * @param {{envio: object, escondido?: boolean, children?: React.ReactNode}} props  `envio` = o que `useEnvioAoCliente` devolve.
 */
export default function RodapeDoEnvio({ envio, escondido = false, children = null }) {
    const { acao, erro, nota, confirmando, enviando } = envio;
    const idDaDica = useId();
    const daNota = useRef(null);
    const doBotao = useRef(null);
    const doRecarregar = useRef(null);
    const marca = [escondido, confirmando, enviando, erro?.tipo, erro?.mensagem, acao?.rotulo, acao?.desligado, nota].join('|');

    useEffect(() => {
        if (escondido || confirmando || typeof document === 'undefined') return;
        const pedido = envio.voltarFoco?.current;
        if (envio.voltarFoco) envio.voltarFoco.current = false;
        const ativo = document.activeElement;
        if (!pedido && ativo && ativo !== document.body && !ativo.disabled) return;
        const botao = doBotao.current && !doBotao.current.disabled ? doBotao.current : null;
        (doRecarregar.current ?? botao ?? (nota ? daNota.current : null))?.focus();
    }, [marca]);

    if (escondido) return children;
    if (confirmando) return <ConfirmacaoDoEnvio envio={envio} />;

    const comBloco = !!(erro || nota || acao?.dica);

    return (
        <>
            {/* Sem nada a dizer, o bloco não ocupa lugar (a situação da publicação, quando há, fica à esquerda). */}
            <div className={cn('flex min-w-0 flex-col gap-1.5 text-[12.5px]', comBloco ? 'basis-full' : 'hidden')} data-envio-ao-cliente="">
                {/* Sempre presente: o leitor de tela anuncia a nota quando ela chega. */}
                <span ref={daNota} tabIndex={-1} role="status" className="font-semibold text-trilha-ok outline-none empty:hidden" data-nota-do-envio="">
                    {nota ?? ''}
                </span>
                {erro && (
                    <div role="alert" className={cn(CAIXA_DO_ERRO, erro.tipo === 'revisao' ? TOM_DO_ERRO.revisao : TOM_DO_ERRO.outro)} data-erro-do-envio={erro.tipo}>
                        <b className="mr-auto min-w-0 font-semibold [overflow-wrap:anywhere]">{erro.mensagem}</b>
                        {erro.tipo === 'revisao' && (
                            <Botao ref={doRecarregar} data-acao="recarregar-envio" onClick={envio.recarregar}>
                                <RotateCw size={14} aria-hidden="true" />
                                Recarregar
                            </Botao>
                        )}
                    </div>
                )}
                {acao?.dica && (
                    <span id={idDaDica} className="text-trilha-muted" data-dica-do-envio="">
                        {acao.dica}
                    </span>
                )}
            </div>

            {children}

            {acao && (
                <Botao
                    ref={doBotao}
                    primario
                    data-acao={acao.reenvio ? 'mandar-ao-cliente-de-novo' : 'mandar-ao-cliente'}
                    disabled={acao.desligado}
                    ocupado={acao.ocupado}
                    aria-describedby={acao.dica ? idDaDica : undefined}
                    onClick={envio.pedir}
                >
                    <UserCheck size={14} aria-hidden="true" />
                    {acao.rotulo}
                </Botao>
            )}
        </>
    );
}
