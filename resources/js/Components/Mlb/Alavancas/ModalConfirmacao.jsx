import { useEffect, useRef, useState } from 'react';
import { Loader2 } from 'lucide-react';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/Components/ui/dialog';
import { BotaoAcao } from '@/Components/Publicador/Mesa/botoes';
import { mensagemDe } from '@/Components/Publicador/apoio';
import AvisoAlavancasTravadas from './AvisoAlavancasTravadas';
import { confirmar, previa, useLote } from './useAlavancas';
import { ROTULO_RESULTADO } from './rotulos';
import { fmtBRL, fmtData, fmtPct } from './formato';

const TEXTO_INCERTO = 'Sem confirmação do Mercado Livre: confira o estado antes de tentar de novo.';

/** Texto do resultado de uma escrita única, conforme o que o servidor gravou. */
function textoDoResultado(escrita) {
    if (escrita?.resultado === 'OK') return 'Feito.';
    if (escrita?.resultado === 'INCERTO') return TEXTO_INCERTO;

    return escrita?.mensagem ?? 'Não foi possível concluir. Tente de novo.';
}

/**
 * "recebe R$ N no preço normal → R$ P na promoção", com o aviso de frete ou de carrinho.
 * Contrato da prévia (166-11): `normal` e `promocao` já são o valor (número), e o frete vem em
 * `frete_conhecido` no próprio `recebe`.
 */
function LinhaRecebe({ recebe }) {
    if (! recebe) return null;
    const temValor = (v) => v !== null && v !== undefined;
    const normal = recebe.normal;
    const promocao = recebe.promocao;
    const semFrete = recebe.frete_conhecido === false ? ' (sem frete)' : '';

    return (
        <div className="space-y-1">
            {temValor(normal) && (
                <p>
                    A loja recebe {fmtBRL(normal)}{semFrete} no preço normal
                    {temValor(promocao) && <> → {fmtBRL(promocao)}{semFrete} na promoção</>}
                    {! temValor(promocao) && recebe.depende_do_carrinho && <> · o desconto depende do carrinho</>}
                </p>
            )}
            {recebe.margem && <p>Margem: {fmtBRL(recebe.margem.valor)} ({fmtPct(recebe.margem.percentual)})</p>}
            {(recebe.alertas ?? []).map((a) => (
                <p key={a.codigo ?? a.texto} className="text-amber-300">{a.texto}</p>
            ))}
        </div>
    );
}

/** Um produto do resumo da prévia. */
function ItemDoResumo({ item }) {
    const recebe = item.recebe;

    return (
        <li className="space-y-1 rounded-lg border border-white/[0.08] bg-white/[0.03] p-3 text-[13px] font-normal text-white/70">
            <p className="font-bold text-white/90">{item.titulo ?? item.item_id} <span className="font-normal text-white/55">{item.item_id}</span></p>
            <p>{item.acao_rotulo}</p>
            {(item.preco_atual !== null && item.preco_atual !== undefined) && (
                <p>
                    Preço atual {fmtBRL(item.preco_atual)}
                    {(item.preco_promocao !== null && item.preco_promocao !== undefined) && <> → preço na promoção {fmtBRL(item.preco_promocao)}</>}
                    {(item.desconto_percentual !== null && item.desconto_percentual !== undefined) && (
                        <> ({fmtPct(item.desconto_percentual)} de desconto{item.preco_original ? <> sobre o preço original {fmtBRL(item.preco_original)}</> : null})</>
                    )}
                </p>
            )}
            {item.prazo && (item.prazo.inicio || item.prazo.fim) && (
                <p>Prazo: {fmtData(item.prazo.inicio)} até {fmtData(item.prazo.fim)}</p>
            )}
            {(item.ml_banca !== null && item.ml_banca !== undefined) && <p>O ML banca {fmtBRL(item.ml_banca)} (estimativa)</p>}
            {(recebe?.desconto_extra_ml !== null && recebe?.desconto_extra_ml !== undefined) && (
                <p>Desconto extra do ML {fmtBRL(recebe.desconto_extra_ml)} (estimativa)</p>
            )}
            <LinhaRecebe recebe={recebe} />
            {(item.linhas ?? []).map((l) => <p key={l.rotulo}>{l.rotulo}: {l.valor}</p>)}
            {(item.avisos ?? []).map((a) => <p key={a} className="text-amber-300">{a}</p>)}
        </li>
    );
}

