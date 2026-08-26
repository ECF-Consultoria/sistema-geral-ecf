import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, X } from 'lucide-react';
import { cn, formatCurrencyCompact } from '@/lib/utils';
import { ehReservaProximoMes, somaMetaDoMes, competenciaDe } from '@/lib/polosEntrantes';
import { STATUS_META, STATUS_ORDEM } from './statusMeta';
import { montarCorDoPolo } from './poloCores';
import StatusDonut from './StatusDonut';

/**
 * ModoTV — o Painel Polos na TV da empresa. Painel de PAREDE, lido a 4–5 metros.
 *
 * Duas telas, uma por aba do painel (a lente ativa decide qual abre):
 *  · METAS       → a aba "Metas → Entrantes (M0)" INTEIRA: meta do mês, aceites, reserva,
 *                  funil de entrada por polo e prontidão de setup dos M0.
 *  · FATURAMENTO → distribuição de status (o gráfico, protagonista), faturamento × meta,
 *                  ADS e ranking de polos.
 * As setas ← → alternam. Nada gira sozinho: cada tela mostra tudo de uma vez.
 *
 * ── Régua da parede (TV 50–55" a 1920×1080 ≈ 0,63 mm/px; cap-height ≈ 0,72×font-size;
 * distância confortável ≈ cap × 200) ──────────────────────────────────────────────────
 * A 1ª versão errou o alvo por EXCESSO: herói de 230px lê a 21 m e sozinho comia 52% da
 * altura útil para mostrar UM número — daí "muito grande e pouca informação". A escala
 * abaixo mira 4–5 m e reinveste os px em MAIS dados, não em letra maior:
 *   96px → 8,7 m · 56px → 5,1 m · 46px → 4,2 m · 44px → 4,0 m · 34px → 3,1 m · 24px → 2,2 m
 * Rótulo a 24px é decisão consciente: rótulo se lê uma vez e depois se reconhece por
 * posição e cor; o que se relê de longe é o NÚMERO, e esse fica em 44–96px.
 *
 * Orçamento de altura em 1080p: 1080 − 64 (cabeçalho) − 32 (padding) = 984px úteis,
 * sem rodapé (as pills de tela e o horário migraram para o cabeçalho).
 *
 * A tela de faturamento é admin-only na origem (`/mlb/polos-painel/financeiro` exige
 * admin): sem cockpit ela nem entra na lista — não fica bloco vazio na parede.
 *
 * Fontes (nada é recalculado por regra própria aqui) — mesmas expressões de
 * EntrantesM0Panel.jsx e do cockpit do backend:
 *  · entrantes = fase 'M0' · aceites = fase 'Aceite no Projeto' (strings EXATAS)
 *  · reserva   = coluna "Status entrada" (texto livre, casado por prefixo em lib/polosEntrantes)
 *  · meta      = soma de `polos_meta_entrada` da competência corrente
 *  · faturamento/ADS/status = cockpit (ECF Drive + Adman), já agregado no backend
 */

// ── Predicados de domínio (strings EXATAS do banco / planilha) ──
const ehAceite  = (e) => e.fase === 'Aceite no Projeto';
const ehM0      = (e) => e.fase === 'M0';
const temCust   = (e) => !!(e.cust_id && String(e.cust_id).trim());
const temAcesso = (e) => e.acesso_colaborador === 'Com acesso';
const temGrupo  = (e) => e.grupo_whatsapp === true;

const MESES_BR = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
const HEX = { yellow: '#ffe600', green: '#22c55e', violet: '#a855f7', sky: '#38bdf8', fuchsia: '#e879f9' };

const pct = (n, t) => (t > 0 ? Math.round((n / t) * 100) : 0);
const fmtInt = (n) => new Intl.NumberFormat('pt-BR').format(Math.round(Number(n) || 0));

// Escala tipográfica da parede — cada valor comentado com a distância de leitura.
const FT = {
    heroi:     'clamp(2.5rem, 5vw, 6rem)',        // 96px · 8,7 m
    subHeroi:  'clamp(1rem, 1.9vw, 2.25rem)',     // 36px · 3,3 m
    kpi:       'clamp(1.5rem, 2.9vw, 3.5rem)',    // 56px · 5,1 m
    kpiSec:    'clamp(1.3rem, 2.4vw, 2.875rem)',  // 46px · 4,2 m
    barraNum:  'clamp(1.2rem, 2.3vw, 2.75rem)',   // 44px · 4,0 m
    barraNome: 'clamp(1rem, 1.75vw, 2.125rem)',   // 34px · 3,1 m
    titulo:    'clamp(0.95rem, 1.5vw, 1.75rem)',  // 29px
    rotulo:    'clamp(0.8rem, 1.25vw, 1.5rem)',   // 24px
};

