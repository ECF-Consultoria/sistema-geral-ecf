import { useCallback, useEffect, useId, useMemo, useRef, useState } from 'react';
import { Check, LoaderCircle, RotateCw, WandSparkles } from 'lucide-react';
import { estadoDoAjuste, mensagemDoErro, pedirAjuste } from '@/lib/trilhaEscrita';
import {
    AVISO_FALHOU,
    AVISO_TERMINOU,
    ESTADO_DO_AJUSTE,
    INTERVALO_DA_CONSULTA_MS,
    MSG_PAROU,
    PERGUNTA_DA_ESCOLHA,
    acaoDoAjuste,
    ajusteDaPrevia,
    ajusteDaResposta,
    ajusteEfetivo,
    ajusteNaFila,
    alternarParte,
    corpoDoAjuste,
    devePararNoErro,
    devePerguntar,
    devePerguntarDepoisDoErro,
    emAndamento,
    erroDoAjuste,
    estadoDepoisDaConsulta,
    estadoDepoisDoEnvio,
    parouDeAcompanhar,
    restantesHoje,
    terminouOAjuste,
    travaDaPublicacao,
    travaDoPreco,
    urlDoAjuste,
    vistaDaEscolha,
    vistaDoAjuste,
} from '@/lib/trilhaAjuste';
import { cn } from '@/lib/utils';
import { Botao, Pilula } from '@/Components/Trilha/Pecas';

// ─── Trilha de produtos — pedir ajuste ao agente pela prévia (4ª entrega, fatia 4b) ──
//
// §19.14 do desenho. O rodapé da gaveta `v:{item}` ganha o secundário [Pedir
// ajuste ao agente]: só para a equipe que recebeu `escrita.revisao.ajuste`, com a
// prévia pronta, o item na revisão, sem publicação andando (ou feita) e sem
// ajuste andando. No limite do dia (3 por anúncio) ele fica desligado com a dica.
//
// O botão abre no rodapé a escolha "O que refazer?" — [Título] [Descrição]
// (alternáveis, pelo menos um), "Restam N ajustes hoje", [Refazer] [Cancelar].
// NENHUM campo de texto: o agente refaz com o próprio preparo (D51), sem pedido livre.
//
// Depois do pedido (202 `{ajuste}`), a gaveta pergunta o `GET` (a mesma URL) a
// cada 3 s enquanto ele anda (na fila → fazendo) e para ao fechar ou ao terminar.
// No fim, recarrega a prévia (o título e a descrição novos aparecem) e a Lista (o
// "Agente refazendo · …" da linha some, D60), e mostra o resultado por parte
// ("Título refeito.", "Descrição mantida: a equipe editou."). Aberta com o ajuste
// andando (a prévia já traz `ajuste`), a gaveta retoma o acompanhamento sozinha.
//
// Os erros do pedido ficam no rodapé: o 409 da revisão velha ("Este anúncio mudou
// depois que você abriu" + [Recarregar], o padrão F6) e os outros 409/422/403 com
// a mensagem do servidor. Num 409 que não é da revisão (outra aba já pediu) ou num
// 422 (o limite do dia), a gaveta pergunta o estado uma vez: passa a acompanhar o
// ajuste que já anda, ou fica sabendo que não sobra nenhum hoje.
//
// Enquanto o ajuste anda, a caixa "Preço" e o [Aprovar e publicar] ficam
// desligados, com a dica (`travaDoPreco`/`travaDaPublicacao`, aplicadas pela vista).

/**
 * O pedido de ajuste e o que vem dele, sem React (o teste chama com o axios de mentira): o 202 traz o ajuste (`fila`);
 * o erro, o que ele quer dizer (`erroDoAjuste`). No 409 que não é da revisão e no 422, uma pergunta ao acompanhamento:
 * se o ajuste já anda (outra aba), a gaveta passa a mostrá-lo; no limite, o "restam 0" chega junto. `ajuste` undefined =
 * não mexe.
 *
 * @returns {Promise<{ajuste: (?object|undefined), erro: ?object}>}
 */
export async function enviarAjuste(url, corpo) {
    try {
        const resposta = await pedirAjuste(url, corpo);

        return { ajuste: ajusteDaResposta(resposta) ?? ajusteNaFila(corpo), erro: null };
    } catch (falha) {
        const erro = erroDoAjuste(falha, mensagemDoErro(falha));
        let ajuste;
        if (devePerguntarDepoisDoErro(erro) && url) {
            try {
                ajuste = ajusteDaResposta(await estadoDoAjuste(url)) ?? undefined;
            } catch {
                // Sem o estado, fica só a mensagem do erro.
            }
        }

        return { ajuste, erro };
    }
}