/** Contagem do lote por resultado e a lista por produto — sem contador de progresso. */
function AndamentoDoLote({ lote }) {
    const contagem = Object.entries(lote.por_resultado ?? {}).filter(([, n]) => n > 0);

    return (
        <div className="space-y-2 text-[13px] font-normal text-white/70">
            <p className="font-bold text-white/90">
                {contagem.map(([chave, n]) => `${ROTULO_RESULTADO[chave] ?? chave}: ${n}`).join(' · ')}
            </p>
            <ul className="max-h-48 space-y-1 overflow-y-auto">
                {(lote.itens ?? []).map((i) => (
                    <li key={i.item_id}>
                        {i.item_id}: {ROTULO_RESULTADO[i.resultado] ?? i.resultado}
                        {i.mensagem && i.resultado !== 'OK' ? ` — ${i.mensagem}` : ''}
                    </li>
                ))}
            </ul>
        </div>
    );
}

/**
 * A janela que TODA escrita das Alavancas usa: prévia → confirmar → resultado ou lote.
 *
 * Props: `aberto`, `onFechar`, `conta` (chave da conta), `acao` (nome do RegistroDeAcoes, ex.
 * 'convite.inscrever'), `itens` (o que o servidor pede para a ação), `titulo` e `onConcluido(resultado)`
 * (a tela relê a lista; `resultado` é a escrita única ou o lote). O botão Confirmar só liga com a
 * assinatura do servidor e conta liberada.
 */
