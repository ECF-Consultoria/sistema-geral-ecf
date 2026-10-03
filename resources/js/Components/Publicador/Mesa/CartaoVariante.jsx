import { useEffect, useState } from 'react';
import { CheckCircle2, Trash2 } from 'lucide-react';
import { CLASSE_INPUT } from '@/Components/Portal/Estrutura/comum';
import { BlocoDeFotos } from '../FotosPorGrupo';
import { AtributosExtrasDaVariante, CampoEstoque, CampoGtin, CampoSku, atributosExtrasDaVariante } from '../GradeVariantes';
import { NOME_TIPO, paraNumero, paraTexto } from '../apoio';
import { gtinsEmUso } from '../ferramentas';
import { cn } from '@/lib/utils';

// ─── Um cartão por variação, como no Mercado Livre (Q-UI-10/11/16) ──────────
//
// Desde 03/10/2026 o cartão traz as FOTOS da variação (o grupo dela vem do
// servidor), depois estoque, código universal, SKU e preços. Estoque, SKU e
// código vêm de GradeVariantes (a lógica de depósito não é duplicada). O
// título é por TIPO de anúncio (card Clássico e Premium), nunca por variante.

/**
 * Preço de uma variante num tipo. Sem preço digitado, o campo MOSTRA o preço importado da
 * Precificação do Portal (docx §4) — mas não o grava: o rascunho segue lendo a Precificação na
 * hora de conferir e publicar, para o preço não congelar (`16` §1.6). Digitar outro valor
 * sobrepõe; "usar o do Portal" volta a seguir a Precificação.
 */
function CampoPreco({ valor, efetivo, disabled, onMudar, chave, tipo, comPortal }) {
    const temValor = valor !== null && valor !== undefined;
    const temEfetivo = efetivo !== null && efetivo !== undefined;
    const [texto, setTexto] = useState(paraTexto(temValor ? valor : efetivo));
    useEffect(() => setTexto(paraTexto(temValor ? valor : efetivo)), [valor, efetivo]); // eslint-disable-line react-hooks/exhaustive-deps
    const falta = ! temValor && ! temEfetivo;
    const doPortal = ! temValor && temEfetivo;

    const sair = () => {
        const n = paraNumero(texto);
        // Igual ao do Portal (ou apagado) = continua seguindo a Precificação.
        if (! temValor && (n === null || (temEfetivo && n === Number(efetivo)))) {
            setTexto(paraTexto(efetivo));

            return;
        }
        onMudar(n);
    };

    return (
        <div>
            <div className="relative">
                <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 font-mono text-[11px] font-bold text-white/40">R$</span>
                <input value={texto} onChange={(e) => setTexto(e.target.value)} onBlur={sair} disabled={disabled} inputMode="decimal" placeholder="0,00"
                    className={cn(CLASSE_INPUT, 'py-1.5 pl-9 font-mono text-[13px] tabular-nums disabled:opacity-60', doPortal && 'pr-24', falta && ! disabled && 'border-amber-400/50')}
                    data-preco={`${chave}|${tipo}`} data-preco-origem={doPortal ? 'portal' : (temValor ? 'digitado' : 'vazio')} />
                {doPortal && (
                    <span className="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 rounded bg-emerald-500/10 px-1.5 py-px text-[11px] font-bold text-emerald-400">do Portal</span>
                )}
            </div>
            {temValor && temEfetivo && Number(valor) !== Number(efetivo) && ! disabled && (
                <button type="button" onClick={() => onMudar(null)} data-preco-voltar={`${chave}|${tipo}`}
                    className="mt-1 text-[11px] text-white/55 underline underline-offset-2 hover:text-ecf-yellow focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow">
                    usar o do Portal ({paraTexto(efetivo)})
                </button>
            )}
            {falta && comPortal && <p className="mt-1 text-[11px] text-amber-300">A Precificação do Portal não tem preço para esta oferta. Preencha lá ou digite aqui.</p>}
        </div>
    );
}

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
export default function CartaoVariante({ m, v, indice, eixos, alvosAtivos, grupo = null, fotosCom = [], onRemover = null }) {
    const { estado, schema } = m;
    const travada = m.disabled || v.publicada;
    const cor = corDaVariante(v, eixos);
    const sku = v.atributos?.SELLER_SKU?.value_name;
    const gtin = v.atributos?.GTIN?.value_name;
    const extras = atributosExtrasDaVariante(schema);
    const limites = schema?.limites ?? {};
    const alvos = m.alvos ?? [];
    const semVariacao = Object.keys(v.valores ?? {}).length === 0;

    return (
        <div className={cn('rounded-xl border border-white/[0.08] bg-white/[0.02] p-4', ! v.ativa && 'opacity-60')} data-cartao-variante={v.chave}>
            <div className="mb-4 flex flex-wrap items-center gap-2">
                {cor && <span className="h-3 w-3 rounded-full border border-white/20" style={{ backgroundColor: cor }} aria-hidden="true" />}
                <h4 className="text-[13px] font-bold text-white">{semVariacao ? 'Produto (sem variação)' : `Variação ${indice}: ${v.rotulo}`}</h4>
                {v.publicada && <span className="inline-flex items-center gap-1 text-[11px] text-emerald-300"><CheckCircle2 size={11} /> publicada</span>}
                {sku && <span className="rounded bg-white/[0.05] px-1.5 py-px font-mono text-[11px] text-white/60">SKU {sku}</span>}
                {gtin && <span className="rounded bg-white/[0.05] px-1.5 py-px font-mono text-[11px] text-white/60">GTIN {gtin}</span>}
                <span className="ml-auto flex items-center gap-2">
                    {! semVariacao && (
                        <label className="inline-flex cursor-pointer items-center gap-2 text-[13px] text-white/70">
                            <input type="checkbox" role="switch" checked={v.ativa} disabled={travada} onChange={(e) => m.mudarVar(v.chave, { ativa: e.target.checked })}
                                className="rounded border-white/20 bg-transparent text-ecf-yellow" aria-label={`Vender ${v.rotulo}`} data-ativa={v.chave} />
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

            <div className="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
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

            {alvos.length > 0 && (
                <div className="mt-4 grid gap-4 md:grid-cols-2">
                    {alvos.map((a) => (
                        <div key={a.listing_type_id}>
                            <span className={ROTULO}>Preço {NOME_TIPO[a.listing_type_id]}</span>
                            <CampoPreco valor={v.precos?.[a.listing_type_id] ?? null} efetivo={v.precos_efetivos?.[a.listing_type_id] ?? null}
                                disabled={travada || ! a.ativo} chave={v.chave} tipo={a.listing_type_id} comPortal={!! estado.produto?.oferta_id}
                                onMudar={(num) => m.mudarVar(v.chave, { precos: { ...(v.precos ?? {}), [a.listing_type_id]: num } })} />
                        </div>
                    ))}
                </div>
            )}
            {estado.produto?.oferta_id && alvosAtivos.some((a) => (v.precos?.[a.listing_type_id] ?? null) === null && (v.precos_efetivos?.[a.listing_type_id] ?? null) !== null) && (
                <p className="mt-2 text-[11px] text-white/40">O preço marcado "do Portal" vem da Precificação e acompanha as mudanças de lá; digite outro valor para trocar só aqui.</p>
            )}
        </div>
    );
}
