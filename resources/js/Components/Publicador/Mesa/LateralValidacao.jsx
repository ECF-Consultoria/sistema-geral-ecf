import { CheckCircle2, Loader2 } from 'lucide-react';
import AvisoContaTravada from '@/Components/Mlb/Publicador/AvisoContaTravada';
import Problemas from '../Problemas';
import { CARD_DA_SECAO, SECOES, secaoDoProblema } from '../apoio';
import { cn } from '@/lib/utils';

// ─── Lateral: Validação no Mercado Livre (UI-SPEC §8.5) ─────────────────────
//
// As 8 verificações levam ao card correspondente. Estado calmo: pendência é
// ponto âmbar, nunca alarme. D26: em conta não liberada a conferência é só
// local — o resultado aparece como local (Lock, cinza), sem âmbar de alerta,
// sem vermelho e nunca como "o Mercado Livre apontou".

const secaoDaEtapa = (etapa) => SECOES.find((s) => s.etapas.includes(etapa))?.chave ?? null;

/** Primeira pendência (BLOCKER) do servidor para a nota "Falta pouco". */
const primeiraPendencia = (pub) => {
    const locais = pub.m.estado?.problemas ?? [];
    const conf = pub.m.estado?.conferencia;
    const doMl = conf?.vale && ! conf.local ? (conf.issues ?? []) : [];

    return [...locais, ...doMl].find((p) => p.severidade === 'BLOCKER')?.mensagem ?? null;
};

