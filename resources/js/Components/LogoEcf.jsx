// Logo ECF reutilizável em React (Phase 32 Plan 03 — D-01 LOCKED).
//
// Equivalente JSX do partial Blade `resources/views/partials/logo-ecf.blade.php`.
// Mantém o mesmo HTML/CSS inline para garantir paridade visual perfeita entre
// o email (Blade) e a página pública /nps/{token} (React).
//
// Spec do usuário (conferida contra print original):
//  - Container flex, gap 10px, Montserrat 700, 42px, letter-spacing -0.02em
//  - "ECF" em branco
//  - Barra vertical 4px × 32px, gradiente vertical azul→rosa→laranja→amarelo
//    (sem border-radius)
//  - Subtítulo "Consultoria & Assessoria" — Montserrat 300, 12px,
//    letter-spacing 0.12em, uppercase, branco, lineHeight 1.4
//
// Prop `theme` aceita 'dark' (default) e fica reservado para evolução futura
// (logo em fundo claro) sem quebrar a assinatura. Por enquanto a paleta de
// texto permanece branca em ambos os temas — igual ao partial Blade.
//
// Uso esperado: header da página /nps/{token} (Respond.jsx), centralizado
// no topo. Pode ser reaproveitado em qualquer outra página interna.

export default function LogoEcf({ theme = 'dark' }) {
    // Placeholder pra evolução futura — atualmente o tema não altera nada visual,
    // assim como o partial Blade. Mantém API explícita pra o dia que precisar.
    void theme;

    const corTexto = '#ffffff';

    return (
        <div
            style={{
                display: 'inline-flex',
                alignItems: 'center',
                gap: '10px',
                fontFamily: "'Montserrat', 'Helvetica', sans-serif",
                fontWeight: 700,
                fontSize: '42px',
                letterSpacing: '-0.02em',
                color: corTexto,
                lineHeight: 1,
            }}
        >
            <span>ECF</span>
            <div
                style={{
                    width: '4px',
                    height: '32px',
                    background:
                        'linear-gradient(0deg, #1e5ef3 0%, #e84393 40%, #f97316 70%, #facc15 100%)',
                }}
            />
            <span
                style={{
                    fontFamily: "'Montserrat', 'Helvetica', sans-serif",
                    fontSize: '12px',
                    fontWeight: 300,
                    letterSpacing: '0.12em',
                    textTransform: 'uppercase',
                    color: corTexto,
                    lineHeight: 1.4,
                }}
            >
                Consultoria
                <br />
                &amp; Assessoria
            </span>
        </div>
    );
}
