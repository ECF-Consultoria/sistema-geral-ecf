import { forwardRef, useCallback, useEffect, useId, useMemo, useRef, useState } from 'react';
import { RotateCcw, RotateCw } from 'lucide-react';
import { mensagemDoErro, salvarPreco } from '@/lib/trilhaEscrita';
import { fmtPreco, linhasDoPreco, textoDoAjustado } from '@/lib/trilhaPrevia';
import {
    DICA_DO_PRECO,
    ESTADO_DO_PRECO,
    alternarVolta,
    digitarPreco,
    escolherCor,
    estadoDepoisDeSalvar,
    estadoDoErro,
    podeEditarPreco,
    textoAbaixoDoMinimo,
    textoDaReferencia,
    textoDasCoresDiferentes,
    textoDoBotaoDoPreco,
    urlDoPreco,
    vistaDoPreco,
} from '@/lib/trilhaPreco';
import { cn } from '@/lib/utils';
import { Botao, CAMPO, Caixa, Opcao, Pilula, ROTULO_DO_CAMPO, Seletor } from '@/Components/Trilha/Pecas';
import { useGuardaDaGaveta } from '@/Components/Trilha/PainelLateral';

// ─── Trilha de produtos — a caixa "Preço" da prévia (4ª entrega, fatia 4c) ──
//
// §19.11 do desenho. Na 4a a caixa só mostrava o preço; agora a EQUIPE ajusta o
// preço do anúncio ali mesmo, e ele grava SÓ no rascunho do anúncio (D45: a
// Precificação nunca muda). Fica editável só com `escrita.revisao.preco` (a
// porta do cliente nem recebe a URL), com a prévia pronta e o item na revisão —
// o publicado é só conferência.
//
// Por tipo (Clássico/Premium): o campo com o valor de agora (aceita vírgula, 2
// casas), "da Precificação: R$ X · mínimo R$ Y" embaixo e [Voltar ao da
// Precificação] (manda `null`: o tipo volta a seguir a Precificação). "Todas as
// cores" × uma cor no seletor. [Salvar preço] manda só o que mudou.
//
// O servidor decide (D46): o "Premium igual ao Clássico" daqui é o aviso na hora
// (o espelho da regra, cor a cor); o mínimo da Precificação é aviso, não trava —
// os `avisos` da resposta aparecem em amarelo.
//
// As respostas: o 200 é a prévia nova, e a gaveta INTEIRA passa a mostrá-la (os
// preços do cartão, o "ajustado", a Situação). O 409 da revisão (`revisao_atual`,
// sem `travado` — o padrão F6) mantém o digitado e oferece [Recarregar] (pede a
// prévia de novo). O 409 da fila, do publicado ou de fora da revisão deixa a
// caixa só leitura, com a mensagem. O 422 marca o campo do tipo.
//
// A guarda (como as outras gavetas): com preço digitado e não salvo, fechar (Esc,
// fundo, "Fechar"), abrir outro item ou trocar o "Ver como" pedem confirmação no
// rodapé; F5 e fechar a aba, o `beforeunload` do navegador.
//
// O campo, o aviso e a confirmação são os do cadastro (`Cadastro/PecasDoCadastro`)
// redesenhados aqui, nas MESMAS classes: importar a pasta `Cadastro/` daqui
// levaria o código do cadastro (~50 kB) para a gaveta da prévia — e, na 4e, o
// cartão da prévia vai ao cliente.

const LINHA_DA_CAIXA = 'flex items-start justify-between gap-2.5 border-b border-trilha-line2 py-2 text-[13px] last:border-b-0';
const CAMPO_COM_ERRO = 'border-trilha-bad focus:border-trilha-bad focus:ring-trilha-bad/30';

/** O aviso amarelo dentro da caixa (o `AvisoNaGaveta` do cadastro, tom `warn`). */
const AvisoDoPreco = forwardRef(function AvisoDoPreco({ titulo = null, children, ...resto }, ref) {
    return (
        <div ref={ref} className="flex flex-col gap-1 rounded-xl border border-trilha-warn/40 bg-trilha-warn-bg px-[14px] py-3 text-[12.5px] text-trilha-warn outline-none" {...resto}>
            {titulo && <b className="text-[13px]">{titulo}</b>}
            <span className="[overflow-wrap:anywhere]">{children}</span>
        </div>
    );
});