// Dias úteis (seg–sex) que ainda restam no mês, contando hoje. Sem calendário de
// feriados de propósito: o número serve de ritmo ("quantos por dia"), não de prazo.
function diasUteisRestantes(hoje) {
    const fim = new Date(hoje.getFullYear(), hoje.getMonth() + 1, 0);
    let n = 0;
    for (let d = new Date(hoje.getFullYear(), hoje.getMonth(), hoje.getDate()); d <= fim; d.setDate(d.getDate() + 1)) {
        const dia = d.getDay();
        if (dia !== 0 && dia !== 6) n += 1;
    }
    return n;
}

// Mede o container em px. O donut é canvas (ECharts): altura em clamp()/% pode chegar
// como 0 e o gráfico não desenha — aqui ele recebe sempre um número resolvido.
// CALLBACK ref, não useRef + useEffect([]): a caixa do donut só existe na tela de
// faturamento. Com effect de deps vazias, quem abre o Modo TV na tela de Metas e depois
// alterna nunca observaria o nó — a altura ficaria 0 e o donut não desenharia nunca.
function useAlturaMedida() {
    const [h, setH] = useState(0);
    const observador = useRef(null);
    const ref = useCallback((el) => {
        observador.current?.disconnect();
        observador.current = null;
        if (!el) { setH(0); return; }
        setH(el.clientHeight);                       // sem ResizeObserver, ao menos mede uma vez
        if (typeof ResizeObserver === 'undefined') return;
        const ro = new ResizeObserver(([entrada]) => setH(entrada.contentRect.height));
        ro.observe(el);
        observador.current = ro;
    }, []);
    return [ref, h];
}

// Literal estavel: recriado a cada render, ele entra nas deps do useMemo do StatusDonut e,
// com notMerge, o donut refaz a animacao de entrada a cada tique do relogio (30s).
const RAIO_DONUT_TV = ['58%', '90%'];

const GAP_LINHA = 10; // gap-y das grades de lista, em px (precisa casar com a classe)

/**
 * Escalas da linha de lista. A parede tem altura fixa e a lista tem tamanho VARIÁVEL:
 * o painel real tem 5 polos, não 18. Com escala única, 5 linhas deixavam metade da tela
 * vazia ("espaçamento horrível"); com escala adaptativa, poucas linhas ficam grandes e
 * preenchem a área — mesma informação, sem buraco.
 */
const ESCALA_LINHA = {
    md: { nome: 'clamp(1.1rem, 2vw, 2.4rem)',   num: 'clamp(1.4rem, 2.7vw, 3.3rem)', apoio: 'clamp(0.85rem, 1.3vw, 1.6rem)', barra: 'clamp(8px, 0.9vw, 16px)',  altura: 82 },
    sm: { nome: FT.barraNome,                   num: FT.barraNum,                    apoio: FT.rotulo,                       barra: 'clamp(6px, 0.65vw, 12px)', altura: 62 },
    xs: { nome: 'clamp(0.9rem, 1.4vw, 1.7rem)', num: 'clamp(1.05rem, 1.8vw, 2.1rem)', apoio: 'clamp(0.7rem, 1vw, 1.2rem)',   barra: 'clamp(5px, 0.5vw, 9px)',   altura: 48 },
    xxs:{ nome: 'clamp(0.8rem, 1.15vw, 1.4rem)', num: 'clamp(0.9rem, 1.45vw, 1.75rem)', apoio: 'clamp(0.65rem, 0.9vw, 1.05rem)', barra: 'clamp(4px, 0.4vw, 7px)', altura: 38 },
};
// Teto em `md` de propósito: na TV o espaçamento com `sm` foi aprovado — o adaptativo
// existe para DESCER e caber todo mundo, não para inflar a linha quando sobra espaço.
const ORDEM_ESCALA = ['md', 'sm', 'xs', 'xxs'];

/**
 * Decide, medindo a caixa: quantos itens cabem e QUÃO GRANDE cada um pode ser.
 * Sem a medição, `slice` fixo clipava em silêncio (em 1366×768 sumia uma fileira inteira
 * de polos dentro do overflow-hidden) e a escala fixa deixava o vão gigante da produção.
 */
