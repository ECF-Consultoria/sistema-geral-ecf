import { NOME_TIPO, itemDaVariante } from '../apoio';
import CampoPreco from './CampoPreco';
import { ROTULO } from './comum';
import { cn } from '@/lib/utils';

// ─── Item "Preços e taxas": uma linha por variação, uma coluna por tipo ──────
//
// O MESMO campo do item da variação (`CampoPreco`, mesmo estado). As tiradas
// ficam de fora; a desativada aparece apagada. A tarifa e o frete ("Quanto eu
// recebo?") ficam no Inspetor, com `m.simular`. Preço do Portal é MOSTRADO,
// não gravado (docx §4; `16` §1.6).

export default function CardPrecos({ m, onSelecionar }) {
    const alvos = m.alvos ?? [];
    const variantes = m.variantes.filter((v) => ! v.orfa);
    const comPortal = !! m.estado.produto?.oferta_id;
    const alvosAtivos = alvos.filter((a) => a.ativo);
    const algumDoPortal = comPortal && variantes.some((v) => alvosAtivos.some((a) => (v.precos?.[a.listing_type_id] ?? null) === null && (v.precos_efetivos?.[a.listing_type_id] ?? null) !== null));
    // Colunas da grade (classes estáticas, para o Tailwind gerar): a variação ocupa o que sobra; cada tipo, 150–240px.
    const colunas = alvos.length > 1
        ? 'sm:grid-cols-[minmax(0,1fr)_minmax(150px,240px)_minmax(150px,240px)]'
        : 'sm:grid-cols-[minmax(0,1fr)_minmax(150px,240px)]';

    if (! m.schema) return <p className="text-[13px] text-white/55">Escolha a categoria para definir os preços.</p>;
    if (alvos.length === 0) return <p className="text-[13px] text-white/55">Sem tipo de anúncio ligado: ligue o Clássico ou o Premium em "Títulos".</p>;

    return (
        <div className="space-y-4">
            <div className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-4" data-tabela-precos={variantes.length}>
                <div className={cn('hidden gap-x-4 sm:grid', colunas)} aria-hidden="true">
                    <span className={ROTULO}>Variação</span>
                    {alvos.map((a) => <span key={a.listing_type_id} className={ROTULO}>Preço {NOME_TIPO[a.listing_type_id]}{! a.ativo ? ' (desligado)' : ''}</span>)}
                </div>
                <ul className="divide-y divide-white/[0.06]">
                    {variantes.map((v) => {
                        const semVariacao = Object.keys(v.valores ?? {}).length === 0;
                        const travada = m.disabled || v.publicada || ! v.ativa;
                        const nome = semVariacao ? 'Produto (sem variação)' : v.rotulo;

                        return (
                            <li key={v.chave} className={cn('grid gap-x-4 gap-y-2 py-3 max-sm:grid-cols-2 sm:items-start', colunas, ! v.ativa && 'opacity-60')} data-linha-preco={v.chave}>
                                <div className="min-w-0 max-sm:col-span-2">
                                    <button type="button" onClick={() => onSelecionar?.(itemDaVariante(v.chave))} className="max-w-full truncate rounded text-left text-[13px] font-bold text-white underline-offset-4 hover:text-ecf-yellow hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow" title={`Abrir ${nome}`}>
                                        {nome}
                                    </button>
                                    <p className="truncate text-[11px] text-white/45">
                                        {v.atributos?.SELLER_SKU?.value_name ? <span className="font-mono">SKU {v.atributos.SELLER_SKU.value_name}</span> : null}
                                        {! v.ativa && <span> · desativada</span>}
                                        {v.publicada && <span> · publicada</span>}
                                    </p>
                                </div>
                                {alvos.map((a) => (
                                    <div key={a.listing_type_id}>
                                        <span className={cn(ROTULO, 'sm:hidden')}>Preço {NOME_TIPO[a.listing_type_id]}</span>
                                        <CampoPreco valor={v.precos?.[a.listing_type_id] ?? null} efetivo={v.precos_efetivos?.[a.listing_type_id] ?? null}
                                            disabled={travada || ! a.ativo} chave={v.chave} tipo={a.listing_type_id} comPortal={comPortal}
                                            rotulo={`Preço ${NOME_TIPO[a.listing_type_id]} de ${semVariacao ? 'produto sem variação' : v.rotulo}`}
                                            onMudar={(num) => m.mudarVar(v.chave, { precos: { ...(v.precos ?? {}), [a.listing_type_id]: num } })} />
                                    </div>
                                ))}
                            </li>
                        );
                    })}
                </ul>
                {algumDoPortal && (
                    <p className="mt-2 text-[11px] text-white/40">O preço marcado "do Portal" vem da Precificação e acompanha as mudanças de lá; digite outro valor para trocar só aqui.</p>
                )}
            </div>
            <p className="text-[13px] text-white/55">A tarifa do Mercado Livre e o frete estão em "Quanto eu recebo?", no Inspetor.</p>
        </div>
    );
}