/**
 * O foco que não pode cair no <body>: depois de salvar (o [Salvar preço] fica desligado), do 409 da revisão (só o
 * [Recarregar] serve) e do 409 da fila (a caixa troca pela só leitura), o foco que estava no botão some. Só move o
 * foco quando ele já se perdeu (ou está num elemento desligado) — quem estava noutro campo fica onde está.
 */
function useFocoQuandoPerdido(alvo, quando) {
    useEffect(() => {
        if (!quando || typeof document === 'undefined') return;
        const ativo = document.activeElement;
        if (!ativo || ativo === document.body || ativo.disabled) alvo.current?.focus();
    }, [quando]);
}

/** A caixa "Preço" só leitura (a da 4a): o valor de cada tipo e o "ajustado". `aviso` = o porquê de não editar (409). */
function PrecoSoLeitura({ previa, aviso = null }) {
    const linhas = linhasDoPreco(previa);
    const doAviso = useRef(null);
    useFocoQuandoPerdido(doAviso, aviso);

    return (
        <Caixa titulo="Preço" data-caixa="preco">
            {aviso && (
                <AvisoDoPreco ref={doAviso} tabIndex={-1} data-preco-so-leitura="">
                    {aviso}
                </AvisoDoPreco>
            )}
            {linhas.length ? (
                <ul className="m-0 flex list-none flex-col p-0">
                    {linhas.map((linha) => {
                        const nota = textoDoAjustado(linha);

                        return (
                            <li key={linha.chave} className={LINHA_DA_CAIXA} data-preco={linha.chave}>
                                <b className="font-semibold">{linha.nome}</b>
                                <span className="flex flex-col items-end gap-0.5 text-right">
                                    <span className="flex flex-wrap items-center justify-end gap-1.5">
                                        {linha.ajustado && (
                                            <Pilula tom="warn" data-ajustado="">
                                                ajustado
                                            </Pilula>
                                        )}
                                        <span className="font-semibold tabular-nums text-trilha-fg">{fmtPreco(linha.valor)}</span>
                                    </span>
                                    {nota && <span className="text-[12px] text-trilha-muted">{nota}</span>}
                                </span>
                            </li>
                        );
                    })}
                </ul>
            ) : (
                <span className="text-[12.5px] text-trilha-muted">Sem preço na prévia ainda.</span>
            )}
        </Caixa>
    );
}

/** O campo de um tipo: o rótulo, o valor (com "R$" na frente), [Voltar ao da Precificação], a referência, os avisos e o erro. */
function CampoDoTipo({ linha, vista, salvando, onDigitar, onVoltar }) {
    const id = useId();
    const idDaDica = `${id}-dica`;
    const idDoErro = `${id}-erro`;
    const notas = [];
    if (linha.semACor) notas.push(['sem-a-cor', `Este tipo não tem a cor ${vista.rotuloDaCor ?? ''}.`, '']);
    if (linha.voltando) notas.push(['voltando', 'Ao salvar, volta a seguir a Precificação.', 'text-trilha-run']);
    else if (vista.cor === null && linha.porCor && !linha.semACor) notas.push(['por-cor', textoDasCoresDiferentes(linha), '']);
    if (linha.abaixoDoMinimo) notas.push(['abaixo-do-minimo', textoAbaixoDoMinimo(linha), 'font-semibold text-trilha-warn']);

    return (
        <div className="flex min-w-0 flex-col gap-1 border-b border-trilha-line2 pb-3 last:border-b-0 last:pb-0" data-preco={linha.chave} data-campo={`preco-${linha.chave}`}>
            <label htmlFor={id} className="flex flex-wrap items-center gap-1.5 text-[12.5px] font-semibold text-trilha-fg2">
                <span>{linha.nome}</span>
                {linha.ajustado && (
                    <Pilula tom="warn" data-ajustado="">
                        ajustado
                    </Pilula>
                )}
            </label>
            <div className="flex flex-wrap items-center gap-2">
                <span className="relative min-w-[140px] flex-1">
                    <span aria-hidden="true" className="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-[13px] text-trilha-muted">
                        R$
                    </span>
                    <input
                        id={id}
                        className={cn(CAMPO, linha.erro && CAMPO_COM_ERRO, 'pl-8 tabular-nums')}
                        type="text"
                        inputMode="decimal"
                        autoComplete="off"
                        placeholder="0,00"
                        aria-describedby={[idDaDica, linha.erro && idDoErro].filter(Boolean).join(' ')}
                        aria-invalid={linha.erro ? true : undefined}
                        value={linha.texto}
                        // Durante o envio o campo só fica sem mexer (não `disabled`): o foco não cai no <body>.
                        readOnly={salvando}
                        disabled={linha.semACor}
                        data-campo-do-preco={linha.chave}
                        onChange={(e) => onDigitar(linha.chave, e.target.value)}
                    />
                </span>
                {linha.podeVoltar && (
                    <Botao data-acao="voltar-a-precificacao" data-tipo={linha.chave} ocupado={salvando} onClick={() => onVoltar(linha)}>
                        <RotateCcw size={14} aria-hidden="true" />
                        {linha.voltando ? 'Desfazer' : 'Voltar ao da Precificação'}
                    </Botao>
                )}
            </div>
            <span id={idDaDica} className="flex flex-col text-[12px] text-trilha-muted">
                <span data-referencia="">{textoDaReferencia(linha)}</span>
                {notas.map(([marca, texto, tom]) => (
                    <span key={marca} className={tom} data-nota-do-preco={marca}>
                        {texto}
                    </span>
                ))}
            </span>
            {linha.erro && (
                <span id={idDoErro} className="text-[12px] font-semibold text-trilha-bad" data-erro-do-campo={`preco-${linha.chave}`}>
                    {linha.erro}
                </span>
            )}
        </div>
    );
}

