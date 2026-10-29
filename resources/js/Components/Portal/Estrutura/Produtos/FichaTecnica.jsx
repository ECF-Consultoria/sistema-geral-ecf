import { useState } from 'react';
import { Loader2, Search, Sparkles } from 'lucide-react';
import CampoFichaTecnica from '@/Components/Portal/Estrutura/Produtos/CampoFichaTecnica';
import { camposDaFicha } from '@/lib/fichaLayout';
import { cn } from '@/lib/utils';

// ─── Bloco "Ficha técnica" da ficha do produto ──────────────────────────────
//
// Aparece quando o produto tem categoria e mostra TODOS os campos dela (decisão de
// 07/10), que o cliente preenche; vai junto no "Salvar produto". O campo que é o eixo
// de alguma variação e as medidas do produto (bloco Medidas e envio) já chegam fora.
//
// 29/10/2026: os campos deixam de seguir os grupos da categoria ("Outros", "Mais
// detalhes" — não diziam nada) e ficam em dois blocos, obrigatórias e outras, sempre
// na ordem da categoria: nada muda de lugar enquanto se preenche (o usuário: "preenche
// e muda de lugar, fica desorganizado"), nem o "não se aplica", que fica no lugar,
// apagado. Busca pelo nome e filtro Todas · Faltando · Preenchidas; o filtro tira uma
// foto ao ser clicado, e o que se preenche depois não some da lista. Conta em lib/fichaLayout.

const GRADE = 'grid gap-x-5 gap-y-4 [grid-template-columns:repeat(auto-fill,minmax(260px,1fr))]';
const FILTROS = [['todas', 'Todas'], ['faltando', 'Faltando'], ['preenchidas', 'Preenchidas']];

/** O resultado do "Preencher com IA" em uma frase. */
function textoDaIa(r) {
    if (r.erro) return r.erro;
    if (! r.aplicados) return 'A IA não achou mais nada para preencher com o que o produto informa.';
    const valores = r.aplicados - r.naoSeAplica;
    const partes = [];
    if (valores > 0) partes.push(`preencheu ${valores} ${valores === 1 ? 'campo' : 'campos'}`);
    if (r.naoSeAplica > 0) partes.push(`marcou ${r.naoSeAplica} como "Não se aplica"`);

    return `A IA ${partes.join(' e ')}. Confira os marcados "sugestão da IA" e clique em Salvar produto.`;
}

