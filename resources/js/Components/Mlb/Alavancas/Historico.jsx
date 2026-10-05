import { useEffect, useState } from 'react';
import axios from 'axios';
import { cn } from '@/lib/utils';
import { BASE_BOTAO, SECUNDARIO } from '@/Components/Publicador/Mesa/botoes';
import { SELECT } from '@/Components/Publicador/Mesa/comum';
import { mensagemDe } from '@/Components/Publicador/apoio';
import { rota, useLeitura } from './useAlavancas';
import { fmtData } from './formato';
import { ROTULO_ACAO, ROTULO_ALAVANCA, ROTULO_RESULTADO, ROTULO_TIPO } from './rotulos';

const PILULA = {
    OK: 'border-emerald-500/30 bg-emerald-500/10 text-emerald-400',
    ERRO: 'border-red-500/30 bg-red-500/10 text-red-300',
    INCERTO: 'border-amber-300/25 bg-amber-300/10 text-amber-300',
    PENDENTE: 'border-sky-500/25 bg-sky-500/10 text-sky-200',
    RECUSADA: 'border-white/[0.08] bg-white/[0.04] text-white/70',
};

const COLUNAS = ['Quando', 'Quem', 'Ação', 'Produto', 'Promoção', 'Resultado', 'Mensagem'];

function Pilula({ resultado }) {
    return (
        <span className={cn('inline-flex items-center whitespace-nowrap rounded-full border px-2 py-1 text-[11px] font-bold', PILULA[resultado] ?? PILULA.RECUSADA)}>
            {ROTULO_RESULTADO[resultado] ?? resultado}
        </span>
    );
}

const JSON_CRU = 'max-h-80 overflow-auto rounded-lg bg-black/40 p-3 text-[11px] font-normal text-white/70';

/** O que foi enviado e o que o Mercado Livre respondeu, em texto puro (nada de HTML injetado). */
function Detalhe({ conta, linha }) {
    const [estado, setEstado] = useState({ dados: null, erro: null, carregando: true });

    useEffect(() => {
        let vivo = true;
        axios.get(rota('historico.mostrar', conta, { escrita: linha.id }))
            .then((r) => { if (vivo) setEstado({ dados: r.data, erro: null, carregando: false }); })
            .catch((e) => { if (vivo) setEstado({ dados: null, erro: mensagemDe(e), carregando: false }); });

        return () => { vivo = false; };
    }, [conta, linha.id]);

    if (estado.carregando) return <p className="text-[13px] font-normal text-white/55">Carregando…</p>;
    if (estado.erro) return <p className="text-[13px] font-normal text-white/55">{estado.erro}</p>;

    return (
        <div className="grid gap-4 lg:grid-cols-2">
            <div>
                <p className="mb-1.5 text-[13px] font-bold text-white/90">O que foi enviado</p>
                <pre className={JSON_CRU}>{JSON.stringify(estado.dados.payload ?? null, null, 2)}</pre>
            </div>
            <div>
                <p className="mb-1.5 text-[13px] font-bold text-white/90">Resposta do Mercado Livre</p>
                <pre className={JSON_CRU}>{JSON.stringify(estado.dados.resposta ?? null, null, 2)}</pre>
            </div>
        </div>
    );
}

