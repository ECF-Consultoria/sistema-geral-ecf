import { CheckCircle2, Trash2 } from 'lucide-react';
import { BlocoDeFotos } from '../FotosPorGrupo';
import { AtributosExtrasDaVariante, CampoEstoque, CampoGtin, CampoSku, atributosExtrasDaVariante } from '../GradeVariantes';
import { gtinsEmUso } from '../ferramentas';
import { cn } from '@/lib/utils';

// ─── Um cartão por variação, como no Mercado Livre (Q-UI-10/11/16) ──────────
//
// Desde 03/10/2026 o cartão traz as FOTOS da variação (o grupo dela vem do
// servidor), depois estoque, código universal e SKU. Estoque, SKU e código vêm
// de GradeVariantes (a lógica de depósito não é duplicada). O título é por TIPO
// de anúncio e o preço, por variação e tipo — os dois na etapa "Título e preço",
// nunca aqui.

/** Cor da bolinha: só se o eixo for de cor e o valor trouxer um hex; senão nada. */
function corDaVariante(v, eixos) {
    const eixo = (eixos ?? []).find((e) => e.chave === 'COLOR');
    if (! eixo) return null;
    const nome = String(v.valores?.COLOR?.nome ?? '').toLowerCase();
    const valor = eixo.valores.find((x) => String(x.nome).toLowerCase() === nome);
    const hex = valor?.hex ?? valor?.rgb ?? null;

    return typeof hex === 'string' && /^#?[0-9a-f]{3,8}$/i.test(hex) ? (hex.startsWith('#') ? hex : `#${hex}`) : null;
}

const ROTULO = 'mb-1 block text-[11px] font-bold uppercase tracking-[0.05em] text-white/40';

/**
 * `grupo` = a chave do grupo de fotos da variação (nulo enquanto o servidor ainda não o deu);
 * `fotosCom` = as outras variações que dividem as mesmas fotos (ex.: Preto/P e Preto/M);
 * `onRemover` = tirar a variação (nulo = não dá: publicada, ou o produto sem variação).
 */
export default function CartaoVariante({ m, v, indice, eixos, grupo = null, fotosCom = [], onRemover = null }) {
    const { estado, schema } = m;
    const travada = m.disabled || v.publicada;
    const cor = corDaVariante(v, eixos);
    const sku = v.atributos?.SELLER_SKU?.value_name;
    const gtin = v.atributos?.GTIN?.value_name;
    const extras = atributosExtrasDaVariante(schema);
    const limites = schema?.limites ?? {};
    const semVariacao = Object.keys(v.valores ?? {}).length === 0;

    return (
        <div className={cn('rounded-xl border border-white/[0.08] bg-white/[0.02] p-4', ! v.ativa && 'opacity-60')} data-cartao-variante={v.chave}>
            <div className="mb-4 flex flex-wrap items-center gap-2">
                {cor && <span className="h-3 w-3 rounded-full border border-white/20" style={{ backgroundColor: cor }} aria-hidden="true" />}
                <h3 className="text-[15px] font-bold text-white">{semVariacao ? 'Produto (sem variação)' : `Variação ${indice}: ${v.rotulo}`}</h3>
                {v.publicada && <span className="inline-flex items-center gap-1 text-[11px] text-emerald-300"><CheckCircle2 size={11} /> publicada</span>}
                {sku && <span className="rounded bg-white/[0.05] px-1.5 py-px font-mono text-[11px] text-white/60">SKU {sku}</span>}
                {gtin && <span className="rounded bg-white/[0.05] px-1.5 py-px font-mono text-[11px] text-white/60">GTIN {gtin}</span>}
                <span className="ml-auto flex items-center gap-2">
                    {! semVariacao && (
                        <label className="inline-flex cursor-pointer items-center gap-2 text-[13px] text-white/70">
                            <input type="checkbox" role="switch" checked={v.ativa} disabled={travada} onChange={(e) => m.mudarVar(v.chave, { ativa: e.target.checked })}
                                className="rounded border-white/20 bg-transparent text-ecf-yellow focus-visible:ring-2 focus-visible:ring-ecf-yellow" aria-label={`Vender ${v.rotulo}`} data-ativa={v.chave} />
                            Ativa
                        </label>
                    )}
                    {onRemover && ! travada && (
                        <button type="button" onClick={onRemover} aria-label={`Tirar a variação ${v.rotulo}`} title="Tirar esta variação (os dados ficam guardados se ela voltar)" data-remover-variacao={v.chave}
                            className="grid h-8 w-8 place-items-center rounded-lg text-white/55 hover:bg-white/[0.05] hover:text-red-300 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow">
                            <Trash2 size={14} />
                        </button>
                    )}
                </span>
            </div>

            {grupo ? (
                <BlocoDeFotos grupo={grupo} titulo="Fotos" obrigatorio={v.ativa}
                    nota={fotosCom.length ? `as mesmas de ${fotosCom.join(', ')}` : null}
                    imagens={estado.imagens} atribuicoes={estado.atribuicoes}
                    maxFotos={limites.max_pictures_per_item_var ?? limites.max_pictures_per_item ?? 10}
                    minimo={limites.min_pictures ?? limites.recommended_pictures ?? null}
                    enviando={m.enviandoFoto} disabled={travada} envioAoMl={estado.publicacao_liberada === true}
                    onArquivos={m.enviarFotos} onAtribuicoes={m.atribuirFotos} onExcluir={m.removerFoto} onReenviar={m.reenviarFoto} />
            ) : (
                <p className="rounded-[10px] border border-white/[0.08] bg-white/[0.02] p-3 text-[13px] text-white/45" data-fotos-variante={v.chave}>
                    {v.ativa ? 'Preparando as fotos desta variação…' : 'Variação desativada: ative para cuidar das fotos dela.'}
                </p>
            )}

            <div className="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <div>
                    <span className={ROTULO}>{estado.conta?.multi_deposito ? 'Estoque por depósito' : 'Estoque'}</span>
                    <CampoEstoque v={v} conta={estado.conta} travada={travada} onMudar={m.mudarVar} />
                </div>
                {schema?.atributos?.GTIN && (
                    <div>
                        <span className={ROTULO}>Código universal</span>
                        <CampoGtin v={v} schema={schema} travada={travada} onMudar={m.mudarVar} className="w-full" existentes={gtinsEmUso(m.variantes)} />
                    </div>
                )}
                <div>
                    <span className={ROTULO}>SKU</span>
                    <CampoSku v={v} travada={travada} onMudar={m.mudarVar} className="w-full" />
                </div>
                <AtributosExtrasDaVariante v={v} extras={extras} travada={travada} onMudar={m.mudarVar} />
            </div>
        </div>
    );
}
