import { Fragment, useEffect, useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { ArrowRight, BookOpen, CheckCircle2, ChevronLeft, ChevronRight, ExternalLink, Layers, Package, Target, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { linkAnuncioMl } from '@/Pages/Mlb/anuncioHistoricoUtils';

// ─── Mapeamento Estrutural — peças comuns aos submódulos ────────────────────
//
// A régua NÃO mora aqui. Situação, unidades e painel chegam prontos do PHP
// (`ReguaEstrutura`), e este arquivo só os DESENHA. Recalcular no JSX é como
// duas telas passam a discordar (`precificacao-onboarding-duas-telas.md` §1).

// As cores da coluna SITUAÇÃO da planilha: verde OK, âmbar "Falta", vermelho
// "Publicar".
export const ESTILO_SITUACAO = {
    ok:             'bg-emerald-500/10 text-emerald-300 border-emerald-500/25',
    falta_classico: 'bg-amber-500/10 text-amber-300 border-amber-500/25',
    falta_premium:  'bg-amber-500/10 text-amber-300 border-amber-500/25',
    publicar:       'bg-red-500/10 text-red-300 border-red-500/25',
};

export const ROTULO_CURTO_SITUACAO = {
    ok:             'OK',
    falta_classico: 'Falta Clássico',
    falta_premium:  'Falta Premium',
    publicar:       'Publicar',
};

const plural = (n, um, varios) => `${n} ${n === 1 ? um : varios}`;

export const fmtReais = (v) => (v === null || v === undefined ? '—' : Number(v).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }));

export const fmtData = (iso) => {
    if (! iso) return '—';
    // Data pura (Y-m-d): montar como local, senão o fuso joga para o dia anterior.
    const [a, m, d] = iso.slice(0, 10).split('-').map(Number);

    return new Date(a, m - 1, d).toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' });
};

export const fmtDiaSemana = (iso) => {
    const [a, m, d] = iso.slice(0, 10).split('-').map(Number);

    return new Date(a, m - 1, d).toLocaleDateString('pt-BR', { weekday: 'short' }).replace('.', '');
};

export const hojeIso = () => {
    const d = new Date();

    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};

export const somarDias = (iso, dias) => {
    const [a, m, d] = iso.split('-').map(Number);
    const x = new Date(a, m - 1, d + dias);

    return `${x.getFullYear()}-${String(x.getMonth() + 1).padStart(2, '0')}-${String(x.getDate()).padStart(2, '0')}`;
};

export function PilulaSituacao({ situacao, longa = false, vocabulario }) {
    return (
        <span className={cn('inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-semibold whitespace-nowrap', ESTILO_SITUACAO[situacao])}>
            {longa ? vocabulario?.situacoes?.[situacao] : ROTULO_CURTO_SITUACAO[situacao]}
        </span>
    );
}

/**
 * A pílula do PRODUTO fechado — agregado, não estado novo: tudo OK → "OK";
 * uma pendência só → a situação dela ("Falta Premium"); mais → "Pendências"
 * (a contagem já está ao lado — repetir o número é ruído), na cor do pior caso (vermelho se alguma está para publicar, âmbar se só
 * falta um lado).
 */
export function PilulaProduto({ resumo }) {
    if (resumo.pendentes === 0) return <PilulaSituacao situacao="ok" />;
    if (resumo.unica_situacao) return <PilulaSituacao situacao={resumo.unica_situacao} />;

    return (
        <span className={cn('inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-semibold whitespace-nowrap',
            resumo.publicar > 0 ? ESTILO_SITUACAO.publicar : ESTILO_SITUACAO.falta_premium)}>
            Pendências
        </span>
    );
}