/** O histórico de escritas da empresa: filtrável, paginado, com payload e resposta crua por linha. */
export default function Historico({ conta }) {
    const [alavanca, setAlavanca] = useState('');
    const [resultado, setResultado] = useState('');
    const [pagina, setPagina] = useState(1);
    const [aberta, setAberta] = useState(null);

    const { dados, erro, carregando } = useLeitura('historico', conta, { alavanca, resultado, pagina });
    const linhas = dados?.linhas ?? [];
    const pag = dados?.paginacao ?? { pagina: 1, ultima: 1 };

    const filtrar = (definir) => (ev) => {
        definir(ev.target.value);
        setPagina(1);
        setAberta(null);
    };

    const alternar = (id) => setAberta((atual) => (atual === id ? null : id));

    return (
        <section className="rounded-xl bg-ecf-card p-4">
            <div className="mb-4 flex flex-wrap items-end gap-4">
                <label className="block w-56">
                    <span className="mb-1.5 block text-[13px] font-bold text-white/90">Alavanca</span>
                    <select value={alavanca} onChange={filtrar(setAlavanca)} className={SELECT}>
                        <option value="">Todas</option>
                        {Object.entries(ROTULO_ALAVANCA).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                    </select>
                </label>
                <label className="block w-56">
                    <span className="mb-1.5 block text-[13px] font-bold text-white/90">Resultado</span>
                    <select value={resultado} onChange={filtrar(setResultado)} className={SELECT}>
                        <option value="">Todos</option>
                        {Object.entries(ROTULO_RESULTADO).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                    </select>
                </label>
            </div>

            {erro && <p className="mb-3 text-[13px] font-normal text-white/55">{erro}</p>}

            {! erro && ! carregando && linhas.length === 0 ? (
                <p className="py-8 text-center text-[13px] font-normal text-white/55">Nenhuma alteração feita por aqui ainda.</p>
            ) : (
                <div className="overflow-x-auto">
                    <table className="w-full text-left text-[13px] font-normal text-white/70">
                        <thead>
                            <tr className="border-b border-white/[0.08] text-white/55">
                                {COLUNAS.map((c) => <th key={c} scope="col" className="px-3 py-2 font-bold">{c}</th>)}
                            </tr>
                        </thead>
                        <tbody>
                            {linhas.map((l) => (
                                <FragmentoDaLinha key={l.id} linha={l} conta={conta} aberta={aberta === l.id} aoAlternar={() => alternar(l.id)} />
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {carregando && <p className="mt-3 text-[13px] font-normal text-white/55">Carregando…</p>}

            <div className="mt-4 flex items-center justify-between gap-3">
                <button type="button" disabled={pagina <= 1} onClick={() => { setPagina(pagina - 1); setAberta(null); }} className={cn(BASE_BOTAO, SECUNDARIO)}>Anterior</button>
                <span className="text-[13px] font-normal text-white/55">{`Página ${pag.pagina} de ${Math.max(pag.ultima, 1)}`}</span>
                <button type="button" disabled={pagina >= pag.ultima} onClick={() => { setPagina(pagina + 1); setAberta(null); }} className={cn(BASE_BOTAO, SECUNDARIO)}>Próxima</button>
            </div>
        </section>
    );
}

function FragmentoDaLinha({ linha, conta, aberta, aoAlternar }) {
    return (
        <>
            <tr
                tabIndex={0}
                aria-expanded={aberta}
                onClick={aoAlternar}
                onKeyDown={(ev) => { if (ev.key === 'Enter') aoAlternar(); }}
                className="cursor-pointer border-b border-white/[0.06] hover:bg-white/[0.03] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow"
            >
                <td className="whitespace-nowrap px-3 py-3">{fmtData(linha.quando, { hora: true })}</td>
                <td className="px-3 py-3">{linha.ator_nome ?? '—'}</td>
                <td className="px-3 py-3">{ROTULO_ACAO[linha.acao] ?? linha.acao}</td>
                <td className="px-3 py-3 font-mono text-[11px]">{linha.item_id ?? '—'}</td>
                <td className="px-3 py-3">{linha.promotion_type ? (ROTULO_TIPO[linha.promotion_type] ?? linha.promotion_type) : '—'}</td>
                <td className="px-3 py-3"><Pilula resultado={linha.resultado} /></td>
                <td className="max-w-[320px] truncate px-3 py-3">{linha.mensagem ?? '—'}</td>
            </tr>
            {aberta && (
                <tr className="border-b border-white/[0.06]">
                    <td colSpan={COLUNAS.length} className="px-3 py-4"><Detalhe conta={conta} linha={linha} /></td>
                </tr>
            )}
        </>
    );
}
