import { useEffect, useState } from 'react';
import { Calculator, Loader2, Sparkles } from 'lucide-react';
import { Botao, CLASSE_INPUT, LinkMl, fmtReais } from '@/Components/Portal/Estrutura/comum';
import { NOME_TIPO, NOTA_TIPO, estadoDasSecoes, paraNumero, paraTexto } from '../apoio';
import { juntarTermo, termoNoTitulo } from '../ferramentas';
import { ChipSecao, PainelDaEtapa } from './comum';
import TermosMaisBuscados from './TermosMaisBuscados';
import { cn } from '@/lib/utils';

// ─── Etapa 4 — Título e preço (check "Título e preço") ──────────────────────
//
// Um bloco por tipo: ativo/inativo, MLB no ar e o título (o limite vem do
// schema). O título é POR TIPO — nunca por variação (Q-UI-10). Sem linha de
// comissão por tipo (Q-UI-13): a tarifa só aparece na simulação.
//
// O título se monta com os termos mais buscados da categoria (docx §3): à mão,
// clicando nos termos, ou pela IA, que filtra os coerentes com o produto e
// prioriza os que já estão no título.
//
// Desde 03/10/2026 o PREÇO também mora aqui (antes ficava no cartão de cada
// variação): uma tabela variação × tipo, com o "Quanto eu recebo?" embaixo.
// Preço do Portal é MOSTRADO, não gravado (docx §4; `16` §1.6).

const MAX_TITULO_PADRAO = 60;
const ROTULO = 'text-[11px] font-bold uppercase tracking-[0.05em] text-white/40';

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
                <label className="flex items-center gap-2 text-[15px] font-bold text-white">
                    <input type="checkbox" role="switch" checked={a.ativo} disabled={m.disabled || !! noAr} onChange={(e) => mudar({ ativo: e.target.checked })}
                        className="rounded border-white/20 bg-transparent text-ecf-yellow focus-visible:ring-2 focus-visible:ring-ecf-yellow" data-alvo-ativo={lt} />
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
                        <span className={ROTULO}>Título ML</span>
                        <span className={cn('font-mono text-[11px] tabular-nums', tamanho > maxTitulo ? 'text-red-300' : 'text-white/40')} data-contador-titulo={lt}>{tamanho}/{maxTitulo}</span>
                    </div>
                    <input value={titulo} disabled={m.disabled || ! a.ativo || rodando} maxLength={255} placeholder={a.titulo_efetivo ?? 'Título do anúncio…'} aria-label={`Título ${NOME_TIPO[lt]}`}
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

/**
 * Preço de uma variante num tipo. Sem preço digitado, o campo MOSTRA o preço importado da
 * Precificação do Portal (docx §4) — mas não o grava: o rascunho segue lendo a Precificação na
 * hora de conferir e publicar, para o preço não congelar (`16` §1.6). Digitar outro valor
 * sobrepõe; "usar o do Portal" volta a seguir a Precificação.
 */