/** Um lado da oferta (Clássico / Premium): ✓ tem, ○ falta. Duplicado mostra ×n. */
export function Lado({ rotulo, quantidade }) {
    const tem = quantidade > 0;

    return (
        <span className={cn('inline-flex items-center gap-1 text-[12px] whitespace-nowrap', tem ? 'text-emerald-300' : 'text-white/35')}>
            <span className={cn('grid h-4 w-4 place-items-center rounded-full border text-[10px]', tem ? 'border-emerald-400/60 bg-emerald-400/15' : 'border-white/20')}>
                {tem ? '✓' : ''}
            </span>
            {rotulo}{quantidade > 1 && <span className="text-white/40">×{quantidade}</span>}
        </span>
    );
}

/** Catálogo e kit virtual: avaliados, NÃO cobrados — só aparecem quando existem. */
export function Indicadores({ catalogos, kitsVirtuais }) {
    if (! catalogos && ! kitsVirtuais) return null;

    return (
        <span className="inline-flex items-center gap-1.5">
            {catalogos > 0 && <span className="rounded-md bg-sky-500/10 px-1.5 py-0.5 text-[10.5px] font-medium text-sky-300">Catálogo</span>}
            {kitsVirtuais > 0 && <span className="rounded-md bg-violet-500/10 px-1.5 py-0.5 text-[10.5px] font-medium text-violet-300">Kit virtual</span>}
        </span>
    );
}

// O código MLB como link para o anúncio publicado, em nova aba. A URL sai do
// `linkAnuncioMl`, a fonte única do sistema — o formato já divergiu uma vez.
// `stopPropagation`: o código fica dentro de linhas clicáveis (gaveta, agenda).
export function LinkMl({ mlb, className }) {
    const href = linkAnuncioMl(mlb);
    if (! href) return null;

    return (
        <a href={href} target="_blank" rel="noopener noreferrer" onClick={(e) => e.stopPropagation()}
            title="Abrir o anúncio no Mercado Livre" data-link-ml={mlb}
            className={cn('inline-flex items-center gap-1 font-mono underline decoration-dotted underline-offset-2 hover:text-ecf-yellow', className)}>
            {mlb}<ExternalLink size={11} className="shrink-0" />
        </a>
    );
}

/**
 * A capa do produto — a foto do primeiro anúncio que o acervo do ML conhece
 * (servidor). Fundo branco: as fotos do ML são de produto recortado sobre
 * branco e ficam "furadas" no tema escuro. Sem anúncio, um ícone neutro.
 */
export function FotoProduto({ url, className }) {
    return url
        ? <img src={url} alt="" loading="lazy" className={cn('h-11 w-11 shrink-0 rounded-lg bg-white object-contain', className)} />
        : <span className={cn('grid h-11 w-11 shrink-0 place-items-center rounded-lg bg-white/[0.04] text-white/25', className)}><Package size={18} /></span>;
}

/**
 * O estoque de uma oferta no ML: FAIXA entre os anúncios dela ("74–998"),
 * porque anúncios do mesmo SKU podem ter estoques diferentes (cada par
 * Clássico + Premium é um produto do vendedor com o seu). Sem anúncio no
 * acervo, não aparece. Nada de "dá para montar N kits": estoque no Full está
 * no galpão do ML, e tirá-lo de lá custa (usuário, 28/09).
 */
export function EstoqueOferta({ estoque, className }) {
    if (! estoque) return null;
    if (estoque.max === 0) return <span className={cn('text-red-300', className)} data-estoque="0">Sem estoque</span>;

    const n = (x) => x.toLocaleString('pt-BR');

    return (
        <span className={cn('text-white/55', className)} data-estoque={`${estoque.min}-${estoque.max}`}
            title={estoque.min === estoque.max ? 'Estoque no Mercado Livre' : 'Os anúncios deste SKU têm estoques diferentes no Mercado Livre'}>
            Estoque {estoque.min === estoque.max ? n(estoque.max) : `${n(estoque.min)}–${n(estoque.max)}`}
        </span>
    );
}

