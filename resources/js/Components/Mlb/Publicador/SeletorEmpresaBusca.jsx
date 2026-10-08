import { useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { Search } from 'lucide-react';
import SeloConta from './SeloConta';
import { textoSeguro } from './BarraDaConta';

// ═══════════════════════════════════════════════════════════════════════════
// Conteúdo do popover "Trocar empresa" (Fase 173, plano 03). O Trigger/Root
// do Popover mora em `BarraDaConta.jsx` — este componente só sabe buscar e
// decidir o destino, sem saber que está dentro de um popover.
//
// Decisão de design (critério "Trocar empresa mantém a aba atual"): lê a
// rota ATIVA por `route().current()` (Ziggy, mesmo padrão de
// `AuthenticatedLayout.jsx`) e monta o destino equivalente na conta
// escolhida — sem precisar de prop nova em Produtos.jsx, Alavancas.jsx,
// MeusAnuncios.jsx, AnunciosHistorico.jsx ou AnunciarMassa.jsx.
// ═══════════════════════════════════════════════════════════════════════════

const ESPERA_MS = 300;

/**
 * Mapa de rota ATIVA → rota EQUIVALENTE na conta nova. `item` é o shape de
 * `GET mlb.anuncios.publicador.empresas-busca?q=`: {chave, nome,
 * identificador, company_id, programa, programa_rotulo, token}.
 *
 * Export NOMEADO: testável direto, sem precisar simular o popover inteiro.
 */
export function destinoParaItem(item) {
    const rotaAtual = route().current();
    const temCompany = item?.company_id !== null && item?.company_id !== undefined;
    const PADRAO = { rota: 'mlb.anuncios.publicador.visao-geral', parametros: { conta: item?.chave ?? null } };

    if (rotaAtual === 'mlb.anuncios.publicador.produtos') {
        return { rota: 'mlb.anuncios.publicador.produtos', parametros: { conta: item?.chave ?? null } };
    }

    // Ziggy aceita wildcard `*` em current() (confirmado em
    // vendor/tightenco/ziggy/src/js/Router.js); cobre qualquer sub-rota de
    // Alavancas (promoções, cupons, vendedor…), não só o index.
    if (route().current('mlb.anuncios.publicador.alavancas.*')) {
        return { rota: 'mlb.anuncios.publicador.alavancas.index', parametros: { conta: item?.chave ?? null } };
    }

    if (rotaAtual === 'mlb.anuncios.meus' || rotaAtual === 'mlb.anuncios.historico') {
        // Publicações não existe sem Company (D23) — cai pra Visão geral.
        return temCompany ? { rota: rotaAtual, parametros: { company: item.company_id } } : PADRAO;
    }

    if (rotaAtual === 'mlb.anuncios.massa') {
        return temCompany ? { rota: 'mlb.anuncios.massa', parametros: { company: item.company_id } } : PADRAO;
    }

    // visao-geral, configuracoes, ou qualquer rota fora do mapa (ex.: veio de
    // fora do Publicador) — default seguro.
    return PADRAO;
}

/** Uma linha de resultado — export nomeado pra testar isolado de item com forma inesperada (sem nome, sem chave). */
export function LinhaResultado({ item, onEscolher }) {
    const nome = textoSeguro(item?.nome);
    const chave = textoSeguro(item?.chave);

    return (
        <li>
            <button
                type="button"
                onClick={() => onEscolher(item)}
                className="flex w-full items-center gap-2 rounded-lg px-2 py-2 text-left text-[13px] font-normal text-white/80 hover:bg-white/[0.05] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow"
            >
                <span className="min-w-0 flex-1 truncate">{nome}</span>
                <span className="shrink-0 font-mono text-[11px] text-white/40">{chave}</span>
                <SeloConta token={item?.token} compacto />
            </button>
        </li>
    );
}

export default function SeletorEmpresaBusca({ aberto, onFechar }) {
    const [busca, setBusca] = useState('');
    const [resultados, setResultados] = useState([]);
    const [carregando, setCarregando] = useState(false);
    const [erro, setErro] = useState(false);
    const espera = useRef(null);

    // Reabrir o popover não deve mostrar o resultado da sessão passada.
    useEffect(() => {
        if (!aberto) return undefined;
        setBusca('');
        setResultados([]);
        setErro(false);
        return undefined;
    }, [aberto]);

    // Busca com espera de 300 ms; string vazia não busca (mesmo padrão de
    // debounce de `AnunciosEmpresas.jsx`, só o tempo muda).
    useEffect(() => {
        clearTimeout(espera.current);
        if (busca.trim() === '') {
            setResultados([]);
            setErro(false);
            return undefined;
        }
        espera.current = setTimeout(() => {
            setCarregando(true);
            setErro(false);
            axios
                .get(route('mlb.anuncios.publicador.empresas-busca'), { params: { q: busca } })
                .then((resposta) => setResultados(Array.isArray(resposta.data) ? resposta.data : []))
                .catch(() => setErro(true))
                .finally(() => setCarregando(false));
        }, ESPERA_MS);
        return () => clearTimeout(espera.current);
    }, [busca]);

    function escolher(item) {
        const { rota, parametros } = destinoParaItem(item);
        onFechar?.();
        router.get(route(rota, parametros));
    }

    const buscaVazia = busca.trim() === '';

    return (
        <div>
            <label className="relative block">
                <span className="sr-only">Buscar empresa</span>
                <Search size={14} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-white/40" aria-hidden="true" />
                <input
                    type="search"
                    autoFocus
                    value={busca}
                    onChange={(ev) => setBusca(ev.target.value)}
                    placeholder="Buscar empresa…"
                    className="h-10 w-full rounded-lg border border-white/[0.08] bg-white/[0.04] pl-9 pr-3 text-[13px] font-normal text-white placeholder:text-white/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow"
                />
            </label>

            <ul aria-label="Resultados da busca" className="mt-2 max-h-[320px] overflow-y-auto">
                {buscaVazia && (
                    <li className="px-2 py-2 text-[13px] font-normal text-white/55">Digite para buscar</li>
                )}
                {!buscaVazia && carregando && (
                    <li className="px-2 py-2 text-[13px] font-normal text-white/55">Buscando…</li>
                )}
                {!buscaVazia && !carregando && erro && (
                    <li className="px-2 py-2 text-[13px] font-normal text-white/55">
                        Não foi possível buscar agora. Tente de novo em instantes.
                    </li>
                )}
                {!buscaVazia && !carregando && !erro && resultados.length === 0 && (
                    <li className="px-2 py-2 text-[13px] font-normal text-white/55">Nenhuma empresa encontrada.</li>
                )}
                {!carregando && !erro && resultados.map((item, indice) => (
                    <LinhaResultado key={item?.chave ?? `item-${indice}`} item={item} onEscolher={escolher} />
                ))}
            </ul>
        </div>
    );
}