/**
 * Uma pergunta ao acompanhamento, sem React: o ajuste de agora (null = nunca pedido), ou a falha (o texto comum) e se
 * ela não passa sozinha (`devePararNoErro`). `ajuste` undefined = não mexe.
 *
 * @returns {Promise<{ajuste: (?object|undefined), falha: ?string, parar: boolean}>}
 */
export async function perguntarAjuste(url) {
    try {
        return { ajuste: ajusteDaResposta(await estadoDoAjuste(url)), falha: null, parar: false };
    } catch (falha) {
        return { ajuste: undefined, falha: mensagemDoErro(falha), parar: devePararNoErro(falha) };
    }
}

/**
 * O estado do ajuste e as ações dele, para a gaveta `v:`. `previa` é a que a gaveta mostra (o `ajuste` dela vale até a
 * gaveta saber de um mais novo); `publicacao` = a publicação que vale agora (`usePublicacaoDaPrevia`); `precoPendente` =
 * preço digitado e não salvo, ou salvando; `onRecarregarPrevia` pede a prévia de novo (o fim do ajuste e o [Recarregar]
 * do 409 da revisão); `onRecarregar` recarrega a Lista (o status da linha).
 *
 * @param {{escrita: ?object, oferta: object, previa: ?object, publicacao?: ?object, precoPendente?: boolean,
 *   onRecarregarPrevia?: ?(() => void), onAvisar?: (texto: string, tipo?: string) => void, onRecarregar?: ?(() => void)}} opcoes
 */
export function useAjusteDaPrevia({ escrita, oferta, previa, publicacao = null, precoPendente = false, onRecarregarPrevia = null, onAvisar = () => {}, onRecarregar = null }) {
    const url = useMemo(() => urlDoAjuste(escrita, oferta?.id), [escrita, oferta?.id]);
    // A prévia de agora, lida também dentro das mudanças de estado: o ajuste que chega é "mais novo" que ela.
    const previaAgora = useRef(previa);
    previaAgora.current = previa;
    const [local, setLocal] = useState(ESTADO_DO_AJUSTE);
    const daPrevia = useMemo(() => ajusteDaPrevia(previa), [previa]);
    const ajuste = ajusteEfetivo(local, previa, daPrevia);
    const restantes = restantesHoje(local, previa);
    const acao = acaoDoAjuste({ escrita, previa, oferta, ajuste, publicacao, restantes, precoPendente });
    // A escolha fica aberta enquanto o botão vale (ou enquanto o pedido dela sai).
    const escolhendo = local.escolhendo && (!!acao || local.enviando);
    const escolha = escolhendo ? vistaDaEscolha({ partes: local.partes, restantes, precoPendente, enviando: local.enviando }) : null;
    const idDaDica = useId();
    const refDoBotao = useRef(null);

    const vivo = useRef(true);
    const emVoo = useRef(false);
    useEffect(() => {
        vivo.current = true;

        return () => {
            vivo.current = false;
        };
    }, []);
    const agora = useRef({});
    agora.current = { acao, escolha, local, url, onAvisar, onRecarregar, onRecarregarPrevia };

    // Muda o estado (`campos` = objeto, ou função do estado → o estado novo inteiro). Quando o AJUSTE muda, ele é
    // marcado com a prévia de agora: a prévia que chegar depois dele é mais nova (`ajusteEfetivo`).
    const mudar = useCallback((campos) => setLocal((l) => {
        const novo = typeof campos === 'function' ? campos(l) : { ...l, ...campos };

        return novo.ajuste !== l.ajuste ? { ...novo, previa: previaAgora.current } : novo;
    }), []);

    // ─── Acompanhar: a cada 3 s enquanto anda; a limpeza (fechar a gaveta, terminar) cancela a próxima ───
    const perguntar = !!url && devePerguntar(ajuste, local.voltas, local.parou);
    useEffect(() => {
        if (!perguntar) return undefined;
        let valendo = true;
        const relogio = setTimeout(async () => {
            const r = await perguntarAjuste(agora.current.url);
            if (!valendo || !vivo.current) return;
            mudar((base) => estadoDepoisDaConsulta(base, r));
        }, INTERVALO_DA_CONSULTA_MS);

        return () => {
            valendo = false;
            clearTimeout(relogio);
        };
    }, [perguntar, ajuste, local.voltas, mudar]);

    // ─── Terminou com a gaveta aberta: a prévia (texto novo) e a Lista (o status da linha) recarregam, e a página avisa ───
    const situacao = ajuste?.situacao ?? null;
    const anterior = useRef(situacao);
    useEffect(() => {
        const antes = anterior.current;
        anterior.current = situacao;
        if (!terminouOAjuste(antes, situacao)) return;
        agora.current.onRecarregarPrevia?.();
        agora.current.onRecarregar?.();
        agora.current.onAvisar?.(situacao === 'feito' ? AVISO_TERMINOU : AVISO_FALHOU, situacao === 'feito' ? 'sucesso' : 'erro');
    }, [situacao]);

    const refazer = async () => {
        const { escolha: daVez, local: atual, url: endereco } = agora.current;
        if (emVoo.current || !daVez?.podeRefazer) return;
        emVoo.current = true;
        const corpo = corpoDoAjuste(previaAgora.current, atual.partes);
        mudar({ enviando: true, erro: null });
        const r = await enviarAjuste(endereco, corpo);
        emVoo.current = false;
        if (!vivo.current) return;
        mudar((base) => estadoDepoisDoEnvio(base, r));
        // O pedido entrou: a linha da Lista passa a dizer "Agente refazendo · …" (D60).
        if (!r.erro) agora.current.onRecarregar?.();
    };

    return {
        url,
        ajuste,
        vista: vistaDoAjuste(ajuste),
        acao,
        restantes,
        escolhendo,
        escolha,
        enviando: local.enviando,
        erro: local.erro,
        falha: local.falha,
        parou: parouDeAcompanhar(ajuste, local.voltas, local.parou),
        andando: emAndamento(ajuste),
        travaDoPreco: travaDoPreco(ajuste, local.enviando),
        travaDaPublicacao: travaDaPublicacao(ajuste, local.enviando),
        idDaDica,
        refDoBotao,
        pedir: () => {
            if (agora.current.acao && !agora.current.acao.desligado) mudar({ escolhendo: true, partes: [], erro: null });
        },
        alternar: (parte) => {
            if (!emVoo.current) mudar((l) => ({ ...l, partes: alternarParte(l.partes, parte) }));
        },
        refazer,
        cancelar: () => {
            if (!emVoo.current) mudar({ escolhendo: false, partes: [] });
        },
        recarregar: () => {
            setLocal(ESTADO_DO_AJUSTE);
            agora.current.onRecarregarPrevia?.();
        },
        acompanharDeNovo: () => mudar({ voltas: 0, parou: false, falha: null }),
    };
}

