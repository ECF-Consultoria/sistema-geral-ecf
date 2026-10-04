import { CheckCircle2, Trash2 } from 'lucide-react';
import CampoAtributo, { RotuloAtributo } from '../CampoAtributo';
import { BlocoDeFotos, fotosDoGrupo } from '../FotosPorGrupo';
import { CampoEstoque, CampoGtin, CampoSku, atributosExtrasDaVariante } from '../GradeVariantes';
import { valorVazio } from '../apoio';
import { eanValido, gtinsEmUso } from '../ferramentas';
import { Campo, useErroDoCampo } from './comum';
import { cn } from '@/lib/utils';

// ─── Uma variação, como no Mercado Livre (etapa Detalhes, 04/10/2026) ───────
//
// O bloco da variação: o nome (Cor: Azul…), as fotos dela (o grupo vem do
// servidor), estoque, SKU, código universal e o que mais a categoria pede POR
// variação. O preço fica em "Condições de venda", com os tipos de anúncio; o
// título é por TIPO, na etapa Produto. Estoque, SKU e código vêm de
// GradeVariantes (a lógica de depósito não é duplicada).
//
// Os erros só aparecem depois do "Continuar" (ver `useErroDoCampo`).

/** Cor da bolinha: só se o eixo for de cor e o valor trouxer um hex; senão nada. */
export function corDaVariante(v, eixos) {
    const eixo = (eixos ?? []).find((e) => e.chave === 'COLOR');
    if (! eixo) return null;
    const nome = String(v.valores?.COLOR?.nome ?? '').toLowerCase();
    const valor = eixo.valores.find((x) => String(x.nome).toLowerCase() === nome);
    const hex = valor?.hex ?? valor?.rgb ?? null;

    return typeof hex === 'string' && /^#?[0-9a-f]{3,8}$/i.test(hex) ? (hex.startsWith('#') ? hex : `#${hex}`) : null;
}

/** Atributo extra da variação (seção VARIANTE): o mesmo campo da ficha, gravado na variação. */
function CampoExtra({ v, a, travada, onMudar }) {
    const valor = v.atributos?.[a.id] ?? null;
    const id = `extra-${a.id}-${v.chave}`;
    const vazio = a.obrigatoriedade === 'REQUIRED' && valorVazio(valor);
    const erro = useErroDoCampo((x) => x.variante === v.chave && x.atributo === a.id, { vazio });
    const mudar = (novo) => {
        const atributos = { ...(v.atributos ?? {}) };
        if (novo === null) delete atributos[a.id]; else atributos[a.id] = novo;
        onMudar(v.chave, { atributos });
    };

    return (
        <Campo rotulo={<RotuloAtributo atributo={a} valor={valor} />} htmlFor={id} erro={erro}>
            <CampoAtributo variante="campo" id={id} atributo={a} valor={valor} disabled={travada} invalido={!! erro} onChange={mudar} />
        </Campo>
    );
}

/**
 * `grupo` = a chave do grupo de fotos da variação (nulo enquanto o servidor ainda não o deu);
 * `fotosCom` = as outras variações que dividem as mesmas fotos (ex.: Preto/P e Preto/M);
 * `onTirar` = tirar a variação (nulo quando não dá).
 */
