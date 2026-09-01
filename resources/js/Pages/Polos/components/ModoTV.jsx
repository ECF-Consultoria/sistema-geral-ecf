import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { Building2, ChevronLeft, ChevronRight, Wallet, X } from 'lucide-react';
import { cn, formatCurrency, formatCurrencyCompact } from '@/lib/utils';
import { ehReservaProximoMes, somaMetaDoMes, competenciaDe, janelaDaCompetencia } from '@/lib/polosEntrantes';
import { STATUS_META, STATUS_ORDEM } from './statusMeta';
import { montarCorDoPolo } from './poloCores';
import FatVsMetaChart, { origemFaturamento } from './FatVsMetaChart';
import StatusDonut from './StatusDonut';

/**
 * ModoTV — o Painel Polos na TV da empresa. Painel de PAREDE, lido a 4–5 metros.
 *
 * Duas telas, uma por aba do painel (a lente ativa decide qual abre):
 *  · METAS       → a aba "Metas → Entrantes (M0)" INTEIRA: meta do mês, aceites, reserva,
 *                  funil de entrada por polo e prontidão de setup dos M0.
 *  · FATURAMENTO → faturamento total e empresas ativas, faturamento × meta POR POLO
 *                  (barras, no lugar da antiga lista de ranking) e distribuição de
 *                  status — o anel, protagonista, ocupando a coluna direita inteira.
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

// Dias úteis (seg–sex) que ainda restam na COMPETÊNCIA de entrantes, contando hoje.
// A janela fecha no dia 27, não no fim do mês do calendário: em 28/08 restam os ~31 dias
// da competência de setembro, e não os 3 que sobravam de agosto — com a conta antiga a
// parede exigiria um ritmo/dia dez vezes maior do que o real. Sem calendário de feriados
// de propósito: o número serve de ritmo ("quantos por dia"), não de prazo.
function diasUteisRestantes(hoje, ym) {
    const janela = janelaDaCompetencia(ym);
    if (!janela) return 0;
    const fim = janela.fim;
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

// Largura do VIEWPORT em px. As fontes do canvas (ECharts) só aceitam px, e a régua
// tipográfica desta tela é toda em `vw` — quem quiser um "1,75vw" DENTRO do gráfico
// precisa da largura resolvida. Viewport, não caixa medida: é determinístico e não
// depende de o layout já ter estabilizado dentro do fullscreen.
function useLarguraJanela() {
    const [largura, setLargura] = useState(() => (typeof window === 'undefined' ? 1920 : window.innerWidth));
    useEffect(() => {
        const aoRedimensionar = () => setLargura(window.innerWidth);
        window.addEventListener('resize', aoRedimensionar);
        return () => window.removeEventListener('resize', aoRedimensionar);
    }, []);
    return largura;
}

// clamp() do CSS resolvido em JS — mesma conta (px mínimo · vw · px máximo), para levar
// a régua da parede para dentro do canvas sem inventar uma segunda escala.
const clampPx = (min, vw, max, largura) => Math.round(Math.min(Math.max(min, (vw / 100) * largura), max));

// Literal estavel: recriado a cada render, ele entra nas deps do useMemo do StatusDonut e,
// com notMerge, o donut refaz a animacao de entrada a cada tique do relogio (30s).
const RAIO_DONUT_TV = ['58%', '90%'];

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
// Teto em `md` de propósito: o espaçamento com `sm` foi aprovado na TV — a escala desce
// conforme entram mais polos, nunca infla além de `md`.

/**
 * Escala da linha a partir da CONTAGEM, não de medição.
 *
 * A versão medida (ResizeObserver na caixa) escondia polo na TV: a altura chega pequena
 * no primeiro layout — o Modo TV entra em fullscreen, o container ainda não estabilizou —
 * e a decisão ficava congelada num número baixo de linhas. Com 5 polos a parede mostrava
 * 2. Regra determinística não tem esse estado: o número de polos é o mesmo em qualquer
 * tela, e a lista renderiza SEMPRE todos os itens — nada de esconder polo.
 */
