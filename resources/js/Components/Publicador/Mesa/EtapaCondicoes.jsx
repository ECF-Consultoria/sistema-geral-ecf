import { useEffect, useState } from 'react';
import { Calculator, Info, Loader2 } from 'lucide-react';
import { LinkMl } from '@/Components/Portal/Estrutura/comum';
import CampoAtributo, { RotuloAtributo } from '../CampoAtributo';
import { NOME_TIPO, NOTA_TIPO, valorVazio } from '../apoio';
import { UNIDADES_MEDIDA, UNIDADES_PESO, daUnidadeMl, numeroDoAtributo, paraUnidadeMl, unidadeInicial } from '../ferramentas';
import CampoPreco from './CampoPreco';
import { BotaoAcao } from './botoes';
import { CAMPO, Campo, ErroDoCampo, INVALIDO, SELECT, Secao, Subtitulo, useErroDoCampo } from './comum';
import { cn, formatCurrency } from '@/lib/utils';

// ─── Etapa 3 — Condições de venda: preço, envio e garantia ──────────────────
//
// Como a 3ª etapa do Mercado Livre. O tipo de anúncio (Clássico, Premium ou os
// dois) se liga aqui, junto do preço — cada tipo vira um anúncio. O preço é
// por variação e por tipo; "Quanto você recebe" simula tarifa e frete com
// `m.simular`. Preço do Portal é MOSTRADO, não gravado (docx §4).
//
// Envio (docx §5): a modalidade vem do servidor (`conta.modos_envio`); as
// medidas são atributos da seção EMBALAGEM, cada uma com a unidade escolhida na
// tela (kg/g, cm/mm/m) e gravada no que o ML aceita (g e cm); o frete grátis
// obrigatório pela faixa de preço vem do ML (`m.consultarFrete`). Os efeitos
// ficam em `useEfeitosDoEnvio`, que a página chama sempre: a regra do frete
// depende de preços e pacote, e precisa rodar em qualquer etapa.

export const ENVIOS = { me2: 'Mercado Envios', custom: 'Envio próprio', not_specified: 'A combinar com o comprador' };
const DIMENSOES = [['SELLER_PACKAGE_HEIGHT', 'Altura'], ['SELLER_PACKAGE_WIDTH', 'Largura'], ['SELLER_PACKAGE_LENGTH', 'Comprimento']];
const PESO = 'SELLER_PACKAGE_WEIGHT';
const ESPERA_FRETE = 1500;

const opcoesDeEnvio = (modos) => Object.fromEntries(Object.entries(ENVIOS).filter(([modo]) => ! modos || modos.includes(modo)));

/** Efeitos do envio (ver o comentário do topo). A página chama sempre, com ou sem estado. */
export function useEfeitosDoEnvio(m) {
    const { schema, rasc, estado } = m;
    const modos = estado?.conta?.modos_envio ?? null;
    const modo = rasc?.envio?.modo ?? 'me2';
    const freteObrigatorio = modo === 'me2' && !! m.frete?.obrigatorio;

    // Forma de envio que a conta não tem (V-SAL-04) volta para Mercado Envios, ou para a primeira que ela tem.
    useEffect(() => {
        if (! estado || m.disabled || ! modos || modos.includes(modo)) return;
        const opcoes = opcoesDeEnvio(modos);
        const padrao = opcoes.me2 ? 'me2' : Object.keys(opcoes)[0];
        if (padrao) m.mudarRasc((r) => ({ envio: { ...r.envio, modo: padrao } }));
    }, [modos?.join(','), modo, m.disabled]); // eslint-disable-line react-hooks/exhaustive-deps

    // Pergunta ao ML se o frete grátis é obrigatório quando preço, pacote ou tipos mudam.
    const assinatura = JSON.stringify([
        modo, schema?.categoria_id ?? null,
        (m.alvos ?? []).filter((a) => a.ativo).map((a) => a.listing_type_id),
        (m.variantes ?? []).filter((v) => v.ativa && ! v.orfa).map((v) => [v.precos, v.precos_efetivos]),
        [...DIMENSOES.map(([id]) => id), PESO].map((id) => rasc?.atributos?.[id]?.value_name ?? null),
    ]);
    useEffect(() => {
        if (! estado || ! schema || modo !== 'me2' || ! estado.conta || estado.conta.erro) return undefined;
        const t = setTimeout(() => m.consultarFrete(), ESPERA_FRETE);

        return () => clearTimeout(t);
    }, [assinatura]); // eslint-disable-line react-hooks/exhaustive-deps

    // Obrigatório pelo ML = marcado.
    useEffect(() => {
        if (estado && freteObrigatorio && ! rasc?.envio?.frete_gratis && ! m.disabled) m.mudarRasc((r) => ({ envio: { ...r.envio, frete_gratis: true } }));
    }, [freteObrigatorio, rasc?.envio?.frete_gratis, m.disabled]); // eslint-disable-line react-hooks/exhaustive-deps
}