function useGradeAdaptativa(total, fallback = 6) {
    const [estado, setEstado] = useState({ n: fallback, escala: 'sm' });
    const observador = useRef(null);
    const elemento = useRef(null);
    const recalcular = useCallback(() => {
        const el = elemento.current;
        if (!el) return;
        const h = el.clientHeight;
        if (h < 40) return;
        // Colunas REAIS do grid ("612px 612px" → 2): replicar o breakpoint em JS erra por
        // causa da barra de rolagem e apodrece calado se a classe mudar.
        const cols = Math.max(1, getComputedStyle(el).gridTemplateColumns.split(' ').filter(Boolean).length);
        const itens = Math.max(1, total);
        const linhasNec = Math.ceil(itens / cols);
        const porLinha = (h - (linhasNec - 1) * GAP_LINHA) / linhasNec;
        // Maior escala em que TODOS cabem. O piso (xxs) só é ultrapassado em lista enorme;
        // aí, e só aí, corta — e o chamador avisa "+N" em vez de sumir com o polo em silêncio.
        const escala = ORDEM_ESCALA.find((k) => porLinha >= ESCALA_LINHA[k].altura) ?? 'xxs';
        const piso = ESCALA_LINHA.xxs.altura + GAP_LINHA;
        const linhasMax = Math.max(1, Math.floor((h + GAP_LINHA) / piso));
        const n = Math.min(itens, linhasMax * cols);
        setEstado((atual) => (atual.n === n && atual.escala === escala ? atual : { n, escala }));
    }, [total]);

    // Callback ref: a grade troca de nó quando a tela muda — effect de deps vazias
    // observaria o nó errado (ou nenhum) e a medição nunca aconteceria.
    const ref = useCallback((el) => {
        observador.current?.disconnect();
        observador.current = null;
        elemento.current = el;
        if (!el) return;
        recalcular();
        if (typeof ResizeObserver === 'undefined') return;
        const ro = new ResizeObserver(() => recalcular());
        ro.observe(el);
        observador.current = ro;
    }, [recalcular]);

    // O total muda sozinho (recarga automática a cada 5 min) sem o nó mudar.
    useEffect(() => { recalcular(); }, [recalcular]);

    return [ref, estado.n, ESCALA_LINHA[estado.escala]];
}

// ── Peças da parede ────────────────────────────────────────────────────────────

function Titulo({ children, extra = null }) {
    return (
        <div className="mb-[8px] flex items-baseline justify-between gap-3">
            <h2 className="uppercase leading-none tracking-[0.14em] text-white/45" style={{ fontSize: FT.rotulo }}>{children}</h2>
            {extra && <span className="tabular-nums leading-none text-white/35" style={{ fontSize: FT.rotulo }}>{extra}</span>}
        </div>
    );
}

// Número herói: rótulo, valor, linha de apoio e barra fina.
function Heroi({ rotulo, valor, apoio = null, pct: p = null, cor = HEX.yellow }) {
    return (
        <div className="flex min-w-0 flex-col justify-center">
            <div className="uppercase leading-[1.15] tracking-[0.14em] text-white/50" style={{ fontSize: FT.titulo }}>{rotulo}</div>
            <div className="mt-[6px] font-display font-extrabold leading-none tabular-nums text-white" style={{ fontSize: FT.heroi }}>{valor}</div>
            {apoio && <div className="mt-[8px] font-semibold leading-none tabular-nums" style={{ fontSize: FT.subHeroi, color: cor }}>{apoio}</div>}
            {p !== null && (
                <div className="mt-[10px] h-[clamp(8px,0.9vw,16px)] w-full overflow-hidden rounded-full bg-white/[0.08]">
                    <div className="h-full rounded-full" style={{ width: `${Math.max(0, Math.min(100, p))}%`, background: cor }} />
                </div>
            )}
        </div>
    );
}

// KPI: rótulo curto + número. `sec` força o degrau menor da escala.
// A fonte cai sozinha em valores longos ("R$ 277K" tem 7 caracteres): sem isso o valor
// quebra em duas linhas e desalinha o card inteiro da tira.
function Kpi({ rotulo, valor, cor = '#ffffff', apoio = null, sec = false }) {
    const n = String(valor).length;
    const fonte = sec || n > 8 ? FT.kpiSec : n > 6 ? FT.barraNum : FT.kpi;
    return (
        <div className="flex min-w-0 flex-col justify-center rounded-xl border border-white/[0.1] bg-white/[0.04] px-[16px] py-[10px]">
            <span className="truncate uppercase leading-[1.25] tracking-[0.12em] text-white/50" style={{ fontSize: FT.rotulo }}>{rotulo}</span>
            <span className="mt-[4px] whitespace-nowrap font-display font-extrabold leading-none tabular-nums"
                  style={{ color: cor, fontSize: fonte }}>{valor}</span>
            {apoio && <span className="mt-[4px] truncate leading-none text-white/35" style={{ fontSize: FT.rotulo }}>{apoio}</span>}
        </div>
    );
}