export default function LateralValidacao({ pub, onIrPara }) {
    const total = pub.totalSecoes;
    const pct = total > 0 ? Math.round((pub.prontas / total) * 100) : 0;
    const tudoPronto = pub.prontas === total;

    const local = pub.conferencia.local;
    const estadoConf = pub.conferencia.estado;
    const conferindo = estadoConf === 'conferindo';
    // WR-F07: tudo o que a conferência do ML apontou — inclusive o bloqueio da ficha (camada L2)
    // achado nela, que antes não era listado e virava "o Mercado Livre apontou 0 pendência(s)".
    const bloqueiosConf = pub.conferencia.bloqueios ?? [];
    const avisosConf = pub.conferencia.listaDeAvisos ?? [];
    const pendenciasLocais = local && estadoConf === 'local_bloqueado'
        ? (pub.m.estado?.problemas ?? []).filter((p) => p.severidade === 'BLOCKER')
        : [];

    const irPorEtapa = (etapa) => {
        const secao = secaoDaEtapa(etapa);
        if (secao) onIrPara(CARD_DA_SECAO[secao]);
    };

    return (
        <section aria-labelledby="titulo-validacao" className="rounded-xl border border-white/[0.08] bg-ecf-card p-6" data-lateral="validacao">
            <div className="flex items-start justify-between gap-3">
                <h2 id="titulo-validacao" className="text-[15px] font-bold text-white">Validação no Mercado Livre</h2>
                <span className="shrink-0 rounded-full bg-white/[0.04] px-3 py-1 text-[11px] font-bold text-white/70">{pub.prontas} de {total} prontos</span>
            </div>

            <div className="mt-4">
                <div className="mb-1 flex items-center justify-between text-[11px] font-normal text-white/55">
                    <span>Prontidão de envio</span>
                    <span className="font-mono">{pct}%</span>
                </div>
                <div role="progressbar" aria-valuemin={0} aria-valuemax={100} aria-valuenow={pct} aria-label="Prontidão de envio" className="h-1 overflow-hidden rounded-full bg-white/[0.08]">
                    <div className="h-full rounded-full bg-ecf-yellow transition-[width]" style={{ width: `${pct}%` }} />
                </div>
            </div>

            <ul className="mt-4">
                {SECOES.map((s) => {
                    const ok = pub.secoes[s.chave]?.completo === true;

                    return (
                        <li key={s.chave}>
                            <button
                                type="button"
                                onClick={() => onIrPara(CARD_DA_SECAO[s.chave])}
                                className="flex h-10 w-full items-center gap-3 rounded-lg px-2 text-left text-[13px] font-normal text-white/80 hover:bg-white/[0.04] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow"
                                data-verificacao={s.chave}
                                data-pronta={ok ? 'sim' : 'nao'}
                            >
                                {ok
                                    ? <CheckCircle2 size={16} className="shrink-0 text-emerald-400" aria-label="Pronto" />
                                    : <span className="grid h-4 w-4 shrink-0 place-items-center" aria-label="Falta"><span className="h-1.5 w-1.5 rounded-full bg-amber-400" /></span>}
                                <span className="min-w-0 flex-1 truncate">{s.titulo}</span>
                            </button>
                        </li>
                    );
                })}
            </ul>

            <p className="mt-4 flex items-start gap-2 rounded-lg border border-white/[0.08] bg-white/[0.03] p-3 text-[13px] font-normal text-white/70" data-nota-prontidao>
                {! tudoPronto && <span className="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-amber-400" aria-hidden="true" />}
                <span>
                    {tudoPronto
                        ? 'Tudo pronto. Pode conferir no Mercado Livre.'
                        : <><span className="font-bold text-white">Falta pouco.</span> {primeiraPendencia(pub) ?? 'Complete os itens acima.'}</>}
                </span>
            </p>

            {/* Linha da conferência: local (D26) em tom calmo, ou a do Mercado Livre. */}
            <div className="mt-4" aria-live="polite" data-conferencia={estadoConf}>
                {local ? (
                    <AvisoContaTravada variante="linha">{pub.conferencia.texto}</AvisoContaTravada>
                ) : (
                    <p className={cn('flex items-start gap-2 text-[13px] font-normal',
                        estadoConf === 'ok' ? 'text-emerald-400' : 'text-white/70')}>
                        {conferindo && <Loader2 size={14} className="mt-1 shrink-0 animate-spin" aria-hidden="true" />}
                        <span>{pub.conferencia.texto}</span>
                    </p>
                )}
            </div>

            {pendenciasLocais.length > 0 && (
                <ul className="mt-3 space-y-2" data-pendencias-locais>
                    {pendenciasLocais.map((p, i) => {
                        const secao = secaoDoProblema(p);

                        return (
                            <li key={`${p.regra}-${i}`} className="flex items-start gap-2 text-[13px] font-normal text-white/70">
                                <span className="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-amber-400" aria-hidden="true" />
                                <span className="min-w-0 flex-1">{p.mensagem}</span>
                                {secao && (
                                    <button type="button" onClick={() => onIrPara(CARD_DA_SECAO[secao])} className="shrink-0 text-[13px] font-normal text-white/70 underline-offset-2 hover:text-ecf-yellow hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow">
                                        Ir para {SECOES.find((s) => s.chave === secao)?.titulo}
                                    </button>
                                )}
                            </li>
                        );
                    })}
                </ul>
            )}

            {bloqueiosConf.length > 0 && (
                <div className="mt-3" data-pendencias-conferencia><Problemas problemas={bloqueiosConf} onIr={irPorEtapa} /></div>
            )}

            {! local && avisosConf.length > 0 && (
                <div className="mt-3 space-y-3">
                    <Problemas problemas={avisosConf} onIr={irPorEtapa} />
                    {estadoConf === 'avisos' && (
                        <label className="flex items-start gap-2 text-[13px] font-normal text-white/70">
                            <input
                                type="checkbox"
                                checked={pub.ciente}
                                onChange={(e) => pub.setCiente(e.target.checked)}
                                className="mt-1 rounded border-white/20 bg-transparent text-ecf-yellow focus-visible:ring-2 focus-visible:ring-ecf-yellow"
                                data-ciente
                            />
                            Li os avisos da conferência e quero publicar assim mesmo.
                        </label>
                    )}
                </div>
            )}
        </section>
    );
}