/** O estoque de UM anúncio, com "no Full" quando está no galpão do ML. */
export function EstoqueAnuncio({ quantidade, full, className }) {
    if (quantidade === null || quantidade === undefined) return null;

    return (
        <span className={cn(quantidade === 0 ? 'text-red-300' : 'text-white/50', className)} data-estoque-anuncio={quantidade}>
            Estoque {quantidade.toLocaleString('pt-BR')}
            {full && <span className="ml-1 rounded bg-emerald-500/10 px-1 text-[10px] font-semibold text-emerald-300" title="Este estoque está no galpão do Mercado Livre (Full)">no Full</span>}
        </span>
    );
}

/** Vendas (vitalícias, do acervo do ML). */
export function Vendas({ quantidade, className }) {
    if (quantidade === null || quantidade === undefined) return null;

    return <span className={cn('text-white/55', className)} data-vendas={quantidade}>{quantidade.toLocaleString('pt-BR')} {quantidade === 1 ? 'venda' : 'vendas'}</span>;
}

/**
 * O menor preço de cada tipo na oferta. A aula: "Clássico para o melhor preço
 * à vista, Premium para o parcelado" — Premium mais barato que o Clássico é
 * par montado ao contrário, e vira aviso.
 */
export function PrecosDaOferta({ precos, vocabulario, className }) {
    if (! precos) return null;

    return (
        <span className={cn('inline-flex flex-wrap items-center gap-x-2 text-white/60', className)} data-precos>
            {precos.classico !== null && <span>{vocabulario.tipos_curtos.classico} {fmtReais(precos.classico)}</span>}
            {precos.premium !== null && <span>{vocabulario.tipos_curtos.premium} {fmtReais(precos.premium)}</span>}
            {precos.invertido && (
                <span className="text-amber-300" data-preco-invertido title="A aula: Clássico para o melhor preço à vista, Premium para o parcelado.">
                    Premium mais barato que o Clássico
                </span>
            )}
        </span>
    );
}

// Os alertas que o próprio acervo calcula — nenhum limite é decidido aqui.
const ROTULO_ALERTA = { foto_insuficiente: 'Poucas fotos', ficha_incompleta: 'Ficha incompleta' };

/** Preço, vendas, fotos e alertas de UM anúncio (do acervo do ML). */
export function DadosDoAnuncio({ ml, className }) {
    if (! ml) return null;

    return (
        <span className={cn('inline-flex flex-wrap items-center gap-x-1.5 text-white/45', className)} data-dados-anuncio>
            {ml.preco !== null && <span>{fmtReais(ml.preco)}</span>}
            <span>· <Vendas quantidade={ml.vendas} className="text-white/45" /></span>
            <span>· {ml.fotos} {ml.fotos === 1 ? 'foto' : 'fotos'}</span>
            {ml.alertas.map((a) => (
                <span key={a} className="rounded bg-amber-500/10 px-1 text-[10.5px] font-semibold text-amber-300" data-alerta={a}>{ROTULO_ALERTA[a] ?? a}</span>
            ))}
        </span>
    );
}

/**
 * O preço que o cliente paga, como o anúncio mostra: o atual, e — quando há
 * promoção — o cheio riscado e o desconto. `preco` vem do `sale_price` do ML
 * (`{ atual, cheio }`); sem ele, `reserva` (o preço do acervo, que é o CHEIO,
 * sem promoção) — por isso o aviso "sem promoção lida".
 */
export function PrecoDeVenda({ preco, reserva, compacto = false, className }) {
    if (preco) {
        // Truncado, como o ML mostra: 17,55% de desconto aparece como "17% OFF".
        const off = preco.cheio > preco.atual ? Math.floor((1 - preco.atual / preco.cheio) * 100) : 0;

        return (
            <span className={cn('inline-flex flex-wrap items-baseline gap-x-1.5', className)} data-preco-venda={preco.atual}>
                <span>{fmtReais(preco.atual)}</span>
                {off > 0 && (
                    <>
                        {! compacto && <span className="text-[0.85em] text-white/35 line-through">{fmtReais(preco.cheio)}</span>}
                        <span className="text-[0.8em] font-semibold text-emerald-300" title={`De ${fmtReais(preco.cheio)}`}>{off}% OFF</span>
                    </>
                )}
            </span>
        );
    }
    if (reserva === null || reserva === undefined) return null;

    return <span className={className} title="Preço cheio do anúncio — a promoção, se houver, ainda não foi lida no Mercado Livre">{fmtReais(reserva)}</span>;
}

