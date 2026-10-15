import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';
import animate from 'tailwindcss-animate';

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: ['class'],
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.jsx',
    ],
    theme: {
        container: { center: true, padding: '2rem', screens: { '2xl': '1400px' } },
        extend: {
            // ═══════════════════════════════════════════════════════════════
            // CAMADA `pub-*` — design system PRÓPRIO do módulo Publicador
            //
            // POR QUE EXISTE (decisão do usuário de 11/10/2026, milestone v26.0):
            // o redesign do Publicador vem de um protótipo de alta fidelidade
            // (`new_layout_publicador/`) com paleta, fonte e raios próprios. As
            // tentativas anteriores de redesign falharam porque o layout novo era
            // ADAPTADO aos tokens antigos do sistema; a camada separada existe
            // justamente para que isso não aconteça de novo.
            //
            // O `CLAUDE.md` manda "manter os tokens `ecf-*`" — e eles CONTINUAM
            // valendo, intactos, para o resto do sistema. Esta camada é ADITIVA e
            // vive só dentro do módulo Publicador. O gate
            // `tests/js/publicador-tokens-do-publicador.test.js` importa este
            // arquivo de verdade e reprova se um `ecf-*`, o `sans` ou o `display`
            // mudarem — é a prova de que nada fora do Publicador foi atingido.
            //
            // Esta camada é a ÚNICA casa dos hex do módulo (TOK-15): a partir
            // daqui, hex literal em arquivo de tela do Publicador é reprovação.
            // ═══════════════════════════════════════════════════════════════
            fontFamily: {
                sans: ['Inter', ...defaultTheme.fontFamily.sans],
                display: ['Manrope', 'Inter', ...defaultTheme.fontFamily.sans],
                // Fontes do Publicador (TOK-01). `sans` e `display` NÃO mudam: a
                // Geist entra pela classe `font-pub`/`font-pub-mono` nos
                // componentes do módulo, nunca pelo `sans` global do sistema.
                // Se a Geist não carregar, cai no Inter declarado na própria pilha.
                pub: ['Geist', 'Inter', ...defaultTheme.fontFamily.sans],
                'pub-mono': ['Geist Mono', ...defaultTheme.fontFamily.mono],
            },
            colors: {
                border: 'hsl(var(--border))',
                input: 'hsl(var(--input))',
                ring: 'hsl(var(--ring))',
                background: 'hsl(var(--background))',
                foreground: 'hsl(var(--foreground))',
                primary: {
                    DEFAULT: 'hsl(var(--primary))',
                    foreground: 'hsl(var(--primary-foreground))',
                },
                secondary: {
                    DEFAULT: 'hsl(var(--secondary))',
                    foreground: 'hsl(var(--secondary-foreground))',
                },
                destructive: {
                    DEFAULT: 'hsl(var(--destructive))',
                    foreground: 'hsl(var(--destructive-foreground))',
                },
                muted: {
                    DEFAULT: 'hsl(var(--muted))',
                    foreground: 'hsl(var(--muted-foreground))',
                },
                accent: {
                    DEFAULT: 'hsl(var(--accent))',
                    foreground: 'hsl(var(--accent-foreground))',
                },
                popover: {
                    DEFAULT: 'hsl(var(--popover))',
                    foreground: 'hsl(var(--popover-foreground))',
                },
                card: {
                    DEFAULT: 'hsl(var(--card))',
                    foreground: 'hsl(var(--card-foreground))',
                },
                sidebar: {
                    DEFAULT: 'hsl(var(--sidebar-background))',
                    foreground: 'hsl(var(--sidebar-foreground))',
                    primary: 'hsl(var(--sidebar-primary))',
                    'primary-foreground': 'hsl(var(--sidebar-primary-foreground))',
                    accent: 'hsl(var(--sidebar-accent))',
                    'accent-foreground': 'hsl(var(--sidebar-accent-foreground))',
                    border: 'hsl(var(--sidebar-border))',
                    ring: 'hsl(var(--sidebar-ring))',
                },
                /* ECF brand shortcuts */
                ecf: {
                    yellow: '#ffe600',
                    'yellow-2': '#f5d400',
                    bg: '#050507',
                    card: '#0f1116',
                    'card-2': '#14161d',
                    line: 'rgba(255,255,255,0.08)',
                    dim: '#9ba0aa',
                    mute: '#6a6f79',
                },
                // Paleta do Publicador (TOK-04..TOK-07), copiada da tabela
                // "Cores (dark mode)" de `new_layout_publicador/README.md`.
                //
                // As TINTAS do protótipo (`rgba(250,204,21,0.12-0.16)`,
                // `rgba(239,68,68,0.10)`, `rgba(34,197,94,0.12)`...) NÃO ganham
                // token: saem do modificador de opacidade do próprio token
                // (`bg-pub-accent/[0.14]`, `border-pub-danger/[0.45]`) — é o mesmo
                // valor computado, com um nome só.
                pub: {
                    // ── Superfícies (TOK-04) ──
                    page: '#0A0A0A',          // fundo da página e do header
                    surface: '#111111',       // cards, tabela, painel, inputs
                    sunken: '#0D0D0D',        // cabeçalho de tabela, rodapés, sub-listas
                    // ⚠️ Fase 187, plano 01 — `sunken-2` fica ENTRE `sunken`
                    // (#0D0D0D) e `raised-0` (#131313), e o protótipo pinta os
                    // TRÊS distintos. Medido em
                    // `design_handoff_publicador/Aprovacao.dc.html:124` — o
                    // fundo da sub-linha de conjunto de fotos, a faixa de 38px
                    // com `border-top:1px solid #1C1C1C` que pendura as duas
                    // ofertas do mesmo conjunto embaixo do grupo.
                    // Por que não serve o parecido: com `sunken` a sub-linha
                    // some dentro do rodapé da tabela; com `raised-0` ela sobe
                    // acima da linha de grupo e inverte a hierarquia visual.
                    // "Usar o token parecido que já existe" é justificativa
                    // vetada (FID-02).
                    'sunken-2': '#0F0F0F',
                    // `raised-0` e `hover-0` nomeiam tons MAIS ESCUROS que
                    // `raised` (#151515) e `hover` (#141414) — o sufixo `-0`
                    // existe para dizer isso. A diferença de 2 e 3 pontos é
                    // visível num card de 1600px, e "usar o token parecido que
                    // já existe" é justificativa vetada (FID-02).
                    'raised-0': '#131313',    // ação RECOMENDADA de "O que fazer agora" (Visao Geral.dc.html:516)
                    raised: '#151515',        // linha de grupo
                    'raised-2': '#181818',    // estágio selecionado
                    'hover-0': '#121212',     // linha do painel de estágio, no hover (Visao Geral.dc.html:137)
                    hover: '#141414',
                    'hover-2': '#161616',     // aba ativa do nav
                    'hover-3': '#1A1A1A',
                    // `hover-toast` é o único `hover-*` AMARELADO da camada: é o
                    // `style-hover` do botão "×" que dispensa a faixa de sucesso
                    // de Publicações (`Publicacoes v2.dc.html:62`), e essa faixa
                    // tem fundo amarelado (`row-new`, #14120A). Todos os outros
                    // `hover-*` são cinzas neutros e nenhum deles funciona sobre
                    // superfície amarelada — "usar o hover que já existe" é
                    // justificativa vetada (FID-02).
                    'hover-toast': '#26220F',
                    seg: '#262626',           // opção ativa do segmented control
                    'seg-ring': '#333333',    // o `inset 0 0 0 1px` da opção ativa
                    // ── Bordas (TOK-05) ──
                    line: '#262626',          // borda de cards
                    'line-strong': '#2E2E2E', // inputs, botões secundários
                    'line-subtle': '#1F1F1F', // divisórias internas
                    'line-subtle-2': '#1C1C1C',
                    'line-off': '#3F3F46',    // checkbox vazio, células tracejadas
                    // Borda dos chips-toggle de filtro no estado OFF — a função
                    // `on(b)` de `Publicacoes v2.dc.html:608`, usada por
                    // "Em catálogo", "Com vendas", "Responsável" e "Publicada".
                    // NÃO é `line-strong` (#2E2E2E): são 4 pontos mais claro e
                    // `line-strong` é a borda de INPUT/botão secundário, outro
                    // uso. Encostar no parecido aqui é FID-02 literal.
                    'line-off-2': '#2A2A2A',
                    // ── Flutuantes (TOK-05) — Fase 187, plano 01 ──
                    // O FUNDO e a BORDA dos elementos que flutuam sobre a fila:
                    // a barra de ação em lote (`Aprovacao.dc.html:897`,
                    // `position:fixed;bottom:24px`) e o toast
                    // (`:905`, `top:124px`).
                    //
                    // ⚠️ `float` tem o MESMO hex de `line-subtle-2` e de
                    // `row-focus`, e isso é de propósito: lá ele é BORDA
                    // (divisória interna) e LINHA DE TABELA (foco do teclado).
                    // Escrever `bg-pub-line-subtle-2` no fundo de uma barra
                    // flutuante seria um nome mentindo sobre o uso. Duplicata
                    // DENTRO da camada é justamente o que a camada existe para
                    // absorver — é o precedente já declarado de
                    // `neutral-dot`/`txt-4` e de `row-focus`/`line-subtle-2`;
                    // nas telas é o que TOK-15 proíbe.
                    float: '#1C1C1C',
                    // A borda dos dois flutuantes e o divisor vertical do
                    // rótulo da barra de lote (`:898`, `border-right`).
                    // ⚠️ NÃO é `seg-ring` (mesmo hex): aquele é o
                    // `inset 0 0 0 1px` da opção ativa do segmented control,
                    // outro uso e outra propriedade.
                    'float-line': '#333333',
                    // ── Texto (TOK-05) ──
                    txt: '#F4F4F5',
                    'txt-2': '#A1A1AA',
                    'txt-3': '#8A8A90',       // metadados
                    'txt-4': '#71717A',       // labels discretos
                    'txt-off': '#52525B',     // desabilitado
                    // ── Accent (TOK-06) — texto sobre amarelo é SEMPRE `pub-page` ──
                    accent: '#FACC15',
                    'accent-hover': '#EAB308',
                    // Os CINCO fundos de linha da tabela de Publicações, na
                    // ordem exata em que o ternário de
                    // `Publicacoes v2.dc.html:553` os resolve — têm de ser
                    // lidos juntos:
                    //   isSel → row-sel · isFocus → row-focus ·
                    //   checked → row-checked · isNew → row-new · senão surface
                    'row-sel': '#221D07',     // linha selecionada
                    // `row-focus` é a linha com o FOCO do teclado. O hex já
                    // tinha nome na camada (`line-subtle-2`), mas lá ele é
                    // BORDA — as divisórias do painel (`:357`-`:359`) e a dos
                    // irmãos (`:603`). Escrever `bg-pub-line-subtle-2` numa
                    // linha de tabela seria um nome mentindo sobre o uso.
                    // Duplicata DENTRO da camada é justamente o que a camada
                    // existe para absorver (é o mesmo caso já declarado de
                    // `neutral-dot`/`txt-4`); nas telas é o que TOK-15 proíbe.
                    'row-focus': '#1C1C1C',
                    // Linha MARCADA (checkbox ligado). Fica ENTRE `row-sel` e
                    // `row-new`, e nenhum dos dois serve: com `row-sel` a linha
                    // marcada some dentro da selecionada, com `row-new` ela some
                    // dentro da nova. O protótipo pinta os cinco distintos.
                    'row-checked': '#18160C',
                    'row-new': '#14120A',     // linha "NOVA"
                    // Amarelo rebaixado ao ponto de virar superfície: fundo do
                    // ícone circular do evento de PUBLICAÇÃO na Atividade
                    // (Visao Geral.dc.html:588). É tinta sólida no protótipo,
                    // não `accent` com opacidade — por isso ganha nome próprio.
                    'accent-sunken': '#1F1B08',
                    // ── Fase 1 (TOK-07) — barras/células da Fase 1 ──
                    fase1: '#D4D4D8',
                    'fase1-2': '#E4E4E7',
                    // ── Semânticas (TOK-07): dot + texto, nunca só cor ──
                    ok: '#22C55E',            // dot  — Ativa, Conectado
                    'ok-txt': '#4ADE80',
                    warn: '#F59E0B',          // dot  — Pausada, Atenção, ERP off
                    'warn-txt': '#FBBF24',
                    danger: '#EF4444',        // dot/fill — Erro, Crítico, Prejuízo, ML off
                    'danger-txt': '#F87171',
                    'danger-txt-2': '#FCA5A5', // texto dentro de caixa de alerta
                    // `neutral-dot` repete o hex de `txt-4` de propósito: são usos
                    // diferentes (label discreto × dot de "Em revisão") e o README
                    // os descreve separados. Duplicata DENTRO da camada é o que a
                    // camada existe para absorver; nas telas é o que TOK-15 proíbe.
                    'neutral-dot': '#71717A',
                    // Borda do ANEL ABERTO de "Em revisão" na célula Status da
                    // tabela de Publicações (`Publicacoes v2.dc.html:250`:
                    // `border:1.6px solid #5F5F68;border-right-color:transparent`).
                    // Mais ESCURO que `neutral-dot`/`txt-4` (#71717A) de
                    // propósito: o anel fica ao lado do próprio rótulo e não
                    // pode competir com o texto dele.
                    'neutral-ring': '#5F5F68',
                    'neutral-txt': '#A1A1AA',
                },
            },
            borderRadius: {
                lg: 'var(--radius)',
                md: 'calc(var(--radius) - 2px)',
                sm: 'calc(var(--radius) - 4px)',
                // Raios do Publicador (TOK-08). Valores exatos do protótipo — não
                // arredondar para o passo do Tailwind (FID-03).
                'pub-card': '16px',
                'pub-table': '14px',       // a tabela de Publicações
                'pub-panel': '12px',
                'pub-sub': '10px',         // sub-cards
                'pub-control': '9px',      // botões e inputs
                'pub-control-sm': '7px',
                'pub-chip': '6px',
                'pub-badge': '4px',        // badges e checkbox
            },
            boxShadow: {
                // Sombras do Publicador (TOK-09) — "quase nenhuma" no protótipo.
                'pub-popover': '0 24px 60px rgba(0,0,0,0.7)',
                'pub-painel': '-24px 0 64px rgba(0,0,0,0.6)',
                'pub-modal': '0 30px 80px rgba(0,0,0,0.8)',
                'pub-cta': '0 0 0 1px rgba(250,204,21,0.25), 0 6px 20px rgba(250,204,21,0.12)',
                'pub-aba-ativa': 'inset 0 0 0 1px #262626',
                'pub-seg-ativo': 'inset 0 0 0 1px #333333',
                // ⚠️ Fase 187, plano 01 — a sombra dos FLUTUANTES da fila.
                // Medida em `design_handoff_publicador/Aprovacao.dc.html`
                // quatro vezes, com o MESMO valor: os dois popovers de filtro
                // (`:82` Responsável e `:95` Motivo de bloqueio), a barra de
                // ação em lote (`:897`) e o toast (`:905`).
                // ⚠️ O plano cita `:88`/`:98` para os popovers; medido com
                // `grep -n`, são `:82`/`:95`.
                //
                // Por que não serve a parecida: `pub-popover` é
                // `0 24px 60px rgba(0,0,0,0.7)` e `pub-painel` é
                // `-24px 0 64px rgba(0,0,0,0.6)` — deslocamento, difusão e
                // alfa DIFERENTES. FID-03 proíbe arredondar em direção ao
                // token que já existe.
                'pub-flutuante': '0 16px 40px rgba(0,0,0,0.6)',
            },
            keyframes: {
                'accordion-down': { from: { height: '0' }, to: { height: 'var(--radix-accordion-content-height)' } },
                'accordion-up': { from: { height: 'var(--radix-accordion-content-height)' }, to: { height: '0' } },
                'fade-in': { from: { opacity: '0', transform: 'translateY(8px)' }, to: { opacity: '1', transform: 'translateY(0)' } },
                // Ticker/marquee horizontal (faixa de destaques do dashboard de Desempenho).
                // Conteúdo é duplicado no JSX; -50% completa um ciclo sem emenda.
                'marquee': { from: { transform: 'translateX(0)' }, to: { transform: 'translateX(-50%)' } },
                // Brilho discreto varrendo a barra do líder (ProducaoBars do Desempenho).
                'sheen': { '0%': { transform: 'translateX(-120%)' }, '60%, 100%': { transform: 'translateX(360%)' } },
                // Borda piscando em vermelho — campos obrigatórios não preenchidos (ex.: Frete na precificação do cliente)
                'blink-red': {
                    '0%, 100%': { borderColor: 'rgb(239 68 68)', boxShadow: '0 0 0 0 rgba(239,68,68,0.45)' },
                    '50%':      { borderColor: 'rgba(239,68,68,0.4)', boxShadow: '0 0 0 4px rgba(239,68,68,0)' },
                },
            },
            animation: {
                'accordion-down': 'accordion-down 0.2s ease-out',
                'accordion-up': 'accordion-up 0.2s ease-out',
                'fade-in': 'fade-in 0.3s ease-out',
                'marquee': 'marquee 40s linear infinite',
                'sheen': 'sheen 2.8s ease-in-out infinite',
                'blink-red': 'blink-red 1.1s ease-in-out infinite',
                // Spinner do Publicador (TOK-12). `spin` é o keyframe padrão do
                // Tailwind — de propósito não se declara outro.
                'pub-spin': 'spin .7s linear infinite',
            },
            transitionTimingFunction: {
                // Entrada do painel lateral de inspeção (TOK-12): `.28s` com esta curva.
                'pub-painel': 'cubic-bezier(.2,.8,.2,1)',
            },
            backgroundImage: {
                'ecf-grad': 'linear-gradient(90deg, #171392 0%, #FE4D46 55%, #EDBA06 100%)',
                'yellow-grad': 'linear-gradient(180deg, #ffe600 0%, #f5d400 100%)',
                // Hachura do segmento "Sem publicação" da barra de estágios
                // (VG-07, `Visao Geral.dc.html:479`, onde `k === 'none'`). Vive
                // aqui porque é assim que o Tailwind a emite como classe
                // (`bg-pub-hachura`); escrita na tela ela seria um
                // `bg-[repeating-linear-gradient(...)]` com DOIS hex literais
                // dentro — o que TOK-15 e o gate de vocabulário reprovam.
                // As duas cores já têm nome na camada (`line-off` e `seg`/`line`);
                // o gradiente é que não tinha.
                'pub-hachura': 'repeating-linear-gradient(135deg,#3F3F46 0 3px,#262626 3px 6px)',
                // ⚠️ Fase 187, plano 01 — o anel de "revisão PARCIAL salva": meia
                // bolinha preenchida. Medido em
                // `design_handoff_publicador/Aprovacao.dc.html:1241`
                // (`PG.partial`, o terceiro elemento da tupla) e na legenda do
                // rodapé da fila, `:194` — ⚠️ o plano cita `:201`.
                // Vive aqui pelo mesmo motivo de `pub-hachura`: é assim que o
                // Tailwind o emite como classe (`bg-pub-anel-parcial`), e
                // escrito na tela seria um `bg-[linear-gradient(...)]` com hex
                // cru dentro — o que TOK-15 e o gate de vocabulário reprovam.
                // O `#A1A1AA` já tem nome na camada (`txt-2`/`neutral-txt`); o
                // gradiente é que não tinha.
                'pub-anel-parcial': 'linear-gradient(90deg,#A1A1AA 50%,transparent 50%)',
            },
        },
    },
    plugins: [forms, animate],
};
