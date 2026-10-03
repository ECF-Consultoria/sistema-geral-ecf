import { useEffect, useState } from 'react';
import { Info, Truck } from 'lucide-react';
import { CLASSE_INPUT, Seletor } from '@/Components/Portal/Estrutura/comum';
import CampoAtributo, { RotuloAtributo } from '../CampoAtributo';
import { estadoDasSecoes, valorVazio } from '../apoio';
import { UNIDADES_MEDIDA, UNIDADES_PESO, daUnidadeMl, numeroDoAtributo, paraUnidadeMl, unidadeInicial } from '../ferramentas';
import { CardMesa, ChipSecao, Tile } from './comum';
import { cn } from '@/lib/utils';

// ─── Card 6 — Logística, dimensões e garantia (check "Envio") ───────────────
//
// A modalidade vem do servidor (`conta.modos_envio`); aqui não se calcula
// elegibilidade nenhuma. As medidas são atributos da seção EMBALAGEM do schema.
//
// Docx §5 (03/10/2026):
// - medidas numa linha só, cada uma com a unidade escolhida na tela (kg/g,
//   cm/mm/m) e gravada convertida no que o ML aceita (g e cm);
// - o chip mostra a forma de envio ESCOLHIDA (antes listava todas as da conta
//   e parecia divergir do seletor); forma que a conta não tem volta para a padrão;
// - frete grátis obrigatório pela faixa de preço vem do ML (`m.consultarFrete`):
//   quando obrigatório, fica marcado e travado.

const ENVIOS = { me2: 'Mercado Envios', custom: 'Envio próprio', not_specified: 'A combinar com o comprador' };
const DIMENSOES = ['SELLER_PACKAGE_HEIGHT', 'SELLER_PACKAGE_WIDTH', 'SELLER_PACKAGE_LENGTH'];
const PESO = 'SELLER_PACKAGE_WEIGHT';
const ESPERA_FRETE = 1500;

/** Uma medida do pacote com a unidade da tela; grava na unidade do ML. */
function MedidaPacote({ m, a, tipo }) {
    const valor = m.rasc.atributos?.[a.id];
    const tabela = tipo === 'peso' ? UNIDADES_PESO : UNIDADES_MEDIDA;
    const unidadeMl = a.unidade_padrao ?? a.unidades?.[0] ?? (tipo === 'peso' ? 'g' : 'cm');
    const gravado = numeroDoAtributo(valor);
    const [unidade, setUnidade] = useState(() => unidadeInicial(gravado, tipo));
    const [texto, setTexto] = useState(() => daUnidadeMl(gravado, tabela[unidade] ?? 1));
    useEffect(() => setTexto(daUnidadeMl(numeroDoAtributo(valor), tabela[unidade] ?? 1)), [valor?.value_name]); // eslint-disable-line react-hooks/exhaustive-deps

    const preenchido = ! valorVazio(valor);
    const recusa = preenchido ? (m.problemasDoAtributo(a.id).find((p) => p.severidade === 'BLOCKER')?.mensagem ?? null) : null;
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
        <Tile rotulo={<RotuloAtributo atributo={a} valor={valor} />} preenchido={preenchido} obrigatorio={a.obrigatoriedade === 'REQUIRED'} problema={recusa}>
            <div className="flex gap-1.5" data-campo-atributo={a.id}>
                <input inputMode="decimal" value={texto} disabled={m.disabled} onChange={(e) => setTexto(e.target.value)} onBlur={() => gravar(texto, unidade)}
                    placeholder="0" className={cn(CLASSE_INPUT, 'min-w-0 py-1.5 text-[13px] tabular-nums disabled:opacity-50')} data-atributo={a.id} />
                <select value={unidade} disabled={m.disabled} onChange={(e) => trocarUnidade(e.target.value)} aria-label={`Unidade de ${a.nome}`} data-unidade={a.id}
                    className={cn(CLASSE_INPUT, 'w-[72px] shrink-0 appearance-auto py-1.5 text-[13px] [&>option]:bg-ecf-card disabled:opacity-50')}>
                    {Object.keys(tabela).map((u) => <option key={u} value={u}>{u}</option>)}
                </select>
            </div>
            {unidade !== unidadeMl && preenchido && <p className="mt-1 text-[11px] text-white/40" data-convertido={a.id}>vai ao Mercado Livre como {valor?.value_name}</p>}
            {recusa && <p className="mt-1 text-[11px] text-amber-300">{recusa}</p>}
        </Tile>
    );
}

