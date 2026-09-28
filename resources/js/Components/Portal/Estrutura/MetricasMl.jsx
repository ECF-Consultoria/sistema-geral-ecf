import { useEffect, useState } from 'react';
import axios from 'axios';
import { BarChart3, Loader2 } from 'lucide-react';
import { LinkMl, fmtReais } from './comum';
import { cn } from '@/lib/utils';

// ─── Os últimos 7 dias de cada anúncio no Mercado Livre ─────────────────────
//
// A regra de ouro da aula: "7 dias depois de publicar, olhe as métricas e
// ajuste" — a Jardinagem. Visitas, vendas e conversão de cada anúncio da
// oferta, e o buy box do que está no catálogo. Lido no ML na hora (o acervo
// não tem esses números), sob demanda: são até 3 chamadas por anúncio, então
// só carrega quando a pessoa pede (ou quando chega pelo "Ver métricas" da
// agenda). O servidor guarda 30 minutos.

const COR_BUYBOX = {
    winning: 'text-emerald-300',
    sharing_first_place: 'text-emerald-300',
    competing: 'text-amber-300',
    losing: 'text-red-300',
    listed: 'text-white/50',
};

const n = (x) => (x === null || x === undefined ? '—' : x.toLocaleString('pt-BR'));

function conversao(visitas, vendas) {
    if (! visitas || vendas === null || vendas === undefined) return '—';

    return `${((vendas / visitas) * 100).toLocaleString('pt-BR', { maximumFractionDigits: 1 })}%`;
}

export default function MetricasMl({ ofertaId, anuncios, vocabulario, auto = false, compacto = false }) {
    const [estado, setEstado] = useState(null);   // null | 'lendo' | { metricas, limitado, conectado } | { erro }

    const carregar = async () => {
        setEstado('lendo');
        try {
            const { data } = await axios.get(route('portal.auth.estrutura.anuncios_ml.metricas', ofertaId));
            setEstado(data);
        } catch {
            setEstado({ erro: 'Não foi possível ler as métricas no Mercado Livre agora.' });
        }
    };

    useEffect(() => {
        setEstado(null);
        if (auto) carregar();
    }, [ofertaId, auto]); // eslint-disable-line react-hooks/exhaustive-deps

    const comMlb = anuncios.filter((a) => a.codigo_mlb);
    if (comMlb.length === 0) return null;

    if (estado === null) {
        return (
            <button type="button" onClick={carregar} data-acao="ver-metricas"
                className="inline-flex items-center gap-1.5 rounded-lg border border-white/[0.10] px-2.5 py-1.5 text-[12px] text-white/70 hover:border-ecf-yellow/40 hover:text-white">
                <BarChart3 size={14} /> Ver visitas e vendas dos últimos 7 dias
            </button>
        );
    }

    if (estado === 'lendo') {
        return <p className="flex items-center gap-2 text-[12px] text-white/45"><Loader2 size={13} className="animate-spin" /> Lendo as métricas no Mercado Livre…</p>;
    }

    if (estado.erro) return <p className="text-[12px] text-red-300/80">{estado.erro}</p>;
    if (! estado.conectado) return <p className="text-[12px] text-white/40">Conecte a conta do Mercado Livre para ver as métricas.</p>;

    const linhas = comMlb.map((a) => ({ ...a, m: estado.metricas[a.codigo_mlb] })).filter((l) => l.m);
    const soma = (campo) => linhas.reduce((t, l) => t + (l.m[campo] ?? 0), 0);

    return (
        <div className={cn('rounded-xl border border-white/[0.08] bg-white/[0.02]', compacto ? 'p-2' : 'p-3')} data-metricas-ml>
            <p className="mb-1.5 text-[11px] uppercase tracking-wide text-white/40">Últimos 7 dias no Mercado Livre</p>
            <table className="w-full text-[12px]">
                <thead className="text-white/40">
                    <tr>
                        <th className="py-1 text-left font-normal">Anúncio</th>
                        <th className="py-1 text-right font-normal">Visitas</th>
                        <th className="py-1 text-right font-normal">Vendas</th>
                        <th className="py-1 text-right font-normal">Conversão</th>
                    </tr>
                </thead>
                <tbody className="text-white/80">
                    {linhas.map((l) => (
                        <tr key={l.codigo_mlb} className="border-t border-white/[0.05] align-top" data-metrica={l.codigo_mlb}>
                            <td className="py-1.5 pr-2">
                                <span className="font-semibold">{vocabulario.tipos[l.tipo]}</span>{' '}
                                <LinkMl mlb={l.codigo_mlb} className="text-white/45" />
                                {l.m.buybox && (
                                    <span className={cn('block text-[11px]', COR_BUYBOX[l.m.buybox.status] ?? 'text-white/50')} data-buybox={l.m.buybox.status}>
                                        Catálogo: {l.m.buybox.rotulo}
                                        {l.m.buybox.preco_para_ganhar && l.m.buybox.status !== 'winning' && ` · para ganhar: ${fmtReais(l.m.buybox.preco_para_ganhar)}`}
                                    </span>
                                )}
                            </td>
                            <td className="py-1.5 text-right">{n(l.m.visitas)}</td>
                            <td className="py-1.5 text-right">{n(l.m.vendas)}</td>
                            <td className="py-1.5 text-right">{conversao(l.m.visitas, l.m.vendas)}</td>
                        </tr>
                    ))}
                </tbody>
                {linhas.length > 1 && (
                    <tfoot className="border-t border-white/[0.10] font-semibold text-white">
                        <tr>
                            <td className="py-1.5">Total</td>
                            <td className="py-1.5 text-right">{n(soma('visitas'))}</td>
                            <td className="py-1.5 text-right">{n(soma('vendas'))}</td>
                            <td className="py-1.5 text-right">{conversao(soma('visitas'), soma('vendas'))}</td>
                        </tr>
                    </tfoot>
                )}
            </table>
            {estado.limitado && <p className="mt-1 text-[11px] text-white/35">Mostrando os 30 primeiros anúncios desta oferta.</p>}
        </div>
    );
}
