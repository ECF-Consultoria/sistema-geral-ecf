import { ChevronDown, Plus, Trash2 } from 'lucide-react';
import { usePage } from '@inertiajs/react';
import CartaoVolume from '@/Components/Portal/Estrutura/Produtos/CartaoVolume';
import GaleriaVariacao from '@/Components/Portal/Estrutura/Produtos/GaleriaVariacao';
import { ResumoDoEnvio } from '@/Components/Portal/Estrutura/Produtos/MedidasEEnvio';
import Explicacao from '@/Components/Explicacao';
import { ConjuntosDoProduto, Obrigatorio, QuadroFotoProduto, RotuloComExplicacao } from '@/Components/Portal/Estrutura/Produtos/PecasDoProduto';
import { primeiraFoto } from '@/lib/imagensVariacao';
import { rotuloDoNovo } from '@/lib/produtosEstrutura';
import { submoduloVisivel } from '@/lib/portalSubmodulos';
import { cn } from '@/lib/utils';

// ─── Uma variação em LINHA que abre (29/10/2026) ────────────────────────────
//
// Antes cada variação era um cartão aberto com tudo (Ref, eixo, custo, estoque,
// caixa, fotos e a faixa de calculados) — duas cores iguais ocupavam 1.140 px e o
// custo e a caixa eram digitados em cada uma. Agora a variação é uma linha curta
// (foto, nome, Ref, estoque, etiquetas) e abre para editar o resto. O custo e a caixa
// vêm "iguais às outras" (`heranca`, lib/fichaLayout) com escape "próprio desta".
// Nada muda no que vai para o servidor: cada variação continua com o seu custo e as
// suas caixas no POST `linhas`.
//
// `dentroDaCor` (cor E tamanho, 28/10/2026): a linha é um tamanho dentro do bloco da cor
// (`VariacoesPorCor`). A cor e as fotos são do bloco; aqui fica o valor do 2º eixo.
// Ref e Estoque travados pelo Bling (184-10) continuam só leitura, lidos com `?.`.

const CAMPO = 'h-11 w-full min-w-0 rounded-lg border border-white/20 bg-black/40 px-3 py-0 text-[14px] text-white placeholder:text-white/30 focus:border-ecf-yellow/40 focus:outline-none focus:ring-0 lg:h-9';
const CAMPO_SO_LEITURA = cn(CAMPO, 'flex items-center border-dashed border-white/15 bg-white/[0.02] text-white/75');
const ROTULO = 'mb-1 block text-[12.5px] font-medium text-white/70';
const AJUDA = 'mt-1 text-[12px] text-white/50';
const SUBTITULO = 'text-[11.5px] font-semibold uppercase tracking-[0.06em] text-white/40';

