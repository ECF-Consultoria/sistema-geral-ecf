import { useEffect, useRef, useState } from 'react';
import { Layers, Plus } from 'lucide-react';
import { Botao } from '@/Components/Portal/Estrutura/comum';
import EditorDeEixos from '../EditorDeEixos';
import { estadoDasSecoes } from '../apoio';
import { gerarEan13, gtinsEmUso, variantesSemGtin } from '../ferramentas';
import CartaoVariante from './CartaoVariante';
import { CardMesa } from './comum';
import { cn } from '@/lib/utils';

// ─── Card 3 — Variações e estoque (checks "Variações" e "Estoque") ──────────
//
// Resumo das variações, editor inline e um cartão por combinação. Quem gera as
// combinações (e guarda os dados das órfãs) é o servidor: aqui só se envia a
// lista de eixos por `m.salvarEixos`.
//
// Docx §4 (03/10/2026): "Adicionar variação" à vista (antes ficava atrás de
// "Editar eixos") e o código universal (EAN-13) gerado sozinho em toda variação
// ativa sem código — uma vez por variação; apagado à mão, não volta sozinho.

function ChipTotal({ faltam, total }) {
    return (
        <span className="inline-flex items-center gap-3">
            <span className="text-[11px] font-bold uppercase tracking-[0.05em] text-white/55 tabular-nums" data-total-anuncios={total}>Total a gerar: {total} {total === 1 ? 'anúncio' : 'anúncios'}</span>
            {faltam === 0 ? (
                <span className="inline-flex items-center gap-1.5 rounded-full bg-emerald-500/10 px-3 py-1 text-[11px] font-bold uppercase tracking-[0.05em] text-emerald-400" data-chip-secao="completo">Completo</span>
            ) : (
                <span className="inline-flex items-center gap-2 rounded-full bg-white/[0.04] px-3 py-1 text-[11px] font-bold uppercase tracking-[0.05em] text-white/55" data-chip-secao={faltam}>
                    <span className="h-1.5 w-1.5 rounded-full bg-amber-400" /> {faltam === 1 ? 'Falta 1' : `Faltam ${faltam}`}
                </span>
            )}
        </span>
    );
}

export default function CardVariacoes({ m, aberto = true, onAlternar }) {
    const [editando, setEditando] = useState(false);
    const gerados = useRef(new Set());
    const { estado, schema } = m;
    const eixos = estado.eixos ?? [];
    const atuais = m.variantes.filter((v) => ! v.orfa);
    const orfas = m.variantes.filter((v) => v.orfa);
    const alvosAtivos = (m.alvos ?? []).filter((a) => a.ativo);
    const total = alvosAtivos.length * atuais.filter((v) => v.ativa).length;
    const situacao = estadoDasSecoes([...m.problemasDaSecao('variacoes'), ...m.problemasDaSecao('variantes')], schema);
    const faltam = situacao.variacoes.faltam + situacao.variantes.faltam;
    const resumo = eixos.length > 0
        ? `Variações por ${eixos.map((e) => e.nome).join(' × ')} · ${atuais.length} ${atuais.length === 1 ? 'combinação' : 'combinações'}`
        : 'Sem variações: o anúncio sai como um produto único.';

    // EAN-13 automático (gerador interno, o mesmo do assistente antigo).
    const semGtin = m.disabled ? [] : variantesSemGtin(m.variantes, schema).filter((v) => ! gerados.current.has(v.chave));
    const chaveSemGtin = semGtin.map((v) => v.chave).join('|');
    useEffect(() => {
        if (! chaveSemGtin) return;
        const usados = gtinsEmUso(m.variantes);
        for (const v of semGtin) {
            const ean = gerarEan13(usados);
            usados.add(ean);
            gerados.current.add(v.chave);
            m.mudarVar(v.chave, { atributos: { ...(v.atributos ?? {}), GTIN: { value_name: ean } } });
        }
    }, [chaveSemGtin]); // eslint-disable-line react-hooks/exhaustive-deps

    const adicionarVariacao = () => {
        setEditando(true);
        setTimeout(() => document.getElementById('editor-variacoes')?.scrollIntoView({ behavior: 'smooth', block: 'center' }), 0);
    };

    return (
        <CardMesa id="card-variacoes" icone={Layers} titulo="Variações e estoque" chip={<ChipTotal faltam={faltam} total={total} />} aberto={aberto} onAlternar={onAlternar}
            apoio="Uma linha por combinação, com estoque, SKU e código universal.">
            {! schema ? (
                <p className="text-[13px] text-white/55">Escolha a categoria para definir as variações.</p>
            ) : (
                <div className="space-y-4">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <p className="text-[13px] text-white/70" data-resumo-eixos>{resumo}</p>
                        {! m.disabled && (
                            <div className="flex flex-wrap gap-2">
                                <Botao onClick={adicionarVariacao} data-acao="adicionar-variacao"><Plus size={14} /> Adicionar variação</Botao>
                                {eixos.length > 0 && (
                                    <Botao variante="fantasma" onClick={() => setEditando((v) => ! v)} aria-expanded={editando} data-acao="editar-eixos">{editando ? 'Fechar edição' : 'Editar variações'}</Botao>
                                )}
                            </div>
                        )}
                    </div>

                    {editando && (
                        <div id="editor-variacoes" className="space-y-2 rounded-xl border border-white/[0.08] bg-white/[0.02] p-4">
                            {/* Sem eixos, o próprio editor já explica o que fazer. */}
                            {eixos.length > 0 && (
                                <p className="text-[13px] text-white/55">Para uma variação nova, acrescente um valor (ex.: outra cor) — cada valor vira uma combinação com estoque, SKU e código próprios.</p>
                            )}
                            <EditorDeEixos eixos={eixos} schema={schema} maxEixos={schema.limites?.max_variation_axes ?? 3} disabled={m.disabled} onSalvar={m.salvarEixos} />
                        </div>
                    )}

                    <div className={cn('grid gap-4', atuais.length > 1 && '2xl:grid-cols-2')}>
                        {atuais.map((v, i) => <CartaoVariante key={v.chave} m={m} v={v} indice={i + 1} eixos={eixos} alvosAtivos={alvosAtivos} />)}
                    </div>

                    {orfas.length > 0 && (
                        <details className="text-[13px] text-white/55" data-orfas={orfas.length}>
                            <summary className="cursor-pointer">Combinações removidas que ainda guardam dados ({orfas.length})</summary>
                            <ul className="mt-2 space-y-0.5">
                                {orfas.map((v) => <li key={v.chave}>{v.rotulo} · estoque {v.estoque ?? '—'} · SKU {v.atributos?.SELLER_SKU?.value_name ?? '—'}</li>)}
                            </ul>
                        </details>
                    )}
                </div>
            )}
        </CardMesa>
    );
}