// ─── Preço ──────────────────────────────────────────────────────────────────

/** O preço de uma variação num tipo: o campo compartilhado, com o erro depois do "Continuar". */
function PrecoDaVariante({ m, v, a, rotulo, comPortal, semRotulo = false }) {
    const lt = a.listing_type_id;
    const id = `preco-${lt}-${v.chave}`;
    const valor = v.precos?.[lt] ?? null;
    const efetivo = v.precos_efetivos?.[lt] ?? null;
    const erro = useErroDoCampo((x) => x.campo === 'preco' && x.variante === v.chave && x.alvo === lt, { vazio: valor === null && efetivo === null });
    const campo = (
        <CampoPreco id={id} valor={valor} efetivo={efetivo} invalido={!! erro} disabled={m.disabled || v.publicada} chave={v.chave} tipo={lt} comPortal={comPortal} rotulo={rotulo}
            onMudar={(num) => m.mudarVar(v.chave, { precos: { ...(v.precos ?? {}), [lt]: num } })} />
    );

    if (semRotulo) return <div>{campo}<ErroDoCampo>{erro}</ErroDoCampo></div>;

    return <Campo rotulo={rotulo} htmlFor={id} erro={erro}>{campo}</Campo>;
}

/** "Quanto você recebe": preço − tarifa − frete, por tipo, na 1ª variação ativa (é assim que o servidor simula). */
function QuantoRecebo({ m }) {
    const sim = m.simulacao ?? null;
    const entradas = sim ? Object.entries(sim) : [];

    return (
        <div className="rounded-lg border border-white/20 bg-black/40 p-4" data-quanto-recebo={sim ? 'sim' : 'nao'}>
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p className="flex items-center gap-2 text-[15px] font-bold text-white"><Calculator size={16} className="text-white/55" aria-hidden="true" /> Quanto você recebe</p>
                    {! sim && <p className="mt-0.5 text-[13px] text-white/50">Tarifa e frete do Mercado Livre com os preços de agora, na primeira variação.</p>}
                </div>
                <BotaoAcao onClick={() => m.simular()} disabled={m.simulando || ! m.schema} data-acao="simular">
                    {m.simulando && <Loader2 size={16} className="animate-spin" aria-hidden="true" />} {sim ? 'Calcular de novo' : 'Calcular'}
                </BotaoAcao>
            </div>
            {sim && entradas.length === 0 && <p className="mt-3 text-[13px] text-white/55">Sem preço para simular. Preencha o preço da primeira variação.</p>}
            {entradas.length > 0 && (
                <div className="mt-4 grid gap-4 md:grid-cols-2">
                    {entradas.map(([lt, s]) => (
                        <dl key={lt} className="space-y-1.5 rounded-lg border border-white/[0.08] p-3 text-[13px] tabular-nums" data-simulacao={lt}>
                            <div className="flex justify-between font-bold text-white"><dt>{NOME_TIPO[lt]}</dt><dd /></div>
                            <div className="flex justify-between text-white/80"><dt>Preço de venda</dt><dd>{formatCurrency(s.preco)}</dd></div>
                            <div className="flex justify-between text-white/55"><dt>Tarifa do Mercado Livre</dt><dd>− {formatCurrency(s.tarifa)}</dd></div>
                            <div className="flex justify-between text-white/55"><dt>Frete</dt><dd>{s.frete_conhecido ? `− ${formatCurrency(s.frete)}` : 'informe o pacote'}</dd></div>
                            <div className="flex items-baseline justify-between border-t border-white/[0.08] pt-1.5 text-white"><dt className="font-bold">Você recebe</dt><dd className="text-[15px] font-bold text-emerald-400">{formatCurrency(s.voce_recebe)}</dd></div>
                        </dl>
                    ))}
                </div>
            )}
        </div>
    );
}