function CampoPreco({ valor, efetivo, disabled, onMudar, chave, tipo, comPortal, rotulo }) {
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
                <input value={texto} onChange={(e) => setTexto(e.target.value)} onBlur={sair} disabled={disabled} inputMode="decimal" placeholder="0,00" aria-label={rotulo}
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

/** Preços: uma linha por variação (as tiradas ficam de fora), uma coluna por tipo; o "Quanto eu recebo?" embaixo. */
function TabelaDePrecos({ m, alvos }) {
    const variantes = m.variantes.filter((v) => ! v.orfa);
    const comPortal = !! m.estado.produto?.oferta_id;
    const sim = m.simulacao ?? null;
    const alvosAtivos = alvos.filter((a) => a.ativo);
    const algumDoPortal = comPortal && variantes.some((v) => alvosAtivos.some((a) => (v.precos?.[a.listing_type_id] ?? null) === null && (v.precos_efetivos?.[a.listing_type_id] ?? null) !== null));
    // Colunas da grade (classes estáticas, para o Tailwind gerar): a variação ocupa o que sobra; cada tipo, 150–240px.
    const colunas = alvos.length > 1
        ? 'sm:grid-cols-[minmax(0,1fr)_minmax(150px,240px)_minmax(150px,240px)]'
        : 'sm:grid-cols-[minmax(0,1fr)_minmax(150px,240px)]';

    return (
        <div className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-4" data-tabela-precos={variantes.length}>
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h3 className="text-[15px] font-bold text-white">Preço por variação</h3>
                <Botao onClick={() => m.simular()} disabled={m.simulando || ! m.schema} data-acao="simular">
                    {m.simulando ? <Loader2 size={14} className="animate-spin" /> : <Calculator size={14} />} Quanto eu recebo?
                </Botao>
            </div>

            <div className={cn('mt-3 hidden gap-x-4 sm:grid', colunas)} aria-hidden="true">
                <span className={ROTULO}>Variação</span>
                {alvos.map((a) => <span key={a.listing_type_id} className={ROTULO}>Preço {NOME_TIPO[a.listing_type_id]}</span>)}
            </div>
            <ul className="mt-2 divide-y divide-white/[0.06]">
                {variantes.map((v) => {
                    const semVariacao = Object.keys(v.valores ?? {}).length === 0;
                    const travada = m.disabled || v.publicada || ! v.ativa;

                    return (
                        <li key={v.chave} className={cn('grid gap-x-4 gap-y-2 py-3 max-sm:grid-cols-2 sm:items-start', colunas, ! v.ativa && 'opacity-60')} data-linha-preco={v.chave}>
                            <div className="min-w-0 max-sm:col-span-2 sm:hidden">
                                <p className="truncate text-[13px] font-bold text-white">{semVariacao ? 'Produto (sem variação)' : v.rotulo}</p>
                            </div>
                            <div className="hidden min-w-0 sm:block">
                                <p className="truncate text-[13px] font-bold text-white">{semVariacao ? 'Produto (sem variação)' : v.rotulo}</p>
                                <p className="truncate text-[11px] text-white/45">
                                    {v.atributos?.SELLER_SKU?.value_name ? <span className="font-mono">SKU {v.atributos.SELLER_SKU.value_name}</span> : null}
                                    {! v.ativa && <span> · desativada</span>}
                                    {v.publicada && <span> · publicada</span>}
                                </p>
                            </div>
                            {alvos.map((a) => (
                                <div key={a.listing_type_id}>
                                    <span className={cn(ROTULO, 'mb-1 block sm:hidden')}>Preço {NOME_TIPO[a.listing_type_id]}</span>
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

            {sim && (
                <div className="mt-4 flex flex-wrap items-center gap-3" data-simulacoes>
                    {Object.entries(sim).map(([lt, s]) => (
                        <span key={lt} className="rounded-lg border border-white/[0.08] bg-ecf-card px-3 py-1.5 font-mono text-[11px] tabular-nums text-white/70" data-simulacao={lt}>
                            <b className="font-sans text-[11px] font-bold text-white">{NOME_TIPO[lt]}</b>: {fmtReais(s.preco)} − tarifa {fmtReais(s.tarifa)}{s.frete_conhecido ? ` − frete ${fmtReais(s.frete)}` : ''} = <b className="font-bold text-emerald-400">{fmtReais(s.voce_recebe)}</b>
                            {! s.frete_conhecido && <span className="font-sans text-white/40"> (informe a embalagem para o frete)</span>}
                        </span>
                    ))}
                </div>
            )}
        </div>
    );
}

export default function CardTiposEPrecos({ m, rodape = null }) {
    const { schema } = m;
    const alvos = m.alvos ?? [];
    const maxTitulo = schema?.limites?.max_title_length ?? MAX_TITULO_PADRAO;
    const problemas = m.problemasDaSecao('tipos');
    const faltam = estadoDasSecoes(problemas, schema).tipos.faltam;
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
        <PainelDaEtapa id="etapa-tipos" titulo="Título e preço" chip={<ChipSecao faltam={faltam} />} problemas={problemas} rodape={rodape}
            apoio="Clássico e Premium saem com o mesmo SKU nas duas vitrines. O título é por tipo; o preço, por variação.">
            {! schema ? (
                <p className="text-[13px] text-white/55">Escolha a categoria para definir os títulos e os preços.</p>
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

                    {alvos.length > 0 && <TabelaDePrecos m={m} alvos={alvos} />}
                </div>
            )}
        </PainelDaEtapa>
    );
}
