import { useEffect, useRef, useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { ArrowRight, BookOpen, CalendarDays, CheckCircle2, ClipboardPaste, DownloadCloud, ExternalLink, Layers, MoreHorizontal, Target, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { linkAnuncioMl } from '@/Pages/Mlb/anuncioHistoricoUtils';

// ─── Mapeamento Estrutural — peças comuns às duas visões ────────────────────
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
 * O que é raro fica aqui: colar anúncios que já existem e rever a aula. Um
 * leigo não precisa ver isso toda vez que abre a tela — o analista mostra na
 * reunião onde fica.
 */
function MaisOpcoes({ onColar, onImportar, onComoFunciona }) {
    const [aberto, setAberto] = useState(false);
    const caixa = useRef(null);

    useEffect(() => {
        if (! aberto) return;
        const fechar = (e) => { if (! caixa.current?.contains(e.target)) setAberto(false); };
        document.addEventListener('mousedown', fechar);

        return () => document.removeEventListener('mousedown', fechar);
    }, [aberto]);

    const item = 'flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-[13px] text-white/80 hover:bg-white/[0.06] hover:text-white';

    return (
        <div className="relative" ref={caixa}>
            <Botao variante="fantasma" onClick={() => setAberto(! aberto)} aria-expanded={aberto} data-acao="mais-opcoes">
                <MoreHorizontal size={15} /> Mais opções
            </Botao>
            {aberto && (
                <div className="absolute right-0 z-40 mt-1 w-64 rounded-xl border border-white/[0.10] bg-ecf-card p-1 shadow-2xl" role="menu">
                    {onImportar && (
                        <button type="button" role="menuitem" className={item} onClick={() => { setAberto(false); onImportar(); }} data-acao="importar-ml">
                            <DownloadCloud size={14} /> Puxar do Mercado Livre
                        </button>
                    )}
                    {onColar && (
                        <button type="button" role="menuitem" className={item} onClick={() => { setAberto(false); onColar(); }} data-acao="colar-anuncios">
                            <ClipboardPaste size={14} /> Colar anúncios
                        </button>
                    )}
                    <button type="button" role="menuitem" className={item} onClick={() => { setAberto(false); onComoFunciona(); }} data-acao="como-funciona">
                        <BookOpen size={14} /> Como funciona
                    </button>
                </div>
            )}
        </div>
    );
}

/** Cabeçalho do módulo: título, as duas visões e as ações de topo. */
export function CabecalhoEstrutura({ visao, onColar, onImportar, onComoFunciona }) {
    const aba = (ativa) => cn(
        'inline-flex items-center gap-1.5 rounded-xl border px-4 py-2 text-[13px] font-medium transition-colors',
        ativa ? 'border-ecf-yellow/60 bg-ecf-yellow/[0.06] text-ecf-yellow' : 'border-white/[0.08] text-white/55 hover:text-white hover:border-white/[0.16]',
    );

    return (
        <header className="space-y-3">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 className="text-white font-display font-bold text-2xl tracking-tight flex items-center gap-2">
                        <Layers size={22} className="text-ecf-yellow" />
                        Mapeamento Estrutural
                    </h1>
                    <p className="text-white/45 text-[13.5px] mt-1 leading-relaxed max-w-xl">
                        Todo produto que você tem vira oferta publicada. Produto guardado não vende.
                    </p>
                </div>
                <MaisOpcoes onColar={onColar} onImportar={onImportar} onComoFunciona={onComoFunciona} />
            </div>
            <nav className="flex gap-2" aria-label="Visões do módulo">
                <Link href={route('portal.auth.estrutura')} className={aba(visao === 'ofertas')} data-visao="ofertas">
                    <Layers size={14} /> Ofertas
                </Link>
                <Link href={route('portal.auth.estrutura.agenda')} className={aba(visao === 'agenda')} data-visao="agenda">
                    <CalendarDays size={14} /> Agenda
                </Link>
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
export function ResumoOperacional({ painel, contagem }) {
    const pct = Math.round((painel.percentual ?? 0) * 1000) / 10;
    const paraHoje = (contagem?.hoje ?? 0) + (contagem?.atrasadas ?? 0);
    const agenda = route('portal.auth.estrutura.agenda');

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
export function ProximoPasso({ passo, onCadastrar }) {
    if (! passo || passo.tipo === 'cadastrar') return null;

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