function SecaoPreco({ m }) {
    const alvos = m.alvos ?? [];
    const ligados = alvos.filter((a) => a.ativo);
    const variantes = m.variantes.filter((v) => ! v.orfa && v.ativa);
    const comPortal = !! m.estado.produto?.oferta_id;
    const semVariacao = variantes.length === 1 && Object.keys(variantes[0].valores ?? {}).length === 0;
    const algumDoPortal = comPortal && variantes.some((v) => ligados.some((a) => (v.precos?.[a.listing_type_id] ?? null) === null && (v.precos_efetivos?.[a.listing_type_id] ?? null) !== null));
    const erroTipos = useErroDoCampo((x) => x.etapa === 'E10' && ! x.campo && ! x.atributo, { vazio: ligados.length === 0 });
    const noArDe = (a) => a.mlb_na_regua ?? Object.entries(m.estado.ja_publicados ?? {}).find(([chave]) => chave.split('|')[0] === a.listing_type_id)?.[1] ?? null;
    const ligar = (lt, ativo) => m.mudarRasc((r) => ({ alvos: r.alvos.map((x) => (x.listing_type_id === lt ? { ...x, ativo } : x)) }));
    // Colunas da grade (classes estáticas, para o Tailwind gerar): a variação ocupa o que sobra; cada tipo, 180–260px.
    const colunas = ligados.length > 1 ? 'md:grid-cols-[minmax(0,1fr)_minmax(180px,260px)_minmax(180px,260px)]' : 'md:grid-cols-[minmax(0,1fr)_minmax(180px,260px)]';

    return (
        <Secao id="preco" titulo="Tipo de anúncio e preço" descricao="Clássico e Premium saem como dois anúncios do mesmo produto, cada um com o próprio preço. Ligue um ou os dois.">
            <div className="space-y-8">
                <fieldset data-tipos-anuncio>
                    <legend className="mb-2 text-[13px] font-bold text-white/90">Vender como</legend>
                    <div className="grid gap-3 sm:grid-cols-2">
                        {alvos.map((a) => {
                            const lt = a.listing_type_id;
                            const noAr = noArDe(a);

                            return (
                                <label key={lt} data-alvo-ativo={lt}
                                    className={cn('flex cursor-pointer items-start gap-3 rounded-lg border p-4',
                                        a.ativo ? 'border-ecf-yellow/50 bg-ecf-yellow/[0.04]' : 'border-white/20 bg-black/40 hover:border-white/35',
                                        erroTipos && 'border-red-400', (m.disabled || noAr) && 'cursor-not-allowed')}>
                                    <input type="checkbox" checked={a.ativo} disabled={m.disabled || !! noAr} onChange={(e) => ligar(lt, e.target.checked)}
                                        className="mt-0.5 h-4 w-4 rounded border-white/40 bg-transparent text-ecf-yellow focus-visible:ring-2 focus-visible:ring-ecf-yellow" />
                                    <span className="min-w-0">
                                        <span className="block text-[15px] font-bold text-white">{NOME_TIPO[lt]}</span>
                                        <span className="block text-[13px] text-white/55">{NOTA_TIPO[lt]}{lt === 'gold_pro' ? ' (se a conta oferecer)' : ''}</span>
                                        {noAr && <span className="mt-1 inline-flex items-center gap-2 text-[13px] font-bold text-emerald-400">No ar <LinkMl mlb={noAr} className="text-[13px] font-normal text-white/70" /></span>}
                                    </span>
                                </label>
                            );
                        })}
                    </div>
                    <ErroDoCampo>{erroTipos}</ErroDoCampo>
                </fieldset>

                {ligados.length > 0 && m.schema && (
                    <div data-tabela-precos={variantes.length}>
                        {semVariacao ? (
                            <div className="grid gap-x-6 gap-y-5 sm:grid-cols-2">
                                {ligados.map((a) => <PrecoDaVariante key={a.listing_type_id} m={m} v={variantes[0]} a={a} comPortal={comPortal} rotulo={`Preço no ${NOME_TIPO[a.listing_type_id]}`} />)}
                            </div>
                        ) : (
                            <>
                                <Subtitulo descricao="Um preço por variação em cada tipo de anúncio.">Preço de cada variação</Subtitulo>
                                <div className={cn('hidden gap-x-6 pb-2 md:grid', colunas)} aria-hidden="true">
                                    <span className="text-[13px] font-bold text-white/60">Variação</span>
                                    {ligados.map((a) => <span key={a.listing_type_id} className="text-[13px] font-bold text-white/60">Preço no {NOME_TIPO[a.listing_type_id]}</span>)}
                                </div>
                                <ul className="divide-y divide-white/[0.08] border-y border-white/[0.08]">
                                    {variantes.map((v) => (
                                        <li key={v.chave} className={cn('grid items-start gap-x-6 gap-y-3 py-4', colunas)} data-linha-preco={v.chave}>
                                            <p className="pt-2.5 text-[15px] font-bold text-white">{v.rotulo}</p>
                                            {ligados.map((a) => (
                                                <div key={a.listing_type_id}>
                                                    <span className="mb-1.5 block text-[13px] font-bold text-white/60 md:hidden">Preço no {NOME_TIPO[a.listing_type_id]}</span>
                                                    <PrecoDaVariante semRotulo m={m} v={v} a={a} comPortal={comPortal} rotulo={`Preço no ${NOME_TIPO[a.listing_type_id]} de ${v.rotulo}`} />
                                                </div>
                                            ))}
                                        </li>
                                    ))}
                                </ul>
                            </>
                        )}
                        {algumDoPortal && <p className="mt-3 text-[13px] text-white/50">O preço marcado "do Portal" vem da Precificação e acompanha as mudanças de lá; digite outro valor para trocar só aqui.</p>}
                    </div>
                )}

                {ligados.length > 0 && m.schema && <QuantoRecebo m={m} />}
            </div>
        </Secao>
    );
}