export default function CartaoVariante({ m, v, eixos, grupo = null, fotosCom = [], onTirar = null }) {
    const { estado, schema } = m;
    const travada = m.disabled || v.publicada;
    const extras = atributosExtrasDaVariante(schema);
    const limites = schema?.limites ?? {};
    const semVariacao = Object.keys(v.valores ?? {}).length === 0;
    const gtin = v.atributos?.GTIN?.value_name ?? '';
    const motivo = v.atributos?.EMPTY_GTIN_REASON?.value_id ?? null;
    const sku = v.atributos?.SELLER_SKU?.value_name ?? '';
    const multi = !! estado.conta?.multi_deposito;
    const fotos = grupo ? fotosDoGrupo(estado.imagens, estado.atribuicoes, grupo).length : 0;
    const cor = corDaVariante(v, eixos);
    const nome = semVariacao ? 'Produto' : eixos.filter((e) => v.valores?.[e.chave]).map((e) => `${e.nome}: ${v.valores[e.chave].nome}`).join(' · ');
    const ativa = v.ativa;

    // Só depois do "Continuar" (e só na variação que vai ao anúncio).
    const erroFotos = useErroDoCampo((x) => !! grupo && x.grupo === grupo, { vazio: ativa && !! grupo && fotos === 0 });
    const erroEstoque = useErroDoCampo((x) => ativa && x.variante === v.chave && x.campo === 'estoque', { vazio: ativa && ! multi && (v.estoque ?? null) === null });
    const erroSku = useErroDoCampo((x) => ativa && x.variante === v.chave && x.campo === 'sku', { vazio: ativa && ! sku });
    const erroGtin = useErroDoCampo((x) => ativa && x.variante === v.chave && ['GTIN', 'EMPTY_GTIN_REASON'].includes(x.atributo), { preenchido: !! gtin || !! motivo });

    return (
        <article className={cn('rounded-xl border p-5 max-sm:p-4', ativa ? 'border-white/[0.12] bg-white/[0.02]' : 'border-white/[0.08] bg-transparent')} data-cartao-variante={v.chave}>
            <header className="mb-5 flex flex-wrap items-center justify-between gap-3">
                <h3 className={cn('flex min-w-0 items-center gap-2.5 text-[15px] font-bold', ativa ? 'text-white' : 'text-white/50')}>
                    {cor && <span className="h-4 w-4 shrink-0 rounded-full border border-white/25" style={{ backgroundColor: cor }} aria-hidden="true" />}
                    <span className="min-w-0">{nome}</span>
                    {v.publicada && <span className="inline-flex items-center gap-1 text-[13px] font-normal text-emerald-400"><CheckCircle2 size={14} aria-hidden="true" /> publicada</span>}
                </h3>
                {! semVariacao && ! travada && (
                    <div className="flex items-center gap-4">
                        <label className="flex cursor-pointer items-center gap-2 text-[13px] text-white/75">
                            <input type="checkbox" role="switch" checked={ativa} onChange={(e) => m.mudarVar(v.chave, { ativa: e.target.checked })}
                                className="h-4 w-4 rounded border-white/40 bg-transparent text-ecf-yellow focus-visible:ring-2 focus-visible:ring-ecf-yellow" data-acao="alternar-variacao" />
                            Vender esta variação
                        </label>
                        {onTirar && (
                            <button type="button" onClick={onTirar} title="Tirar esta variação (os dados ficam guardados se ela voltar)" aria-label={`Tirar a variação ${v.rotulo}`} data-acao="excluir-variacao"
                                className="grid h-9 w-9 place-items-center rounded-lg text-white/55 hover:bg-red-500/10 hover:text-red-300 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow">
                                <Trash2 size={16} aria-hidden="true" />
                            </button>
                        )}
                    </div>
                )}
            </header>

            {! ativa ? (
                <p className="text-[13px] text-white/45">Fora do anúncio. Marque "Vender esta variação" para preencher fotos, estoque e código.</p>
            ) : (
                <div className="space-y-5">
                    {grupo ? (
                        <BlocoDeFotos grupo={grupo} titulo={semVariacao ? 'Fotos do produto' : 'Fotos'} erro={erroFotos}
                            nota={fotosCom.length ? `as mesmas de ${fotosCom.join(', ')}` : null}
                            imagens={estado.imagens} atribuicoes={estado.atribuicoes}
                            maxFotos={limites.max_pictures_per_item_var ?? limites.max_pictures_per_item ?? 10}
                            minimo={limites.min_pictures ?? limites.recommended_pictures ?? null}
                            enviando={m.enviandoFoto} disabled={travada} envioAoMl={estado.publicacao_liberada === true}
                            onArquivos={m.enviarFotos} onAtribuicoes={m.atribuirFotos} onExcluir={m.removerFoto} onReenviar={m.reenviarFoto} />
                    ) : (
                        <p className="rounded-lg border border-white/20 bg-black/40 p-4 text-[13px] text-white/45" data-fotos-variante={v.chave}>Preparando as fotos desta variação…</p>
                    )}

                    <div className="grid gap-x-6 gap-y-5 sm:grid-cols-2 xl:grid-cols-3">
                        <Campo rotulo={multi ? 'Estoque por depósito' : 'Estoque'} htmlFor={`estoque-${v.chave}`} erro={erroEstoque}>
                            <CampoEstoque grande id={`estoque-${v.chave}`} invalido={!! erroEstoque} v={v} conta={estado.conta} travada={travada} onMudar={m.mudarVar} />
                        </Campo>
                        <Campo rotulo="SKU" htmlFor={`sku-${v.chave}`} erro={erroSku} dica={erroSku ? null : 'Código seu para este item; não se repete entre variações.'}>
                            <CampoSku grande id={`sku-${v.chave}`} invalido={!! erroSku} v={v} travada={travada} onMudar={m.mudarVar} />
                        </Campo>
                        {schema?.atributos?.GTIN && (
                            <Campo rotulo="Código universal (EAN)" htmlFor={`gtin-${v.chave}`} erro={erroGtin}
                                extra={gtin ? <span className={cn('text-[13px]', eanValido(gtin) ? 'text-emerald-400' : 'text-white/45')} data-ean-valido={eanValido(gtin) ? 'sim' : 'nao'}>{eanValido(gtin) ? 'válido' : `${gtin.length} dígitos`}</span> : null}>
                                <CampoGtin grande comRotulo id={`gtin-${v.chave}`} invalido={!! erroGtin} v={v} schema={schema} travada={travada} onMudar={m.mudarVar} existentes={gtinsEmUso(m.variantes)} />
                            </Campo>
                        )}
                        {extras.map((a) => <CampoExtra key={a.id} v={v} a={a} travada={travada} onMudar={m.mudarVar} />)}
                    </div>
                </div>
            )}
        </article>
    );
}