export default function ModalConfirmacao({ aberto, onFechar, conta, acao, itens, titulo = 'Confirmar alteração', onConcluido }) {
    const [fase, setFase] = useState('carregando');
    const [dados, setDados] = useState(null);
    const [erro, setErro] = useState(null);
    const [precisaRefazer, setPrecisaRefazer] = useState(false);
    const [resultado, setResultado] = useState(null);
    const [loteId, setLoteId] = useState(null);
    const [tentativa, setTentativa] = useState(0);
    const concluido = useRef(false);

    const lote = useLote(conta, loteId);

    function concluir(valor) {
        if (concluido.current) return;
        concluido.current = true;
        onConcluido?.(valor);
    }

    useEffect(() => {
        if (! aberto) return undefined;
        let vivo = true;
        setFase('carregando');
        setDados(null);
        setErro(null);
        setPrecisaRefazer(false);
        setResultado(null);
        setLoteId(null);
        concluido.current = false;

        previa(conta, acao, itens)
            .then((r) => { if (vivo) { setDados(r.data); setFase('previa'); } })
            .catch((e) => { if (vivo) { setErro(mensagemDe(e)); setFase('previa'); } });

        return () => { vivo = false; };
    // A prévia é refeita ao abrir e no "Conferir de novo"; `itens` e `acao` não mudam com a janela aberta.
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [aberto, tentativa]);

    useEffect(() => {
        if (loteId && lote.dados?.terminado) concluir(lote.dados);
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [loteId, lote.dados?.terminado]);

    async function enviar() {
        setFase('enviando');
        setErro(null);
        try {
            const r = await confirmar(conta, acao, itens, dados?.assinatura);
            if (r.status === 202) {
                setLoteId(r.data.lote);
                setFase('lote');

                return;
            }
            setResultado(r.data.escrita);
            setFase('resultado');
            concluir(r.data.escrita);
        } catch (e) {
            const d = e.response?.data;
            setErro(mensagemDe(e));
            setFase('previa');
            if (e.response?.status === 409 && d?.regra === 'ALAV-ASSIN-USADA') {
                // A confirmação já foi usada: nunca reenvia, só relê a tela.
                setFase('resultado');
                concluir(null);
            } else if (d?.regra === 'ALAV-ASSIN') {
                setPrecisaRefazer(true);
            }
        }
    }

    const liberada = dados?.liberada !== false;
    const podeConfirmar = fase === 'previa' && ! erro && Boolean(dados?.assinatura) && liberada;
    const resumo = dados?.resumo;
    const encerrado = fase === 'resultado' || (fase === 'lote' && lote.dados?.terminado);

    return (
        <Dialog open={aberto} onOpenChange={(v) => { if (! v && fase !== 'enviando') onFechar?.(); }}>
            <DialogContent className="max-w-2xl rounded-2xl border-white/[0.08] bg-ecf-card p-6 shadow-none">
                <DialogTitle className="text-[15px] font-bold text-white">{titulo}</DialogTitle>
                <DialogDescription className="text-[13px] font-normal text-white/55">
                    Confira o resumo. Nada é enviado ao Mercado Livre antes de você confirmar.
                </DialogDescription>

                {fase === 'carregando' && (
                    <p className="flex items-center gap-2 text-[13px] font-normal text-white/70">
                        <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />
                        Conferindo com o Mercado Livre…
                    </p>
                )}

                {resumo && (fase === 'previa' || fase === 'enviando') && (
                    <div className="space-y-3">
                        <p className="text-[13px] font-normal text-white/70">
                            Conta: <span className="font-bold text-white/90">{resumo.conta?.nome}</span>
                            {resumo.conta?.nickname ? ` (${resumo.conta.nickname})` : ''}
                        </p>
                        <ul className="max-h-80 space-y-2 overflow-y-auto">
                            {(resumo.itens ?? []).map((i) => <ItemDoResumo key={i.item_id} item={i} />)}
                        </ul>
                        {(resumo.avisos ?? []).map((a) => <p key={a} className="text-[13px] font-normal text-amber-300">{a}</p>)}
                        {dados.parcial && (
                            <p className="text-[13px] font-normal text-white/55">Parte dos números não foi calculada agora; a escrita não depende deles.</p>
                        )}
                    </div>
                )}

                {! liberada && fase === 'previa' && (
                    <AvisoAlavancasTravadas variante="linha">{dados?.motivo ?? 'Alavancas ainda não liberadas para esta conta.'}</AvisoAlavancasTravadas>
                )}

                {erro && fase !== 'resultado' && <p className="text-[13px] font-normal text-white/70">{erro}</p>}

                {fase === 'resultado' && (
                    <p className="text-[13px] font-bold text-white/90">{resultado ? textoDoResultado(resultado) : 'Esta confirmação já foi usada. A tela foi atualizada.'}</p>
                )}

                {fase === 'lote' && (
                    <div className="space-y-2">
                        {lote.dados ? <AndamentoDoLote lote={lote.dados} /> : (
                            <p className="flex items-center gap-2 text-[13px] font-normal text-white/70">
                                <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />
                                Enviando ao Mercado Livre…
                            </p>
                        )}
                        {lote.erro && <p className="text-[13px] font-normal text-white/55">{lote.erro}</p>}
                        {lote.esgotou && (
                            <p className="text-[13px] font-normal text-white/55">O envio continua em segundo plano. Consulte o histórico para ver o que já foi feito.</p>
                        )}
                    </div>
                )}

                <div className="flex justify-end gap-2">
                    <BotaoAcao onClick={onFechar} disabled={fase === 'enviando'}>{encerrado ? 'Fechar' : 'Cancelar'}</BotaoAcao>
                    {precisaRefazer && (
                        <BotaoAcao onClick={() => setTentativa((n) => n + 1)}>Conferir de novo</BotaoAcao>
                    )}
                    {! encerrado && fase !== 'lote' && (
                        <BotaoAcao primario disabled={! podeConfirmar} onClick={enviar}>
                            {fase === 'enviando' && <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />}
                            Confirmar
                        </BotaoAcao>
                    )}
                </div>
            </DialogContent>
        </Dialog>
    );
}
