<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        {{-- Modo claro/escuro: aplica a preferencia ANTES do render pra evitar flash --}}
        <script>
            (function () {
                try {
                    if (localStorage.getItem('ecf-theme') === 'light') {
                        document.documentElement.classList.add('light');
                    }
                } catch (e) {}
            })();
        </script>

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts ECF -->
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Manrope:wght@500;700;800;900&display=swap" rel="stylesheet" />

        {{-- Fontes do modulo Publicador (TOK-01, milestone v26.0, decisao do usuario de 11/10/2026).
             Entram AO LADO de Inter/Manrope, nunca no lugar: o <body> segue com `font-sans`
             (Inter) e o resto do sistema continua nos tokens `ecf-*`. A Geist e consumida
             so pelas classes `font-pub`/`font-pub-mono` dos componentes do Publicador.
             Geist 400/500/600/700 para UI; Geist Mono 400/500 para SKU, MLB, codigos e
             atalhos de teclado. Mesmo host das fontes que ja carregavamos — nao acrescenta
             origem de terceiro nova. `display=swap` evita texto invisivel se a rede falhar. --}}
        <link href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600;700&family=Geist+Mono:wght@400;500&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @routes
        @viteReactRefresh
        @vite(['resources/js/app.jsx', "resources/js/Pages/{$page['component']}.jsx"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia

        {{-- Portal do glide-data-grid: a lib procura #portal via getElementById toda vez que
             abre um editor de celula. Sem este no, a edicao/dropdown da grade em massa nao
             abre e falha em SILENCIO (so loga no console). Precisa ser o ultimo filho de body. --}}
        <div id="portal"></div>
    </body>
</html>