export function Botao({ variante = 'secundario', className, children, ...props }) {
    return (
        <button
            type="button"
            className={cn(
                'inline-flex items-center justify-center gap-1.5 rounded-xl px-3 py-2 text-[13px] font-medium transition-colors disabled:opacity-40 disabled:pointer-events-none',
                variante === 'primario' && 'bg-ecf-yellow text-black hover:bg-ecf-yellow/90',
                variante === 'secundario' && 'border border-white/[0.10] bg-white/[0.03] text-white/80 hover:bg-white/[0.07] hover:text-white',
                variante === 'fantasma' && 'text-white/55 hover:text-white hover:bg-white/[0.05]',
                variante === 'perigo' && 'border border-red-500/30 text-red-300 hover:bg-red-500/10',
                className,
            )}
            {...props}
        >
            {children}
        </button>
    );
}

export function Campo({ rotulo, erro, dica, children, className }) {
    return (
        <label className={cn('block', className)}>
            {rotulo && <span className={cn('block text-[12.5px] font-medium mb-1', erro ? 'text-red-400' : 'text-white/70')}>{rotulo}</span>}
            {children}
            {dica && ! erro && <span className="block text-[11.5px] text-white/35 mt-1">{dica}</span>}
            {erro && <span className="block text-[12px] text-red-400 mt-1">{erro}</span>}
        </label>
    );
}

export const CLASSE_INPUT = 'w-full rounded-xl border border-white/[0.10] bg-white/[0.04] px-3 py-2 text-[13.5px] text-white placeholder:text-white/25 focus:border-ecf-yellow/40 focus:outline-none focus:ring-0';

// Select NATIVO de propósito: o Radix Select com `value=""` derruba a tela
// (memória do projeto: "Radix value='' = tela preta"), e aqui o vazio é um
// valor legítimo (logística não informada).
export function Seletor({ valor, onChange, opcoes, vazio, className, ...props }) {
    return (
        <select
            value={valor ?? ''}
            onChange={(e) => onChange(e.target.value === '' ? null : e.target.value)}
            className={cn(CLASSE_INPUT, 'appearance-auto [&>option]:bg-ecf-card', className)}
            {...props}
        >
            {vazio !== undefined && <option value="">{vazio}</option>}
            {Object.entries(opcoes).map(([v, r]) => <option key={v} value={v}>{r}</option>)}
        </select>
    );
}

/**
 * O retorno das escritas (`back()->with('success', …)`). O portal não tem aviso
 * global, então cada página do módulo mostra o seu. Chave `success` já existe
 * no `HandleInertiaRequests` — nada novo lá.
 */
export function AvisoFlash() {
    const { flash } = usePage().props;
    const [visivel, setVisivel] = useState(null);

    useEffect(() => {
        if (flash?.success) {
            setVisivel(flash.success);
            const t = setTimeout(() => setVisivel(null), 6000);

            return () => clearTimeout(t);
        }
    }, [flash?.success, flash]);

    if (! visivel) return null;

    return (
        <div role="status" className="fixed bottom-5 left-1/2 z-[60] -translate-x-1/2 flex items-start gap-2 rounded-xl border border-emerald-500/30 bg-[#0f1a14] px-4 py-3 text-[13px] text-emerald-200 shadow-2xl max-w-[92vw]">
            <CheckCircle2 size={16} className="mt-0.5 shrink-0" />
            <span>{visivel}</span>
            <button type="button" onClick={() => setVisivel(null)} className="ml-2 text-emerald-200/60 hover:text-emerald-100" aria-label="Fechar aviso">
                <X size={14} />
            </button>
        </div>
    );
}

