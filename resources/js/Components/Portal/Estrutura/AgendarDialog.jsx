import { useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import Janela from './Janela';
import { Botao, CLASSE_INPUT, Campo, hojeIso } from './comum';

/** Agendar uma ação para UMA oferta. A proposta em lote é `PropostaAgenda`. */
export default function AgendarDialog({ aberta, onFechar, oferta, acaoInicial = 'publicacao', dataInicial = null, vocabulario }) {
    const [data, setData] = useState(hojeIso());
    const [acao, setAcao] = useState(acaoInicial);
    const [erros, setErros] = useState({});
    const [enviando, setEnviando] = useState(false);

    useEffect(() => {
        if (aberta) {
            setData(dataInicial ?? hojeIso()); setAcao(acaoInicial); setErros({});
        }
    }, [aberta]); // eslint-disable-line react-hooks/exhaustive-deps

    const enviar = () => router.post(route('portal.auth.estrutura.agenda.criar'), { oferta_id: oferta.id, data, acao }, {
        preserveScroll: true,
        preserveState: true,
        onStart: () => setEnviando(true),
        onFinish: () => setEnviando(false),
        onSuccess: () => onFechar(true),
        onError: setErros,
    });

    return (
        <Janela aberta={aberta} onFechar={() => onFechar(false)} titulo={`Agendar ${oferta?.sku ?? ''}`}
            descricao="Ritmo da aula: 1 publicação por dia. 7 dias depois, a Jardinagem (olhar métricas e ajustar o anúncio).">
            <div className="space-y-3">
                <Campo rotulo="Ação" erro={erros.acao}>
                    <div className="flex gap-2">
                        {Object.entries(vocabulario.acoes).map(([v, r]) => (
                            <button key={v} type="button" onClick={() => setAcao(v)}
                                className={`flex-1 rounded-xl border px-3 py-2 text-[13px] ${acao === v ? 'border-ecf-yellow/50 bg-ecf-yellow/10 text-ecf-yellow' : 'border-white/[0.10] text-white/60'}`}>
                                {r}
                            </button>
                        ))}
                    </div>
                </Campo>
                <Campo rotulo="Data" erro={erros.data}>
                    <input type="date" value={data} onChange={(e) => setData(e.target.value)} className={`${CLASSE_INPUT} [color-scheme:dark]`} />
                </Campo>
                <div className="flex justify-end gap-2 pt-1">
                    <Botao variante="fantasma" onClick={() => onFechar(false)}>Cancelar</Botao>
                    <Botao variante="primario" onClick={enviar} disabled={enviando || ! data}>Agendar</Botao>
                </div>
            </div>
        </Janela>
    );
}