// ─── Envio ──────────────────────────────────────────────────────────────────

/** Uma medida do pacote com a unidade da tela; grava na unidade do ML. */
function MedidaPacote({ m, a, rotulo, tipo }) {
    const valor = m.rasc.atributos?.[a.id];
    const tabela = tipo === 'peso' ? UNIDADES_PESO : UNIDADES_MEDIDA;
    const unidadeMl = a.unidade_padrao ?? a.unidades?.[0] ?? (tipo === 'peso' ? 'g' : 'cm');
    const gravado = numeroDoAtributo(valor);
    const [unidade, setUnidade] = useState(() => unidadeInicial(gravado, tipo));
    const [texto, setTexto] = useState(() => daUnidadeMl(gravado, tabela[unidade] ?? 1));
    useEffect(() => setTexto(daUnidadeMl(numeroDoAtributo(valor), tabela[unidade] ?? 1)), [valor?.value_name]); // eslint-disable-line react-hooks/exhaustive-deps

    const preenchido = ! valorVazio(valor);
    const id = `pacote-${a.id}`;
    const erro = useErroDoCampo((x) => x.atributo === a.id, { vazio: a.obrigatoriedade === 'REQUIRED' && ! preenchido, preenchido });
    const gravar = (t, u) => {
        // g sem casas; cm com uma (o ML aceita 12.5 cm).
        const n = paraUnidadeMl(t, tabela[u] ?? 1, tipo === 'peso' ? 0 : 1);
        m.mudarAtributo(a.id, n === null ? null : { value_id: null, value_name: `${n} ${unidadeMl}`, origem: 'user', revisar: false });
    };
    const trocarUnidade = (u) => {
        // O número na tela continua sendo o mesmo pacote: só muda a unidade em que é mostrado.
        setUnidade(u);
        setTexto(daUnidadeMl(numeroDoAtributo(valor), tabela[u] ?? 1));
    };

    return (
        <Campo rotulo={rotulo} htmlFor={id} erro={erro} dica={unidade !== unidadeMl && preenchido ? `Vai ao Mercado Livre como ${valor?.value_name}.` : null}>
            <div className="flex gap-2" data-campo-atributo={a.id}>
                <input id={id} inputMode="decimal" value={texto} disabled={m.disabled} onChange={(e) => setTexto(e.target.value)} onBlur={() => gravar(texto, unidade)}
                    placeholder="0" aria-invalid={!! erro || undefined} className={cn(CAMPO, 'min-w-0 tabular-nums', erro && INVALIDO)} data-atributo={a.id} />
                <select value={unidade} disabled={m.disabled} onChange={(e) => trocarUnidade(e.target.value)} aria-label={`Unidade de ${rotulo}`} data-unidade={a.id}
                    className={cn(SELECT, 'w-20 shrink-0')}>
                    {Object.keys(tabela).map((u) => <option key={u} value={u}>{u}</option>)}
                </select>
            </div>
        </Campo>
    );
}