/**
 * Cabeçalho de cada submódulo: onde estou (o nome do submódulo, com o módulo
 * acima), para que serve, as ações daquela página e a trilha do caminho.
 *
 * A trilha é a mesma régua do menu lateral (`ModulosPortal::SUBMODULOS`), lida
 * das props da página — não há segunda lista aqui para divergir. No celular
 * ela some: o layout já desenha os submódulos numa linha abaixo do menu.
 */
export function CabecalhoEstrutura({ etapa, descricao, acoes = null, onComoFunciona, amplo = false }) {
    const { modulos = [] } = usePage().props;
    const subs = modulos.find((m) => m.chave === 'estrutura')?.submodulos ?? [];
    const atual = subs.find((s) => s.chave === etapa);

    // Variante ampla (167-20, D-25): o topo da REF-1/REF-3 — rótulo, título grande, trilha em círculos
    // ligados por linha. Só Produtos passa `amplo`; as outras páginas seguem no ramo padrão abaixo.
    // Com `amplo`, `acoes` não é desenhado aqui: a página desenha a barra de ações logo abaixo.
    if (amplo) {
        return (
            <header data-cabecalho-amplo>
                <div className="flex items-start justify-between gap-4">
                    <div className="min-w-0">
                        <p className="text-[13px] font-medium uppercase tracking-[0.2em] text-white/55">Mapeamento Estrutural</p>
                        <h1 className="mt-3 font-display text-[36px] font-bold leading-none tracking-tight text-white sm:text-[52px]" data-etapa={etapa}>{atual?.rotulo ?? 'Mapeamento Estrutural'}</h1>
                        {descricao && <p className="mt-1 max-w-[1100px] text-[16px] leading-relaxed text-white/70 sm:text-[18px]">{descricao}</p>}
                    </div>
                    {onComoFunciona && (
                        <Botao variante="fantasma" onClick={onComoFunciona} data-acao="como-funciona">
                            <BookOpen size={14} /> Como funciona
                        </Botao>
                    )}
                </div>
                <nav className="mt-6 hidden w-full max-w-[1340px] items-center sm:flex" aria-label="Etapas do Mapeamento Estrutural" data-trilha>
                    {subs.map((s, i) => {
                        const circulo = (
                            <span className={cn('grid h-[38px] w-[38px] place-items-center rounded-full border text-[15px] tabular-nums',
                                s.ativo ? 'border-ecf-yellow bg-ecf-yellow font-semibold text-black' : 'border-white/30 text-white/80')}>{i + 1}</span>
                        );

                        return (
                            <Fragment key={s.chave}>
                                {i > 0 && <span className="mx-5 h-px min-w-6 flex-1 bg-white/15" aria-hidden="true" />}
                                {s.em_breve ? (
                                    <span className="inline-flex shrink-0 cursor-default items-center gap-4 text-[16px] text-white/25" data-trilha-etapa={s.chave} data-em-breve>
                                        {circulo}{s.rotulo}<span className="text-[10px] uppercase tracking-wide">em breve</span>
                                    </span>
                                ) : (
                                    <Link href={s.url} aria-current={s.ativo ? 'step' : undefined} data-trilha-etapa={s.chave}
                                        className={cn('inline-flex shrink-0 items-center gap-4 text-[16px] transition-colors',
                                            s.ativo ? 'font-semibold text-ecf-yellow' : 'text-white/75 hover:text-white')}>
                                        {circulo}{s.rotulo}
                                    </Link>
                                )}
                            </Fragment>
                        );
                    })}
                </nav>
            </header>
        );
    }

    return (
        <header className="space-y-3">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <p className="flex items-center gap-1.5 text-[11.5px] font-semibold uppercase tracking-wider text-white/35">
                        <Layers size={13} className="text-ecf-yellow" /> Mapeamento Estrutural
                    </p>
                    <h1 className="mt-1 font-display text-2xl font-bold tracking-tight text-white" data-etapa={etapa}>{atual?.rotulo ?? 'Mapeamento Estrutural'}</h1>
                    {descricao && <p className="mt-1 max-w-2xl text-[13.5px] leading-relaxed text-white/45">{descricao}</p>}
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    {acoes}
                    {onComoFunciona && (
                        <Botao variante="fantasma" onClick={onComoFunciona} data-acao="como-funciona">
                            <BookOpen size={14} /> Como funciona
                        </Botao>
                    )}
                </div>
            </div>
            <nav className="hidden flex-wrap items-center gap-1 rounded-2xl border border-white/[0.08] bg-ecf-card p-1.5 sm:flex" aria-label="Etapas do Mapeamento Estrutural" data-trilha>
                {subs.map((s, i) => {
                    const classe = cn('inline-flex items-center gap-2 rounded-xl px-3 py-1.5 text-[12.5px] transition-colors',
                        s.ativo ? 'bg-ecf-yellow/10 font-semibold text-ecf-yellow' : 'text-white/55 hover:bg-white/[0.04] hover:text-white');
                    const numero = (
                        <span className={cn('grid h-5 w-5 place-items-center rounded-full border text-[10.5px] tabular-nums',
                            s.ativo ? 'border-ecf-yellow/60' : 'border-white/15 text-white/40')}>{i + 1}</span>
                    );

                    return (
                        <span key={s.chave} className="inline-flex items-center gap-1">
                            {i > 0 && <ArrowRight size={12} className="text-white/20" aria-hidden />}
                            {s.em_breve ? (
                                <span className={cn(classe, 'cursor-default text-white/25 hover:bg-transparent hover:text-white/25')} data-trilha-etapa={s.chave} data-em-breve>
                                    {numero}{s.rotulo}<span className="text-[10px] uppercase tracking-wide">em breve</span>
                                </span>
                            ) : (
                                <Link href={s.url} className={classe} aria-current={s.ativo ? 'step' : undefined} data-trilha-etapa={s.chave}>
                                    {numero}{s.rotulo}
                                </Link>
                            )}
                        </span>
                    );
                })}
            </nav>
        </header>
    );
}

