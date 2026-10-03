import { useState } from 'react';
import { Calculator, Loader2, Sparkles, Tag } from 'lucide-react';
import { Botao, CLASSE_INPUT, LinkMl, fmtReais } from '@/Components/Portal/Estrutura/comum';
import { NOME_TIPO, NOTA_TIPO, estadoDasSecoes } from '../apoio';
import { juntarTermo, termoNoTitulo } from '../ferramentas';
import { CardMesa, ChipSecao } from './comum';
import TermosMaisBuscados from './TermosMaisBuscados';
import { cn } from '@/lib/utils';

// ─── Card 5 — Clássico e Premium (check "Tipos de anúncio") ─────────────────
//
// Um bloco por tipo: ativo/inativo, MLB no ar, título (o limite vem do schema)
// e "Quanto eu recebo?". O título é POR TIPO — nunca por variação (Q-UI-10).
// Sem linha de comissão por tipo (Q-UI-13): a tarifa só aparece na simulação.
//
// O título se monta com os termos mais buscados da categoria (docx §3): à mão,
// clicando nos termos, ou pela IA, que filtra os coerentes com o produto e
// prioriza os que já estão no título.

const MAX_TITULO_PADRAO = 60;

function BlocoDoTipo({ m, a, outro, maxTitulo, termos }) {
    const lt = a.listing_type_id;
    const titulo = a.titulo ?? '';
    const tamanho = (titulo || a.titulo_efetivo || '').length;
    const noAr = a.mlb_na_regua ?? Object.entries(m.estado.ja_publicados ?? {}).find(([chave]) => chave.split('|')[0] === lt)?.[1] ?? null;
    const faltaTitulo = a.ativo && ! titulo && ! a.titulo_efetivo;
    const tituloDoOutro = outro && (outro.titulo || outro.titulo_efetivo);
    const ia = m.palavrasIa?.[`titulo_${lt}`] ?? {};
    const rodando = ia.status === 'rodando';
    // A IA prioriza os termos que a pessoa já pôs no título.
    const escolhidos = termos.filter((t) => termoNoTitulo(titulo || a.titulo_efetivo, t));

    const mudar = (patch) => m.mudarRasc((r) => ({ alvos: r.alvos.map((x) => (x.listing_type_id === lt ? { ...x, ...patch } : x)) }));

    return (
        <div className={cn('space-y-3 rounded-xl border border-white/[0.08] bg-white/[0.02] p-4', ! a.ativo && 'opacity-60')} data-alvo={lt}>
            <div className="flex flex-wrap items-center justify-between gap-2">
                <label className="flex items-center gap-2 text-[13px] font-bold text-white">
                    <input type="checkbox" role="switch" checked={a.ativo} disabled={m.disabled || !! noAr} onChange={(e) => mudar({ ativo: e.target.checked })}
                        className="rounded border-white/20 bg-transparent text-ecf-yellow" data-alvo-ativo={lt} />
                    {NOME_TIPO[lt]}
                </label>
                {noAr && (
                    <span className="inline-flex items-center gap-2 text-[11px] font-bold uppercase tracking-[0.05em] text-emerald-400">
                        No ar <LinkMl mlb={noAr} className="text-[11px] font-normal normal-case text-white/70" />
                    </span>
                )}
            </div>
            <p className="text-[11px] text-white/40">{NOTA_TIPO[lt]}{lt === 'gold_pro' ? ' · 12x sem juros, se a conta oferecer' : ''}</p>

            {! noAr && (
                <div>
                    <div className="mb-1 flex items-center justify-between gap-2">
                        <span className="text-[11px] font-bold uppercase tracking-[0.05em] text-white/40">Título ML</span>
                        <span className={cn('font-mono text-[11px] tabular-nums', tamanho > maxTitulo ? 'text-red-300' : 'text-white/40')} data-contador-titulo={lt}>{tamanho}/{maxTitulo}</span>
                    </div>
                    <input value={titulo} disabled={m.disabled || ! a.ativo || rodando} maxLength={255} placeholder={a.titulo_efetivo ?? 'Título do anúncio…'}
                        onChange={(e) => mudar({ titulo: e.target.value })}
                        className={cn(CLASSE_INPUT, 'text-[13px] disabled:opacity-50', faltaTitulo && 'border-amber-400/50')} data-titulo={lt} />
                    <div className="mt-1 flex flex-wrap items-center justify-between gap-2">
                        {! titulo && a.titulo_efetivo && m.estado.produto?.oferta_id
                            ? <p className="text-[11px] text-white/40">vem da aba Anúncios — digite para trocar</p>
                            : <span />}
                        <span className="flex flex-wrap items-center gap-3">
                            {outro && ! titulo && tituloDoOutro && ! m.disabled && (
                                <button type="button" onClick={() => m.copiarTituloDo(outro.listing_type_id, lt)} data-acao={`copiar-titulo-${lt}`}
                                    className="text-[11px] text-white/55 hover:text-ecf-yellow focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow">
                                    Copiar do {NOME_TIPO[outro.listing_type_id]}
                                </button>
                            )}
                            {a.ativo && ! m.disabled && (
                                <button type="button" onClick={() => m.pedirPalavrasIa(`titulo_${lt}`, { escolhidos })} disabled={rodando} data-acao={`titulo-ia-${lt}`}
                                    className="inline-flex items-center gap-1 text-[11px] font-bold text-white/70 hover:text-ecf-yellow focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow disabled:opacity-60">
                                    {rodando ? <Loader2 size={12} className="animate-spin" /> : <Sparkles size={12} />}
                                    {rodando ? 'IA escrevendo…' : 'Sugerir com IA'}
                                </button>
                            )}
                        </span>
                    </div>
                    {ia.status === 'erro' && <p className="mt-1 text-[11px] text-amber-300">{ia.erro}</p>}
                </div>
            )}
        </div>
    );
}