function formatarSaldoLidoEm(iso) {
    if (! iso) return null;
    const data = new Date(iso);
    if (Number.isNaN(data.getTime())) return null;

    return data.toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

const caixaEmTexto = (caixas) => {
    const c = (caixas ?? []).find((x) => [x.c, x.l, x.a, x.kg].some((m) => String(m ?? '').trim() !== ''));
    if (! c) return 'sem caixa';
    const mais = (caixas ?? []).length - 1;

    return `${c.c || '?'} × ${c.l || '?'} × ${c.a || '?'} cm · ${c.kg || '?'} kg${mais > 0 ? ` +${mais}` : ''}`;
};

function Etiqueta({ tipo = 'neutra', children }) {
    const cor = { neutra: 'bg-white/[0.06] text-white/60', info: 'bg-sky-300/10 text-sky-200', aviso: 'bg-amber-300/10 text-amber-200', erro: 'bg-red-400/10 text-red-200' }[tipo];

    return <span className={cn('inline-flex h-[22px] items-center whitespace-nowrap rounded-full px-2.5 text-[11.5px] font-semibold', cor)}>{children}</span>;
}

/** O campo de estoque (ou o número do Bling, só leitura). */
function CampoEstoque({ variacao, ficha, id, compacto = false }) {
    if (variacao.bling?.estoque_travado) {
        return <output id={id} className={cn(CAMPO_SO_LEITURA, 'tabular-nums', compacto && 'lg:h-8')} title="Este número vem do Bling da empresa">{variacao.estoque ?? '—'}</output>;
    }

    return (
        <div className="relative">
            {/* Demo 23/10 (N8): o número que já está no campo fica selecionado ao focar ("1" sobre "1" dava "11"). */}
            <input id={id} className={cn(CAMPO, 'pr-10 tabular-nums', compacto && 'lg:h-8')} inputMode="numeric" value={variacao.estoque ?? ''} placeholder="0"
                onFocus={(e) => e.target.select()} onChange={(e) => ficha.alterar(variacao._k, 'estoque', e.target.value)}
                aria-label={compacto ? `Estoque de ${variacao.valor || variacao.codigo || 'nova variação'}` : undefined} />
            <span aria-hidden="true" className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-[12px] text-white/40">un.</span>
        </div>
    );
}

/**
 * Sem a ficha em volta (o cartão sozinho, nos testes e em qualquer outro uso), a variação vem aberta e
 * com o custo e a caixa dela mesma: nada de "igual às outras".
 */
const HERANCA_SOLTA = {
    varias: false, herdaCusto: () => true, herdaCaixa: () => true, caixasComuns: [], custoComumTexto: '',
    usarCustoComum: () => {}, usarCustoProprio: () => {}, usarCaixaComum: () => {}, usarCaixaPropria: () => {},
};

export default function CartaoVariacao({
    variacao, ficha, podeExcluir, onExcluir, aberta = true, onAlternar = () => {}, heranca = HERANCA_SOLTA, rotuloDoEixo = '', dentroDaCor = false, rotuloDoSegundo = '', fotosDaCor = null,
}) {
    const k = variacao._k;
    const gravada = !! variacao.id;
    const oferta = variacao.oferta;
    const explicacoes = ficha.explicacoes ?? {};
    const { modulos = [] } = usePage().props;
    const listaVisivel = submoduloVisivel(modulos, 'lista');
    const erro = ficha.erros[k];
    const semRef = String(variacao.codigo ?? '').trim() === '';
    const herdaCusto = heranca.herdaCusto(variacao);
    const herdaCaixa = heranca.herdaCaixa(variacao);
    const caixas = ficha.caixasEdit(variacao);
    const nome = dentroDaCor ? (variacao.valor_2 || rotuloDoNovo(rotuloDoSegundo)) : (variacao.valor || variacao.codigo || rotuloDoNovo(rotuloDoEixo));
    const fotos = fotosDaCor ?? variacao.imagens ?? [];
    const nConjuntos = Array.isArray(oferta?.conjuntos) ? oferta.conjuntos.length : 0;
    const linhaId = `variacao-linha-${k}`;
    const detalheId = `variacao-detalhe-${k}`;

    const alternar = (e) => {
        if (e.target.closest('input, output, button, a, select, label')) return;
        onAlternar(k);
    };

    return (
        <section id={`variacao-${k}`} className={cn('scroll-mt-4 rounded-xl border', aberta ? 'border-white/[0.14] bg-white/[0.03]' : 'border-white/[0.07] bg-white/[0.015]', erro && 'border-red-400/40')}
            data-variacao-form data-aberta={aberta ? 'sim' : 'nao'}>
            {/* A linha inteira abre e fecha; o estoque dela edita sem abrir. */}
            <div className="grid cursor-pointer grid-cols-[62px_minmax(0,1fr)_auto] items-center gap-x-4 gap-y-2 px-3 py-2.5 lg:grid-cols-[62px_minmax(0,1.3fr)_minmax(0,1fr)_132px_230px_32px]"
                onClick={alternar} data-linha-variacao>
                <QuadroFotoProduto nome={variacao.nome} foto={primeiraFoto(fotos)} tamanho="mini" />
                <div className="min-w-0">
                    <p className="truncate text-[15px] font-semibold text-white">{nome}</p>
                    <p className="truncate text-[12px] text-white/45">
                        {dentroDaCor && variacao.valor ? `${variacao.valor} · ` : ''}
                        {nConjuntos > 0 ? `faz parte de ${nConjuntos} ${nConjuntos === 1 ? 'conjunto' : 'conjuntos'}` : (gravada ? 'vendida sozinha' : 'ainda não salva')}
                    </p>
                </div>
                <span className="hidden truncate font-pub-mono text-[12.5px] text-white/70 lg:block" title={variacao.codigo}>{variacao.codigo || '—'}</span>
                <div className="hidden lg:block"><CampoEstoque variacao={variacao} ficha={ficha} id={`estoque-linha-${k}`} compacto /></div>
                <div className="hidden flex-wrap justify-end gap-1.5 lg:flex">
                    {erro && <Etiqueta tipo="erro">não salva</Etiqueta>}
                    {! erro && semRef && <Etiqueta tipo="aviso">sem referência</Etiqueta>}
                    {heranca.varias && ! herdaCusto && <Etiqueta tipo="info">custo próprio</Etiqueta>}
                    {heranca.varias && ! herdaCaixa && <Etiqueta tipo="info">caixa própria</Etiqueta>}
                    {! dentroDaCor && <Etiqueta>{fotos.length} {fotos.length === 1 ? 'foto' : 'fotos'}</Etiqueta>}
                </div>
                <button type="button" onClick={() => onAlternar(k)} aria-expanded={aberta} aria-controls={detalheId} id={linhaId}
                    aria-label={aberta ? `Fechar ${nome}` : `Abrir ${nome}`} data-acao="abrir-variacao"
                    className="grid h-8 w-8 place-items-center justify-self-end rounded-lg text-white/50 hover:bg-white/[0.06] hover:text-white">
                    <ChevronDown size={18} className={cn('transition-transform', aberta && 'rotate-180')} />
                </button>
            </div>

            {aberta && (
                <div id={detalheId} role="region" aria-labelledby={linhaId} className="space-y-5 border-t border-white/[0.07] px-4 pb-4 pt-4" data-detalhe-variacao>
                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        <div>
                            {dentroDaCor ? (
                                <>
                                    <label className={ROTULO} htmlFor={`valor2-${k}`}>{rotuloDoSegundo || 'Valor'}</label>
                                    <input id={`valor2-${k}`} className={CAMPO} value={variacao.valor_2 ?? ''} onChange={(e) => ficha.alterar(k, 'valor_2', e.target.value)}
                                        placeholder={rotuloDoSegundo === 'Tamanho' ? 'ex.: 34-39' : 'ex.: 220V'} data-campo-valor-2 />
                                </>
                            ) : (
                                <>
                                    <RotuloComExplicacao className={ROTULO} htmlFor={`valor-${k}`} explicacao={explicacoes.valor} nome={rotuloDoEixo || 'Valor'}>{rotuloDoEixo || 'Valor'}</RotuloComExplicacao>
                                    <input id={`valor-${k}`} className={CAMPO} value={variacao.valor ?? ''} onChange={(e) => ficha.alterar(k, 'valor', e.target.value)}
                                        placeholder={String(rotuloDoEixo).toLowerCase() === 'cor' ? 'ex.: Verde' : 'ex.: Natural'} />
                                </>
                            )}
                        </div>
                        <div>
                            <RotuloComExplicacao className={ROTULO} htmlFor={`ref-${k}`} explicacao={explicacoes.ref} nome="Referência">Referência (código) <Obrigatorio /></RotuloComExplicacao>
                            {variacao.bling?.sku_travado ? (
                                <>
                                    <output id={`ref-${k}`} className={cn(CAMPO_SO_LEITURA, 'font-pub-mono text-[13px]')}>{variacao.codigo}</output>
                                    <p className={AJUDA}>este código vem do Bling da empresa e não muda aqui</p>
                                </>
                            ) : (
                                <input id={`ref-${k}`} className={cn(CAMPO, 'font-pub-mono text-[13px]', semRef && 'border-red-400/60')} value={variacao.codigo ?? ''}
                                    onChange={(e) => ficha.alterar(k, 'codigo', e.target.value)} placeholder="código interno" aria-invalid={semRef || undefined} />
                            )}
                        </div>
                        <div>
                            <RotuloComExplicacao className={ROTULO} htmlFor={`estoque-${k}`} explicacao={explicacoes.estoque} nome="Estoque">Estoque</RotuloComExplicacao>
                            <CampoEstoque variacao={variacao} ficha={ficha} id={`estoque-${k}`} />
                            {variacao.bling?.estoque_travado && (
                                <p className={AJUDA}>este número vem do Bling da empresa{formatarSaldoLidoEm(variacao.bling?.saldo_lido_em) ? ` · atualizado em ${formatarSaldoLidoEm(variacao.bling?.saldo_lido_em)}` : ''}</p>
                            )}
                        </div>
                    </div>

                    {variacao.bling?.removido && (
                        <p className="text-[12px] text-amber-300/80" data-aviso-bling-removido>Este produto não está mais no Bling. Ref e estoque voltaram a ser editáveis aqui.</p>
                    )}

                    {/* Custo e caixa: "iguais às outras" (o comum) ou "próprios desta". Com uma variação só, o
                        custo é digitado aqui mesmo e a caixa mora em Medidas e envio. */}
                    <div className="grid gap-5 xl:grid-cols-2">
                        <fieldset className="min-w-0 border-0 p-0" data-custo-da-variacao>
                            <legend className={cn(SUBTITULO, 'flex items-center gap-1')}>Custo <Explicacao texto={explicacoes.custo} nome="Custo" /></legend>
                            {heranca.varias ? (
                                <div className="mt-2 space-y-2">
                                    <label className="flex cursor-pointer items-center gap-2.5 text-[13.5px] text-white/80">
                                        <input type="radio" name={`custo-${k}`} checked={herdaCusto} onChange={() => heranca.usarCustoComum(variacao)} className="h-4 w-4 border-white/40 bg-black/40 text-white focus:ring-0 focus:ring-offset-0" />
                                        Igual às outras ({heranca.custoComumTexto})
                                    </label>
                                    <label className="flex cursor-pointer items-center gap-2.5 text-[13.5px] text-white/80">
                                        <input type="radio" name={`custo-${k}`} checked={! herdaCusto} onChange={() => heranca.usarCustoProprio(variacao)} className="h-4 w-4 border-white/40 bg-black/40 text-white focus:ring-0 focus:ring-offset-0" />
                                        Custo próprio desta
                                    </label>
                                    {! herdaCusto && (
                                        <div className="relative max-w-[220px]">
                                            <span aria-hidden="true" className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[12.5px] text-white/40">R$</span>
                                            <input id={`custo-${k}`} className={cn(CAMPO, 'pl-9 tabular-nums')} inputMode="decimal" value={variacao.custo ?? ''} placeholder="0,00"
                                                onChange={(e) => ficha.alterar(k, 'custo', e.target.value)} aria-label="Custo desta variação" />
                                        </div>
                                    )}
                                </div>
                            ) : (
                                <div className="relative mt-2 max-w-[220px]">
                                    <span aria-hidden="true" className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[12.5px] text-white/40">R$</span>
                                    <input id={`custo-${k}`} className={cn(CAMPO, 'pl-9 tabular-nums')} inputMode="decimal" value={variacao.custo ?? ''} placeholder="0,00"
                                        onChange={(e) => ficha.alterar(k, 'custo', e.target.value)} aria-label="Custo" />
                                </div>
                            )}
                        </fieldset>

                        <fieldset className="min-w-0 border-0 p-0" data-caixa-da-variacao>
                            <legend className={cn(SUBTITULO, 'flex items-center gap-1')}>Caixa de envio <Explicacao texto={explicacoes.volumes} nome="Caixa de envio" /></legend>
                            {heranca.varias ? (
                                <div className="mt-2 space-y-2">
                                    <label className="flex cursor-pointer items-center gap-2.5 text-[13.5px] text-white/80">
                                        <input type="radio" name={`caixa-${k}`} checked={herdaCaixa} onChange={() => heranca.usarCaixaComum(variacao)} className="h-4 w-4 border-white/40 bg-black/40 text-white focus:ring-0 focus:ring-offset-0" />
                                        Igual às outras ({caixaEmTexto(heranca.caixasComuns)})
                                    </label>
                                    <label className="flex cursor-pointer items-center gap-2.5 text-[13.5px] text-white/80">
                                        <input type="radio" name={`caixa-${k}`} checked={! herdaCaixa} onChange={() => heranca.usarCaixaPropria(variacao)} className="h-4 w-4 border-white/40 bg-black/40 text-white focus:ring-0 focus:ring-offset-0" />
                                        Caixa própria desta
                                    </label>
                                </div>
                            ) : (
                                <p className="mt-2 text-[13px] text-white/60">{caixaEmTexto(caixas)} · edite em{' '}
                                    <a href="#secao-medidas" onClick={(e) => { e.preventDefault(); document.getElementById('secao-medidas')?.scrollIntoView({ behavior: 'smooth' }); }}
                                        className="text-white underline decoration-white/30 underline-offset-[3px] hover:decoration-white">Medidas e envio</a>
                                </p>
                            )}
                        </fieldset>
                    </div>

                    {heranca.varias && ! herdaCaixa && (
                        <div className="space-y-2.5" data-caixas-da-variacao>
                            {caixas.map((caixa, i) => (
                                <CartaoVolume key={i} variacao={variacao} indice={i} caixa={caixa} ficha={ficha} podeRemover={caixas.length > 1} />
                            ))}
                            <button type="button" onClick={() => ficha.adicionarVolume(variacao)} data-acao="adicionar-volume-da-variacao"
                                className="inline-flex h-9 items-center gap-1.5 rounded-lg border border-dashed border-white/[0.16] px-3 text-[13px] text-white/75 hover:bg-white/[0.03] hover:text-white">
                                <Plus size={14} /> Adicionar caixa
                            </button>
                            <ResumoDoEnvio variacao={variacao} mexida={gravada && Array.isArray(variacao.volumes_digitados)} />
                        </div>
                    )}

                    {! dentroDaCor && <GaleriaVariacao variacao={variacao} aoMudar={(imagens) => ficha.definirImagens(k, imagens)} />}

                    <div className="flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-white/[0.06] pt-3 text-[12.5px] text-white/50">
                        {oferta && (
                            <span className="flex flex-wrap items-center gap-2">
                                {/* Os combos e kits em que a variação entra, com atalho para a Precificação: só aqui, no
                                    detalhe aberto — na linha fechada vai só a contagem (os SKUs eram ruído). */}
                                {nConjuntos > 0 && <><span>Faz parte de:</span><ConjuntosDoProduto conjuntos={oferta?.conjuntos} /></>}
                                {listaVisivel
                                    ? <a href={route('portal.auth.estrutura.lista', { q: oferta.sku })} className="text-white/70 underline-offset-2 hover:text-white hover:underline">Ver na Lista SKUs</a>
                                    : <a href={route('portal.auth.estrutura.precificacao', { q: oferta.sku })} className="text-white/70 underline-offset-2 hover:text-white hover:underline" data-acao="precificar-variacao">Ver na Precificação</a>}
                            </span>
                        )}
                        {podeExcluir && (
                            <button type="button" onClick={() => onExcluir(variacao)} data-acao="excluir-variacao"
                                className="ml-auto inline-flex items-center gap-1.5 font-medium text-red-300/90 hover:text-red-200">
                                <Trash2 size={14} /> Excluir {dentroDaCor ? 'este tamanho' : 'esta variação'}
                            </button>
                        )}
                    </div>

                    {erro && <p role="alert" className="text-[12.5px] text-red-300" data-erro-variacao>{erro}</p>}
                </div>
            )}
        </section>
    );
}
