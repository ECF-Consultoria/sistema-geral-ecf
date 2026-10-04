import { CheckCircle2 } from 'lucide-react';
import { BlocoDeFotos } from '../FotosPorGrupo';
import { AtributosExtrasDaVariante, CampoEstoque, CampoGtin, CampoSku, atributosExtrasDaVariante } from '../GradeVariantes';
import { NOME_TIPO, NOTA_TIPO } from '../apoio';
import { eanValido, gtinsEmUso } from '../ferramentas';
import CampoPreco from './CampoPreco';
import { ROTULO } from './comum';
import { cn } from '@/lib/utils';

// ─── O item de UMA variação no centro da mesa (Conceito E, 03/10/2026) ──────
//
// Como no Mercado Livre: a variação com as próprias fotos (o grupo dela vem do
// servidor), depois estoque, SKU, código universal (com "gerar outro") e o
// preço de venda em cada tipo de anúncio — o MESMO campo da tabela "Preços e
// taxas". Estoque, SKU e código vêm de GradeVariantes (a lógica de depósito não
// é duplicada). O título é por TIPO de anúncio (item "Títulos"), nunca aqui.
// O cabeçalho (nome, Desativar, Excluir) é do `ItemDoCentro`.

/** Cor da bolinha: só se o eixo for de cor e o valor trouxer um hex; senão nada. */
export function corDaVariante(v, eixos) {
    const eixo = (eixos ?? []).find((e) => e.chave === 'COLOR');
    if (! eixo) return null;
    const nome = String(v.valores?.COLOR?.nome ?? '').toLowerCase();
    const valor = eixo.valores.find((x) => String(x.nome).toLowerCase() === nome);
    const hex = valor?.hex ?? valor?.rgb ?? null;

    return typeof hex === 'string' && /^#?[0-9a-f]{3,8}$/i.test(hex) ? (hex.startsWith('#') ? hex : `#${hex}`) : null;
}

/**
 * `grupo` = a chave do grupo de fotos da variação (nulo enquanto o servidor ainda não o deu);
 * `fotosCom` = as outras variações que dividem as mesmas fotos (ex.: Preto/P e Preto/M).
 */
export default function CartaoVariante({ m, v, eixos, grupo = null, fotosCom = [] }) {
    const { estado, schema } = m;
    const travada = m.disabled || v.publicada;
    const extras = atributosExtrasDaVariante(schema);
    const limites = schema?.limites ?? {};
    const alvos = m.alvos ?? [];
    const semVariacao = Object.keys(v.valores ?? {}).length === 0;
    const gtin = v.atributos?.GTIN?.value_name ?? '';
    const comPortal = !! estado.produto?.oferta_id;

    return (
        <div className="space-y-6" data-cartao-variante={v.chave}>
            {/* Identificação: o valor desta variação em cada eixo (muda-se pelos eixos, em "Variações"). */}
            {! semVariacao && (
                <div data-identificacao>
                    <span className={ROTULO}>Identificação</span>
                    <div className="flex flex-wrap items-center gap-2">
                        {eixos.map((e) => {
                            const valor = v.valores?.[e.chave];
                            if (! valor) return null;
                            const cor = e.chave === 'COLOR' ? corDaVariante(v, eixos) : null;

                            return (
                                <span key={e.chave} className="inline-flex items-center gap-2 rounded-lg border border-white/[0.08] bg-white/[0.03] px-3 py-1.5 text-[13px] text-white/80" data-eixo-valor={e.chave}>
                                    {cor && <span className="h-3 w-3 rounded-full border border-white/20" style={{ backgroundColor: cor }} aria-hidden="true" />}
                                    <span className="text-white/45">{e.nome}:</span> <span className="font-bold text-white">{valor.nome}</span>
                                </span>
                            );
                        })}
                        {v.publicada && <span className="inline-flex items-center gap-1 text-[11px] text-emerald-300"><CheckCircle2 size={11} /> publicada no Mercado Livre</span>}
                    </div>
                </div>
            )}

            {grupo ? (
                <BlocoDeFotos grupo={grupo} titulo={semVariacao ? 'Fotos do produto' : `Fotos da variação ${v.rotulo}`} obrigatorio={v.ativa}
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

            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <div>
                    <span className={ROTULO}>{estado.conta?.multi_deposito ? 'Estoque por depósito' : 'Estoque'}</span>
                    <CampoEstoque v={v} conta={estado.conta} travada={travada} onMudar={m.mudarVar} />
                </div>
                <div>
                    <span className={ROTULO}>SKU</span>
                    <CampoSku v={v} travada={travada} onMudar={m.mudarVar} className="w-full" />
                </div>
                <AtributosExtrasDaVariante v={v} extras={extras} travada={travada} onMudar={m.mudarVar} />
            </div>

            {schema?.atributos?.GTIN && (
                <div className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-4" data-codigo-universal>
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <span className={cn(ROTULO, 'mb-0')}>Código universal (EAN / GTIN)</span>
                        {gtin && (
                            <span className={cn('text-[11px] font-bold', eanValido(gtin) ? 'text-emerald-400' : 'text-white/45')} data-ean-valido={eanValido(gtin) ? 'sim' : 'nao'}>
                                {eanValido(gtin) ? 'EAN-13 válido' : `${gtin.length} dígitos`}
                            </span>
                        )}
                    </div>
                    <div className="mt-2">
                        <CampoGtin v={v} schema={schema} travada={travada} onMudar={m.mudarVar} className="w-full" existentes={gtinsEmUso(m.variantes)} comRotulo />
                    </div>
                    <p className="mt-2 text-[11px] text-white/40">Nasce sozinho em toda variação sem código; "gerar outro" cria um EAN-13 novo que não repete os das outras variações.</p>
                </div>
            )}

            {alvos.length > 0 && (
                <div data-precos-da-variante>
                    <span className={ROTULO}>Preço de venda da variação</span>
                    <div className="grid gap-4 md:grid-cols-2">
                        {alvos.map((a) => (
                            <div key={a.listing_type_id} className={cn('rounded-xl border border-white/[0.08] bg-white/[0.02] p-4', ! a.ativo && 'opacity-60')} data-preco-tipo={a.listing_type_id}>
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <span className="text-[13px] font-bold text-white">Anúncio {NOME_TIPO[a.listing_type_id]}</span>
                                    <span className="text-[11px] text-white/40">{a.ativo ? NOTA_TIPO[a.listing_type_id] : 'Desligado em Títulos'}</span>
                                </div>
                                <CampoPreco className="mt-2" valor={v.precos?.[a.listing_type_id] ?? null} efetivo={v.precos_efetivos?.[a.listing_type_id] ?? null}
                                    disabled={travada || ! a.ativo || ! v.ativa} chave={v.chave} tipo={a.listing_type_id} comPortal={comPortal}
                                    rotulo={`Preço ${NOME_TIPO[a.listing_type_id]} de ${semVariacao ? 'produto sem variação' : v.rotulo}`}
                                    onMudar={(num) => m.mudarVar(v.chave, { precos: { ...(v.precos ?? {}), [a.listing_type_id]: num } })} />
                            </div>
                        ))}
                    </div>
                    {comPortal && <p className="mt-2 text-[11px] text-white/40">O preço marcado "do Portal" vem da Precificação e acompanha as mudanças de lá; digite outro valor para trocar só aqui. A tarifa e o frete estão em "Quanto eu recebo?", no Inspetor.</p>}
                </div>
            )}
        </div>
    );
}