function gradeExplicita(total) {
    // Colunas e fileiras vão em `style`, não em classe: media query (`xl:`) depende do
    // viewport e foi ela que escondeu polo na TV — abaixo de 1280px a lista virava 1
    // coluna, dobrava o número de fileiras e o excedente era comido pelo overflow.
    // `minmax(0, 1fr)` nas fileiras garante que TODAS existam dividindo a altura.
    // LISTA (1 coluna) enquanto couber: é como o funil por polo se lê melhor na parede —
    // uma linha por polo, de cima para baixo. Só passa a 2 colunas quando são tantos que
    // a fileira ficaria fina demais.
    const colunas = total <= 9 ? 1 : 2;
    const linhas  = Math.max(1, Math.ceil(total / colunas));
    return {
        colunas,
        linhas,
        style: {
            gridTemplateColumns: `repeat(${colunas}, minmax(0, 1fr))`,
            gridTemplateRows: `repeat(${linhas}, minmax(0, 1fr))`,
        },
    };
}

function escalaPorContagem(linhas) {
    // Recebe FILEIRAS (já descontadas as colunas), não o total: é a fileira que consome
    // altura. As fileiras dividem a caixa, então a escala só precisa manter o texto legível.
    if (linhas <= 5) return ESCALA_LINHA.md;
    if (linhas <= 8) return ESCALA_LINHA.sm;
    if (linhas <= 12) return ESCALA_LINHA.xs;
    return ESCALA_LINHA.xxs;
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
    const fonte = sec || n > 8 ? FT.barraNome : n > 6 ? FT.barraNum : FT.kpi;
    return (
        <div className="flex min-w-0 flex-col justify-center rounded-xl border border-white/[0.1] bg-white/[0.04] px-[12px] py-[10px]">
            <span className="truncate uppercase leading-[1.25] tracking-[0.12em] text-white/50" style={{ fontSize: FT.rotulo }}>{rotulo}</span>
            <span className="mt-[4px] whitespace-nowrap font-display font-extrabold leading-none tabular-nums"
                  style={{ color: cor, fontSize: fonte }}>{valor}</span>
            {apoio && <span className="mt-[4px] truncate leading-none text-white/35" style={{ fontSize: FT.rotulo }}>{apoio}</span>}
        </div>
    );
}

// Fonte do número do card de comando por COMPRIMENTO da string. "R$ 4.807.978,51" tem 15
// caracteres: na fonte do herói ele sairia da caixa e quebraria o card em duas linhas — e
// número quebrado na parede é pior que número um degrau menor. Todo degrau segue acima do
// piso de 28px da régua (o menor, 48px, lê a 4,4 m).
const FONTE_NUMERO_CARD = [
    { ate: 4,  fonte: 'clamp(2.2rem, 5vw, 6rem)' },          // 96px · 8,7 m — "134"
    { ate: 8,  fonte: 'clamp(1.9rem, 4.2vw, 5rem)' },        // 80px · 7,3 m
    { ate: 12, fonte: 'clamp(1.5rem, 3.4vw, 4rem)' },        // 65px · 5,9 m
    { ate: 15, fonte: 'clamp(1.25rem, 2.9vw, 3.5rem)' },     // 56px · 5,1 m — "R$ 4.807.978,51"
    { ate: Infinity, fonte: 'clamp(1.1rem, 2.5vw, 3rem)' },  // 48px · 4,4 m — piso
];
const fonteDoNumero = (valor) => FONTE_NUMERO_CARD.find((f) => String(valor).length <= f.ate).fonte;

/**
 * CardTV — card de comando da parede: rótulo + ícone, número grande e linha de apoio.
 * É o `HeroKpi` do painel relido com a tipografia de parede; o ícone escala junto com o
 * rótulo (`1.3em`) para não virar um selo de 16px numa TV de 55".
 */
function CardTV({ titulo, valor, icone: Icone, sublabel = null, cor = '#ffffff' }) {
    return (
        <div className="flex min-w-0 flex-col justify-center rounded-2xl border border-white/[0.1] bg-white/[0.04] px-[20px] py-[14px]">
            <div className="flex items-start justify-between gap-[10px]">
                <span className="truncate uppercase leading-[1.2] tracking-[0.1em] text-white/45" style={{ fontSize: FT.rotulo }}>{titulo}</span>
                {Icone && (
                    <span className="shrink-0 rounded-xl bg-white/[0.05] p-[6px] leading-none text-white/40" style={{ fontSize: FT.rotulo }}>
                        <Icone size="1.3em" />
                    </span>
                )}
            </div>
            <span className="mt-[10px] whitespace-nowrap font-display font-extrabold leading-none tabular-nums"
                  style={{ color: cor, fontSize: fonteDoNumero(valor) }}>{valor}</span>
            {sublabel && (
                <span className="mt-[10px] truncate leading-[1.2] text-white/40" style={{ fontSize: FT.rotulo }}>{sublabel}</span>
            )}
        </div>
    );
}

