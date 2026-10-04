import { useEffect, useMemo, useState } from 'react';
import { Check, Loader2, Plus, TrendingUp } from 'lucide-react';
import { CLASSE_INPUT } from '@/Components/Portal/Estrutura/comum';
import { NOME_TIPO } from '../apoio';
import { termoNoTitulo } from '../ferramentas';
import { cn } from '@/lib/utils';

// ─── Termos mais buscados da categoria, para montar o título (docx §3) ──────
//
// O ML devolve até 50 termos da semana (`/trends`), cheios de marca de
// concorrente e de produto vizinho. O filtro de coerência é da pessoa: por
// padrão aparecem só os que têm palavra do nome do produto (`relacionado`,
// calculado no servidor); "ver todos" mostra o resto. Clicar num termo
// acrescenta ao título do tipo escolhido as palavras que ele ainda não tem.

const GRUPO = { crescimento: 'Em alta na semana', desejado: 'Mais desejado', popular: 'Mais popular' };

const semAcento = (s) => String(s ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

export default function TermosMaisBuscados({ m, alvos, destino, onDestino, onUsar }) {
    const categoria = m.estado.rascunho?.categoria_id ?? null;
    const { dados, carregando, erro } = m.termos ?? {};
    const [todos, setTodos] = useState(false);
    const [filtro, setFiltro] = useState('');

    // Uma leitura por categoria: trocar a categoria relê.
    useEffect(() => {
        if (categoria && m.termos?.categoria !== categoria && ! m.termos?.carregando) m.carregarTermos();
    }, [categoria]); // eslint-disable-line react-hooks/exhaustive-deps

    const lista = dados?.termos ?? [];
    const relacionados = lista.filter((t) => t.relacionado).length;
    const verTodos = todos || relacionados === 0;
    const visiveis = useMemo(() => {
        const f = semAcento(filtro.trim());

        return lista.filter((t) => (verTodos || t.relacionado) && (! f || semAcento(t.termo).includes(f)));
    }, [lista, verTodos, filtro]);
    const tituloDestino = alvos.find((a) => a.listing_type_id === destino);
    const textoDestino = tituloDestino ? (tituloDestino.titulo || tituloDestino.titulo_efetivo || '') : '';

    return (
        <div className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-4" data-termos-mais-buscados={lista.length}>
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex items-center gap-2">
                    <TrendingUp size={14} className="text-white/55" />
                    <span className="text-[13px] font-bold text-white">Termos mais buscados na categoria</span>
                </div>
                {alvos.length > 1 && (
                    <div role="radiogroup" aria-label="Acrescentar no título do" className="inline-flex items-center gap-1 rounded-[10px] border border-white/[0.08] bg-white/[0.03] p-1 text-[11px]" data-destino-termos>
                        <span className="px-1.5 text-white/40">Acrescentar no</span>
                        {alvos.map((a) => (
                            <button key={a.listing_type_id} type="button" role="radio" aria-checked={destino === a.listing_type_id} onClick={() => onDestino(a.listing_type_id)}
                                className={cn('rounded-lg border px-2 py-0.5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow',
                                    destino === a.listing_type_id ? 'border-ecf-yellow/40 bg-ecf-yellow/10 font-bold text-ecf-yellow' : 'border-transparent text-white/55 hover:text-white')}>
                                {NOME_TIPO[a.listing_type_id]}
                            </button>
                        ))}
                    </div>
                )}
            </div>

            {carregando && (
                <p className="mt-3 flex items-center gap-2 text-[13px] text-white/55"><Loader2 size={14} className="animate-spin" /> Buscando os termos no Mercado Livre…</p>
            )}
            {erro && ! carregando && (
                <p className="mt-3 text-[13px] text-amber-300">
                    {erro}{' '}
                    <button type="button" onClick={() => m.carregarTermos()} className="underline underline-offset-2 hover:text-white">Tentar de novo</button>
                </p>
            )}
            {dados && ! carregando && lista.length === 0 && (
                <p className="mt-3 text-[13px] text-white/55">O Mercado Livre não tem termos em alta para esta categoria agora.</p>
            )}

            {dados && ! carregando && lista.length > 0 && (
                <>
                    <div className="mt-3 flex flex-wrap items-center gap-3">
                        <input value={filtro} onChange={(e) => setFiltro(e.target.value)} placeholder="Filtrar termos…" aria-label="Filtrar termos"
                            className={cn(CLASSE_INPUT, 'w-56 py-1.5 text-[13px]')} data-filtro-termos />
                        {relacionados > 0 && (
                            <label className="flex cursor-pointer items-center gap-2 text-[13px] text-white/70">
                                <input type="checkbox" checked={todos} onChange={(e) => setTodos(e.target.checked)} className="rounded border-white/20 bg-transparent text-ecf-yellow" data-ver-todos-termos />
                                Ver também os que não citam o produto
                            </label>
                        )}
                    </div>
                    <ul className="mt-3 flex flex-wrap gap-2" data-lista-termos>
                        {visiveis.map((t) => {
                            const usado = termoNoTitulo(textoDestino, t.termo);

                            return (
                                <li key={t.posicao}>
                                    <button type="button" disabled={m.disabled || ! tituloDestino?.ativo} onClick={() => onUsar(t.termo)} data-termo={t.termo}
                                        title={[t.grupo ? GRUPO[t.grupo] : null, `#${t.posicao} na semana`].filter(Boolean).join(' · ')}
                                        className={cn('inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-[13px] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow disabled:opacity-50',
                                            usado ? 'border-emerald-400/30 bg-emerald-500/10 text-emerald-300'
                                                : (t.relacionado ? 'border-white/[0.16] bg-white/[0.06] text-white hover:border-ecf-yellow/40' : 'border-white/[0.08] text-white/55 hover:text-white'))}>
                                        {usado ? <Check size={12} /> : <Plus size={12} />}
                                        <span className="tabular-nums text-white/40">{t.posicao}</span> {t.termo}
                                    </button>
                                </li>
                            );
                        })}
                    </ul>
                    {visiveis.length === 0 && <p className="mt-3 text-[13px] text-white/55">Nenhum termo com esse filtro.</p>}
                    <p className="mt-3 text-[11px] text-white/40">Lista da semana no Mercado Livre, por posição. Confira se o termo descreve este produto antes de usar — vem com marca de concorrente e produto parecido.</p>
                </>
            )}
        </div>
    );
}