/** A caixa "Preço" editável (só a equipe com `escrita.revisao.preco`, a prévia pronta e o item na revisão). */
function PrecoEditavel({ previa, preco }) {
    const { estado } = preco;
    const vista = vistaDoPreco(previa, estado);
    const idDaCor = useId();
    const salvando = !!estado.salvando;
    const daNota = useRef(null);
    const doRecarregar = useRef(null);
    useFocoQuandoPerdido(daNota, estado.nota);
    useFocoQuandoPerdido(doRecarregar, estado.conflito);

    let rodape = { texto: DICA_DO_PRECO, classe: 'text-trilha-muted', marca: 'dica' };
    if (estado.nota) rodape = { texto: estado.nota, classe: 'font-semibold text-trilha-ok', marca: 'salvo' };
    if (vista.resumo) rodape = { texto: vista.resumo, classe: 'font-semibold text-trilha-fg2', marca: 'resumo' };

    return (
        <Caixa titulo="Preço" data-caixa="preco" data-preco-editavel="">
            <form
                noValidate
                className="flex flex-col gap-3"
                onSubmit={(evento) => {
                    evento.preventDefault();
                    preco.salvar();
                }}
            >
                {vista.cores.length > 1 && (
                    <label htmlFor={idDaCor} className={ROTULO_DO_CAMPO}>
                        Aplicar em
                        <Seletor
                            id={idDaCor}
                            value={vista.cor ?? ''}
                            disabled={salvando}
                            data-escolha-da-cor=""
                            onChange={(e) => preco.escolherCor(e.target.value || null)}
                        >
                            <Opcao value="">Todas as cores</Opcao>
                            {vista.cores.map((c) => (
                                <Opcao key={c.chave} value={c.chave}>
                                    {c.rotulo}
                                </Opcao>
                            ))}
                        </Seletor>
                    </label>
                )}

                <div className="flex flex-col gap-3">
                    {vista.linhas.map((linha) => (
                        <CampoDoTipo key={linha.chave} linha={linha} vista={vista} salvando={salvando} onDigitar={preco.digitar} onVoltar={preco.alternarVolta} />
                    ))}
                </div>

                {vista.premiumIgual && (
                    <AvisoDoPreco data-premium-igual="">
                        {vista.premiumIgual}
                    </AvisoDoPreco>
                )}

                {estado.avisos?.length > 0 && (
                    <AvisoDoPreco titulo="Salvo, com aviso:" data-avisos-do-preco="">
                        {estado.avisos.join(' ')}
                    </AvisoDoPreco>
                )}

                {estado.conflito && (
                    <div
                        role="alert"
                        className="flex flex-wrap items-center gap-2 rounded-xl border border-trilha-warn/40 bg-trilha-warn-bg px-[14px] py-2.5 text-[12.5px] text-trilha-warn"
                        data-conflito-do-preco=""
                    >
                        <span className="mr-auto flex min-w-0 flex-col gap-0.5">
                            <b className="font-semibold [overflow-wrap:anywhere]">{estado.conflito}</b>
                            {vista.mexido && <span>Recarregar descarta o preço digitado aqui.</span>}
                        </span>
                        <Botao ref={doRecarregar} data-acao="recarregar-previa" onClick={preco.recarregar}>
                            <RotateCw size={14} aria-hidden="true" />
                            Recarregar
                        </Botao>
                    </div>
                )}

                {estado.erroGeral && (
                    <span role="alert" className="text-[12.5px] font-semibold text-trilha-bad [overflow-wrap:anywhere]" data-erro-do-preco="">
                        {estado.erroGeral}
                    </span>
                )}

                <div className="flex flex-wrap items-center justify-end gap-2">
                    <span
                        ref={daNota}
                        tabIndex={-1}
                        className={cn('mr-auto min-w-0 self-center text-[12.5px] outline-none [overflow-wrap:anywhere]', rodape.classe)}
                        data-estado-do-preco={rodape.marca}
                    >
                        {rodape.texto}
                    </span>
                    <Botao
                        primario
                        type="submit"
                        ocupado={salvando}
                        // Nada a salvar (nada digitado, ou igual ao de agora) — ou o anúncio mudou: só o [Recarregar] serve.
                        disabled={!vista.mexido || !!estado.conflito}
                        data-acao="salvar-preco"
                    >
                        {textoDoBotaoDoPreco(estado)}
                    </Botao>
                </div>
            </form>
        </Caixa>
    );
}