/** Atributo da embalagem que a tela não converte: o campo do schema. */
function CampoEmbalagem({ m, a }) {
    const valor = m.rasc.atributos?.[a.id];
    const id = `pacote-${a.id}`;
    const erro = useErroDoCampo((x) => x.atributo === a.id, { vazio: a.obrigatoriedade === 'REQUIRED' && valorVazio(valor), preenchido: ! valorVazio(valor) });

    return (
        <Campo rotulo={<RotuloAtributo atributo={a} valor={valor} />} htmlFor={id} erro={erro}>
            <CampoAtributo variante="campo" id={id} atributo={a} valor={valor} disabled={m.disabled} invalido={!! erro} onChange={(v) => m.mudarAtributo(a.id, v)} />
        </Campo>
    );
}

/** A unidade do ML é uma das que a tela sabe converter? Senão o campo fica o do schema. */
const converte = (a, tipo) => (tipo === 'peso' ? UNIDADES_PESO : UNIDADES_MEDIDA)[a.unidade_padrao ?? a.unidades?.[0] ?? (tipo === 'peso' ? 'g' : 'cm')] === 1;

function SecaoEnvio({ m }) {
    const { schema, rasc, estado } = m;
    const modos = estado.conta?.modos_envio ?? null;
    const embalagem = Object.values(schema?.atributos ?? {}).filter((a) => a.secao === 'EMBALAGEM');
    const ids = DIMENSOES.map(([id]) => id);
    const dimensoes = DIMENSOES.map(([id, nome]) => [embalagem.find((a) => a.id === id), nome]).filter(([a]) => a);
    const peso = embalagem.find((a) => a.id === PESO) ?? null;
    const outras = embalagem.filter((a) => ! ids.includes(a.id) && a.id !== PESO);
    const opcoesEnvio = opcoesDeEnvio(modos);
    const modo = rasc.envio?.modo ?? 'me2';
    const frete = modo === 'me2' ? m.frete : null;
    const freteObrigatorio = !! frete?.obrigatorio;
    const erroEnvio = useErroDoCampo((x) => x.campo === 'envio');

    return (
        <Secao id="envio" titulo="Envio" descricao="É com as medidas do pacote fechado que o Mercado Livre calcula o frete.">
            <div className="space-y-8">
                <Campo rotulo="Forma de envio" htmlFor="campo-envio" erro={erroEnvio} className="max-w-md">
                    <select id="campo-envio" value={modo} disabled={m.disabled} data-campo="envio" aria-invalid={!! erroEnvio || undefined}
                        onChange={(e) => m.mudarRasc((r) => ({ envio: { ...r.envio, modo: e.target.value || 'me2' } }))}
                        className={cn(SELECT, erroEnvio && INVALIDO)} data-modalidade={modo}>
                        {Object.entries(opcoesEnvio).map(([valor, nome]) => <option key={valor} value={valor}>{nome}</option>)}
                    </select>
                </Campo>

                {(dimensoes.length > 0 || peso) && (
                    <div>
                        <Subtitulo descricao="Com a embalagem, do jeito que vai para o comprador.">Pacote</Subtitulo>
                        <div className="grid gap-x-6 gap-y-5 sm:grid-cols-2 xl:grid-cols-4" data-medidas-pacote>
                            {dimensoes.map(([a, nome]) => (converte(a, 'medida') ? <MedidaPacote key={a.id} m={m} a={a} rotulo={nome} tipo="medida" /> : <CampoEmbalagem key={a.id} m={m} a={a} />))}
                            {peso && (converte(peso, 'peso') ? <MedidaPacote m={m} a={peso} rotulo="Peso" tipo="peso" /> : <CampoEmbalagem m={m} a={peso} />)}
                        </div>
                    </div>
                )}

                {outras.length > 0 && (
                    <div className="grid gap-x-6 gap-y-5 sm:grid-cols-2 xl:grid-cols-4">{outras.map((a) => <CampoEmbalagem key={a.id} m={m} a={a} />)}</div>
                )}

                {modo === 'me2' && (
                    <div>
                        <label className={cn('flex items-center gap-3', freteObrigatorio ? 'cursor-not-allowed' : 'cursor-pointer')}>
                            <input type="checkbox" checked={!! rasc.envio?.frete_gratis || freteObrigatorio} disabled={m.disabled || freteObrigatorio}
                                onChange={(e) => m.mudarRasc((r) => ({ envio: { ...r.envio, frete_gratis: e.target.checked } }))}
                                className="h-4 w-4 rounded border-white/40 bg-transparent text-ecf-yellow focus-visible:ring-2 focus-visible:ring-ecf-yellow" data-campo="frete-gratis" />
                            <span className="text-[15px] text-white">Oferecer frete grátis</span>
                        </label>
                        {frete?.conhecido && (
                            <p className={cn('ml-7 mt-1.5 flex items-start gap-1.5 text-[13px]', freteObrigatorio ? 'text-emerald-300' : 'text-white/50')} data-frete-regra={freteObrigatorio ? 'obrigatorio' : (frete.parcial ? 'parcial' : 'opcional')}>
                                <Info size={14} className="mt-0.5 shrink-0" aria-hidden="true" />
                                {freteObrigatorio
                                    ? 'Obrigatório: nesta faixa de preço o Mercado Livre exige frete grátis.'
                                    : (frete.parcial
                                        ? 'Opcional para as variações mais baratas; nas que passam da faixa o Mercado Livre liga o frete grátis sozinho.'
                                        : 'Opcional nesta faixa de preço.')}
                            </p>
                        )}
                    </div>
                )}
            </div>
        </Secao>
    );
}