export default function FichaTecnica({ tecnica, salvando, onPreencherComIa = null, podeIa = false }) {
    const [busca, setBusca] = useState('');
    const [filtro, setFiltro] = useState('todas');
    const [foto, setFoto] = useState(null);   // Set de ids do filtro escolhido, ou null em "Todas"
    // "Preencher com IA" (29/10/2026): preenche os vazios na tela; quem grava é o "Salvar produto".
    const [ia, setIa] = useState({ rodando: false, resultado: null });
    const preencher = async () => {
        if (! onPreencherComIa || ia.rodando) return;
        setIa({ rodando: true, resultado: null });
        const r = await onPreencherComIa();
        setIa({ rodando: false, resultado: r });
    };
    if (! tecnica.temCategoria) {
        return (
            <section id="secao-ficha-tecnica" className="scroll-mt-4 rounded-[14px] border border-white/[0.08] bg-ecf-card p-4 lg:p-5" data-ficha-tecnica="sem-categoria">
                <h2 className="text-[19px] font-bold text-white">Ficha técnica</h2>
                <p className="mt-0.5 text-[13.5px] text-white/60">Escolha a categoria do produto (lá em cima, em Produto) para ver as características que ela pede.</p>
            </section>
        );
    }

    const grupos = tecnica.grupos ?? [];
    const escolherFiltro = (k) => {
        setFiltro(k);
        setFoto(k === 'todas' ? null : new Set(camposDaFicha(grupos, tecnica.valores, { filtro: k }).ids));
    };
    const r = camposDaFicha(tecnica.grupos ?? [], tecnica.valores, { busca, filtro, so: foto });
    const c = r.contagem;
    const mostrar = (campo) => (
        <CampoFichaTecnica key={campo.id} campo={campo} atual={tecnica.valores[campo.id]}
            erro={tecnica.erros[campo.id]} onMudar={tecnica.mudar} explicacao={campo.explicacao}
            sugeridoIa={!! tecnica.sugeridos?.has(campo.id)} />
    );
    const nada = r.obrigatorias.length === 0 && r.outras.length === 0;

    return (
        <section id="secao-ficha-tecnica" className="scroll-mt-4 rounded-[14px] border border-white/[0.08] bg-ecf-card p-4 lg:p-5" data-ficha-tecnica>
            <h2 className="text-[19px] font-bold text-white">Ficha técnica</h2>
            <p className="mt-0.5 text-[13.5px] text-white/65">As características que a categoria pede. Primeiro as obrigatórias.</p>

            {tecnica.erroGeral && (
                <p role="alert" className="mt-3 rounded-xl border border-red-400/20 bg-red-500/10 px-3 py-2 text-[12.5px] text-red-200" data-erro-ficha-tecnica>{tecnica.erroGeral}</p>
            )}

            {tecnica.carregando && (
                <p role="status" className="mt-4 flex items-center gap-2 text-[14px] text-white/60" data-carregando-ficha-tecnica>
                    <Loader2 size={16} className="animate-spin" aria-hidden="true" /> Carregando os campos…
                </p>
            )}

            {! tecnica.carregando && tecnica.indisponivel && (
                <div role="status" className="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-white/[0.08] bg-white/[0.03] px-3 py-2 text-[14px] text-white/70" data-indisponivel-ficha-tecnica>
                    <span>Não foi possível carregar os campos agora; tente de novo.</span>
                    <button type="button" onClick={tecnica.tentarDeNovo} data-acao="recarregar-ficha-tecnica"
                        className="h-9 rounded-lg border border-white/[0.10] bg-white/[0.03] px-4 text-[14px] text-white hover:bg-white/[0.07]">
                        Tentar de novo
                    </button>
                </div>
            )}

            {! tecnica.carregando && ! tecnica.indisponivel && c.todas > 0 && (
                <>
                    <div className="mt-4 flex flex-wrap items-center gap-3" data-barra-ficha-tecnica>
                        <div className="relative w-full max-w-[320px]">
                            <Search size={15} aria-hidden="true" className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-white/40" />
                            <input value={busca} onChange={(e) => setBusca(e.target.value)} placeholder="Buscar característica" aria-label="Buscar característica" data-busca-ficha-tecnica
                                className="h-9 w-full rounded-lg border border-white/20 bg-black/40 pl-9 pr-3 text-[13.5px] text-white placeholder:text-white/35 focus:border-ecf-yellow/40 focus:outline-none focus:ring-0" />
                        </div>
                        <div role="group" aria-label="Mostrar" className="inline-flex rounded-lg border border-white/[0.08] bg-white/[0.03] p-[3px]">
                            {FILTROS.map(([k, rotulo]) => (
                                <button key={k} type="button" onClick={() => escolherFiltro(k)} aria-pressed={filtro === k} data-filtro-ficha-tecnica={k}
                                    className={cn('rounded-md px-3 py-1 text-[12.5px]', filtro === k ? 'bg-white/[0.10] font-semibold text-white' : 'text-white/60 hover:text-white')}>
                                    {rotulo} <span className="tabular-nums text-white/45">{c[k]}</span>
                                </button>
                            ))}
                        </div>
                        {foto && (
                            <span className="text-[12px] text-white/40" data-filtro-foto>
                                lista de quando você filtrou · <button type="button" onClick={() => escolherFiltro(filtro)} className="text-white/70 underline decoration-white/30 underline-offset-[3px] hover:text-white">atualizar</button>
                            </span>
                        )}
                        {onPreencherComIa && (
                            <button type="button" onClick={preencher} disabled={! podeIa || ia.rodando || salvando} data-acao="preencher-com-ia"
                                title={podeIa ? 'Preenche os campos vazios com o que o produto informa. Nada é salvo antes de você clicar em Salvar produto.' : 'Salve o produto uma vez para usar a IA.'}
                                className="ml-auto inline-flex h-9 items-center gap-2 rounded-lg border border-sky-300/30 bg-sky-300/[0.08] px-4 text-[13px] font-medium text-sky-100 hover:bg-sky-300/[0.14] disabled:cursor-not-allowed disabled:opacity-50">
                                {ia.rodando ? <Loader2 size={15} className="animate-spin" aria-hidden="true" /> : <Sparkles size={15} aria-hidden="true" />}
                                {ia.rodando ? 'A IA está lendo o produto…' : 'Preencher com IA'}
                            </button>
                        )}
                    </div>

                    {ia.resultado && (
                        <p role="status" data-resultado-ia={ia.resultado.erro ? 'erro' : 'ok'}
                            className={cn('mt-3 rounded-lg border px-3 py-2 text-[13px]', ia.resultado.erro ? 'border-red-400/25 bg-red-500/[0.08] text-red-200' : 'border-sky-300/25 bg-sky-300/[0.06] text-sky-100')}>
                            {textoDaIa(ia.resultado)}
                        </p>
                    )}

                    {/* Durante o "Salvando…" nada se edita (mesma regra dos outros blocos da ficha). */}
                    <fieldset disabled={salvando} className="min-w-0 border-0 p-0" data-campos-ficha-tecnica>
                        {r.obrigatorias.length > 0 && (
                            <div className="mt-5" data-grupo-tecnico="obrigatorias">
                                <h3 className="mb-3 flex items-center gap-2 text-[11.5px] font-semibold uppercase tracking-[0.07em] text-white/45">
                                    Obrigatórias
                                    {c.obrigatoriasFaltando > 0
                                        ? <span className="rounded-full bg-amber-300/10 px-2 py-0.5 text-[11px] normal-case tracking-normal text-amber-200">{c.obrigatoriasFaltando} faltando</span>
                                        : <span className="rounded-full bg-emerald-400/10 px-2 py-0.5 text-[11px] normal-case tracking-normal text-emerald-300">completas</span>}
                                </h3>
                                <div className={GRADE}>{r.obrigatorias.map(mostrar)}</div>
                            </div>
                        )}
                        {r.outras.length > 0 && (
                            <div className="mt-6" data-grupo-tecnico="outras">
                                <h3 className="mb-3 flex items-center gap-2 text-[11.5px] font-semibold uppercase tracking-[0.07em] text-white/45">
                                    Outras características
                                    <span className="rounded-full bg-white/[0.06] px-2 py-0.5 text-[11px] normal-case tracking-normal text-white/55">
                                        {c.outrasPreenchidas} preenchidas
                                    </span>
                                </h3>
                                <div className={GRADE}>{r.outras.map(mostrar)}</div>
                            </div>
                        )}
                        {nada && <p className="mt-5 text-[13px] text-white/50" data-ficha-tecnica-vazia>Nenhuma característica {busca.trim() ? `com “${busca.trim()}” ` : ''}neste filtro.</p>}
                    </fieldset>
                </>
            )}
        </section>
    );
}