// Linha de polo: nome + número grande + números de apoio + barra do percentual.
// `escala` vem da grade (useGradeAdaptativa): com poucas linhas, a linha cresce.
function LinhaPolo({ nome, valor, apoio = null, pct: p, cor = HEX.yellow, escala = ESCALA_LINHA.sm }) {
    return (
        <div className="flex min-w-0 flex-col justify-center">
            <div className="flex items-baseline justify-between gap-3">
                {/* leading-[1.1], não leading-none: com line-height 1 o truncate corta o
                    descender de "ç"/"g" ("Bragança") por 4-6px. */}
                <span className="truncate font-semibold leading-[1.1] text-white" style={{ fontSize: escala.nome }} title={nome}>{nome}</span>
                <span className="shrink-0 whitespace-nowrap leading-none">
                    <b className="font-display font-extrabold tabular-nums" style={{ fontSize: escala.num, color: cor }}>{valor}</b>
                    {apoio && <span className="ml-[8px] tabular-nums text-white/35" style={{ fontSize: escala.apoio }}>{apoio}</span>}
                </span>
            </div>
            <div className="mt-[6px] w-full overflow-hidden rounded-full bg-white/[0.08]" style={{ height: escala.barra }}>
                <div className="h-full rounded-full" style={{ width: `${Math.max(0, Math.min(100, p))}%`, background: cor }} />
            </div>
        </div>
    );
}