const fmtInt = (n) => (n ?? 0).toLocaleString('pt-BR');

function Numero({ valor, rotulo, detalhe, cor, href, ...dados }) {
    const corpo = (
        <>
            <span className={cn('block font-display text-[22px] font-bold leading-none', cor)} {...dados}>{fmtInt(valor)}</span>
            <span className="mt-1 flex items-center gap-1 text-[12px] text-white/50">
                {rotulo}
                {detalhe && <span className="text-red-300/90">· {detalhe}</span>}
                {href && <ArrowRight size={12} className="text-white/30" />}
            </span>
        </>
    );

    return href
        ? <Link href={href} className="block rounded-lg -m-1.5 p-1.5 hover:bg-white/[0.04]">{corpo}</Link>
        : <div>{corpo}</div>;
}

/**
 * O resumo do topo: TRABALHO primeiro (anúncios a publicar, o que é de hoje,
 * Jardinagem, completas), progresso ao lado. Faixa baixa de propósito — a ação
 * da tela é o "Próximo passo", logo abaixo. Os números da planilha (K5:K15)
 * vêm somados sobre TODAS as ofertas, nunca sobre a página; os da agenda vêm
 * contados no servidor (`EstruturaVisaoService::agenda()['contagem']`).
 */
export function ResumoOperacional({ painel, contagem, agendaVisivel = true }) {
    const pct = Math.round((painel.percentual ?? 0) * 1000) / 10;
    const paraHoje = (contagem?.hoje ?? 0) + (contagem?.atrasadas ?? 0);
    // Quem não vê o Cronograma (o cliente, desde 09/10/2026) não ganha link para ele.
    const agenda = agendaVisivel ? route('portal.auth.estrutura.agenda') : null;

    return (
        <section className="flex flex-col gap-4 rounded-2xl border border-white/[0.08] bg-ecf-card px-4 py-4 sm:px-5 lg:flex-row lg:items-center lg:gap-6" data-painel>
            <div className="grid grid-cols-2 gap-x-6 gap-y-3 sm:grid-cols-4 lg:shrink-0">
                <Numero valor={painel.a_publicar} rotulo="anúncios a publicar" cor="text-red-300" data-a-publicar="" />
                <Numero valor={paraHoje} rotulo="para hoje" cor="text-ecf-yellow" href={agenda}
                    detalhe={contagem?.atrasadas > 0 ? `${contagem.atrasadas} atrasada(s)` : null} data-para-hoje="" />
                <Numero valor={contagem?.jardinagem} rotulo="Jardinagem" cor="text-sky-300" href={agenda} data-jardinagem="" />
                <Numero valor={painel.completas} rotulo={painel.completas === 1 ? 'oferta completa' : 'ofertas completas'} cor="text-emerald-300" />
            </div>
            <div className="min-w-0 flex-1 lg:border-l lg:border-white/[0.06] lg:pl-6"
                title="Cada oferta precisa de 1 Clássico e 1 Premium. Anúncio repetido do mesmo tipo não soma; Inativo não conta; Pausado conta.">
                <div className="flex items-baseline justify-between gap-3">
                    <p className="text-[13px] text-white/60">
                        <strong className="text-[17px] text-white font-display" data-publicados>{fmtInt(painel.publicados)}</strong>
                        {' '}de <strong className="text-white/85" data-necessarios>{fmtInt(painel.necessarios)}</strong> anúncios publicados
                    </p>
                    <span className="font-display text-lg font-bold text-ecf-yellow" data-percentual>{pct.toLocaleString('pt-BR')}%</span>
                </div>
                <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-white/[0.06]" role="progressbar" aria-valuenow={pct} aria-valuemin={0} aria-valuemax={100}>
                    <div className="h-full rounded-full bg-ecf-yellow transition-all" style={{ width: `${Math.min(100, pct)}%` }} />
                </div>
                <p className="mt-2 text-[11.5px] text-white/35">
                    <span data-ofertas>{fmtInt(painel.ofertas)}</span> {painel.ofertas === 1 ? 'oferta' : 'ofertas'}
                    {' · '}{plural(painel.por_fase.simples, 'simples', 'simples')} · {plural(painel.por_fase.combo, 'combo', 'combos')} · {plural(painel.por_fase.kit, 'kit', 'kits')} · {plural(painel.por_fase.combit, 'combit', 'combits')}
                </p>
            </div>
        </section>
    );
}

