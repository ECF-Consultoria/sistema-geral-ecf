import { useEffect, useMemo, useState } from 'react';
import { AlertCircle, AlertTriangle, ArrowRight, Check, ChevronRight, Copy, Loader2 } from 'lucide-react';
import { fmtReais } from '@/Components/Portal/Estrutura/comum';
import { avisoSemSugestao } from '@/lib/precificacaoEnvio';
import { freteEmBranco, rotuloDoFrete } from '@/lib/precificacaoFreteSugerido';
import { rotuloDaFase } from '@/lib/precificacaoPorFamilia';
import {
    EXCECOES, PENDENCIAS, composicao, contagem, dadosParaGravar, faixaDoCartao, fmtPct, localizar, mudouDoGravado, paraNumero, paraTexto, reaisParaTexto,
    pendente, porPrioridade, primeiraParaAbrir, proximaPendente, rotuloDoMembro,
} from '@/lib/precificacaoSimulador';
import { cn } from '@/lib/utils';

// ─── Simulador da Precificação (20/10/2026) ─────────────────────────────────
//
// O desenho do simulador do onboarding de Polos (`ImplementacaoPublica.jsx`,
// SimuladorPreco), pedido pelo usuário para o Portal: catálogo de cartões em
// cima e UMA oferta por vez embaixo — "quanto você gasta" de um lado, o preço
// em destaque e "para onde vai cada real" do outro.
//
// Prioridade (pedido do usuário): o que falta preço vem primeiro; o que já tem
// preço fica recolhido em "Já precificados", apagado. Abre na primeira que falta
// e, pronta uma, o "Próximo que falta" leva à seguinte.
//
// A conta NÃO é feita aqui: o preço, o frete sugerido e as `partes` chegam do
// servidor (`PrecificacaoEstrutura`). Esta tela só desenha e manda gravar, pelo
// `onSalvar` da página da Precificação. O custo da oferta ligada a produto é o da
// variação: aqui é só leitura, com o caminho para alterar. (A ficha do produto tem
// a sua própria lista, mais enxuta: `Produtos/PrecificacaoDoProduto`.)

const TIPOS = [['classico', 'Clássico'], ['premium', 'Premium']];
const nomeDoTipo = (tipo) => (tipo === 'premium' ? 'Premium' : 'Clássico');
const DIFERENCA_PREMIUM = 5;   // regra do onboarding: a comissão do Premium acompanha a do Clássico, +5

const CAMPO_PEQUENO = 'h-8 w-full rounded-lg border border-white/[0.08] bg-white/[0.05] px-2 text-[12px] tabular-nums text-white placeholder:text-white/30 focus:border-ecf-yellow/40 focus:outline-none focus:ring-0';
const ROTULO_PEQUENO = 'mb-1 block text-[10px] uppercase tracking-wider text-white/30';
const SECAO = 'text-[11px] uppercase tracking-wider text-white/40';

/** Copia no formato que o Mercado Livre aceita ("131.22"). O valor é o próprio botão. */
function Copiar({ valor, rotulo, className, children }) {
    const [copiado, setCopiado] = useState(false);
    const copiar = () => {
        try {
            navigator.clipboard.writeText(Number(valor).toFixed(2));
            setCopiado(true);
            setTimeout(() => setCopiado(false), 1500);
        } catch { /* sem clipboard: o valor segue visível */ }
    };
    const Icone = copiado ? Check : Copy;

    return (
        // O ícone fica FORA do fluxo (à direita do valor): escondido, não abre espaço no meio da frase.
        <button type="button" onClick={copiar} title={`Copiar ${rotulo}`} className={cn('group/copiar relative inline-flex items-center transition hover:opacity-90', className)}>
            {children}
            <Icone size={13} aria-hidden="true" className={cn('absolute left-full top-1/2 ml-1 -translate-y-1/2', copiado ? 'text-emerald-400' : 'text-white/40 opacity-0 group-hover/copiar:opacity-100')} />
        </button>
    );
}