function TileAtributo({ m, a }) {
    const valor = m.rasc.atributos?.[a.id];
    const preenchido = ! valorVazio(valor);
    const recusa = preenchido ? (m.problemasDoAtributo(a.id).find((p) => p.severidade === 'BLOCKER')?.mensagem ?? null) : null;

    return (
        <Tile rotulo={<RotuloAtributo atributo={a} valor={valor} />} preenchido={preenchido} obrigatorio={a.obrigatoriedade === 'REQUIRED'} problema={recusa}>
            <CampoAtributo variante="tile" atributo={a} valor={valor} disabled={m.disabled} erro={recusa} onChange={(v) => m.mudarAtributo(a.id, v)} />
        </Tile>
    );
}

/** A unidade do ML é uma das que a tela sabe converter? Senão o campo fica o do schema. */
const converte = (a, tipo) => (tipo === 'peso' ? UNIDADES_PESO : UNIDADES_MEDIDA)[a.unidade_padrao ?? a.unidades?.[0] ?? (tipo === 'peso' ? 'g' : 'cm')] === 1;

export default function CardLogistica({ m, aberto = true, onAlternar }) {
    const { schema, rasc, estado } = m;
    const faltam = estadoDasSecoes(m.problemasDaSecao('envio'), schema).envio.faltam;
    const modos = estado.conta?.modos_envio ?? null;
    const embalagem = Object.values(schema?.atributos ?? {}).filter((a) => a.secao === 'EMBALAGEM');
    const dimensoes = DIMENSOES.map((id) => embalagem.find((a) => a.id === id)).filter(Boolean);
    const peso = embalagem.find((a) => a.id === PESO) ?? null;
    const outras = embalagem.filter((a) => ! DIMENSOES.includes(a.id) && a.id !== PESO);
    const garantias = schema?.garantia?.tipos ?? [];
    const semGarantia = (id) => /sem garantia/i.test(garantias.find((g) => String(g.id) === String(id))?.name ?? '');
    const garantia = rasc.garantia ?? null;

    const opcoesEnvio = Object.fromEntries(Object.entries(ENVIOS).filter(([modo]) => ! modos || modos.includes(modo)));
    const modo = rasc.envio?.modo ?? 'me2';
    const frete = modo === 'me2' ? m.frete : null;
    const freteObrigatorio = !! frete?.obrigatorio;

    // Forma de envio que a conta não tem (V-SAL-04) volta para Mercado Envios, ou para a primeira que ela tem.
    useEffect(() => {
        if (m.disabled || ! modos || modos.includes(modo)) return;
        const padrao = opcoesEnvio.me2 ? 'me2' : Object.keys(opcoesEnvio)[0];
        if (padrao) m.mudarRasc((r) => ({ envio: { ...r.envio, modo: padrao } }));
    }, [modos?.join(','), modo, m.disabled]); // eslint-disable-line react-hooks/exhaustive-deps

    // Pergunta ao ML se o frete grátis é obrigatório quando preço, pacote ou tipos mudam.
    const assinatura = JSON.stringify([
        modo, schema?.categoria_id ?? null,
        (m.alvos ?? []).filter((a) => a.ativo).map((a) => a.listing_type_id),
        m.variantes.filter((v) => v.ativa && ! v.orfa).map((v) => [v.precos, v.precos_efetivos]),
        [...DIMENSOES, PESO].map((id) => rasc.atributos?.[id]?.value_name ?? null),
    ]);
    useEffect(() => {
        if (! schema || modo !== 'me2' || ! estado.conta || estado.conta.erro) return undefined;
        const t = setTimeout(() => m.consultarFrete(), ESPERA_FRETE);

        return () => clearTimeout(t);
    }, [assinatura]); // eslint-disable-line react-hooks/exhaustive-deps

    // Obrigatório pelo ML = marcado.
    useEffect(() => {
        if (freteObrigatorio && ! rasc.envio?.frete_gratis && ! m.disabled) m.mudarRasc((r) => ({ envio: { ...r.envio, frete_gratis: true } }));
    }, [freteObrigatorio, rasc.envio?.frete_gratis, m.disabled]); // eslint-disable-line react-hooks/exhaustive-deps

    const chip = (
        <span className="inline-flex items-center gap-3">
            {ENVIOS[modo] && <span className="text-[11px] font-bold uppercase tracking-[0.05em] text-white/55" data-modalidade={modo}>{ENVIOS[modo]}</span>}
            <ChipSecao faltam={faltam} />
        </span>
    );

    return (
        <CardMesa id="card-logistica" icone={Truck} titulo="Logística, dimensões e garantia" chip={chip} aberto={aberto} onAlternar={onAlternar}
            apoio="É com as medidas do pacote fechado que o Mercado Livre calcula o frete.">
            {! schema ? (
                <p className="text-[13px] text-white/55">Escolha a categoria para definir o envio.</p>
            ) : (
                <div className="space-y-6">
                    {(dimensoes.length > 0 || peso) && (
                        <div>
                            <p className="mb-2 text-[11px] font-bold uppercase tracking-[0.05em] text-white/40">Pacote fechado</p>
                            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" data-medidas-pacote>
                                {dimensoes.map((a) => (converte(a, 'medida') ? <MedidaPacote key={a.id} m={m} a={a} tipo="medida" /> : <TileAtributo key={a.id} m={m} a={a} />))}
                                {peso && (converte(peso, 'peso') ? <MedidaPacote m={m} a={peso} tipo="peso" /> : <TileAtributo m={m} a={peso} />)}
                            </div>
                        </div>
                    )}

                    {outras.length > 0 && (
                        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">{outras.map((a) => <TileAtributo key={a.id} m={m} a={a} />)}</div>
                    )}

                    <div className="grid gap-4 lg:grid-cols-3">
                        <div data-tile-garantia>
                            <span className="mb-1 block text-[11px] font-bold uppercase tracking-[0.05em] text-white/40">Garantia</span>
                            <div className="space-y-2">
                                <Seletor valor={garantia?.tipo ?? ''} vazio="Escolha…" disabled={m.disabled} data-campo="garantia-tipo" className="text-[13px]"
                                    opcoes={Object.fromEntries(garantias.map((g) => [g.id, g.name]))}
                                    onChange={(t) => m.mudarRasc((r) => ({ garantia: t === null ? null : { ...(r.garantia ?? {}), tipo: t, ...(semGarantia(t) ? { tempo: null, unidade: null } : {}) } }))} />
                                {garantia?.tipo && ! semGarantia(garantia.tipo) && (
                                    <div className="flex gap-2">
                                        <input type="number" min={1} value={garantia.tempo ?? ''} disabled={m.disabled} aria-label="Tempo de garantia"
                                            onChange={(e) => m.mudarRasc((r) => ({ garantia: { ...r.garantia, tempo: e.target.value === '' ? null : Number(e.target.value) } }))}
                                            className={cn(CLASSE_INPUT, 'text-[13px] tabular-nums')} data-campo="garantia-tempo" />
                                        <Seletor valor={garantia.unidade ?? ''} vazio="…" disabled={m.disabled} data-campo="garantia-unidade" className="w-28 text-[13px]"
                                            opcoes={Object.fromEntries((schema.garantia?.unidades ?? ['dias', 'meses', 'anos']).map((u) => [u, u]))}
                                            onChange={(u) => m.mudarRasc((r) => ({ garantia: { ...r.garantia, unidade: u } }))} />
                                    </div>
                                )}
                            </div>
                        </div>

                        <label className="block">
                            <span className="mb-1 block text-[11px] font-bold uppercase tracking-[0.05em] text-white/40">Forma de envio</span>
                            <Seletor valor={modo} disabled={m.disabled} data-campo="envio" className="text-[13px]" opcoes={opcoesEnvio}
                                onChange={(novo) => m.mudarRasc((r) => ({ envio: { ...r.envio, modo: novo ?? 'me2' } }))} />
                        </label>

                        <div>
                            <span className="mb-1 block text-[11px] font-bold uppercase tracking-[0.05em] text-white/40">Frete grátis</span>
                            <label className={cn('flex items-center gap-2.5 py-2', freteObrigatorio ? 'cursor-not-allowed' : 'cursor-pointer')}>
                                <input type="checkbox" checked={!! rasc.envio?.frete_gratis || freteObrigatorio} disabled={m.disabled || freteObrigatorio}
                                    onChange={(e) => m.mudarRasc((r) => ({ envio: { ...r.envio, frete_gratis: e.target.checked } }))}
                                    className="rounded border-white/20 bg-transparent text-ecf-yellow" data-campo="frete-gratis" />
                                <span className="text-[13px] text-white/70">Oferecer frete grátis para o comprador</span>
                            </label>
                            {frete?.conhecido && (
                                <p className={cn('flex items-start gap-1.5 text-[11px]', freteObrigatorio ? 'text-emerald-300' : 'text-white/45')} data-frete-regra={freteObrigatorio ? 'obrigatorio' : (frete.parcial ? 'parcial' : 'opcional')}>
                                    <Info size={12} className="mt-px shrink-0" />
                                    {freteObrigatorio
                                        ? 'Obrigatório: nesta faixa de preço o Mercado Livre exige frete grátis.'
                                        : (frete.parcial
                                            ? 'Opcional para as variações mais baratas; nas que passam da faixa o Mercado Livre liga o frete grátis sozinho.'
                                            : 'Opcional nesta faixa de preço.')}
                                </p>
                            )}
                        </div>
                    </div>
                </div>
            )}
        </CardMesa>
    );
}
