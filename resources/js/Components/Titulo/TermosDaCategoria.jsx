import { useMemo, useState } from 'react';
import { Check, Loader2, Plus, TrendingUp } from 'lucide-react';
import { CLASSE_INPUT } from '@/Components/Portal/Estrutura/comum';
import { limiteDoTitulo, termoCabe, termoNoTitulo } from '@/lib/tituloDoProduto';
import { cn } from '@/lib/utils';

// ─── Termos mais buscados da categoria, para montar o título (19/10/2026) ────
//
// O mesmo painel no cadastro do produto (Portal) e no editor do Publicador.
//
// - Todos os termos da categoria aparecem; "Só os que citam o produto" (`relacionado`,
//   calculado no servidor) é um filtro que a pessoa liga, e começa desligado.
// - O chip marcado é DERIVADO do texto do título (`termoNoTitulo`): clicar acrescenta
//   ou tira (`onAlternar`), e apagar o termo digitando desmarca sozinho.
// - Termo que não cabe no limite fica apagado; o marcado sempre pode sair.
// - Categoria de nicho vem com poucos termos: o servidor completa com os da categoria
//   de cima (`origem: 'acima'`), num bloco à parte com o nome dela.
//
// Os textos que citam a origem dos termos vêm de quem usa (`textos`): o cadastro do
// cliente não pode dizer de onde a lista vem (sigilo do Portal).

const GRUPO = { crescimento: 'Em alta na semana', desejado: 'Mais desejado', popular: 'Mais popular' };

export const TEXTOS_NEUTROS = {
    carregando: 'Buscando os termos desta categoria…',
    vazio: 'Esta categoria não tem termos em alta agora.',
    rodape: 'Lista da semana, por posição. Confira se o termo descreve o seu produto antes de usar.',
};

const semAcento = (s) => String(s ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

const quantosTermos = (n) => (n === 0 ? 'nenhum termo' : `só ${n} ${n === 1 ? 'termo' : 'termos'}`);

export default function TermosDaCategoria({
    dados, carregando = false, erro = null, onTentarDeNovo = null,
    titulo, limite, onAlternar, desabilitado = false, textos = {}, cabecalho = null, className,
}) {
    const t = { ...TEXTOS_NEUTROS, ...textos };
    const [soRelacionados, setSoRelacionados] = useState(false);
    const [filtro, setFiltro] = useState('');

    const lista = dados?.termos ?? [];
    const relacionados = lista.filter((x) => x.relacionado).length;
    const visiveis = useMemo(() => {
        const f = semAcento(filtro.trim());

        return lista.filter((x) => (! soRelacionados || x.relacionado) && (! f || semAcento(x.termo).includes(f)));
    }, [lista, soRelacionados, filtro]);
    const acima = dados?.acima ?? null;
    const daCategoria = visiveis.filter((x) => x.origem !== 'acima');
    const deCima = visiveis.filter((x) => x.origem === 'acima');
    const nomeCategoria = dados?.categoria?.caminho?.at(-1) ?? 'Esta categoria';
    const maximo = limiteDoTitulo(limite);

    const chip = (x) => {
        const usado = termoNoTitulo(titulo, x.termo);
        const cabe = termoCabe(titulo, x.termo, maximo);
        const onde = x.origem === 'acima' ? `#${x.posicao} em ${acima?.nome ?? 'categoria acima'}` : `#${x.posicao} na semana`;
        const dica = cabe ? [x.grupo ? GRUPO[x.grupo] : null, onde].filter(Boolean).join(' · ') : `Não cabe no limite de ${maximo} caracteres`;

        return (
            <li key={`${x.origem ?? 'categoria'}-${x.posicao}`}>
                <button type="button" disabled={desabilitado || ! cabe} onClick={() => onAlternar(x.termo)} aria-pressed={usado}
                    data-termo={x.termo} data-origem={x.origem ?? 'categoria'} data-usado={usado ? '1' : '0'} title={dica}
                    className={cn('inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-[13px] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow disabled:opacity-50',
                        usado ? 'border-emerald-400/30 bg-emerald-500/10 text-emerald-300 hover:border-emerald-300/50'
                            : (x.relacionado ? 'border-white/[0.16] bg-white/[0.06] text-white hover:border-ecf-yellow/40' : 'border-white/[0.08] text-white/70 hover:text-white'))}>
                    {usado ? <Check size={12} aria-hidden="true" /> : <Plus size={12} aria-hidden="true" />}
                    <span className="tabular-nums text-white/40">{x.posicao}</span> {x.termo}
                </button>
            </li>
        );
    };

    return (
        <div className={cn('rounded-xl border border-white/[0.08] bg-white/[0.02] p-4', className)} data-termos-mais-buscados={lista.length}>
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex items-center gap-2">
                    <TrendingUp size={14} className="text-white/55" aria-hidden="true" />
                    <span className="text-[13px] font-bold text-white">Termos mais buscados na categoria</span>
                </div>
                {cabecalho}
            </div>

            {carregando && (
                <p className="mt-3 flex items-center gap-2 text-[13px] text-white/55" data-termos-carregando><Loader2 size={14} className="animate-spin" aria-hidden="true" /> {t.carregando}</p>
            )}
            {erro && ! carregando && (
                <p className="mt-3 text-[13px] text-amber-300" data-erro-termos>
                    {erro}{' '}
                    {onTentarDeNovo && <button type="button" onClick={onTentarDeNovo} className="underline underline-offset-2 hover:text-white">Tentar de novo</button>}
                </p>
            )}
            {dados && ! carregando && lista.length === 0 && (
                <p className="mt-3 text-[13px] text-white/55" data-termos-vazio>{t.vazio}</p>
            )}

            {dados && ! carregando && lista.length > 0 && (
                <>
                    <div className="mt-3 flex flex-wrap items-center gap-3">
                        <input value={filtro} onChange={(e) => setFiltro(e.target.value)} placeholder="Filtrar termos…" aria-label="Filtrar termos"
                            className={cn(CLASSE_INPUT, 'w-56 py-1.5 text-[13px]')} data-filtro-termos />
                        {relacionados > 0 && (
                            <label className="flex cursor-pointer items-center gap-2 text-[13px] text-white/70">
                                <input type="checkbox" checked={soRelacionados} onChange={(e) => setSoRelacionados(e.target.checked)}
                                    className="rounded border-white/20 bg-transparent text-ecf-yellow" data-so-relacionados />
                                Só os que citam o produto
                            </label>
                        )}
                    </div>
                    {daCategoria.length > 0 && (
                        <ul className="mt-3 flex flex-wrap gap-2" data-lista-termos>{daCategoria.map(chip)}</ul>
                    )}
                    {acima && deCima.length > 0 && (
                        <div className="mt-4 border-t border-white/[0.06] pt-3" data-termos-de-cima>
                            <p className="text-[13px] font-bold text-white/80">Da categoria acima · {acima.nome}</p>
                            <p className="mt-0.5 text-[11px] text-white/40">{nomeCategoria} tem {quantosTermos(dados.total)} na semana; estes vêm da categoria que a contém.</p>
                            <ul className="mt-2 flex flex-wrap gap-2" data-lista-termos-de-cima>{deCima.map(chip)}</ul>
                        </div>
                    )}
                    {visiveis.length === 0 && <p className="mt-3 text-[13px] text-white/55">Nenhum termo com esse filtro.</p>}
                    <p className="mt-3 text-[11px] text-white/40">{t.rodape}</p>
                </>
            )}
        </div>
    );
}
