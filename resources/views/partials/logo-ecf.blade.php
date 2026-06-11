{{--
    Logo ECF reutilizável em Blade — Phase 32 Plan 01 (D-01 LOCKED).

    HTML/CSS 100% inline para compatibilidade com clientes de email (Gmail/
    Outlook/Apple Mail descartam <style>, ignoram CSS externo). Spec do
    usuário, conferida contra print:

      - Container flex, gap 10px, Montserrat 700, 42px, letter-spacing -0.02em
      - "ECF" em branco
      - Barra vertical 4px × 32px, gradiente vertical azul→rosa→laranja→amarelo
        (sem border-radius)
      - Subtítulo "Consultoria<br>& Assessoria" Montserrat 300, 12px,
        letter-spacing 0.12em, uppercase, branco

    Variável opcional: $theme ('dark' default, 'light' para futuro fundo claro).
    Por enquanto a paleta texto/cor permanece branca em ambos os temas — quando
    surgir uso real em fundo claro, ajustar aqui.
--}}
@php
    $theme = $theme ?? 'dark';
    // Por enquanto idênticos — placeholder pra evolução futura sem quebrar API
    $corTexto = '#ffffff';
@endphp
<div style="display:flex;align-items:center;gap:10px;font-family:'Montserrat','Helvetica',sans-serif;font-weight:700;font-size:42px;letter-spacing:-0.02em;color:{{ $corTexto }};">
    <span>ECF</span>
    <div style="width:4px;height:32px;background:linear-gradient(0deg,#1e5ef3 0%,#e84393 40%,#f97316 70%,#facc15 100%);"></div>
    <span style="font-family:'Montserrat','Helvetica',sans-serif;font-size:12px;font-weight:300;letter-spacing:0.12em;text-transform:uppercase;color:{{ $corTexto }};line-height:1.4;">Consultoria<br>&amp; Assessoria</span>
</div>
