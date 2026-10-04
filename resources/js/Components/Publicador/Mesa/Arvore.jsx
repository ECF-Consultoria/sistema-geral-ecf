import { useRef } from 'react';
import { FileText, Images, Layers, ListChecks, Package, Plus, Receipt, Truck, Type } from 'lucide-react';
import { ITENS, ITEM_NOVA_VARIACAO, contarBloqueios, contarItensProntos, itemDaVariante, partesDoItem, problemasDaVariante, problemasDoItem } from '../apoio';
import { contagemDaFicha, problemasDoGrupoDaFicha } from './CardFichaTecnica';
import { corDaVariante } from './CartaoVariante';
import { fotosDaVariante } from './CardVariacoes';
import { PontoDeStatus } from './comum';
import { cn } from '@/lib/utils';

// ─── Coluna esquerda: a estrutura do anúncio (Conceito E, 03/10/2026) ───────
//
// A árvore do anúncio: 8 itens com ícone, nome e ponto de status (verde pronto,
// âmbar faltando); a ficha abre em "Pedidos pelo ML (11/15)" e "Outras
// características"; "Variações" lista cada variação (com a cor, quando o eixo
// traz) e "+ nova variação". No rodapé, as verificações do Mercado Livre que já
// passam. Clicar seleciona o item que o centro mostra. Setas ↑↓ andam pela
// árvore; Home/End vão às pontas. Abaixo de `md` vira um seletor nativo.

const ICONE = { produto: Package, ficha: ListChecks, variacoes: Layers, fotos: Images, titulos: Type, precos: Receipt, logistica: Truck, descricao: FileText };

const ITEM = 'flex w-full items-center gap-2 rounded-lg px-2.5 text-left text-[13px] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ecf-yellow';
const SUBITEM = 'flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-[13px] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ecf-yellow';
const ativo = (sim) => (sim ? 'border border-white/[0.10] bg-white/[0.06] font-bold text-white' : 'border border-transparent text-white/70 hover:bg-white/[0.04] hover:text-white');

/**
 * `estados` = `estadoDosItens(...)`; `selecionado` = chave do item aberto no centro;
 * `onSelecionar(chave)`. `pub` dá as variações, os problemas e as verificações.
 */