/** Campo grande em reais, com rótulo e explicação curta — o CampoValor do onboarding. `invalido` pisca em vermelho. */
export function CampoReais({ rotulo, dica, valor, onChange, onBlur, placeholder = '0,00', invalido = false, lateral = null, ...props }) {
    return (
        <div>
            <div className="flex items-center justify-between gap-2">
                <label className={cn('block text-[13px] font-medium', invalido ? 'text-red-400' : 'text-white/70')}>{rotulo}</label>
                {lateral}
            </div>
            {dica && <p className="mb-1.5 mt-0.5 text-[11px] text-white/35">{dica}</p>}
            <div className={cn('flex items-center rounded-xl border bg-white/[0.04]', invalido ? 'animate-blink-red border-red-500' : 'border-white/[0.1] transition-colors focus-within:border-ecf-yellow/40')}>
                <span className={cn('pl-3 text-sm', invalido ? 'text-red-400' : 'text-white/30')}>R$</span>
                <input value={valor} onChange={(e) => onChange(e.target.value)} onBlur={onBlur} onKeyDown={(e) => e.key === 'Enter' && e.currentTarget.blur()}
                    inputMode="decimal" placeholder={placeholder}
                    className="h-11 w-full border-0 bg-transparent px-2 text-base font-medium tabular-nums text-white placeholder:text-white/25 focus:outline-none focus:ring-0" {...props} />
            </div>
        </div>
    );
}

/** Um cartão do catálogo: o produto (a unidade e os combos dela) ou um conjunto. Aberto, mostra cada oferta. */
function CartaoDoCatalogo({ cartao, tipo, selId, aberto, pronto, onAlternar, onEscolher }) {
    const principal = cartao.membros[0].oferta;
    const varios = cartao.membros.length > 1;
    const faltam = cartao.membros.filter(pendente).length;
    const ativo = cartao.membros.some((m) => m.oferta.id === selId);
    const faixa = faixaDoCartao(cartao, tipo);
    const textoFaixa = faixa === null ? 'sem preço'
        : (faixa.max - faixa.min > 0.005 ? `${fmtReais(faixa.min)} – ${fmtReais(faixa.max)}` : fmtReais(faixa.min));
    const nome = principal.nome || principal.sku;

    return (
        <div className={cn('rounded-xl border transition',
            ativo ? 'border-ecf-yellow/50 bg-ecf-yellow/[0.08]' : 'border-white/[0.08] bg-white/[0.02] hover:bg-white/[0.05]',
            pronto && ! ativo && 'opacity-60 hover:opacity-100')}
        data-cartao-simulador={cartao.chave} data-pronto={pronto ? 'sim' : 'nao'}>
            <button type="button" onClick={() => (varios ? onAlternar() : onEscolher(principal.id))} aria-expanded={varios ? aberto : undefined}
                className="flex w-full items-start gap-1.5 py-2 pl-2.5 pr-2 text-left">
                {varios && <ChevronRight size={13} className={cn('mt-0.5 shrink-0 text-white/35 transition-transform', aberto && 'rotate-90')} />}
                <span className="min-w-0 flex-1">
                    <span className="block truncate text-[13px] font-medium text-white/90" title={nome}>
                        {principal.fase !== 'simples' && principal.fase !== 'combo' && <span className="mr-1 text-white/45">{rotuloDaFase(principal)} ·</span>}
                        {nome}
                    </span>
                    <span className="block truncate text-[11px] text-white/40">
                        {textoFaixa}
                        <span className="text-white/30"> · {varios ? `${cartao.membros.length} ofertas` : principal.sku}</span>
                    </span>
                </span>
                {faltam > 0 ? (
                    <span title={`${faltam} sem preço`} className="mt-0.5 inline-flex shrink-0 items-center gap-1 rounded-md bg-red-500/15 px-1.5 py-0.5 text-[10px] font-semibold text-red-300">
                        <AlertCircle size={10} />{varios ? faltam : ''}
                    </span>
                ) : (
                    <Check size={13} className="mt-0.5 shrink-0 text-emerald-400" aria-label="Com preço" />
                )}
            </button>

            {varios && aberto && (
                <div className="flex flex-wrap gap-1 border-t border-white/[0.06] px-2 py-2">
                    {cartao.membros.map((m) => {
                        const sel = m.oferta.id === selId;

                        return (
                            <button key={m.oferta.id} type="button" onClick={() => onEscolher(m.oferta.id)} data-membro={m.oferta.id}
                                title={`${m.oferta.sku} · ${m.calculo?.[tipo]?.minimo != null && ! pendente(m) ? fmtReais(m.calculo[tipo].minimo) : (PENDENCIAS[m.calculo?.pendencia] ?? 'sem preço')}`}
                                className={cn('inline-flex items-center gap-1 rounded-md px-1.5 py-1 text-[11px] font-medium transition',
                                    sel ? 'bg-ecf-yellow text-black' : 'bg-white/[0.05] text-white/60 hover:bg-white/[0.1]')}>
                                {rotuloDoMembro(m.oferta)}
                                {pendente(m) && <AlertCircle size={10} className={sel ? 'text-red-700' : 'text-red-400'} />}
                            </button>
                        );
                    })}
                </div>
            )}
        </div>
    );
}