/**
 * A faixa "próximo passo": UMA coisa a fazer agora. Quem decide qual é o PHP
 * (`EstruturaVisaoService::proximoPasso`), sobre o conjunto inteiro; aqui só
 * se escreve em português. Não esconde nada da tela — só aponta. O analista
 * ensina o método uma vez; depois, é esta faixa que lembra o cliente dele.
 */
export function ProximoPasso({ passo, onCadastrar, agendaVisivel = true }) {
    if (! passo || passo.tipo === 'cadastrar') return null;
    // Os passos da agenda (hoje, agendar, em dia) só existem para quem vê o Cronograma (09/10/2026).
    if (! agendaVisivel && passo.tipo !== 'variacoes') return null;

    const nome = (o) => o?.nome || o?.sku;
    const textos = {
        hoje: {
            titulo: passo.primeira?.acao === 'jardinagem'
                ? `Hoje: Jardinagem de ${nome(passo.primeira)}`
                : `Hoje: publicar ${nome(passo.primeira)}`,
            texto: (passo.quantidade > 1 ? `E mais ${passo.quantidade - 1} tarefa(s) para hoje ou atrasada(s). ` : '')
                + 'Depois de publicar, conclua na Agenda informando o código MLB.',
            botao: 'Abrir a agenda',
            href: route('portal.auth.estrutura.agenda'),
        },
        agendar: {
            titulo: `${passo.quantidade} oferta(s) ainda sem data para publicar`,
            texto: 'O ritmo é uma publicação por dia. A agenda sugere as datas para você.',
            botao: 'Agendar o que falta',
            href: route('portal.auth.estrutura.agenda') + '?proposta=1',
        },
        variacoes: {
            titulo: `${passo.quantidade} produto(s) ainda sem combo, kit ou combit`,
            texto: 'Dá combo? Em quantas unidades? Combina com qual outro produto? Use "+ Variação" no produto.',
        },
        em_dia: {
            titulo: 'Tudo em dia',
            texto: 'O que falta publicar já tem data. É só seguir a agenda.',
            botao: 'Ver a agenda',
            href: route('portal.auth.estrutura.agenda'),
        },
    };
    const t = textos[passo.tipo];
    if (! t) return null;

    return (
        <section className="flex flex-wrap items-center gap-x-5 gap-y-3 rounded-2xl border border-ecf-yellow/45 bg-ecf-yellow/[0.04] px-4 py-4 sm:px-5" data-proximo-passo={passo.tipo}>
            <div className="flex items-center gap-2.5 sm:border-r sm:border-white/[0.10] sm:pr-5">
                <Target size={22} className="shrink-0 text-ecf-yellow" />
                <span className="text-[13.5px] font-semibold text-white whitespace-nowrap">Próximo passo</span>
            </div>
            <div className="min-w-0 flex-1 basis-60">
                <p className="text-[15px] font-semibold text-ecf-yellow">{t.titulo}</p>
                <p className="text-[12.5px] text-white/55">{t.texto}</p>
            </div>
            {t.href && (
                <Link href={t.href} className="inline-flex items-center gap-1.5 rounded-xl bg-ecf-yellow px-4 py-2.5 text-[13px] font-semibold text-black hover:bg-ecf-yellow/90" data-acao="proximo-passo">
                    {t.botao} <ArrowRight size={14} />
                </Link>
            )}
        </section>
    );
}