/**
 * A caixa "Preço" da prévia. Sem `preco` (a vista desenhada sem o hook), sem a URL (quem não escreve, o servidor de
 * antes da 4c), com a prévia publicada ou depois de um 409 da fila: só leitura, como na 4a.
 *
 * @param {{previa: object, preco?: ?{editavel: boolean, estado: object, salvar: () => void, recarregar: () => void,
 *   digitar: (tipo: string, texto: string) => void, alternarVolta: (linha: object) => void, escolherCor: (cor: ?string) => void}}} props
 */
export default function CaixaDoPreco({ previa, preco = null }) {
    if (!preco?.editavel || preco.estado?.leitura) return <PrecoSoLeitura previa={previa} aviso={preco?.estado?.leitura ?? null} />;

    return <PrecoEditavel previa={previa} preco={preco} />;
}

/** As ações da confirmação da guarda: [Descartar] e [Continuar editando] (a que não perde nada, com o foco e o Esc). */
export const acoesDaConfirmacao = (preco) => [
    { rotulo: 'Descartar', acao: 'descartar-preco', perigo: true, onClick: preco.descartar },
    { rotulo: 'Continuar editando', acao: 'continuar-editando', foco: true, onClick: preco.continuar },
];

/**
 * A confirmação da guarda no RODAPÉ da gaveta (o `ConfirmacaoNaGaveta` do cadastro): "O preço digitado ainda não foi
 * salvo.", com o foco em [Continuar editando]; o Esc aqui é o mesmo que ele (o `preventDefault` avisa a gaveta).
 */
export function ConfirmacaoDoPreco({ preco }) {
    const focado = useRef(null);
    const acoes = acoesDaConfirmacao(preco);
    const doFoco = acoes.find((a) => a.foco);

    useEffect(() => {
        focado.current?.focus();
    }, []);

    return (
        <div
            className="flex w-full flex-wrap items-center justify-end gap-2"
            data-confirmacao=""
            onKeyDown={(evento) => {
                if (evento.key !== 'Escape') return;
                evento.preventDefault();
                doFoco.onClick();
            }}
        >
            <span role="alert" className="mr-auto min-w-0 self-center text-[12.5px] font-semibold text-trilha-warn">
                O preço digitado ainda não foi salvo.
            </span>
            {acoes.map((a) => (
                <Botao key={a.acao} ref={a === doFoco ? focado : undefined} perigo={!!a.perigo} data-acao={a.acao} onClick={a.onClick}>
                    {a.rotulo}
                </Botao>
            ))}
        </div>
    );
}

/**
 * O pedido de salvar e o estado que vem dele, sem React (o teste chama com o axios de mentira): o 200 traz a prévia
 * nova e o estado limpo com os avisos; o erro, o estado do erro (409 da revisão, 409 da fila, 422, o resto).
 *
 * @returns {Promise<{estado: object, previa: ?object}>}
 */