// O botão de alternar das partes: o mesmo jeito das abas Clássico/Premium da prévia (cópia das classes de
// `PreviaDoAnuncio.jsx`, que importa este arquivo).
const BOTAO_DE_ALTERNAR = cn(
    'inline-flex items-center gap-1.5 whitespace-nowrap rounded-full border px-3 py-1 text-[12.5px] font-semibold',
    'focus-visible:[outline-style:solid] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-trilha-run',
);
const MARCADO = 'border-trilha-navy bg-trilha-navy text-trilha-on-navy';
const DESMARCADO = 'border-trilha-line bg-trilha-surface text-trilha-fg hover:border-trilha-muted';

/** As ações da escolha: [Refazer] (o primário) e [Cancelar] (a que não faz nada, com o Esc). */
export const acoesDaEscolha = (ajuste) => [
    { rotulo: ajuste.escolha?.rotulo ?? 'Refazer', acao: 'refazer-ajuste', primario: true, onClick: ajuste.refazer },
    { rotulo: 'Cancelar', acao: 'cancelar-ajuste', onClick: ajuste.cancelar },
];

/**
 * A escolha no RODAPÉ da gaveta (como a confirmação da publicação, 4d): "O que refazer?" com [Título] [Descrição]
 * (alternáveis), "Restam N ajustes hoje", [Refazer] e [Cancelar]. Nenhum campo de texto. O foco vai para a 1ª parte; o
 * Esc é o [Cancelar] (o `preventDefault` avisa a gaveta para não fechar).
 */
