import { useCallback, useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { Loader2 } from 'lucide-react';
import { cn } from '@/lib/utils';

// ─── Categoria real do Mercado Livre, escolhida na própria célula (D-06) ─────
//
// A busca abre já preenchida com o nome do produto. NADA é aceito sozinho: a 1ª
// sugestão só ganha destaque de teclado; quem grava é Enter ou clique da pessoa.
// Categoria que não é folha aparece apagada e não é selecionável. A busca usa só
// o app token no servidor; lista vazia vem junto de `indisponivel`, e a tela não
// afirma que o ML caiu (o preditor devolve [] nos dois casos).

const ESPERA_BUSCA_MS = 350;
const MAXIMO_ITENS = 8;
// O servidor busca com `q` de 2 a 120 caracteres (min:2|max:120); o nome do produto aceita 255. Fora
// disso o 422 parecia "o Mercado Livre caiu" e "Tentar de novo" repetia o mesmo erro (revisão FE-WR-08).
const MINIMO_BUSCA = 2;
const MAXIMO_BUSCA = 120;

export default function PickerCategoria({ row, textoInicial, onCommit, onClose }) {
    const [busca, setBusca] = useState(() => String(textoInicial ?? row?.nome ?? '').slice(0, MAXIMO_BUSCA));
    const [itens, setItens] = useState([]);
    const [estado, setEstado] = useState('buscando');     // buscando | pronto | curta | recusada | indisponivel
    const [ativo, setAtivo] = useState(0);
    const [tentativa, setTentativa] = useState(0);
    const campo = useRef(null);

    useEffect(() => { campo.current?.focus(); }, []);

    // Busca ao abrir e a cada digitação, com debounce.
    useEffect(() => {
        const q = busca.trim();
        if (q === '') { setItens([]); setEstado('pronto'); return undefined; }
        if (q.length < MINIMO_BUSCA) { setItens([]); setEstado('curta'); return undefined; }
        let vivo = true;
        setEstado('buscando');
        const t = setTimeout(async () => {
            try {
                const { data } = await axios.get(route('portal.auth.estrutura.produtos.categorias'), { params: { q: q.slice(0, MAXIMO_BUSCA) } });
                if (! vivo) return;
                setItens((data.categorias ?? []).slice(0, MAXIMO_ITENS));
                setEstado(data.indisponivel && (data.categorias ?? []).length === 0 ? 'indisponivel' : 'pronto');
                setAtivo(0);
            } catch (e) {
                if (! vivo) return;
                setItens([]);
                // 422 é o texto da busca, não o Mercado Livre: tentar de novo igual não adianta.
                setEstado(e.response?.status === 422 ? 'recusada' : 'indisponivel');
            }
        }, ESPERA_BUSCA_MS);

        return () => { vivo = false; clearTimeout(t); };
    }, [busca, tentativa]);

    const escolher = useCallback((item) => {
        if (! item || item.folha === false) return;
        onCommit({
            categoria_ml_id: item.id,
            categoria_ml_nome: item.nome,
            categoria_ml_caminho: item.caminho_texto,
            categoria: item.nome,
            _categoriaEscolhida: true,
        });
        onClose();
    }, [onCommit, onClose]);

    const aoTecla = (e) => {
        if (e.key === 'ArrowDown') { e.preventDefault(); setAtivo((a) => Math.min(a + 1, Math.max(itens.length - 1, 0))); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); setAtivo((a) => Math.max(a - 1, 0)); }
        else if (e.key === 'Enter') { e.preventDefault(); e.stopPropagation(); escolher(itens[ativo]); }
    };

    return (
        <div className="w-[420px] max-w-[92vw] rounded-xl border border-white/[0.08] bg-ecf-card p-2 shadow-xl" onMouseDown={(e) => e.stopPropagation()}>
            <input
                ref={campo}
                value={busca}
                onChange={(e) => { setBusca(e.target.value); setAtivo(0); }}
                onKeyDown={aoTecla}
                maxLength={MAXIMO_BUSCA}
                placeholder="Buscar categoria"
                aria-label="Buscar categoria"
                className="h-10 w-full rounded-lg border border-white/[0.10] bg-white/[0.04] px-3 text-[13.5px] text-white placeholder:text-white/25 focus:border-ecf-yellow/40 focus:outline-none focus:ring-0"
            />

            {estado === 'buscando' && (
                <p className="flex items-center gap-2 px-2 py-3 text-[12px] text-white/45" role="status">
                    <Loader2 className="h-3 w-3 animate-spin" aria-hidden="true" /> Buscando categorias…
                </p>
            )}
            {estado === 'indisponivel' && (
                <div className="px-2 py-3 text-[12px] text-white/60" role="status">
                    <p>Não deu para buscar agora. Você pode tentar de novo ou deixar para depois.</p>
                    <button type="button" onClick={() => setTentativa((n) => n + 1)} data-acao="tentar-categoria-de-novo"
                        className="mt-2 rounded-lg px-2 py-1 text-[12px] text-white/70 hover:bg-white/[0.06] hover:text-white">
                        Tentar de novo
                    </button>
                </div>
            )}
            {estado === 'curta' && (
                <p className="px-2 py-3 text-[12px] text-white/45" role="status">Digite ao menos 2 letras.</p>
            )}
            {estado === 'recusada' && (
                <p className="px-2 py-3 text-[12px] text-white/45" role="status">Não deu para buscar com esse texto. Use de 2 a 120 letras, como o tipo do produto.</p>
            )}
            {estado === 'pronto' && itens.length === 0 && (
                <p className="px-2 py-3 text-[12px] text-white/45">Nada encontrado. Tente outra palavra, como o tipo do produto.</p>
            )}

            {estado === 'pronto' && itens.length > 0 && (
                <ul role="listbox" className="mt-2 max-h-72 overflow-y-auto">
                    {itens.map((item, n) => {
                        const folha = item.folha !== false;

                        return (
                            <li key={item.id} role="option" aria-selected={n === ativo} aria-disabled={! folha}
                                onMouseEnter={() => setAtivo(n)}
                                onClick={() => escolher(item)}
                                className={cn('rounded-lg px-2 py-2', folha ? 'cursor-pointer' : 'cursor-not-allowed', n === ativo && folha && 'bg-white/[0.06]')}>
                                <p className={cn('text-[13px] font-semibold', folha ? 'text-white/85' : 'text-white/35')}>{item.nome}</p>
                                <p className={cn('line-clamp-2 text-[12px]', folha ? 'text-white/45' : 'text-white/35')}>{item.caminho_texto}</p>
                                {! folha && <p className="text-[12px] text-white/35">Escolha uma mais específica</p>}
                            </li>
                        );
                    })}
                </ul>
            )}
        </div>
    );
}