export async function enviarPreco(url, corpo, estado = ESTADO_DO_PRECO) {
    try {
        const previa = await salvarPreco(url, corpo);

        return { estado: estadoDepoisDeSalvar(estado, previa), previa };
    } catch (falha) {
        return { estado: estadoDoErro(estado, falha, mensagemDoErro(falha)), previa: null };
    }
}

/**
 * O estado da caixa "Preço" e as ações dela, para a gaveta `v:`: o que foi digitado, salvar, recarregar e a guarda de
 * "não salvo". `onPreviaNova` recebe a prévia da resposta (a gaveta inteira passa a mostrá-la); `onRecarregarPrevia`
 * pede a prévia de novo (o 409 da revisão).
 *
 * @param {{escrita: ?object, oferta: object, previa: ?object, onPreviaNova: (previa: object) => void,
 *   onRecarregarPrevia: () => void, onAvisar?: (texto: string, tipo?: string) => void, onRecarregar?: ?(() => void)}} opcoes
 */
export function usePrecoDaPrevia({ escrita, oferta, previa, onPreviaNova, onRecarregarPrevia, onAvisar = () => {}, onRecarregar = null }) {
    const url = useMemo(() => urlDoPreco(escrita, oferta?.id), [escrita, oferta?.id]);
    const editavel = podeEditarPreco(escrita, previa, oferta);
    const [estado, setEstado] = useState(ESTADO_DO_PRECO);
    const vivo = useRef(true);

    // O de agora, para as funções estáveis (a guarda) e para o salvar não ler um estado velho.
    const agora = useRef({});
    agora.current = { estado, previa, url, mexido: editavel && !estado.leitura && !estado.salvando && vistaDoPreco(previa, estado).mexido };

    useEffect(() => {
        vivo.current = true;

        return () => {
            vivo.current = false;
        };
    }, []);

    // ─── A guarda ───
    const [pedido, setPedido] = useState(null); // `{seguir}`: o que fazer se a pessoa descartar
    const guardiao = useMemo(() => ({
        mexido: () => !!agora.current.mexido,
        confirmar: (seguir) => setPedido({ seguir }),
    }), []);
    useGuardaDaGaveta(guardiao);
    const mexido = agora.current.mexido;
    useEffect(() => {
        if (!mexido || typeof window === 'undefined') return undefined;
        const aoSair = (evento) => {
            evento.preventDefault();
            evento.returnValue = '';
        };
        window.addEventListener('beforeunload', aoSair);

        return () => window.removeEventListener('beforeunload', aoSair);
    }, [mexido]);

    const limpar = useCallback(() => {
        setPedido(null);
        setEstado(ESTADO_DO_PRECO);
    }, []);

    const descartar = () => {
        const seguir = pedido?.seguir;
        setPedido(null);
        agora.current.mexido = false;
        setEstado((e) => ({ ...e, digitado: {}, tentou: false, erros: {}, erroGeral: null }));
        seguir?.();
    };

    const salvar = async () => {
        const { estado: atual, previa: daVez, url: endereco } = agora.current;
        if (atual.salvando) return;
        const conferido = { ...atual, tentou: true };
        const v = vistaDoPreco(daVez, conferido);
        if (!v.corpo) {
            // Inválido (os erros aparecem nos campos) ou nada a salvar.
            setEstado(conferido);

            return;
        }
        setEstado({ ...conferido, salvando: true, erroGeral: null, conflito: null, nota: null });
        const r = await enviarPreco(endereco, v.corpo, conferido);
        if (!vivo.current) return;
        setEstado(r.estado);
        if (r.previa) {
            onPreviaNova(r.previa);
            onAvisar(r.estado.nota, 'sucesso');
            // A linha da oferta (status, "a conferir") também muda; o painel aberto fica.
            onRecarregar?.();
        }
    };

    return {
        editavel,
        estado,
        pedido,
        salvar,
        limpar,
        descartar,
        continuar: () => setPedido(null),
        recarregar: () => {
            limpar();
            onRecarregarPrevia?.();
        },
        digitar: (tipo, texto) => setEstado((e) => digitarPreco(e, tipo, texto)),
        alternarVolta: (linha) => setEstado((e) => alternarVolta(e, linha)),
        escolherCor: (cor) => setEstado((e) => escolherCor(e, cor)),
    };
}