/** Um percentual do produto (exceção). Vazio = vale o da empresa, que aparece apagado. */
export function CampoPercentual({ rotulo, valor, empresa, onChange, onBlur, chave, placeholder = null, invalido = false }) {
    return (
        <div>
            <label className={cn(ROTULO_PEQUENO, invalido && 'text-red-400')}>{rotulo}</label>
            <input value={valor} onChange={(e) => onChange(e.target.value)} onBlur={onBlur} onKeyDown={(e) => e.key === 'Enter' && e.currentTarget.blur()}
                inputMode="decimal" placeholder={placeholder ?? paraTexto(empresa)} className={cn(CAMPO_PEQUENO, invalido && 'animate-blink-red border-red-500')}
                data-excecao={chave} data-invalido={invalido ? 'sim' : undefined} />
        </div>
    );
}

/**
 * O lado de quem digita: o que é a oferta, quanto se gasta (custo e frete do tipo aberto) e os percentuais
 * próprios. Grava ao sair do campo, a linha inteira, como a tela da Precificação.
 */
function EntradaDaOferta({ membro, tipo, parametros, freteTabela, onSalvar }) {
    const { oferta, calculo: c } = membro;
    const doProduto = c.do_produto === true;
    const custoDigitado = c.custo?.origem === 'digitado' ? c.custo.valor : null;
    const [custo, setCusto] = useState(reaisParaTexto(custoDigitado));
    const [fretes, setFretes] = useState({ classico: reaisParaTexto(c.frete_classico), premium: reaisParaTexto(c.frete_premium) });
    const textosDasExcecoes = () => Object.fromEntries(EXCECOES.map((k) => [k, paraTexto(c.excecoes?.[k])]));
    const [excecoes, setExcecoes] = useState(textosDasExcecoes);
    const [avancado, setAvancado] = useState(true);

    // Relê só quando o que ESTA oferta tem gravado mudou: gravar recarrega a conta e não pode apagar o que se digita.
    const assinaturaExcecoes = EXCECOES.map((k) => c.excecoes?.[k]).join('|');
    useEffect(() => {
        setCusto(reaisParaTexto(custoDigitado));
        setFretes({ classico: reaisParaTexto(c.frete_classico), premium: reaisParaTexto(c.frete_premium) });
        setExcecoes(textosDasExcecoes());
    }, [custoDigitado, c.frete_classico, c.frete_premium, assinaturaExcecoes]); // eslint-disable-line react-hooks/exhaustive-deps

    const salvar = (mudancas = {}) => {
        const atual = { custo, fretes, excecoes, ...mudancas };
        const dados = dadosParaGravar({ doProduto, custo: atual.custo, freteClassico: atual.fretes.classico, fretePremium: atual.fretes.premium, excecoes: atual.excecoes });
        const gravado = { ...(doProduto ? {} : { custo: custoDigitado }), frete_classico: c.frete_classico, frete_premium: c.frete_premium, ...c.excecoes };
        if (! mudouDoGravado(dados, gravado)) return;
        onSalvar(oferta, dados);
    };

    const mudarExcecao = (chave, texto) => setExcecoes((atual) => {
        const novo = { ...atual, [chave]: texto };
        const classico = parseFloat(paraNumero(texto));
        if (chave === 'comissao_classico' && Number.isFinite(classico)) {
            novo.comissao_premium = paraTexto(Math.round((classico + DIFERENCA_PREMIUM) * 100) / 100);
        }

        return novo;
    });

    const t = c[tipo];
    const outro = tipo === 'classico' ? 'Premium' : 'Clássico';
    const origem = rotuloDoFrete(t, outro, fmtReais);
    const semSugestao = avisoSemSugestao(c.envio, t);
    const semFrete = !! t?.sem_frete;
    const vigencia = freteTabela?.vigente_desde ? ` (tabela do Mercado Livre vigente desde ${String(freteTabela.vigente_desde).slice(0, 10).split('-').reverse().join('/')})` : '';
    const comps = Array.isArray(oferta.componentes) ? oferta.componentes : [];
    const corTipo = tipo === 'classico' ? 'text-blue-300' : 'text-violet-300';

    // O custo: o da variação (só leitura aqui, com o caminho para o produto) ou o digitado aqui.
    let campoCusto;
    if (doProduto) {
        campoCusto = (
            <div>
                <p className="text-[13px] font-medium text-white/70">Custo do produto</p>
                <p className="mb-1.5 mt-0.5 text-[11px] text-white/35">Vem do cadastro do produto (o custo da variação).</p>
                <div className="flex h-11 items-center justify-between rounded-xl border border-white/[0.06] bg-white/[0.02] px-3" data-custo-do-produto>
                    <span className={cn('text-base font-medium tabular-nums', c.custo?.valor != null ? 'text-white/80' : 'text-red-300')}>
                        {c.custo?.valor != null ? fmtReais(c.custo.valor) : 'sem custo'}
                    </span>
                    <a href={route('portal.auth.estrutura.produtos', { q: oferta.sku })} className="text-[12px] text-ecf-yellow hover:underline" data-acao="alterar-no-produtos">
                        Alterar no produto
                    </a>
                </div>
            </div>
        );
    } else {
        const calculado = c.custo?.calculado;
        campoCusto = (
            <CampoReais rotulo="Custo do produto" valor={custo} onChange={setCusto} onBlur={() => salvar()}
                dica={comps.length > 0
                    ? (calculado != null ? `Em branco vale a soma das peças: ${fmtReais(calculado)}.` : 'Em branco vale a soma das peças — falta o custo de uma delas.')
                    : 'Quanto você paga no produto (sem frete).'}
                placeholder={calculado != null ? reaisParaTexto(calculado) : '0,00'} invalido={c.pendencia === 'sem_custo'} data-campo="custo" />
        );
    }

    return (
        <div className="space-y-4 rounded-2xl border border-white/[0.08] bg-white/[0.02] p-5" data-entrada={oferta.id}>
            <p className={SECAO}>Identificação</p>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)]">
                <div>
                    <span className="mb-1 block text-[13px] font-medium text-white/70">SKU <span className="font-normal text-white/30">(código)</span></span>
                    <div className="flex h-10 items-center truncate rounded-xl border border-white/[0.06] bg-white/[0.02] px-3 font-mono text-[13px] text-white/70" title={oferta.sku}>{oferta.sku}</div>
                </div>
                <div>
                    <span className="mb-1 block text-[13px] font-medium text-white/70">{rotuloDoMembro(oferta)}</span>
                    <div className="flex h-10 items-center truncate rounded-xl border border-white/[0.06] bg-white/[0.02] px-3 text-sm text-white/70" title={oferta.nome ?? ''}>{oferta.nome || '—'}</div>
                </div>
            </div>
            {comps.length > 0 && (
                <p className="-mt-1 text-[12px] text-white/45" data-feito-de>
                    Feito de {comps.map((x) => `${x.quantidade}× ${x.nome || x.sku}`).join(' + ')}
                </p>
            )}

            <p className={cn(SECAO, 'pt-1')}>Quanto você gasta</p>
            {campoCusto}

            <div>
                <CampoReais rotulo={`Frete (${nomeDoTipo(tipo)})`} dica="Quanto custa enviar este produto. Em branco vale o sugerido."
                    valor={fretes[tipo]} onChange={(v) => setFretes((f) => ({ ...f, [tipo]: v }))}
                    onBlur={() => salvar()} placeholder={freteEmBranco(t) ? reaisParaTexto(t.frete) : '0,00'} invalido={semFrete}
                    data-campo={`frete-${tipo}`} />
                {origem && <p className={cn('mt-1.5 text-[11.5px]', origem.alerta ? 'text-amber-300' : 'text-white/45')} title={origem.fonte === 'tabela' ? `Estimado pela tabela de custos de envio${vigencia}.` : undefined} data-frete-origem={origem.tipo} data-frete-alerta={origem.alerta ? 'sim' : undefined}>{origem.texto}</p>}
                {semFrete && (
                    <p className="mt-1.5 flex items-center gap-1.5 text-[12px] font-medium text-red-400">
                        <AlertCircle size={13} className="shrink-0" />
                        Frete não informado{semSugestao ? ` (${semSugestao})` : ''} — informe o frete deste tipo para o preço sair certo.
                    </p>
                )}
            </div>

            <button type="button" onClick={() => setAvancado((v) => ! v)} aria-expanded={avancado}
                className="flex items-center gap-2 pt-1 text-[12px] text-white/40 transition-colors hover:text-white/70">
                <span className={cn('text-[10px] transition-transform', avancado && 'rotate-180')}>▼</span>
                Ajustar percentuais (avançado) — em branco vale o da empresa
            </button>
            {avancado && (
                <div className="space-y-3 rounded-xl border border-white/[0.06] bg-white/[0.02] p-3" data-percentuais>
                    <p className={cn('text-[11px] font-bold uppercase tracking-wider', corTipo)}>{nomeDoTipo(tipo)} — taxas do site</p>
                    <div className="grid grid-cols-2 gap-2">
                        <CampoPercentual rotulo="Comissão %" chave={`comissao_${tipo}`} valor={excecoes[`comissao_${tipo}`]} empresa={parametros?.[`comissao_${tipo}`]}
                            onChange={(v) => mudarExcecao(`comissao_${tipo}`, v)} onBlur={() => salvar()} />
                        <CampoPercentual rotulo="Imposto %" chave="imposto" valor={excecoes.imposto} empresa={parametros?.imposto}
                            onChange={(v) => mudarExcecao('imposto', v)} onBlur={() => salvar()} />
                    </div>
                    <p className="pt-1 text-[10px] uppercase tracking-wider text-white/30">Seus alvos (valem p/ Clássico e Premium)</p>
                    <div className="grid grid-cols-3 gap-2">
                        <CampoPercentual rotulo="Margem Contrib. %" chave="margem_contribuicao" valor={excecoes.margem_contribuicao} empresa={parametros?.margem_contribuicao}
                            onChange={(v) => mudarExcecao('margem_contribuicao', v)} onBlur={() => salvar()} />
                        <CampoPercentual rotulo="Lucro Líquido %" chave="lucro_liquido" valor={excecoes.lucro_liquido} empresa={parametros?.lucro_liquido}
                            onChange={(v) => mudarExcecao('lucro_liquido', v)} onBlur={() => salvar()} />
                        <div title="O acréscimo é o mesmo para todos os produtos da empresa">
                            <span className={ROTULO_PEQUENO}>Acréscimo %</span>
                            <div className="flex h-8 items-center rounded-lg border border-white/[0.05] bg-white/[0.02] px-2 text-[12px] tabular-nums text-white/50">
                                {fmtPct(c.percentuais?.acrescimo ?? parametros?.acrescimo)} <span className="ml-1 text-white/30">da empresa</span>
                            </div>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}

/** O lado do resultado: o preço em destaque, o de anunciar, para onde vai cada real, lucro e margem. */
export function ResultadoDaOferta({ membro, tipo, proxima = null, onProxima = null, titulo = null, nota = null }) {
    const c = membro.calculo;
    const t = c[tipo];
    const partes = composicao(t?.partes);
    const preco = t?.minimo;
    const pronto = ! c.pendencia;

    const cabecalho = (
        <div className="flex flex-wrap items-center justify-between gap-2">
            {pronto
                ? <span className="inline-flex items-center gap-1.5 rounded-full bg-emerald-500/10 px-2.5 py-0.5 text-[11px] font-semibold text-emerald-300" data-situacao="pronto"><Check size={12} /> Preço pronto</span>
                : <span className="inline-flex items-center gap-1.5 rounded-full bg-red-500/15 px-2.5 py-0.5 text-[11px] font-semibold text-red-300" data-situacao={c.pendencia}><AlertCircle size={12} /> {PENDENCIAS[c.pendencia] ?? 'Pendente'}</span>}
            {proxima !== null && (
                <button type="button" onClick={onProxima} data-acao="proxima-pendente"
                    className="inline-flex items-center gap-1 rounded-lg border border-white/[0.12] bg-white/[0.04] px-2.5 py-1 text-[12px] font-medium text-white/75 transition hover:border-ecf-yellow/40 hover:text-white">
                    Próximo que falta <ArrowRight size={13} />
                </button>
            )}
        </div>
    );

    if (c.pendencia === 'impossivel') {
        return (
            <div className="space-y-4 rounded-2xl border border-red-500/30 bg-red-500/[0.05] p-5" data-resultado={membro.oferta.id}>
                {cabecalho}
                <p className="flex items-start gap-2 text-[13px] text-red-200">
                    <AlertTriangle size={15} className="mt-0.5 shrink-0" />
                    Comissão + imposto + margem + lucro somam 100% ou mais: não existe preço que feche essa conta. Diminua algum percentual.
                </p>
            </div>
        );
    }

    return (
        <div className="space-y-4 rounded-2xl border border-ecf-yellow/20 bg-gradient-to-b from-ecf-yellow/[0.06] to-transparent p-5" data-resultado={membro.oferta.id}>
            {cabecalho}
            {preco != null ? (
                <>
                    <div>
                        <p className={SECAO}>Preço de venda sugerido · {titulo ? `${titulo} · ` : ''}{nomeDoTipo(tipo)}</p>
                        <Copiar valor={preco} rotulo="o preço da promoção" className="mt-1">
                            <span className={cn('font-display text-4xl font-extrabold leading-none tabular-nums', t.sem_frete ? 'text-white/45' : 'text-ecf-yellow')} data-preco-sugerido>{fmtReais(preco)}</span>
                        </Copiar>
                        {t.sem_frete ? (
                            <p className="mt-1.5 text-[12px] text-amber-200/80">Ainda sem o frete — este preço só cobre o custo. Informe o frete ao lado.</p>
                        ) : (
                            <p className="mt-1.5 text-[12px] text-white/45">
                                Anuncie por{' '}
                                <Copiar valor={t.anunciado} rotulo="o preço de anunciar" className="font-semibold text-white/80"><span data-preco-anunciar>{fmtReais(t.anunciado)}</span></Copiar>
                                {' '}e dê o desconto até {fmtReais(preco)} na Central de Promoções.
                            </p>
                        )}
                        {nota}
                    </div>

                    {partes.length > 0 && (
                        <div data-composicao-preco>
                            <p className={cn(SECAO, 'mb-1.5')}>Para onde vai cada real</p>
                            <div className="flex h-3 overflow-hidden rounded-full bg-white/[0.04]">
                                {partes.map((p) => (
                                    <div key={p.chave} title={`${p.rotulo}: ${fmtReais(p.valor)}`} style={{ width: `${Math.max(0, (p.valor / preco) * 100)}%`, background: p.cor }} />
                                ))}
                            </div>
                            <div className="mt-2 grid grid-cols-2 gap-x-4 gap-y-1">
                                {partes.map((p) => (
                                    <div key={p.chave} className="flex items-center gap-2 text-[12px]">
                                        <span className="h-2 w-2 shrink-0 rounded-full" style={{ background: p.cor }} />
                                        <span className="flex-1 text-white/50">{p.rotulo}</span>
                                        <span className="tabular-nums text-white/80">{fmtReais(p.valor)}</span>
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}

                    {t.partes && (
                        <div className="grid grid-cols-2 gap-3">
                            <div className="rounded-xl border border-green-500/20 bg-green-500/[0.06] p-3">
                                <p className="text-[11px] text-white/40">Lucro líquido por venda</p>
                                <p className="mt-0.5 text-lg font-bold tabular-nums text-green-400">{fmtReais(t.partes.lucro_liquido)}</p>
                                <p className="text-[11px] text-white/30">{fmtPct(c.percentuais?.lucro_liquido)} do preço</p>
                            </div>
                            <div className="rounded-xl border border-sky-500/20 bg-sky-500/[0.06] p-3">
                                <p className="text-[11px] text-white/40">Margem de contribuição</p>
                                <p className="mt-0.5 text-lg font-bold tabular-nums text-sky-300">{fmtReais(t.partes.margem_contribuicao)}</p>
                                <p className="text-[11px] text-white/30">{fmtPct(c.percentuais?.margem_contribuicao)} do preço</p>
                            </div>
                        </div>
                    )}
                </>
            ) : (
                <div className="flex flex-col items-center justify-center py-10 text-center">
                    <p className="text-sm text-white/50">Informe o <span className="text-white/80">custo</span> e o <span className="text-white/80">frete</span> ao lado</p>
                    <p className="mt-1 text-[12px] text-white/30">e o preço de venda aparece aqui automaticamente.</p>
                </div>
            )}
        </div>
    );
}

/**
 * `cartoes`: `[{ chave, membros: [{ oferta, calculo }], familia? }]` (lib `precificacaoSimulador`).
 * `onSalvar(oferta, dados)` devolve uma Promise; `{ erro }` na resposta aparece ao lado do "Salvo".
 */
export default function SimuladorPrecificacao({ cartoes, parametros, freteTabela = null, onSalvar, carregando = false }) {
    const [tipo, setTipo] = useState('classico');
    const [selId, setSelId] = useState(() => primeiraParaAbrir(cartoes));
    const [aberto, setAberto] = useState(null);
    const [verProntos, setVerProntos] = useState(false);
    const [salvando, setSalvando] = useState(0);
    const [erro, setErro] = useState(null);

    const { faltam, prontos } = useMemo(() => porPrioridade(cartoes), [cartoes]);
    const conta = useMemo(() => contagem(cartoes), [cartoes]);
    const achado = localizar(cartoes, selId);
    const mostrarProntos = verProntos || faltam.length === 0;
    const proxima = achado ? proximaPendente(cartoes, selId) : null;

    // A oferta aberta saiu da lista (outra página, outro filtro): abre a primeira que falta.
    useEffect(() => {
        if (! achado) setSelId(primeiraParaAbrir(cartoes));
    }, [cartoes, achado]);

    // Escolher uma oferta abre o cartão dela (acordeão: um aberto por vez, como no onboarding).
    const chaveDoSel = achado?.cartao.chave ?? null;
    useEffect(() => {
        if (chaveDoSel && achado.cartao.membros.length > 1) setAberto(chaveDoSel);
    }, [chaveDoSel]); // eslint-disable-line react-hooks/exhaustive-deps

    const salvar = async (oferta, dados) => {
        setSalvando((n) => n + 1);
        setErro(null);
        try {
            const r = await onSalvar(oferta, dados);
            if (r?.erro) setErro(r.erro);
        } finally {
            setSalvando((n) => n - 1);
        }
    };

    const cartao = (c, pronto) => (
        <CartaoDoCatalogo key={c.chave} cartao={c} tipo={tipo} selId={selId} pronto={pronto}
            aberto={aberto === c.chave}
            onAlternar={() => {
                // Abrir o cartão também escolhe: o painel de baixo nunca fica sem dono.
                if (! c.membros.some((m) => m.oferta.id === selId)) setSelId((c.membros.find(pendente) ?? c.membros[0]).oferta.id);
                setAberto((a) => (a === c.chave ? null : c.chave));
            }}
            onEscolher={setSelId} />
    );

    if (cartoes.length === 0) {
        return <p className="rounded-2xl border border-white/[0.08] bg-white/[0.02] py-8 text-center text-[13px] text-white/50" data-simulador-vazio>Nenhuma oferta para precificar aqui.</p>;
    }

    return (
        <div className="space-y-5" data-simulador>
            <div className="space-y-3">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <p className="text-[11px] uppercase tracking-wider text-white/40" data-contagem>
                        {conta.faltam > 0
                            ? <>Faltam preço <span className="font-semibold text-red-300">({conta.faltam})</span></>
                            : <span className="text-emerald-300">Tudo com preço</span>}
                        <span className="text-white/25"> · {conta.prontas} de {conta.total} {conta.total === 1 ? 'oferta pronta' : 'ofertas prontas'}</span>
                    </p>
                    <div className="flex items-center gap-3">
                        <span className="inline-flex items-center gap-1.5 text-[11px] text-white/35" data-estado-salvo>
                            {salvando > 0 || carregando
                                ? <><Loader2 size={11} className="animate-spin text-ecf-yellow" /> {salvando > 0 ? 'Salvando…' : 'Calculando…'}</>
                                : erro
                                    ? <span className="text-red-300">{erro}</span>
                                    : <><span className="h-1.5 w-1.5 rounded-full bg-green-400" /> Salvo automaticamente</>}
                        </span>
                        <div className="inline-flex rounded-xl border border-white/[0.08] bg-white/[0.04] p-1" role="group" aria-label="Clássico ou Premium">
                            {TIPOS.map(([k, l]) => (
                                <button key={k} type="button" onClick={() => setTipo(k)} aria-pressed={tipo === k} data-tipo-anuncio={k}
                                    className={cn('rounded-lg px-4 py-1.5 text-[13px] font-semibold transition', tipo === k ? 'bg-ecf-yellow text-black' : 'text-white/50 hover:text-white/80')}>
                                    {l}
                                </button>
                            ))}
                        </div>
                    </div>
                </div>

                {faltam.length > 0 ? (
                    <div className="-mr-1 max-h-[300px] overflow-y-auto pr-1">
                        <div className="grid grid-cols-1 items-start gap-2 sm:grid-cols-2 lg:grid-cols-3" data-catalogo="faltam">
                            {faltam.map((c) => cartao(c, false))}
                        </div>
                    </div>
                ) : (
                    <p className="flex items-center gap-2 rounded-xl border border-emerald-500/20 bg-emerald-500/[0.05] px-3 py-2 text-[12.5px] text-emerald-300">
                        <Check size={14} /> Todas as ofertas daqui já têm preço.
                    </p>
                )}

                {prontos.length > 0 && (
                    <div>
                        {faltam.length > 0 && (
                            <button type="button" onClick={() => setVerProntos((v) => ! v)} aria-expanded={mostrarProntos} data-acao="ver-precificados"
                                className="flex items-center gap-1.5 py-1 text-[12px] text-white/45 transition hover:text-white/75">
                                <ChevronRight size={13} className={cn('transition-transform', mostrarProntos && 'rotate-90')} />
                                Já precificados ({prontos.reduce((n, c) => n + c.membros.length, 0)})
                            </button>
                        )}
                        {mostrarProntos && (
                            <div className="-mr-1 mt-1.5 max-h-[220px] overflow-y-auto pr-1">
                                <div className="grid grid-cols-1 items-start gap-2 sm:grid-cols-2 lg:grid-cols-3" data-catalogo="prontos">
                                    {prontos.map((c) => cartao(c, true))}
                                </div>
                            </div>
                        )}
                    </div>
                )}
            </div>

            {achado && (
                <div className="grid grid-cols-1 gap-5 lg:grid-cols-2">
                    <EntradaDaOferta key={achado.membro.oferta.id} membro={achado.membro} tipo={tipo} parametros={parametros}
                        freteTabela={freteTabela} onSalvar={salvar} />
                    <ResultadoDaOferta membro={achado.membro} tipo={tipo} proxima={proxima} onProxima={() => proxima !== null && setSelId(proxima)} />
                </div>
            )}
        </div>
    );
}