export default function Arvore({ pub, estados, selecionado, onSelecionar }) {
    const lista = useRef(null);
    const { m } = pub;
    const { estado } = m;
    const variantes = m.variantes.filter((v) => ! v.orfa);
    const ativas = variantes.filter((v) => v.ativa).length;
    const eixos = estado.eixos ?? [];
    const temVariacoes = eixos.some((e) => e.valores.length > 0);
    const problemas = pub.problemas;
    const ficha = m.schema ? contagemDaFicha(m) : null;
    const { raiz, sub } = partesDoItem(selecionado);
    const prontos = contarItensProntos(estados);
    const pct = pub.totalSecoes > 0 ? Math.round((pub.prontas / pub.totalSecoes) * 100) : 0;

    // Setas ↑↓ movem o foco entre os botões da árvore (Home/End vão às pontas).
    const teclado = (e) => {
        if (! ['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(e.key)) return;
        const itens = [...(lista.current?.querySelectorAll('button[data-item-arvore]') ?? [])];
        const i = itens.indexOf(document.activeElement);
        if (i === -1) return;
        e.preventDefault();
        const destino = e.key === 'Home' ? 0 : (e.key === 'End' ? itens.length - 1 : (i + (e.key === 'ArrowDown' ? 1 : -1) + itens.length) % itens.length);
        itens[destino]?.focus();
    };

    const faltamDaVariante = (v) => (v.ativa ? contarBloqueios(problemasDaVariante(problemas, v, fotosDaVariante(estado, v).grupo)) : null);
    // Mesma régua do item pai: as pendências que bloqueiam, não os campos vazios.
    const faltamDaFicha = (grupo) => (ficha ? contarBloqueios(problemasDoGrupoDaFicha(problemasDoItem('ficha', problemas), m.schema, grupo)) : null);

    const Item = ({ item, children }) => {
        const Icone = ICONE[item.chave];
        const selecionadoAqui = raiz === item.chave && (sub === null || item.chave !== 'variacoes');

        return (
            <li>
                <button type="button" onClick={() => onSelecionar(item.chave)} aria-current={selecionado === item.chave ? 'location' : undefined}
                    data-item-arvore={item.chave} data-faltam={estados[item.chave]?.faltam ?? ''}
                    className={cn(ITEM, 'h-9', ativo(selecionadoAqui && sub === null))}>
                    <Icone size={16} className={cn('shrink-0', selecionadoAqui ? 'text-white' : 'text-white/45')} aria-hidden="true" />
                    <span className="min-w-0 flex-1 truncate">{item.titulo}</span>
                    {item.chave === 'variacoes' && temVariacoes && <span className="shrink-0 text-[11px] text-white/45 tabular-nums">{ativas === 1 ? '1 ativa' : `${ativas} ativas`}</span>}
                    <PontoDeStatus faltam={estados[item.chave]?.faltam} />
                </button>
                {children}
            </li>
        );
    };

    const Sub = ({ chave, children, faltam, corDaBolinha = null, destaque = false }) => (
        <li>
            <button type="button" onClick={() => onSelecionar(chave)} aria-current={selecionado === chave ? 'location' : undefined} data-item-arvore={chave}
                className={cn(SUBITEM, destaque ? 'text-ecf-yellow hover:bg-ecf-yellow/[0.06]' : ativo(selecionado === chave))}>
                {corDaBolinha !== undefined && corDaBolinha !== null && <span className="h-2.5 w-2.5 shrink-0 rounded-full border border-white/20" style={{ backgroundColor: corDaBolinha }} aria-hidden="true" />}
                <span className="min-w-0 flex-1 truncate">{children}</span>
                {faltam !== undefined && <PontoDeStatus faltam={faltam} pequeno />}
            </button>
        </li>
    );

    const Subitens = ({ children }) => <ul className="ml-[18px] mt-0.5 space-y-0.5 border-l border-white/[0.08] pl-3">{children}</ul>;

    return (
        <>
            {/* ≥ md: a árvore. */}
            <nav aria-label="Estrutura do anúncio" className="hidden flex-col rounded-xl border border-white/[0.08] bg-ecf-card md:flex" data-arvore={selecionado}>
                <div className="flex items-center justify-between gap-2 px-4 pb-2 pt-4">
                    <span className="text-[11px] font-bold uppercase tracking-[0.05em] text-white/55">Estrutura do anúncio</span>
                    <span className={cn('font-mono text-[11px] font-bold tabular-nums', prontos === ITENS.length ? 'text-emerald-400' : 'text-white/55')} data-itens-prontos={prontos}>{prontos}/{ITENS.length} ok</span>
                </div>
                <ol ref={lista} onKeyDown={teclado} className="space-y-0.5 px-2 pb-2">
                    {ITENS.map((item) => (
                        <Item key={item.chave} item={item}>
                            {item.chave === 'ficha' && m.schema && (
                                <Subitens>
                                    <Sub chave="ficha/obrigatorios" faltam={faltamDaFicha('obrigatorios')}>Pedidos pelo ML ({ficha.obrigatoriosPreenchidos}/{ficha.obrigatorios})</Sub>
                                    <Sub chave="ficha/outras" faltam={faltamDaFicha('outras')}>Outras características</Sub>
                                </Subitens>
                            )}
                            {item.chave === 'variacoes' && m.schema && (
                                <Subitens>
                                    {variantes.map((v) => (
                                        <Sub key={v.chave} chave={itemDaVariante(v.chave)} faltam={faltamDaVariante(v)} corDaBolinha={corDaVariante(v, eixos)}>
                                            {Object.keys(v.valores ?? {}).length === 0 ? 'Produto' : v.rotulo}{! v.ativa && <span className="text-white/45"> · desativada</span>}
                                        </Sub>
                                    ))}
                                    {! m.disabled && <Sub chave={ITEM_NOVA_VARIACAO} destaque><Plus size={12} className="mr-1 inline" aria-hidden="true" />nova variação</Sub>}
                                </Subitens>
                            )}
                        </Item>
                    ))}
                </ol>
                <div className="mt-auto border-t border-white/[0.06] px-4 py-3" data-progresso-verificacoes={pub.prontas}>
                    <div className="flex items-center justify-between text-[11px] text-white/55">
                        <span>Verificações do Mercado Livre</span>
                        <span className="font-mono text-white/80">{pub.prontas} de {pub.totalSecoes}</span>
                    </div>
                    <div role="progressbar" aria-valuemin={0} aria-valuemax={100} aria-valuenow={pct} aria-label="Verificações do Mercado Livre" className="mt-1.5 h-1 overflow-hidden rounded-full bg-white/[0.08]">
                        <div className="h-full rounded-full bg-emerald-400 transition-[width]" style={{ width: `${pct}%` }} />
                    </div>
                </div>
            </nav>

            {/* < md: seletor nativo com os mesmos itens. */}
            <label className="block md:hidden">
                <span className="sr-only">Item do anúncio</span>
                <select value={selecionado ?? ''} onChange={(e) => e.target.value && onSelecionar(e.target.value)} data-arvore-seletor
                    className="h-11 w-full appearance-auto rounded-lg border border-white/[0.10] bg-white/[0.04] px-3 text-[13px] font-bold text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow [&>optgroup]:bg-ecf-card [&>option]:bg-ecf-card">
                    {ITENS.map((item) => {
                        const texto = (faltam) => (faltam === 0 ? 'pronto' : (faltam === 1 ? 'falta 1' : `faltam ${faltam}`));
                        if (item.chave === 'variacoes' && m.schema) {
                            return (
                                <optgroup key={item.chave} label={`${item.titulo} — ${texto(estados[item.chave]?.faltam ?? 0)}`}>
                                    <option value="variacoes">Variações (eixos e lista)</option>
                                    {variantes.map((v) => <option key={v.chave} value={itemDaVariante(v.chave)}>{Object.keys(v.valores ?? {}).length === 0 ? 'Produto' : v.rotulo}{v.ativa ? ` — ${texto(faltamDaVariante(v))}` : ' — desativada'}</option>)}
                                    {! m.disabled && <option value={ITEM_NOVA_VARIACAO}>+ nova variação</option>}
                                </optgroup>
                            );
                        }
                        if (item.chave === 'ficha' && m.schema) {
                            return (
                                <optgroup key={item.chave} label={`${item.titulo} — ${texto(estados[item.chave]?.faltam ?? 0)}`}>
                                    <option value="ficha">Ficha técnica (tudo)</option>
                                    <option value="ficha/obrigatorios">Pedidos pelo ML ({ficha.obrigatoriosPreenchidos}/{ficha.obrigatorios})</option>
                                    <option value="ficha/outras">Outras características</option>
                                </optgroup>
                            );
                        }

                        return <option key={item.chave} value={item.chave}>{item.titulo} — {texto(estados[item.chave]?.faltam ?? 0)}</option>;
                    })}
                </select>
            </label>
        </>
    );
}
