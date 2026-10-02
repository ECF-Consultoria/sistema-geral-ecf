import { useState } from 'react';
import { Layers } from 'lucide-react';
import { Botao } from '@/Components/Portal/Estrutura/comum';
import EditorDeEixos from '../EditorDeEixos';
import { estadoDasSecoes } from '../apoio';
import CartaoVariante from './CartaoVariante';
import { CardMesa } from './comum';

// ─── Card 3 — Variações e estoque (checks "Variações" e "Estoque") ──────────
//
// Resumo dos eixos, editor inline e um cartão por combinação. Quem gera as
// combinações (e guarda os dados das órfãs) é o servidor: aqui só se envia a
// lista de eixos por `m.salvarEixos`.

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
    const { estado, schema } = m;
    const eixos = estado.eixos ?? [];
    const atuais = m.variantes.filter((v) => ! v.orfa);
    const orfas = m.variantes.filter((v) => v.orfa);
    const alvosAtivos = (m.alvos ?? []).filter((a) => a.ativo);
    const total = alvosAtivos.length * atuais.filter((v) => v.ativa).length;
    const situacao = estadoDasSecoes([...m.problemasDaSecao('variacoes'), ...m.problemasDaSecao('variantes')], schema);
    const faltam = situacao.variacoes.faltam + situacao.variantes.faltam;
    const resumo = eixos.length > 0
        ? `Eixos: ${eixos.map((e) => e.nome).join(' × ')} · ${atuais.length} ${atuais.length === 1 ? 'combinação' : 'combinações'}`
        : 'Sem variações: o anúncio sai como um produto único.';

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
                            <Botao onClick={() => setEditando((v) => ! v)} aria-expanded={editando} data-acao="editar-eixos">{editando ? 'Fechar edição' : 'Editar eixos'}</Botao>
                        )}
                    </div>

                    {editando && (
                        <EditorDeEixos eixos={eixos} schema={schema} maxEixos={schema.limites?.max_variation_axes ?? 3} disabled={m.disabled} onSalvar={m.salvarEixos} />
                    )}

                    <div className="space-y-4">
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