export function EscolhaDoAjuste({ ajuste }) {
    const { escolha } = ajuste;
    const idDaPergunta = useId();
    const idDaDica = useId();
    const primeira = useRef(null);
    const [refazer, cancelar] = acoesDaEscolha(ajuste);

    useEffect(() => {
        primeira.current?.focus();
    }, []);

    if (!escolha) return null;

    return (
        <div
            className="flex w-full flex-wrap items-center justify-end gap-2"
            data-escolha-do-ajuste=""
            onKeyDown={(evento) => {
                if (evento.key !== 'Escape') return;
                evento.preventDefault();
                cancelar.onClick();
            }}
        >
            <span className="mr-auto flex min-w-0 flex-col gap-0.5 self-center">
                <span id={idDaPergunta} className="text-[12.5px] font-semibold text-trilha-fg">
                    {PERGUNTA_DA_ESCOLHA}
                </span>
                {escolha.restantes && (
                    <span className="text-[12px] text-trilha-muted" data-restantes-do-ajuste="">
                        {escolha.restantes}
                    </span>
                )}
                {escolha.dica && (
                    <span id={idDaDica} className="text-[12px] text-trilha-muted" data-dica-da-escolha="">
                        {escolha.dica}
                    </span>
                )}
            </span>
            <span role="group" aria-labelledby={idDaPergunta} className="flex flex-wrap items-center gap-1.5" data-partes-do-ajuste="">
                {escolha.opcoes.map((o, i) => (
                    <button
                        key={o.parte}
                        ref={i === 0 ? primeira : undefined}
                        type="button"
                        aria-pressed={o.marcada}
                        aria-disabled={escolha.ocupado || undefined}
                        data-parte={o.parte}
                        onClick={() => ajuste.alternar(o.parte)}
                        className={cn(BOTAO_DE_ALTERNAR, o.marcada ? MARCADO : DESMARCADO, escolha.ocupado && 'cursor-progress opacity-60')}
                    >
                        {o.marcada && <Check size={13} aria-hidden="true" />}
                        {o.nome}
                    </button>
                ))}
            </span>
            <Botao
                primario
                data-acao={refazer.acao}
                disabled={!escolha.podeRefazer && !escolha.ocupado}
                ocupado={escolha.ocupado}
                aria-describedby={escolha.dica ? idDaDica : undefined}
                onClick={refazer.onClick}
            >
                {refazer.rotulo}
            </Botao>
            <Botao data-acao={cancelar.acao} ocupado={escolha.ocupado} onClick={cancelar.onClick}>
                {cancelar.rotulo}
            </Botao>
        </div>
    );
}

/** O secundário [Pedir ajuste ao agente] (a dica dele mora no bloco, em cima do rodapé). */
export function BotaoDoAjuste({ ajuste }) {
    const { acao } = ajuste;
    if (!acao) return null;

    return (
        <Botao
            ref={ajuste.refDoBotao}
            data-acao="pedir-ajuste"
            disabled={acao.desligado}
            aria-describedby={acao.dica ? ajuste.idDaDica : undefined}
            onClick={ajuste.pedir}
        >
            <WandSparkles size={14} aria-hidden="true" />
            {acao.rotulo}
        </Botao>
    );
}

const CAIXA_DE_AVISO = 'flex flex-wrap items-center gap-2 rounded-xl border px-3 py-2 text-[12.5px]';
const TOM_DA_CAIXA = {
    warn: 'border-trilha-warn/40 bg-trilha-warn-bg text-trilha-warn',
    bad: 'border-trilha-bad/40 bg-trilha-bad-bg text-trilha-bad',
};
const TOM_DO_RESULTADO = { ok: 'text-trilha-ok', neutro: 'text-trilha-fg2', bad: 'text-trilha-bad' };

/**
 * O bloco do ajuste, em cima das ações do rodapé: a situação (uma região `status`, sempre presente, para o leitor de
 * tela ler cada troca), o resultado por parte, o "parei de acompanhar", o erro do pedido e a dica do botão. Ocupa a
 * linha inteira só quando tem o que mostrar. `escondido` = a confirmação da publicação tomou o rodapé.
 *
 * O foco não cai no <body>: a cada troca (a escolha que fecha, o pedido que entra, o erro), se o foco se perdeu ele vai
 * para o [Recarregar], o [Pedir ajuste ao agente], o erro ou a situação. Este efeito roda ANTES do da publicação (vem
 * antes no rodapé): quando ele acha onde pôr o foco, o de lá não mexe.
 */