// Linha de polo: nome + número grande + números de apoio + barra do percentual.
// `escala` vem de escalaPorContagem(): quanto mais polos, menor a linha.
function LinhaPolo({ nome, valor, apoio = null, pct: p, cor = HEX.yellow, escala = ESCALA_LINHA.sm }) {
    return (
        <div className="flex min-w-0 flex-col justify-center overflow-hidden">
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

    // Ao voltar de um reload o Modo TV é restaurado sem gesto do usuário, e a Fullscreen
    // API exige gesto — então rearmamos na primeira interação (um clique/tecla qualquer
    // na TV ou no controle) em vez de deixar a barra do navegador aparecendo para sempre.
    useEffect(() => {
        if (typeof document === 'undefined' || document.fullscreenElement) return undefined;
        const reentrarFullscreen = () => {
            try { document.documentElement.requestFullscreen?.(); } catch (_) { /* bloqueado */ }
            window.removeEventListener('pointerdown', reentrarFullscreen);
            window.removeEventListener('keydown', reentrarFullscreen);
        };
        window.addEventListener('pointerdown', reentrarFullscreen);
        window.addEventListener('keydown', reentrarFullscreen);
        return () => {
            window.removeEventListener('pointerdown', reentrarFullscreen);
            window.removeEventListener('keydown', reentrarFullscreen);
        };
    }, []);

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

    // Dois rótulos de mês, de propósito: a tela de METAS fala em competência de entrantes
    // (fecha dia 27) e a de FATURAMENTO segue o mês do calendário. Fundir os dois faria a
    // parede anunciar "Agosto" enquanto cobra a meta de setembro.
    const mesAtual = competenciaDe(agora);
    const [anoComp, numComp] = mesAtual.split('-');
    const mesNome           = MESES_BR[(Number(numComp) || 1) - 1];   // competência (corte 27)
    const mesNomeCalendario = MESES_BR[agora.getMonth()];             // mês corrido — só faturamento

    // ── Aba Metas → Entrantes (M0): os mesmos números de EntrantesM0Panel ──
    const entrantes = useMemo(() => empresas.filter(ehM0), [empresas]);
    const aceites   = useMemo(() => empresas.filter(ehAceite), [empresas]);
    const reservas  = useMemo(() => empresas.filter(ehReservaProximoMes), [empresas]);
    const coorte    = aceites.length + entrantes.length;          // funil de entrada
    const pctEntrada = pct(entrantes.length, coorte);             // quantos do funil já entraram
    const metaMes   = useMemo(() => somaMetaDoMes(metasEntrada, mesAtual), [metasEntrada, mesAtual]);
    const pctMeta   = pct(entrantes.length, metaMes);
    const faltam    = Math.max(0, metaMes - entrantes.length);
    const diasUteis = useMemo(() => diasUteisRestantes(agora, mesAtual), [agora, mesAtual]);
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
        { rotulo: 'Acesso', n: entrantes.filter(temAcesso).length, cor: HEX.sky },
        { rotulo: 'Grupo WhatsApp',     n: entrantes.filter(temGrupo).length,  cor: HEX.green },
    ]), [entrantes]);

    // ── Faturamento: cockpit (admin) ──
    const polosCk   = cockpit?.polos ?? [];
    const temCk     = isAdmin && polosCk.length > 0 && !cockpit?.erro;
    const totalFat  = useMemo(() => polosCk.reduce((s, p) => s + (Number(p.faturamento) || 0), 0), [polosCk]);
    const totalAtiv = useMemo(() => polosCk.reduce((s, p) => s + (Number(p.ativos) || 0), 0), [polosCk]);
    const metaFat    = Number(cockpit?.metaFaturamento) || 0;
    const pctFat     = metaFat > 0 ? Math.round((totalFat / metaFat) * 100) : 0;
    const corPoloCk  = useMemo(() => montarCorDoPolo(polosCk), [polosCk]);
    const statusDist = cockpit?.statusDist ?? null;
    const totalStatus = Number(statusDist?.total) || 0;
    // Mês do cabeçalho POR TELA: metas são sempre do mês corrente, mas `mesRefLabel` é o mês
    // SELECIONADO no cockpit financeiro (o seletor do painel) — exibi-lo na tela de metas
    // rotularia números de agosto como "Julho/2026" se alguém tivesse trocado o mês.
    const mesCorrente = `${mesNome}/${anoComp}`;
    // Fallback do faturamento é o mês do CALENDÁRIO — usar `mesCorrente` aqui vazaria a
    // competência de entrantes para uma tela que não segue o corte do dia 27.
    const mesRefFat   = cockpit?.mesRefLabel ?? `${mesNomeCalendario}/${agora.getFullYear()}`;
    const parcial     = !!cockpit?.parcial;   // mês corrente ainda em curso (Adman ao vivo)

    // ── Telas (a lente ativa escolhe a inicial) ──
    const telas = useMemo(() => (temCk ? ['metas', 'faturamento'] : ['metas']), [temCk]);
    // A tela escolhida também sobrevive ao reload — senão a parede volta sozinha para a
    // aba que a lente do painel indica, e não para a que estava no ar.
    const [tela, setTela] = useState(() => {
        try {
            const salva = window.sessionStorage.getItem('polos-painel-tv-tela');
            if (salva === 'metas' || salva === 'faturamento') return salva;
        } catch (_) { /* quota/priv */ }
        return lenteInicial === 'metas' ? 'metas' : 'faturamento';
    });
    const telaAtiva = telas.includes(tela) ? tela : 'metas';
    const idx = telas.indexOf(telaAtiva);

    useEffect(() => {
        try { window.sessionStorage.setItem('polos-painel-tv-tela', telaAtiva); } catch (_) { /* quota/priv */ }
    }, [telaAtiva]);

    // Métrica do gráfico de polos. Mora aqui, e não dentro do FatVsMetaChart, porque na
    // parede o toggle vive no cabeçalho do card — assim o canvas fica sozinho na faixa e
    // a altura medida é dele, não dele mais um cabeçalho.
    const [modoFat, setModoFat] = useState('faturamento');

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

    // Altura real das caixas de canvas — donut e gráfico de barras só desenham com px
    // resolvido. Medir para DIMENSIONAR canvas é legítimo; o que nunca se decide por
    // medição é QUANTOS itens aparecem (foi isso que já escondeu polo na parede).
    const [refDonut, alturaDonut]     = useAlturaMedida();
    const [refGrafico, alturaGrafico] = useAlturaMedida();
    const larguraJanela               = useLarguraJanela();
    // Teto de fonte do gráfico pela altura da FILEIRA: com muitos polos a tipografia de
    // parede se sobreporia. O piso de 11px existe para o nome nunca sumir — com
    // interval:0 o ECharts desenha todos, e nome apertado é melhor que polo anônimo.
    const tetoFonteGrafico = alturaGrafico > 0 && polosCk.length > 0
        ? Math.max(11, Math.floor((alturaGrafico / polosCk.length) * 0.42))
        : 999;
    const gradePolos  = gradeExplicita(porPolo.length);
    const escalaPolos = escalaPorContagem(gradePolos.linhas);

    const hora = agora.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
    const horaAtualizacao = atualizadoEm.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });

    return (
        <div className="fixed inset-0 z-50 flex flex-col overflow-hidden bg-ecf-bg text-white">
            {/* Cabeçalho de 64px: logo + tela + mês + relógio (sem rodapé — a área é da parede) */}
            <div className="flex shrink-0 items-center justify-between gap-[16px] border-b border-white/[0.07] px-[24px] pb-[12px] pt-[12px]">
                <div className="flex min-w-0 items-center gap-[16px] overflow-hidden">
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
                <div className="flex min-w-0 shrink-0 items-center gap-[16px]">
                    <span className="truncate uppercase leading-none tracking-[0.16em] text-white/40" style={{ fontSize: FT.rotulo }}>
                        {telaAtiva === 'metas' ? mesCorrente : mesRefFat}{telaAtiva !== 'metas' && parcial ? ' · parcial' : ''}
                    </span>
                    <span className="hidden leading-none text-white/25 lg:inline" style={{ fontSize: FT.rotulo }}>atual. {horaAtualizacao}</span>
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
            <div className="min-h-0 flex-1 px-[24px] pb-[20px] pt-[14px]">
                {telaAtiva === 'metas' ? (
                    /* ── METAS = a aba "Entrantes (M0)" inteira: meta, funil, polos e setup ── */
                    <div className="grid h-full grid-rows-[auto_minmax(0,1fr)_auto] gap-[16px]">
                        {/* Faixa A — meta do mês + os 4 números do funil */}
                        <div className="grid grid-cols-1 gap-[20px] md:grid-cols-[minmax(0,1fr)_minmax(0,1.35fr)]">
                            <Heroi
                                rotulo={`Entrantes (M0) — ${mesNome}/${anoComp}`}
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
                                     apoio={faltam === 0 ? 'no mês' : diasUteis > 0 ? `${diasUteis} dias úteis` : 'sem dia útil'} />
                            </div>
                        </div>

                        {/* Faixa B — funil de entrada por polo (o ganho de densidade mora aqui) */}
                        <div className="flex min-h-0 flex-col">
                            <Titulo extra="entrantes · aceites · reserva">Funil de entrada por polo</Titulo>
                            <div className="grid min-h-0 flex-1 gap-x-[24px] gap-y-[10px] overflow-hidden" style={gradePolos.style}>
                                {porPolo.map((p) => (
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
                                                   pct={pct(s.n, entrantes.length)} cor={s.cor} escala={ESCALA_LINHA.sm} />
                                    ))}
                                </div>
                            )}
                        </div>
                    </div>
                ) : (
                    /* ── FATURAMENTO = dois números de comando + faturamento × meta por polo
                       + distribuição de status (o anel) ocupando a coluna direita inteira.
                       Colunas e fileiras vão em `style`, nunca em `lg:`/`xl:`: a TV roda a
                       960×600 CSS px (zoom do aparelho) e esses breakpoints NÃO disparam
                       lá — foi o que empilhou o donut em 110px de diâmetro. */
                    <div className="grid h-full gap-[16px]"
                         style={{ gridTemplateColumns: 'minmax(0, 1.45fr) minmax(0, 1fr)', gridTemplateRows: 'auto minmax(0, 1fr)' }}>
                        {/* Faixa A — os dois números que a parede responde de longe */}
                        <div className="grid gap-[16px]"
                             style={{ gridColumn: 1, gridRow: 1, gridTemplateColumns: 'minmax(0, 1.25fr) minmax(0, 1fr)' }}>
                            {/* O mês e o "parcial" já estão no cabeçalho: a linha de apoio deste
                                card rende mais carregando a META, que sumiria da tela junto com
                                a barra do herói antigo. */}
                            <CardTV titulo="Faturamento total" icone={Wallet}
                                    cor={metaFat > 0 && pctFat >= 100 ? HEX.green : HEX.yellow}
                                    valor={formatCurrency(totalFat)}
                                    sublabel={metaFat > 0
                                        ? `meta ${formatCurrencyCompact(metaFat)} · ${pctFat}%`
                                        : `${mesRefFat} · ${parcial ? 'parcial' : 'fechado'}`} />
                            <CardTV titulo="Empresas ativas" icone={Building2}
                                    valor={fmtInt(totalAtiv)}
                                    sublabel={`${polosCk.length} ${polosCk.length === 1 ? 'polo' : 'polos'}`} />
                        </div>

                        {/* Distribuição de status — coluna inteira à direita, o anel protagonista */}
                        <div className="flex min-h-0 flex-col rounded-2xl border border-emerald-500/30 bg-white/[0.03] px-[20px] py-[14px]"
                             style={{ gridColumn: 2, gridRow: '1 / span 2' }}>
                            <Titulo extra={totalStatus > 0 ? `${totalStatus} empresas` : null}>Distribuição de status</Titulo>
                            {totalStatus === 0 ? (
                                /* cockpitVazio devolve statusDist zerado: sem isto o card ficaria
                                   um retângulo mudo na parede, sem dizer que não há dado. */
                                <p className="m-auto text-white/35" style={{ fontSize: FT.barraNome }}>Sem empresas na meta neste mês</p>
                            ) : (
                                <>
                                    <div ref={refDonut} className="min-h-0 flex-1">
                                        {statusDist && alturaDonut > 120 ? (
                                            <StatusDonut
                                                statusDist={statusDist}
                                                height={alturaDonut}
                                                fonteCentro={alturaDonut >= 200 ? FT.heroi : FT.kpi}
                                                fonteRotulo={FT.rotulo}
                                                corFundo="#050507"
                                                borda={alturaDonut >= 200 ? 5 : 2}
                                                raio={RAIO_DONUT_TV}
                                                interativo={false}
                                            />
                                        ) : (
                                            /* Anel abaixo de 120px vira bolinha ilegível — a barra
                                               100% empilhada lê melhor de longe. */
                                            statusDist && <div className="py-[8px]"><StatusDonut statusDist={statusDist} compacto /></div>
                                        )}
                                    </div>
                                    {/* 2×2 fixo, não uma fileira de 4: em 4 colunas o rótulo
                                        "Em progresso" quebraria dentro de ~70px de largura. */}
                                    <div className="mt-[12px] grid shrink-0 grid-cols-2 gap-x-[16px] gap-y-[12px]">
                                        {STATUS_ORDEM.map((k) => (
                                            <div key={k} className="min-w-0">
                                                <div className="font-display font-extrabold leading-none tabular-nums"
                                                     style={{ fontSize: FT.kpi, color: STATUS_META[k].cor }}>
                                                    {fmtInt(statusDist?.[k] ?? 0)}
                                                </div>
                                                <div className="mt-[6px] truncate uppercase leading-[1.2] tracking-[0.1em] text-white/45"
                                                     style={{ fontSize: FT.rotulo }}>{STATUS_META[k].label}</div>
                                                <div className="mt-[4px] leading-none tabular-nums text-white/35"
                                                     style={{ fontSize: FT.rotulo }}>{pct(statusDist?.[k] ?? 0, totalStatus)}%</div>
                                            </div>
                                        ))}
                                    </div>
                                </>
                            )}
                        </div>

                        {/* Faixa B — faturamento × meta POR POLO. Substitui a lista de
                            ranking: a barra já ordena por % da meta e ainda mostra, no
                            trilho descoberto, o quanto falta — coisa que a lista não dizia. */}
                        <div className="flex min-h-0 flex-col rounded-2xl border border-white/[0.1] bg-white/[0.03] px-[20px] py-[14px]"
                             style={{ gridColumn: 1, gridRow: 2 }}>
                            <div className="flex shrink-0 items-baseline justify-between gap-[12px]">
                                <h2 className="uppercase leading-none tracking-[0.14em] text-white/45" style={{ fontSize: FT.rotulo }}>Faturamento vs meta</h2>
                                <span className="truncate leading-[1.2] text-white/30" style={{ fontSize: FT.rotulo }}>
                                    {origemFaturamento(cockpit?.fonteFaturamento, parcial)}
                                </span>
                            </div>
                            {/* Toggle no cabeçalho do CARD, não dentro do gráfico: assim o canvas
                                fica sozinho na faixa e a altura medida é só dele. */}
                            <div className="mt-[10px] flex shrink-0 items-center gap-[8px]">
                                {[
                                    { key: 'faturamento', label: 'Faturamento' },
                                    { key: 'cobertura',   label: 'Cobertura da meta' },
                                ].map((t) => (
                                    <button key={t.key} type="button" onClick={() => setModoFat(t.key)}
                                            className={cn('rounded-lg px-[12px] py-[5px] uppercase leading-none tracking-[0.12em] transition-colors',
                                                modoFat === t.key ? 'bg-ecf-yellow font-semibold text-black' : 'border border-white/[0.1] text-white/40 hover:text-white/70')}
                                            style={{ fontSize: FT.rotulo }}>
                                        {t.label}
                                    </button>
                                ))}
                            </div>
                            {/* Medir a caixa aqui é legítimo — canvas precisa de px resolvido.
                                O que NÃO se decide por medição é quantos polos aparecem: a
                                lista vai inteira para o ECharts, que divide a altura entre
                                todos. Nenhum polo some porque a faixa ficou baixa. */}
                            <div ref={refGrafico} className="mt-[10px] min-h-0 flex-1">
                                {alturaGrafico > 80 && (
                                    <FatVsMetaChart
                                        polos={polosCk}
                                        corDoPolo={corPoloCk}
                                        parede
                                        modo={modoFat}
                                        altura={alturaGrafico}
                                        fonteCategoria={Math.min(clampPx(14, 1.75, 34, larguraJanela), tetoFonteGrafico)}
                                        fonteEixo={clampPx(11, 1.1, 21, larguraJanela)}
                                        fonteValor={Math.min(clampPx(14, 1.6, 31, larguraJanela), tetoFonteGrafico)}
                                        interativo={false}
                                    />
                                )}
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}