/** 1 … 4 5 6 … 108 — sempre a primeira, a última e as vizinhas da atual. */
function paginasVisiveis(atual, total) {
    const set = new Set([1, total, atual - 1, atual, atual + 1].filter((p) => p >= 1 && p <= total));
    const lista = [...set].sort((a, b) => a - b);
    const saida = [];
    lista.forEach((p, i) => {
        if (i > 0 && p - lista[i - 1] > 1) saida.push(`…${p}`);
        saida.push(p);
    });

    return saida;
}

export function Paginacao({ paginacao, onIr, rotulo = 'grupos' }) {
    return (
        <nav className="flex flex-wrap items-center justify-between gap-3 pt-1" aria-label="Paginação" data-paginacao>
            <div className="flex items-center gap-1">
                <button type="button" disabled={paginacao.pagina <= 1} onClick={() => onIr(paginacao.pagina - 1)} aria-label="Página anterior"
                    className="rounded-lg p-2 text-white/50 hover:bg-white/[0.05] hover:text-white disabled:opacity-30">
                    <ChevronLeft size={15} />
                </button>
                {paginasVisiveis(paginacao.pagina, paginacao.paginas).map((p) => (typeof p === 'string'
                    ? <span key={p} className="px-1 text-[12.5px] text-white/30">…</span>
                    : (
                        <button key={p} type="button" onClick={() => onIr(p)} aria-current={p === paginacao.pagina ? 'page' : undefined}
                            className={cn('min-w-[32px] rounded-lg px-2 py-1.5 text-[12.5px]',
                                p === paginacao.pagina ? 'bg-ecf-yellow font-semibold text-black' : 'text-white/60 hover:bg-white/[0.05] hover:text-white')}>
                            {p}
                        </button>
                    )))}
                <button type="button" disabled={paginacao.pagina >= paginacao.paginas} onClick={() => onIr(paginacao.pagina + 1)} aria-label="Próxima página"
                    className="rounded-lg p-2 text-white/50 hover:bg-white/[0.05] hover:text-white disabled:opacity-30">
                    <ChevronRight size={15} />
                </button>
            </div>
            <span className="text-[12px] text-white/40">{paginacao.blocos} {rotulo} · {paginacao.por_pagina} por página</span>
        </nav>
    );
}