export function BlocoDoAjuste({ ajuste, escondido = false }) {
    const { vista, acao, erro, falha, parou, escolhendo, enviando } = ajuste;
    const daSituacao = useRef(null);
    const doRecarregar = useRef(null);
    const doErro = useRef(null);
    const marca = [escolhendo, enviando, vista?.situacao, vista?.titulo, erro?.tipo, erro?.mensagem, !!acao, acao?.desligado, parou, escondido].join('|');

    useEffect(() => {
        if (escondido || typeof document === 'undefined') return;
        const ativo = document.activeElement;
        if (ativo && ativo !== document.body && !ativo.disabled) return;
        const botao = ajuste.refDoBotao.current && !ajuste.refDoBotao.current.disabled ? ajuste.refDoBotao.current : null;
        (doRecarregar.current ?? botao ?? doErro.current ?? (vista ? daSituacao.current : null))?.focus();
    }, [marca]);

    if (escondido) return null;

    const comBloco = !!(vista || erro || falha || parou || acao?.dica);

    return (
        <div className={cn('flex min-w-0 flex-col gap-1.5 text-[12.5px]', comBloco && 'basis-full')} data-ajuste={vista?.situacao ?? ''}>
            <span
                ref={daSituacao}
                tabIndex={-1}
                role="status"
                className="flex min-w-0 flex-wrap items-center gap-1.5 outline-none"
                data-situacao-do-ajuste={vista?.situacao ?? ''}
            >
                {vista && (
                    <>
                        <Pilula tom={vista.tom} className="whitespace-normal" data-pilula-do-ajuste="">
                            {vista.andando ? (
                                <LoaderCircle size={12} aria-hidden="true" className="shrink-0 animate-spin motion-reduce:animate-none" />
                            ) : (
                                <WandSparkles size={12} aria-hidden="true" className="shrink-0" />
                            )}
                            {vista.titulo}
                        </Pilula>
                        {vista.detalhe && (
                            <span className="text-trilha-fg2 [overflow-wrap:anywhere]" data-detalhe-do-ajuste="">
                                {vista.detalhe}
                            </span>
                        )}
                    </>
                )}
                {vista?.resultados.length > 0 && (
                    <span className="flex basis-full flex-col gap-0.5" data-resultado-do-ajuste="">
                        {vista.resultados.map((r) => (
                            <span key={r.parte} className={cn('[overflow-wrap:anywhere]', TOM_DO_RESULTADO[r.tom])} data-parte={r.parte} data-situacao-da-parte={r.situacao}>
                                <b className="font-semibold">{r.texto}</b>
                                {r.detalhe && <span className="text-trilha-fg2"> {r.detalhe}</span>}
                            </span>
                        ))}
                    </span>
                )}
            </span>

            {parou && (
                <span className="flex flex-wrap items-center gap-2 text-trilha-fg2" data-parou-de-acompanhar-o-ajuste="">
                    <span className="min-w-0 [overflow-wrap:anywhere]">{falha ? `${falha} ${MSG_PAROU}` : MSG_PAROU}</span>
                    <Botao data-acao="acompanhar-ajuste-de-novo" onClick={ajuste.acompanharDeNovo}>
                        <RotateCw size={14} aria-hidden="true" />
                        Acompanhar de novo
                    </Botao>
                </span>
            )}
            {falha && !parou && (
                <span className="text-trilha-warn" data-falha-da-consulta-do-ajuste="">
                    Não consegui conferir agora; tento de novo em instantes.
                </span>
            )}

            {erro && (
                <div
                    ref={doErro}
                    tabIndex={-1}
                    role="alert"
                    className={cn(CAIXA_DE_AVISO, 'outline-none', erro.tipo === 'revisao' ? TOM_DA_CAIXA.warn : TOM_DA_CAIXA.bad)}
                    data-erro-do-ajuste={erro.tipo}
                >
                    <b className="mr-auto min-w-0 font-semibold [overflow-wrap:anywhere]">{erro.mensagem}</b>
                    {erro.tipo === 'revisao' && (
                        <Botao ref={doRecarregar} data-acao="recarregar-ajuste" onClick={ajuste.recarregar}>
                            <RotateCw size={14} aria-hidden="true" />
                            Recarregar
                        </Botao>
                    )}
                </div>
            )}

            {acao?.dica && (
                <span id={ajuste.idDaDica} className="text-trilha-muted" data-dica-do-ajuste="">
                    {acao.dica}
                </span>
            )}
        </div>
    );
}

/**
 * O rodapé com o ajuste (4b): o bloco do ajuste em cima das ações (`children`: o rodapé da publicação, ou as ações da
 * 4a). Com a escolha aberta, só ela — o rodapé de sempre sai, como na confirmação da publicação.
 *
 * @param {{ajuste: object, escondido?: boolean, children?: React.ReactNode}} props  `ajuste` = o que
 *   `useAjusteDaPrevia` devolve.
 */
export default function RodapeDoAjuste({ ajuste, escondido = false, children = null }) {
    if (ajuste.escolhendo) return <EscolhaDoAjuste ajuste={ajuste} />;

    return (
        <>
            <BlocoDoAjuste ajuste={ajuste} escondido={escondido} />
            {children}
        </>
    );
}
