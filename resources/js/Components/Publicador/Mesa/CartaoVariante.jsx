import { CheckCircle2, Trash2 } from 'lucide-react';
import CampoAtributo, { RotuloAtributo } from '../CampoAtributo';
import { CampoEstoque, CampoGtin, CampoSku, atributosExtrasDaVariante } from '../GradeVariantes';
import { valorVazio } from '../apoio';
import { eanValido, gtinsEmUso, nomeDaCor } from '../ferramentas';
import { CampoCorPrincipal, ondeFicaOTom } from './CorPrincipal';
import { Campo, useErroDoCampo } from './comum';
import { cn } from '@/lib/utils';

// ─── Os DADOS de uma variação (etapa Detalhes, D1, Fase 169, 07/10/2026) ────
//
// Estoque, SKU, código universal e o que mais a categoria pede POR variação —
// aqui, NUNCA na etapa Imagens. Até 07/10 estes campos e as fotos da variação
// moravam no MESMO cartão (`FotosEVariacoes`/`CartaoVariante` de antes), e
// quando "Fotos e variações" ganhou etapa própria (169-04) eles foram junto
// por engano — o usuário relatou em produção: "levou alguns campos que não
// devia [...] a etapa Imagens é para ter apenas as imagens". As FOTOS agora
// moram só em `CartaoFotosVariante.jsx` (etapa Imagens); este cartão é
// dados-apenas, em Detalhes, como primeira seção (`DadosDasVariacoes.jsx`).
//
// `CabecalhoVariante` é o cabeçalho comum às duas vistas (nome, cor, badge
// "publicada") — aqui ele também traz "Vender esta variação" e "Tirar": são
// controles de gestão da variação, que vivem só em Detalhes.
//
// O preço fica em "Condições de venda", com os tipos de anúncio; o título é
// por TIPO, na etapa Produto. Estoque, SKU e código vêm de GradeVariantes (a
// lógica de depósito não é duplicada).
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

/**
 * O cabeçalho de um cartão de variação (nome, cor, "publicada") — comum ao cartão de fotos
 * (Imagens) e ao de dados (Detalhes). `onToggleAtiva`/`onTirar` nulos = sem esses controles (é o
 * caso do cartão de fotos: a gestão da variação mora só em Detalhes).
 */
export function CabecalhoVariante({ v, cor, nome, travada, semVariacao, onToggleAtiva = null, onTirar = null }) {
    return (
        <header className="mb-5 flex flex-wrap items-center justify-between gap-3">
            <h3 className={cn('flex min-w-0 items-center gap-2.5 text-[15px] font-bold', v.ativa ? 'text-white' : 'text-white/50')}>
                {cor && <span className="h-4 w-4 shrink-0 rounded-full border border-white/25" style={{ backgroundColor: cor }} aria-hidden="true" />}
                <span className="min-w-0">{nome}</span>
                {v.publicada && <span className="inline-flex items-center gap-1 text-[13px] font-normal text-emerald-400"><CheckCircle2 size={14} aria-hidden="true" /> publicada</span>}
            </h3>
            {! semVariacao && ! travada && (onToggleAtiva || onTirar) && (
                <div className="flex items-center gap-4">
                    {onToggleAtiva && (
                        <label className="flex cursor-pointer items-center gap-2 text-[13px] text-white/75">
                            <input type="checkbox" role="switch" checked={v.ativa} onChange={(e) => onToggleAtiva(e.target.checked)}
                                className="h-4 w-4 rounded border-white/40 bg-transparent text-ecf-yellow focus-visible:ring-2 focus-visible:ring-ecf-yellow" data-acao="alternar-variacao" />
                            Vender esta variação
                        </label>
                    )}
                    {onTirar && (
                        <button type="button" onClick={onTirar} title="Tirar esta variação (os dados ficam guardados se ela voltar)" aria-label={`Tirar a variação ${v.rotulo}`} data-acao="excluir-variacao"
                            className="grid h-9 w-9 place-items-center rounded-lg text-white/55 hover:bg-red-500/10 hover:text-red-300 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow">
                            <Trash2 size={16} aria-hidden="true" />
                        </button>
                    )}
                </div>
            )}
        </header>
    );
}

