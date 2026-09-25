import { useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { router } from '@inertiajs/react';
import { Link2, Search } from 'lucide-react';
import { CLASSE_INPUT } from './comum';
import { cn } from '@/lib/utils';

// ─── Procurar nos anúncios do Mercado Livre ─────────────────────────────────
//
// A exceção do casamento por SKU: o anúncio existe no ML, mas o SKU dele é
// diferente do da oferta (ou nem tem SKU). Procura-se pelo título ou pelo MLB
// nos anúncios da empresa (o acervo, sincronizado todo dia) e liga-se com um
// clique. Quem monta o anúncio é o servidor, a partir do registro do acervo —
// o navegador só diz QUAL.
//
// Anúncio já ligado a outra oferta aparece, mas não se liga daqui: mudar de
// oferta é editar, não ligar.

export default function BuscaAnunciosMl({ oferta, tipo = null, onLigado }) {
    const [busca, setBusca] = useState('');
    const [resultado, setResultado] = useState(null);
    const [erro, setErro] = useState(null);
    const [ligando, setLigando] = useState(null);
    const pedido = useRef(0);

    useEffect(() => {
        const n = ++pedido.current;
        const t = setTimeout(async () => {
            try {
                const { data } = await axios.get(route('portal.auth.estrutura.anuncios_ml.buscar'), { params: { q: busca, tipo: tipo ?? undefined } });
                if (n === pedido.current) setResultado(data);
            } catch {
                if (n === pedido.current) setResultado({ total_acervo: 0, itens: [] });
            }
        }, 300);

        return () => clearTimeout(t);
    }, [busca, tipo]);

    const ligar = (item) => router.post(route('portal.auth.estrutura.anuncios_ml.ligar', oferta.id), { ml_item_id: item.mlb }, {
        preserveScroll: true,
        preserveState: true,
        onStart: () => { setLigando(item.mlb); setErro(null); },
        onFinish: () => setLigando(null),
        onSuccess: () => onLigado(),
        onError: (e) => setErro(e.ml_item_id ?? 'Não foi possível ligar este anúncio.'),
    });

    const semAcervo = resultado && resultado.total_acervo === 0;

    return (
        <div className="space-y-2" data-busca-ml>
            <p className="text-[12.5px] font-medium text-white/70">Procurar nos seus anúncios do Mercado Livre</p>
            {semAcervo ? (
                <p className="text-[12px] text-white/40">
                    Seus anúncios do Mercado Livre aparecem aqui depois da sincronização diária (precisa da conta conectada). Enquanto isso, informe o código abaixo.
                </p>
            ) : (
                <>
                    <div className="relative">
                        <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-white/30" />
                        <input value={busca} onChange={(e) => setBusca(e.target.value)} placeholder="Título ou código MLB"
                            className={cn(CLASSE_INPUT, 'pl-8')} data-campo="busca-ml" />
                    </div>
                    <ul className="max-h-60 space-y-1 overflow-y-auto">
                        {resultado?.itens.map((item) => {
                            const bloqueado = !! item.ligado_a;

                            return (
                                <li key={item.mlb} className="flex items-center gap-2 rounded-lg border border-white/[0.06] px-2 py-1.5" data-anuncio-ml={item.mlb}>
                                    {item.thumbnail
                                        ? <img src={item.thumbnail} alt="" className="h-9 w-9 shrink-0 rounded object-cover" loading="lazy" />
                                        : <span className="h-9 w-9 shrink-0 rounded bg-white/[0.05]" />}
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-[12.5px] text-white/85" title={item.titulo}>{item.titulo}</p>
                                        <p className="text-[11px] text-white/40">
                                            <span className="font-mono">{item.mlb}</span> · {item.tipo} · {item.status}
                                            {item.catalogo && ' · catálogo'}
                                            {item.ligado_a && <span className="text-sky-300"> · já na oferta {item.ligado_a}</span>}
                                            {item.na_espera && <span className="text-amber-300"> · aguardando oferta</span>}
                                        </p>
                                    </div>
                                    <button type="button" disabled={bloqueado || ligando !== null} onClick={() => ligar(item)} data-acao="ligar-ml"
                                        className="inline-flex shrink-0 items-center gap-1 rounded-lg px-2 py-1 text-[12px] font-medium text-ecf-yellow hover:bg-ecf-yellow/10 disabled:text-white/25 disabled:hover:bg-transparent">
                                        <Link2 size={13} /> {ligando === item.mlb ? 'Ligando…' : 'Ligar'}
                                    </button>
                                </li>
                            );
                        })}
                        {resultado && resultado.itens.length === 0 && (
                            <li className="text-[12px] text-white/40">Nenhum anúncio encontrado com essa busca.</li>
                        )}
                    </ul>
                </>
            )}
            {erro && <p className="text-[12px] text-red-400">{erro}</p>}
        </div>
    );
}