// ─── Garantia ───────────────────────────────────────────────────────────────

function SecaoGarantia({ m }) {
    const { schema, rasc } = m;
    const garantias = schema?.garantia?.tipos ?? [];
    const semGarantia = (id) => /sem garantia/i.test(garantias.find((g) => String(g.id) === String(id))?.name ?? '');
    const garantia = rasc.garantia ?? null;
    const precisaTempo = !! garantia?.tipo && ! semGarantia(garantia.tipo);
    const erro = useErroDoCampo((x) => x.campo === 'garantia', { vazio: ! garantia?.tipo || (precisaTempo && ! garantia?.tempo) });
    const tipoInvalido = !! erro && ! garantia?.tipo;
    const tempoInvalido = !! erro && precisaTempo;

    return (
        <Secao id="garantia" titulo="Garantia">
            <div className="grid gap-x-6 gap-y-5 sm:grid-cols-2 xl:grid-cols-3" data-tile-garantia>
                <Campo rotulo="Tipo de garantia" htmlFor="garantia-tipo">
                    <select id="garantia-tipo" value={garantia?.tipo ?? ''} disabled={m.disabled} data-campo="garantia-tipo" aria-invalid={tipoInvalido || undefined}
                        className={cn(SELECT, tipoInvalido && INVALIDO)}
                        onChange={(e) => {
                            const t = e.target.value || null;
                            m.mudarRasc((r) => ({ garantia: t === null ? null : { ...(r.garantia ?? {}), tipo: t, ...(semGarantia(t) ? { tempo: null, unidade: null } : {}) } }));
                        }}>
                        <option value="">Escolha…</option>
                        {garantias.map((g) => <option key={g.id} value={g.id}>{g.name}</option>)}
                    </select>
                </Campo>
                {precisaTempo && (
                    <Campo rotulo="Tempo de garantia" htmlFor="garantia-tempo">
                        <div className="flex gap-2">
                            <input id="garantia-tempo" type="number" min={1} value={garantia.tempo ?? ''} disabled={m.disabled} aria-invalid={(tempoInvalido && ! garantia.tempo) || undefined}
                                onChange={(e) => m.mudarRasc((r) => ({ garantia: { ...r.garantia, tempo: e.target.value === '' ? null : Number(e.target.value) } }))}
                                className={cn(CAMPO, 'min-w-0 tabular-nums', tempoInvalido && ! garantia.tempo && INVALIDO)} data-campo="garantia-tempo" />
                            <select value={garantia.unidade ?? ''} disabled={m.disabled} data-campo="garantia-unidade" aria-label="Unidade do tempo de garantia"
                                onChange={(e) => m.mudarRasc((r) => ({ garantia: { ...r.garantia, unidade: e.target.value || null } }))}
                                className={cn(SELECT, 'w-28 shrink-0')}>
                                <option value="">…</option>
                                {(schema.garantia?.unidades ?? ['dias', 'meses', 'anos']).map((u) => <option key={u} value={u}>{u}</option>)}
                            </select>
                        </div>
                    </Campo>
                )}
            </div>
            <ErroDoCampo>{erro}</ErroDoCampo>
        </Secao>
    );
}

export default function EtapaCondicoes({ m, children }) {
    if (! m.schema) {
        return (
            <div className="space-y-6" data-etapa-conteudo="condicoes">
                <Secao id="preco" titulo="Condições de venda">
                    <p className="text-[15px] text-white/55">Escolha a categoria na etapa Produto para definir preço, envio e garantia.</p>
                </Secao>
                {children}
            </div>
        );
    }

    return (
        <div className="space-y-6" data-etapa-conteudo="condicoes">
            <SecaoPreco m={m} />
            <SecaoEnvio m={m} />
            <SecaoGarantia m={m} />
            {children}
        </div>
    );
}