export default function CardTiposEPrecos({ m, aberto = true, onAlternar }) {
    const { schema } = m;
    const alvos = m.alvos ?? [];
    const maxTitulo = schema?.limites?.max_title_length ?? MAX_TITULO_PADRAO;
    const faltam = estadoDasSecoes(m.problemasDaSecao('tipos'), schema).tipos.faltam;
    const sim = m.simulacao ?? null;
    const editaveis = alvos.filter((a) => ! (a.mlb_na_regua ?? null));
    const [destino, setDestino] = useState(null);
    const destinoValido = editaveis.some((a) => a.listing_type_id === destino) ? destino : (editaveis.find((a) => a.ativo) ?? editaveis[0])?.listing_type_id ?? null;
    const termos = (m.termos?.dados?.termos ?? []).map((t) => t.termo);

    /** Acrescenta o termo no título do tipo escolhido, partindo do título planejado quando ainda não há digitado. */
    const usarTermo = (termo) => m.mudarRasc((r) => ({
        alvos: r.alvos.map((x) => {
            if (x.listing_type_id !== destinoValido) return x;
            const base = x.titulo || alvos.find((a) => a.listing_type_id === x.listing_type_id)?.titulo_efetivo || '';

            return { ...x, titulo: juntarTermo(base, termo) };
        }),
    }));

    return (
        <CardMesa id="card-tipos" icone={Tag} titulo="Clássico e Premium" chip={<ChipSecao faltam={faltam} />} aberto={aberto} onAlternar={onAlternar}
            apoio="Os dois tipos de anúncio saem com o mesmo SKU nas duas vitrines.">
            {! schema ? (
                <p className="text-[13px] text-white/55">Escolha a categoria para definir os títulos.</p>
            ) : (
                <div className="space-y-4">
                    <div className="grid gap-4 md:grid-cols-2">
                        {alvos.map((a) => (
                            <BlocoDoTipo key={a.listing_type_id} m={m} a={a} maxTitulo={maxTitulo} termos={termos}
                                outro={alvos.find((x) => x.listing_type_id !== a.listing_type_id)} />
                        ))}
                    </div>

                    {editaveis.length > 0 && (
                        <TermosMaisBuscados m={m} alvos={editaveis} destino={destinoValido} onDestino={setDestino} onUsar={usarTermo} />
                    )}

                    <div className="flex flex-wrap items-center gap-3">
                        <Botao onClick={() => m.simular()} disabled={m.simulando || ! schema} data-acao="simular">
                            {m.simulando ? <Loader2 size={14} className="animate-spin" /> : <Calculator size={14} />} Quanto eu recebo?
                        </Botao>
                        {sim && Object.entries(sim).map(([lt, s]) => (
                            <span key={lt} className="rounded-lg border border-white/[0.08] bg-ecf-card px-3 py-1.5 font-mono text-[11px] tabular-nums text-white/70" data-simulacao={lt}>
                                <b className="font-sans text-[11px] font-bold text-white">{NOME_TIPO[lt]}</b>: {fmtReais(s.preco)} − tarifa {fmtReais(s.tarifa)}{s.frete_conhecido ? ` − frete ${fmtReais(s.frete)}` : ''} = <b className="font-bold text-emerald-400">{fmtReais(s.voce_recebe)}</b>
                                {! s.frete_conhecido && <span className="font-sans text-white/40"> (informe a embalagem para o frete)</span>}
                            </span>
                        ))}
                    </div>
                </div>
            )}
        </CardMesa>
    );
}