/** A "Cor principal" da variação quando as variações são por Cor: o tom do nome dela (ver CorPrincipal.jsx). */
function TomDaVariante({ v, a, travada, onMudar }) {
    const valor = v.atributos?.MAIN_COLOR ?? null;
    const erro = useErroDoCampo((x) => x.variante === v.chave && x.atributo === 'MAIN_COLOR', { vazio: a.obrigatoriedade === 'REQUIRED' && ! valor?.value_id });

    return (
        <CampoCorPrincipal id={`extra-MAIN_COLOR-${v.chave}`} atributo={a} valor={valor} nome={nomeDaCor(v, null)} disabled={travada} erro={erro}
            onMudar={(novo) => onMudar(v.chave, (atual) => {
                const atributos = { ...(atual.atributos ?? {}) };
                if (novo === null) delete atributos.MAIN_COLOR; else atributos.MAIN_COLOR = novo;

                return { atributos };
            })} />
    );
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

/** `onTirar` = tirar a variação (nulo quando não dá). */
export default function CartaoVariante({ m, v, eixos, onTirar = null }) {
    const { estado, schema } = m;
    const travada = m.disabled || v.publicada;
    // Fase 175 plano 09 (§7): num kit com estoque calculado o número vem do
    // produto base (`floor(base ÷ N)` por variante e por depósito) e ninguém
    // digita duas vezes a mesma coisa. O campo fica somente leitura COM a
    // explicação ao lado — desabilitado com explicação, nunca escondido (D23).
    // ⚠️ `travada` NÃO muda: SKU, GTIN e os demais campos do kit SÃO editáveis.
    const estoqueDoBase = !! estado.produto?.estoque_calculado;
    const estoqueTravado = travada || estoqueDoBase;
    // A "Cor principal" fica junto do nome da cor: aqui quando as variações são por Cor; na ficha quando a Cor é do produto.
    const tom = ondeFicaOTom(schema, eixos);
    const extras = atributosExtrasDaVariante(schema).filter((a) => ! (tom && a.id === 'MAIN_COLOR'));
    const semVariacao = Object.keys(v.valores ?? {}).length === 0;
    const gtin = v.atributos?.GTIN?.value_name ?? '';
    const motivo = v.atributos?.EMPTY_GTIN_REASON?.value_id ?? null;
    const sku = v.atributos?.SELLER_SKU?.value_name ?? '';
    const multi = !! estado.conta?.multi_deposito;
    const cor = corDaVariante(v, eixos);
    const nome = semVariacao ? 'Produto' : eixos.filter((e) => v.valores?.[e.chave]).map((e) => `${e.nome}: ${v.valores[e.chave].nome}`).join(' · ');
    const ativa = v.ativa;

    // Só depois do "Continuar" (e só na variação que vai ao anúncio).
    const erroEstoque = useErroDoCampo((x) => ativa && x.variante === v.chave && x.campo === 'estoque', { vazio: ativa && ! multi && (v.estoque ?? null) === null });
    const erroSku = useErroDoCampo((x) => ativa && x.variante === v.chave && x.campo === 'sku', { vazio: ativa && ! sku });
    const erroGtin = useErroDoCampo((x) => ativa && x.variante === v.chave && ['GTIN', 'EMPTY_GTIN_REASON'].includes(x.atributo), { preenchido: !! gtin || !! motivo });

    return (
        <article className={cn('rounded-xl border p-5 max-sm:p-4', ativa ? 'border-white/[0.12] bg-white/[0.02]' : 'border-white/[0.08] bg-transparent')} data-cartao-dados-variante={v.chave}>
            <CabecalhoVariante v={v} cor={cor} nome={nome} travada={travada} semVariacao={semVariacao}
                onToggleAtiva={semVariacao ? null : (ligado) => m.mudarVar(v.chave, { ativa: ligado })} onTirar={onTirar} />

            {! ativa ? (
                <p className="text-[13px] text-white/45">Fora do anúncio. Marque "Vender esta variação" para preencher estoque e código.</p>
            ) : (
                <div className="grid gap-x-6 gap-y-5 sm:grid-cols-2 xl:grid-cols-3">
                    <Campo rotulo={multi ? 'Estoque por depósito' : 'Estoque'} htmlFor={`estoque-${v.chave}`} erro={erroEstoque}
                        dica={estoqueDoBase ? 'calculado do produto base' : null}>
                        <CampoEstoque grande id={`estoque-${v.chave}`} invalido={!! erroEstoque} v={v} conta={estado.conta} travada={estoqueTravado} onMudar={m.mudarVar} />
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
                    {tom === 'variacao' && ! semVariacao && <TomDaVariante v={v} a={schema.atributos.MAIN_COLOR} travada={travada} onMudar={m.mudarVar} />}
                    {extras.map((a) => <CampoExtra key={a.id} v={v} a={a} travada={travada} onMudar={m.mudarVar} />)}
                </div>
            )}
        </article>
    );
}