export default function ModoTV({
    empresas = [],
    metasEntrada = [],
    regioes = [],
    cockpit = null,
    isAdmin = false,
    lenteInicial = 'geral',
    onSair,
    onAtualizar,
    minutosParaAtualizar = 5,
}) {
    const { asset_url } = usePage().props;
    const [agora, setAgora]             = useState(() => new Date());
    const [atualizadoEm, setAtualizado] = useState(() => new Date());

    // Relógio do cabeçalho (30s bastam — o painel não é cronômetro).
    useEffect(() => {
        const id = setInterval(() => setAgora(new Date()), 30_000);
        return () => clearInterval(id);
    }, []);

    // Recarrega os dados sozinho: a TV fica ligada o dia inteiro e ninguém aperta F5.
    useEffect(() => {
        if (!onAtualizar) return undefined;
        const id = setInterval(() => { onAtualizar(); setAtualizado(new Date()); }, minutosParaAtualizar * 60_000);
        return () => clearInterval(id);
    }, [onAtualizar, minutosParaAtualizar]);

    const mesNome  = MESES_BR[agora.getMonth()];
    const mesAtual = competenciaDe(agora);

    // ── Aba Metas → Entrantes (M0): os mesmos números de EntrantesM0Panel ──
    const entrantes = useMemo(() => empresas.filter(ehM0), [empresas]);
    const aceites   = useMemo(() => empresas.filter(ehAceite), [empresas]);
    const reservas  = useMemo(() => empresas.filter(ehReservaProximoMes), [empresas]);
    const coorte    = aceites.length + entrantes.length;          // funil de entrada
    const pctEntrada = pct(entrantes.length, coorte);             // quantos do funil já entraram
    const metaMes   = useMemo(() => somaMetaDoMes(metasEntrada, mesAtual), [metasEntrada, mesAtual]);
    const pctMeta   = pct(entrantes.length, metaMes);
    const faltam    = Math.max(0, metaMes - entrantes.length);
    const diasUteis = useMemo(() => diasUteisRestantes(agora), [agora]);
    const ritmo     = faltam > 0 && diasUteis > 0 ? (faltam / diasUteis) : 0;

    // Funil por polo: entrantes · aceites · reserva, com o % de conversão do PRÓPRIO polo
    // (ent / (ent+ace)) — igual ao mini-card da aba. Ordenado por entrantes: numa parede o
    // que interessa é quem puxa o número, não a ordem cadastral das regiões.
    // "Sem polo" em vez de descartar: empresa sem polo conta no herói e no funil, então
    // sumir daqui faria a soma das linhas não fechar com o número grande da tela.
    const poloDe = (e) => e.polo || 'Sem polo';
    const { porPolo, ordemPolos } = useMemo(() => {
        const presentes = [...new Set([...aceites, ...entrantes, ...reservas].map(poloDe))];
        // Ordem ESTÁVEL (catálogo de regiões + presentes) — é dela que sai a cor, porque
        // montarCorDoPolo é posicional: tirar a cor da lista já ordenada por volume faria
        // o polo trocar de cor sempre que alguém entrasse, e divergir do resto do painel.
        const ordem = [...new Set([...(regioes ?? []), ...presentes])].filter((p) => presentes.includes(p));
        const linhas = ordem
            .map((polo) => {
                const ent = entrantes.filter((e) => poloDe(e) === polo).length;
                const ace = aceites.filter((e) => poloDe(e) === polo).length;
                const res = reservas.filter((e) => poloDe(e) === polo).length;
                return { polo, ent, ace, res, pc: pct(ent, ent + ace) };
            })
            .sort((a, b) => (b.ent - a.ent) || (b.ace - a.ace));
        return { porPolo: linhas, ordemPolos: ordem };
    }, [aceites, entrantes, reservas, regioes]);
    const corDoPolo = useMemo(() => montarCorDoPolo(ordemPolos.map((polo) => ({ polo }))), [ordemPolos]);

    // Prontidão de setup dos M0 (informativo — NÃO é a régua de aceite).
    const setup = useMemo(() => ([
        { rotulo: 'Cust ID',            n: entrantes.filter(temCust).length,   cor: HEX.yellow },
        { rotulo: 'Acesso colaborador', n: entrantes.filter(temAcesso).length, cor: HEX.sky },
        { rotulo: 'Grupo WhatsApp',     n: entrantes.filter(temGrupo).length,  cor: HEX.green },
    ]), [entrantes]);

    // ── Faturamento: cockpit (admin) ──
    const polosCk   = cockpit?.polos ?? [];
    const temCk     = isAdmin && polosCk.length > 0 && !cockpit?.erro;
    const totalFat  = useMemo(() => polosCk.reduce((s, p) => s + (Number(p.faturamento) || 0), 0), [polosCk]);
    const totalAtiv = useMemo(() => polosCk.reduce((s, p) => s + (Number(p.ativos) || 0), 0), [polosCk]);
    const totalAds  = useMemo(
        () => polosCk.reduce((s, p) => s + (p.empresas ?? []).reduce((x, e) => x + (Number(e.ads) || 0), 0), 0),
        [polosCk],
    );
    const metaFat    = Number(cockpit?.metaFaturamento) || 0;
    const pctFat     = metaFat > 0 ? Math.round((totalFat / metaFat) * 100) : 0;
    const tetoAds    = (Number(cockpit?.adsLimites?.teto) || 0) * totalAtiv;
    const ranking    = useMemo(() => [...polosCk].sort((a, b) => (Number(b.pct) || 0) - (Number(a.pct) || 0)), [polosCk]);
    const corPoloCk  = useMemo(() => montarCorDoPolo(polosCk), [polosCk]);
    const statusDist = cockpit?.statusDist ?? null;
    const totalStatus = Number(statusDist?.total) || 0;
    // Mês do cabeçalho POR TELA: metas são sempre do mês corrente, mas `mesRefLabel` é o mês
    // SELECIONADO no cockpit financeiro (o seletor do painel) — exibi-lo na tela de metas
    // rotularia números de agosto como "Julho/2026" se alguém tivesse trocado o mês.
    const mesCorrente = `${mesNome}/${agora.getFullYear()}`;
    const mesRefFat   = cockpit?.mesRefLabel ?? mesCorrente;
    const parcial     = !!cockpit?.parcial;   // mês corrente ainda em curso (Adman ao vivo)

    // ── Telas (a lente ativa escolhe a inicial) ──
    const telas = useMemo(() => (temCk ? ['metas', 'faturamento'] : ['metas']), [temCk]);
    const [tela, setTela] = useState(() => (lenteInicial === 'metas' ? 'metas' : 'faturamento'));
    const telaAtiva = telas.includes(tela) ? tela : 'metas';
    const idx = telas.indexOf(telaAtiva);

    const proxima  = useCallback(() => setTela(telas[(idx + 1) % telas.length]), [telas, idx]);
    const anterior = useCallback(() => setTela(telas[(idx - 1 + telas.length) % telas.length]), [telas, idx]);

    useEffect(() => {
        const onKey = (ev) => {
            if (ev.key === 'ArrowRight') { ev.preventDefault(); proxima(); }
            else if (ev.key === 'ArrowLeft') { ev.preventDefault(); anterior(); }
            else if (ev.key === 'Escape') { onSair?.(); }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [proxima, anterior, onSair]);

    // Altura real da caixa do donut (canvas precisa de px resolvido) e quantos itens
    // cabem em cada lista sem clipar.
    const [refDonut, alturaDonut] = useAlturaMedida();
    const [refPolos, cabemPolos, escalaPolos]  = useGradeAdaptativa(porPolo.length, 10);
    const [refRanking, cabemRank, escalaRank]   = useGradeAdaptativa(ranking.length, 8);

    const hora = agora.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
    const horaAtualizacao = atualizadoEm.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });

    return (
        <div className="fixed inset-0 z-50 flex flex-col overflow-hidden bg-ecf-bg text-white">
            {/* Cabeçalho de 64px: logo + tela + mês + relógio (sem rodapé — a área é da parede) */}
            <div className="flex shrink-0 items-center justify-between gap-[20px] px-[24px] pb-[10px] pt-[14px]">
                <div className="flex min-w-0 items-center gap-[16px]">
                    <img
                        src={`${asset_url ?? ''}/images/logo.png`}
                        alt="ECF Consultoria"
                        className="ecf-logo w-auto shrink-0 object-contain"
                        style={{ height: 'clamp(20px, 2.1vw, 40px)' }}
                        onError={(e) => { e.currentTarget.style.display = 'none'; }}
                    />
                    <div className="flex items-center gap-[8px]">
                        {telas.map((t) => (
                            <button key={t} type="button" onClick={() => setTela(t)}
                                    className={cn('rounded-lg px-[12px] py-[4px] uppercase leading-none tracking-[0.16em] transition-colors',
                                        t === telaAtiva ? 'bg-ecf-yellow/15 text-ecf-yellow' : 'text-white/25 hover:text-white/50')}
                                    style={{ fontSize: FT.rotulo }}>
                                {t === 'metas' ? 'Metas' : 'Faturamento'}
                            </button>
                        ))}
                    </div>
                </div>
                <div className="flex shrink-0 items-center gap-[16px]">
                    <span className="uppercase leading-none tracking-[0.16em] text-white/40" style={{ fontSize: FT.rotulo }}>
                        {telaAtiva === 'metas' ? mesCorrente : mesRefFat}{telaAtiva !== 'metas' && parcial ? ' · parcial' : ''}
                    </span>
                    <span className="leading-none text-white/25" style={{ fontSize: FT.rotulo }}>atual. {horaAtualizacao}</span>
                    <span className="font-display font-extrabold leading-none tabular-nums text-white" style={{ fontSize: FT.titulo }}>{hora}</span>
                    {/* Controles quase invisíveis: a TV não tem mouse — reaparecem no hover. */}
                    <div className="flex items-center gap-1.5 opacity-10 transition-opacity hover:opacity-100">
                        {telas.length > 1 && (
                            <>
                                <button type="button" onClick={anterior} title="Tela anterior (←)"
                                        className="rounded-lg border border-white/[0.1] bg-white/[0.04] p-1.5 text-white/70 hover:bg-white/[0.1]"><ChevronLeft size={16} /></button>
                                <button type="button" onClick={proxima} title="Próxima tela (→)"
                                        className="rounded-lg border border-white/[0.1] bg-white/[0.04] p-1.5 text-white/70 hover:bg-white/[0.1]"><ChevronRight size={16} /></button>
                            </>
                        )}
                        <button type="button" onClick={() => onSair?.()} title="Sair do Modo TV (Esc)"
                                className="rounded-lg border border-white/[0.1] bg-white/[0.04] p-1.5 text-white/70 hover:bg-white/[0.1]"><X size={16} /></button>
                    </div>
                </div>
            </div>

            {/* Corpo: TELA ÚNICA — tudo visível de uma vez, sem rolagem. */}
            <div className="min-h-0 flex-1 px-[24px] pb-[20px]">
                {telaAtiva === 'metas' ? (
                    /* ── METAS = a aba "Entrantes (M0)" inteira: meta, funil, polos e setup ── */
                    <div className="grid h-full grid-rows-[auto_minmax(0,1fr)_auto] gap-[16px]">
                        {/* Faixa A — meta do mês + os 4 números do funil */}
                        <div className="grid grid-cols-1 gap-[20px] lg:grid-cols-[minmax(0,1fr)_minmax(0,1.35fr)]">
                            <Heroi
                                rotulo={`Entrantes (M0) — ${mesNome}/${agora.getFullYear()}`}
                                valor={metaMes > 0 ? `${entrantes.length}/${metaMes}` : fmtInt(entrantes.length)}
                                apoio={metaMes > 0
                                    ? (faltam > 0 ? `meta ${metaMes} · faltam ${faltam} · ${pctMeta}%` : `meta ${metaMes} · batida · ${pctMeta}%`)
                                    : 'meta do mês não cadastrada'}
                                pct={metaMes > 0 ? Math.min(100, pctMeta) : null}
                                // Sem meta cadastrada, `faltam` é 0 por definição — pintar de verde
                                // diria "meta batida" numa parede onde não existe meta nenhuma.
                                cor={metaMes === 0 ? HEX.yellow : (faltam > 0 ? HEX.yellow : HEX.green)}
                            />
                            <div className="grid grid-cols-2 gap-[12px] sm:grid-cols-4">
                                <Kpi rotulo="Aceites" valor={fmtInt(aceites.length)} cor={HEX.fuchsia} apoio="pré-M0" />
                                <Kpi rotulo="Reserva" valor={fmtInt(reservas.length)} cor={HEX.violet} apoio="próx. mês" />
                                <Kpi rotulo="Funil" valor={fmtInt(coorte)} apoio={`${pctEntrada}% entraram`} />
                                {/* diasUteis pode ser 0 (último dia do mês caindo em fim de semana):
                                    aí "ritmo/dia" não existe e o que resta é o que falta. */}
                                <Kpi rotulo={faltam === 0 ? 'Dias úteis' : diasUteis > 0 ? 'Ritmo/dia' : 'Faltam'}
                                     valor={faltam === 0 ? fmtInt(diasUteis) : diasUteis > 0 ? ritmo.toLocaleString('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) : fmtInt(faltam)}
                                     apoio={faltam === 0 ? 'até o fim do mês' : diasUteis > 0 ? `${diasUteis} dias úteis` : 'sem dia útil restante'} />
                            </div>
                        </div>

                        {/* Faixa B — funil de entrada por polo (o ganho de densidade mora aqui) */}
                        <div className="flex min-h-0 flex-col">
                            <Titulo extra={porPolo.length > cabemPolos
                                ? `+${porPolo.length - cabemPolos} fora da tela · entrantes · aceites · reserva`
                                : 'entrantes · aceites · reserva'}>Funil de entrada por polo</Titulo>
                            <div ref={refPolos} className="grid min-h-0 flex-1 grid-cols-1 content-start gap-x-[24px] gap-y-[10px] overflow-hidden xl:grid-cols-2">
                                {porPolo.slice(0, cabemPolos).map((p) => (
                                    <LinhaPolo key={p.polo} nome={p.polo} valor={fmtInt(p.ent)}
                                               apoio={`${p.ace} ac${p.res > 0 ? ` · ${p.res} res` : ''} · ${p.pc}%`}
                                               pct={p.pc} cor={corDoPolo[p.polo] ?? HEX.yellow} escala={escalaPolos} />
                                ))}
                                {porPolo.length === 0 && (
                                    <p className="text-white/35" style={{ fontSize: FT.barraNome }}>Nenhum seller no funil de entrada.</p>
                                )}
                            </div>
                        </div>

                        {/* Faixa C — prontidão de setup dos M0 (informativo, não é régua de aceite) */}
                        <div>
                            <Titulo extra={`base ${entrantes.length} em M0`}>Prontidão de setup</Titulo>
                            {/* Sem M0 as três barras virariam "0/0 · 0%" — número sem base, que a
                                4 m se lê como dado real. Mesmo estado vazio da aba. */}
                            {entrantes.length === 0 ? (
                                <p className="leading-none text-white/35" style={{ fontSize: FT.barraNome }}>Nenhum seller em Fase M0.</p>
                            ) : (
                                <div className="grid grid-cols-3 gap-[20px]">
                                    {setup.map((s) => (
                                        <LinhaPolo key={s.rotulo} nome={s.rotulo} valor={`${s.n}/${entrantes.length}`}
                                                   apoio={`${pct(s.n, entrantes.length)}%`}
                                                   pct={pct(s.n, entrantes.length)} cor={s.cor} escala={ESCALA_LINHA.md} />
                                    ))}
                                </div>
                            )}
                        </div>
                    </div>
                ) : (
                    /* ── FATURAMENTO = distribuição de status (gráfico) + faturamento + ranking ── */
                    <div className="grid h-full grid-rows-[auto_minmax(0,1fr)] gap-[16px]">
                        {/* Faixa A — faturamento × meta + ADS */}
                        <div className="grid grid-cols-1 gap-[20px] lg:grid-cols-[minmax(0,1fr)_minmax(0,1.35fr)]">
                            <Heroi
                                rotulo="Faturamento do mês"
                                valor={formatCurrencyCompact(totalFat)}
                                apoio={metaFat > 0 ? `meta ${formatCurrencyCompact(metaFat)} · ${pctFat}%` : null}
                                pct={metaFat > 0 ? Math.min(100, pctFat) : null}
                                cor={pctFat >= 100 ? HEX.green : HEX.yellow}
                            />
                            <div className="grid grid-cols-2 gap-[12px] sm:grid-cols-4">
                                <Kpi rotulo="Ativas" valor={fmtInt(totalAtiv)} apoio="M2–M4" />
                                <Kpi rotulo="Média" valor={formatCurrencyCompact(totalAtiv > 0 ? totalFat / totalAtiv : 0)} apoio="por empresa" />
                                <Kpi rotulo="ADS gasto" valor={formatCurrencyCompact(totalAds)} cor={HEX.sky}
                                     apoio={tetoAds > 0 ? `${pct(totalAds, tetoAds)}% do teto` : null} />
                                <Kpi rotulo="ADS saldo" valor={formatCurrencyCompact(Math.max(0, tetoAds - totalAds))} apoio="teto × ativas" />
                            </div>
                        </div>

                        {/* Faixa B — o gráfico de status como protagonista + ranking ao lado */}
                        <div className="grid min-h-0 grid-cols-1 gap-[24px] lg:grid-cols-[minmax(0,0.92fr)_minmax(0,1.08fr)]">
                            <div className="flex min-h-0 flex-col rounded-2xl border border-white/[0.1] bg-white/[0.03] px-[20px] py-[14px]">
                                <Titulo extra={totalStatus > 0 ? `${totalStatus} empresas` : null}>Distribuição de status</Titulo>
                                {totalStatus === 0 ? (
                                    /* cockpitVazio devolve statusDist zerado: sem isto o card ficaria
                                       um retângulo mudo na parede, sem dizer que não há dado. */
                                    <p className="m-auto text-white/35" style={{ fontSize: FT.barraNome }}>Sem empresas na meta neste mês</p>
                                ) : (
                                <div className="grid min-h-0 flex-1 grid-cols-[minmax(0,1fr)_auto] items-center gap-[20px]">
                                    <div ref={refDonut} className="h-full min-h-0">
                                        {statusDist && alturaDonut > 40 && (
                                            <StatusDonut
                                                statusDist={statusDist}
                                                height={alturaDonut}
                                                fonteCentro={FT.kpi}
                                                fonteRotulo={FT.rotulo}
                                                corFundo="#050507"
                                                borda={5}
                                                raio={RAIO_DONUT_TV}
                                                interativo={false}
                                            />
                                        )}
                                    </div>
                                    {/* Legenda grande: a cor sozinha não se lê de longe — vai número junto */}
                                    <div className="flex shrink-0 flex-col justify-center gap-[10px]">
                                        {STATUS_ORDEM.map((k) => (
                                            <div key={k} className="flex items-baseline gap-[10px] whitespace-nowrap leading-none">
                                                <span className="inline-block shrink-0 rounded-full"
                                                      style={{ width: '0.7em', height: '0.7em', background: STATUS_META[k].cor, fontSize: FT.kpiSec }} />
                                                <b className="font-display font-extrabold tabular-nums"
                                                   style={{ fontSize: FT.kpiSec, color: STATUS_META[k].cor }}>{fmtInt(statusDist?.[k] ?? 0)}</b>
                                                <span className="text-white/45" style={{ fontSize: FT.rotulo }}>
                                                    {STATUS_META[k].label} · {pct(statusDist?.[k] ?? 0, totalStatus)}%
                                                </span>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                                )}
                            </div>

                            <div className="flex min-h-0 flex-col">
                                <Titulo extra={ranking.length > cabemRank
                                    ? `+${ranking.length - cabemRank} fora da tela · % da meta`
                                    : '% da meta · faturamento'}>Ranking de polos</Titulo>
                                {/* content-evenly, não content-start: com 10-12 polos o ranking é mais
                                    baixo que o donut ao lado, e o vão sobrava todo no rodapé. */}
                                <div ref={refRanking} className="grid min-h-0 flex-1 grid-cols-1 content-evenly gap-x-[28px] gap-y-[10px] overflow-hidden xl:grid-cols-2">
                                    {ranking.slice(0, cabemRank).map((p) => (
                                        <LinhaPolo key={p.polo} nome={p.polo} valor={`${Math.round(Number(p.pct) || 0)}%`}
                                                   apoio={formatCurrencyCompact(p.faturamento)}
                                                   pct={Number(p.pct) || 0}
                                                   cor={(Number(p.pct) || 0) >= 100 ? HEX.green : (corPoloCk[p.polo] ?? HEX.yellow)}
                                                   escala={escalaRank} />
                                    ))}
                                </div>
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}
