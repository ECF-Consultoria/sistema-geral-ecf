import { useEffect, useState } from 'react';
import { Loader2, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { BASE_BOTAO, PRIMARIO, SECUNDARIO } from '@/Components/Publicador/Mesa/botoes';
import { fmtHora, numeroSeguro, previsaoDoLote, rodadasDoLote, textoSeguro } from './regrasDoLote.js';

// ─── "Agendar publicação" (10/10/2026) ──────────────────────────────────────
//
// Decisão do usuário (10/10): a fila anda em RODADAS — "sobe cinco de uma vez
// (Clássico e Premium), depois de uns 20 minutos mais cinco". Os dois números
// são ajustáveis; o intervalo protege a conta de restrição do Mercado Livre.
// A janela é opcional. Com avisos do ML na conferência, o "Estou ciente" é
// obrigatório (o mesmo do editor). Fila já andando: os produtos entram no FIM
// dela, e a rodada, o intervalo e a janela daqui passam a valer para ela.
// Confirmação no próprio diálogo, nunca `window.confirm` (aparece em branco
// numa tela dark e não explica nada).

const CAMPO = 'h-10 rounded-lg border border-white/[0.08] bg-white/[0.04] px-3 text-[13px] font-normal text-white placeholder:text-white/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow';
const ROTULO = 'mb-1 block text-[13px] font-normal text-white/70';

/**
 * @param {{aberto: boolean, produtos: number, anuncios: number, comAvisos: number, filaViva: boolean,
 *   porRodadaPadrao: number, porRodadaMaximo: number, teto: number,
 *   intervaloPadrao: number, intervaloMinimo: number, intervaloMaximo: number, enviando: boolean, erro: ?string,
 *   onFechar: Function, onConfirmar: (opcoes: object) => void, agora?: Date}} props
 */
export default function DialogoAgendar({
    aberto, produtos = 0, anuncios = 0, comAvisos = 0, filaViva = false,
    porRodadaPadrao = 5, porRodadaMaximo = 10, teto = 2,
    intervaloPadrao = 20, intervaloMinimo = 2, intervaloMaximo = 240, enviando = false, erro = null,
    porRodadaAtual = null, intervaloAtual = null, janelaAtual = null,
    onFechar, onConfirmar, agora = null,
}) {
    // Fila já andando: o diálogo abre com a rodada, o intervalo e o horário DELA (agendar de novo os reaplica à fila).
    const janelaDaFila = janelaAtual && typeof janelaAtual === 'object' && typeof janelaAtual.inicio === 'string' && typeof janelaAtual.fim === 'string' ? janelaAtual : null;
    const porRodadaInicial = String(numeroSeguro(porRodadaAtual) ?? porRodadaPadrao);
    const intervaloInicial = String(numeroSeguro(intervaloAtual) ?? intervaloPadrao);
    const [porRodada, setPorRodada] = useState(porRodadaInicial);
    const [intervalo, setIntervalo] = useState(intervaloInicial);
    const [comJanela, setComJanela] = useState(janelaDaFila !== null);
    const [inicio, setInicio] = useState(janelaDaFila?.inicio ?? '08:00');
    const [fim, setFim] = useState(janelaDaFila?.fim ?? '20:00');
    const [ciente, setCiente] = useState(false);

    useEffect(() => {
        if (aberto) {
            setPorRodada(porRodadaInicial);
            setIntervalo(intervaloInicial);
            setComJanela(janelaDaFila !== null);
            setInicio(janelaDaFila?.inicio ?? '08:00');
            setFim(janelaDaFila?.fim ?? '20:00');
            setCiente(false);
        }
    }, [aberto, porRodadaInicial, intervaloInicial, janelaDaFila?.inicio, janelaDaFila?.fim]); // eslint-disable-line react-hooks/exhaustive-deps

    if (! aberto) return null;

    const tamanho = Number(porRodada);
    const porRodadaValido = Number.isInteger(tamanho) && tamanho >= 1 && tamanho <= porRodadaMaximo;
    const minutos = Number(intervalo);
    const intervaloValido = Number.isInteger(minutos) && minutos >= intervaloMinimo && minutos <= intervaloMaximo;
    const janelaValida = ! comJanela || (/^\d{2}:\d{2}$/.test(inicio) && /^\d{2}:\d{2}$/.test(fim) && inicio !== fim);
    const precisaCiente = comAvisos > 0;
    const pode = produtos > 0 && porRodadaValido && intervaloValido && janelaValida && (! precisaCiente || ciente) && ! enviando;
    const rodadas = porRodadaValido ? rodadasDoLote(produtos, tamanho) : 0;
    const termina = intervaloValido && porRodadaValido ? previsaoDoLote(produtos, minutos, agora ?? new Date(), tamanho, teto) : null;

    function confirmar() {
        if (! pode) return;
        onConfirmar?.({
            produtos_por_rodada: tamanho,
            intervalo_minutos: minutos,
            janela_inicio: comJanela ? inicio : null,
            janela_fim: comJanela ? fim : null,
            ciente: precisaCiente ? ciente : false,
        });
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div className="absolute inset-0 bg-black/70" aria-hidden="true" onClick={() => ! enviando && onFechar?.()} />
            <div role="dialog" aria-modal="true" aria-labelledby="titulo-agendar" className="relative w-full max-w-[520px] rounded-xl border border-white/[0.10] bg-ecf-card p-5 shadow-2xl">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h2 id="titulo-agendar" className="text-[18px] font-bold text-white">Agendar publicação</h2>
                        <p className="mt-1 text-[13px] font-normal text-white/60">
                            {produtos === 1 ? '1 produto' : `${produtos} produtos`} · {anuncios === 1 ? '1 anúncio' : `${anuncios} anúncios`} (Clássico e Premium, todas as cores)
                        </p>
                    </div>
                    <button type="button" onClick={() => onFechar?.()} disabled={enviando} aria-label="Fechar" className="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-white/[0.10] text-white/70 hover:bg-white/[0.06] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow">
                        <X className="h-4 w-4" aria-hidden="true" />
                    </button>
                </div>

                <div className="mt-5 space-y-4">
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label htmlFor="por-rodada-lote" className={ROTULO}>Produtos por rodada</label>
                            <input
                                id="por-rodada-lote"
                                type="number"
                                inputMode="numeric"
                                min={1}
                                max={porRodadaMaximo}
                                value={porRodada}
                                onChange={(ev) => setPorRodada(ev.target.value)}
                                className={cn(CAMPO, 'w-24', ! porRodadaValido && 'border-red-400')}
                            />
                            <p className="mt-1 text-[11px] font-normal text-white/40">
                                Sobem juntos, cada um no Clássico e no Premium. Máximo de {porRodadaMaximo}.
                            </p>
                        </div>
                        <div>
                            <label htmlFor="intervalo-lote" className={ROTULO}>Intervalo entre as rodadas</label>
                            <div className="flex items-center gap-2">
                                <input
                                    id="intervalo-lote"
                                    type="number"
                                    inputMode="numeric"
                                    min={intervaloMinimo}
                                    max={intervaloMaximo}
                                    value={intervalo}
                                    onChange={(ev) => setIntervalo(ev.target.value)}
                                    className={cn(CAMPO, 'w-24', ! intervaloValido && 'border-red-400')}
                                />
                                <span className="text-[13px] font-normal text-white/60">minutos</span>
                            </div>
                            <p className="mt-1 text-[11px] font-normal text-white/40">
                                A próxima rodada só começa depois que a anterior termina. Mínimo de {intervaloMinimo} minutos.
                            </p>
                        </div>
                    </div>

                    <div>
                        <label className="flex items-center gap-2 text-[13px] font-normal text-white/80">
                            <input type="checkbox" checked={comJanela} onChange={(ev) => setComJanela(ev.target.checked)} className="h-4 w-4 accent-ecf-yellow" />
                            Publicar só dentro de um horário
                        </label>
                        {comJanela && (
                            <div className="mt-2 flex items-center gap-2 text-[13px] font-normal text-white/60">
                                entre
                                <input type="time" aria-label="Início do horário" value={inicio} onChange={(ev) => setInicio(ev.target.value)} className={cn(CAMPO, 'w-28')} />
                                e
                                <input type="time" aria-label="Fim do horário" value={fim} onChange={(ev) => setFim(ev.target.value)} className={cn(CAMPO, 'w-28')} />
                            </div>
                        )}
                    </div>

                    {precisaCiente && (
                        <label className="flex items-start gap-2 rounded-lg border border-amber-500/30 bg-amber-500/10 p-3 text-[13px] font-normal text-amber-200">
                            <input type="checkbox" checked={ciente} onChange={(ev) => setCiente(ev.target.checked)} className="mt-0.5 h-4 w-4 accent-ecf-yellow" />
                            <span>
                                Estou ciente dos avisos do Mercado Livre na conferência de {comAvisos === 1 ? '1 produto' : `${comAvisos} produtos`}.
                            </span>
                        </label>
                    )}

                    <p className="rounded-lg border border-white/[0.08] bg-white/[0.03] p-3 text-[13px] font-normal text-white/70">
                        {filaViva
                            ? 'A fila desta conta já existe: os produtos entram no fim dela, e a rodada, o intervalo e o horário daqui passam a valer para a fila.'
                            : `Começa no próximo minuto${rodadas > 1 ? `, em ${rodadas} rodadas` : ''}.`}
                        {! filaViva && termina && ! comJanela && <> Termina por volta de <span className="font-bold text-white">{fmtHora(termina)}</span>.</>}
                    </p>

                    {textoSeguro(erro, '') !== '' && (
                        <p className="rounded-lg border border-red-500/30 bg-red-500/[0.06] px-3 py-2 text-[13px] font-normal text-red-300">{textoSeguro(erro)}</p>
                    )}
                </div>

                <div className="mt-5 flex justify-end gap-2 border-t border-white/[0.08] pt-4">
                    <button type="button" onClick={() => onFechar?.()} disabled={enviando} className={cn(BASE_BOTAO, SECUNDARIO)}>Voltar</button>
                    <button type="button" onClick={confirmar} disabled={! pode} className={cn(BASE_BOTAO, PRIMARIO)}>
                        {enviando && <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />}
                        {produtos === 1 ? 'Agendar 1 produto' : `Agendar ${numeroSeguro(produtos) ?? 0} produtos`}
                    </button>
                </div>
            </div>
        </div>
    );
}
