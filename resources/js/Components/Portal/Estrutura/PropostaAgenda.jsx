import { useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { router } from '@inertiajs/react';
import Janela from './Janela';
import { Botao, PilulaSituacao, fmtData, fmtDiaSemana } from './comum';

// ─── Agendar o que falta ────────────────────────────────────────────────────
//
// "Pegue os buracos do mapeamento e agende … 1 publicação por dia até zerar a
// lista." Quem calcula as datas é o SERVIDOR (`EstruturaAgendaService::proposta`),
// em dias corridos, a partir do próximo dia livre. Desmarcar um item pede a
// proposta de novo, só com os marcados — as datas se recompactam, e a agenda
// não fica com buraco. Confirmar manda só QUAIS ofertas; as datas são
// recalculadas lá de novo.

export default function PropostaAgenda({ aberta, onFechar, vocabulario }) {
    const [itens, setItens] = useState(null);
    const [marcados, setMarcados] = useState(new Set());
    const [todos, setTodos] = useState([]);
    const [enviando, setEnviando] = useState(false);
    const pedido = useRef(0);

    const buscar = async (somente = null) => {
        const n = ++pedido.current;
        const { data } = await axios.post(route('portal.auth.estrutura.agenda.proposta'), somente ? { ofertas: somente } : {});
        if (n === pedido.current) setItens(data.itens);

        return data.itens;
    };

    useEffect(() => {
        if (! aberta) return;
        setItens(null);
        buscar().then((lista) => {
            setTodos(lista);
            setMarcados(new Set(lista.map((i) => i.oferta_id)));
        });
    }, [aberta]); // eslint-disable-line react-hooks/exhaustive-deps

    const alternar = (id) => {
        const novo = new Set(marcados);
        novo.has(id) ? novo.delete(id) : novo.add(id);
        setMarcados(novo);
        // Mantém a ordem da lista; as datas vêm recalculadas do servidor.
        const ids = todos.filter((t) => novo.has(t.oferta_id)).map((t) => t.oferta_id);
        if (ids.length) buscar(ids); else setItens([]);
    };

    const dataDe = (id) => itens?.find((i) => i.oferta_id === id)?.data;

    const aplicar = () => router.post(route('portal.auth.estrutura.agenda.proposta.aplicar'), { ofertas: [...marcados] }, {
        preserveScroll: true,
        onStart: () => setEnviando(true),
        onFinish: () => setEnviando(false),
        onSuccess: () => onFechar(true),
    });

    return (
        <Janela aberta={aberta} onFechar={() => onFechar(false)} largura="max-w-2xl" titulo="Agendar o que falta"
            descricao="Uma publicação por dia, em dias corridos, a partir do próximo dia livre, na ordem da sua lista. Ofertas que já têm publicação agendada ficam de fora.">
            {itens === null && <p className="text-[13px] text-white/40">Calculando…</p>}
            {itens !== null && todos.length === 0 && (
                <p className="text-[13px] text-white/55">Nenhum buraco sem agenda. Tudo o que falta publicar já tem data.</p>
            )}
            {todos.length > 0 && (
                <>
                    <ul className="max-h-[50vh] overflow-y-auto divide-y divide-white/[0.05]" data-proposta>
                        {todos.map((t) => {
                            const marcado = marcados.has(t.oferta_id);
                            const data = marcado ? dataDe(t.oferta_id) : null;

                            return (
                                <li key={t.oferta_id} className="flex items-center gap-3 py-2">
                                    <input type="checkbox" checked={marcado} onChange={() => alternar(t.oferta_id)} aria-label={`Agendar ${t.sku}`} />
                                    <span className="w-24 shrink-0 text-[12.5px] text-white/70">
                                        {data ? `${fmtDiaSemana(data)} ${fmtData(data)}` : '—'}
                                    </span>
                                    <span className="flex-1 min-w-0 truncate text-[13px]">
                                        <span className="font-mono text-white">{t.sku}</span>
                                        {t.nome && <span className="text-white/45"> · {t.nome}</span>}
                                    </span>
                                    <PilulaSituacao situacao={t.situacao} vocabulario={vocabulario} />
                                </li>
                            );
                        })}
                    </ul>
                    <div className="flex justify-end gap-2 pt-2">
                        <Botao variante="fantasma" onClick={() => onFechar(false)}>Cancelar</Botao>
                        <Botao variante="primario" onClick={aplicar} disabled={enviando || marcados.size === 0} data-acao="aplicar-proposta">
                            Agendar {marcados.size}
                        </Botao>
                    </div>
                </>
            )}
        </Janela>
    );
}
