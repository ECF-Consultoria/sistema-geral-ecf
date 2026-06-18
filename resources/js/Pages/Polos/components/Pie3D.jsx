import { cn } from '@/lib/utils';

/**
 * Pie3D — pizza 3D em CSS puro (Phase 38), sem dependência de lib de gráfico.
 *
 * Renderiza um disco inclinado (rotateX) com espessura extrudada via camadas
 * empilhadas em translateZ (preserve-3d): as camadas mais fundas ficam mais
 * escuras (parede lateral) e a face do topo recebe um brilho radial.
 * Recriação fiel das "pizzas 3D" da planilha, com tema dark ECF.
 *
 * Props:
 *   slices : Array<{ color: string, value: number, label?: string }>
 *            (value em qualquer escala — normalizado para 100%)
 *   size   : diâmetro em px (default 200)
 *   depth  : espessura em px (default 24)
 *   tilt   : inclinação em graus (default 56)
 *   gap    : separar fatias com um filete escuro (default true)
 */
export default function Pie3D({
    slices = [],
    size   = 200,
    depth  = 24,
    tilt   = 56,
    gap    = true,
    className,
}) {
    const total = slices.reduce((acc, s) => acc + Math.max(s.value ?? 0, 0), 0);

    // ── Monta os stops do conic-gradient (com filete separador entre fatias) ──
    const sep  = gap && slices.length > 1 ? 0.8 : 0; // % de separação visual
    const segs = [];
    let acc = 0;
    slices.forEach((s, i) => {
        const frac  = total > 0 ? (Math.max(s.value ?? 0, 0) / total) * 100 : (i === 0 ? 100 : 0);
        const start = acc;
        const end   = acc + frac;
        if (sep && i > 0 && frac > 0) {
            segs.push(`rgba(5,5,7,0.6) ${start}% ${start + sep}%`);
            segs.push(`${s.color} ${start + sep}% ${end}%`);
        } else {
            segs.push(`${s.color} ${start}% ${end}%`);
        }
        acc = end;
    });
    const bg = `conic-gradient(from 0deg, ${segs.join(', ')})`;

    // ── Camadas de profundidade (parede): mais fundo = mais escuro ──
    const paredes = [];
    for (let i = depth; i >= 1; i--) {
        const brilho = 0.40 + (0.35 * (depth - i)) / depth; // 0.40 (base) → ~0.75 (topo da parede)
        paredes.push(
            <div
                key={i}
                className="absolute inset-0 rounded-full"
                style={{ background: bg, transform: `translateZ(${-i}px)`, filter: `brightness(${brilho})` }}
            />,
        );
    }

    return (
        <div className={cn('relative mx-auto', className)} style={{ width: size, height: size }}>
            {/* Sombra projetada no "chão" */}
            <div
                className="absolute left-1/2 -translate-x-1/2 rounded-[50%]"
                style={{
                    width: size * 0.86,
                    height: size * 0.20,
                    bottom: size * 0.04,
                    background: 'radial-gradient(ellipse, rgba(0,0,0,0.55), rgba(0,0,0,0) 70%)',
                    filter: 'blur(7px)',
                }}
            />

            {/* Cena 3D inclinada */}
            <div
                className="absolute inset-0"
                style={{ transformStyle: 'preserve-3d', transform: `perspective(1000px) rotateX(${tilt}deg)` }}
            >
                {paredes}

                {/* Face do topo */}
                <div className="absolute inset-0 rounded-full" style={{ background: bg, transform: 'translateZ(1px)' }} />

                {/* Brilho radial (sheen) sobre a face do topo */}
                <div
                    className="absolute inset-0 rounded-full"
                    style={{
                        transform: 'translateZ(1.5px)',
                        background: 'radial-gradient(circle at 34% 28%, rgba(255,255,255,0.28), rgba(255,255,255,0) 56%)',
                    }}
                />

                {/* Anel de borda sutil para definir o contorno do topo */}
                <div
                    className="absolute inset-0 rounded-full"
                    style={{ transform: 'translateZ(2px)', boxShadow: 'inset 0 0 0 1px rgba(255,255,255,0.08)' }}
                />
            </div>
        </div>
    );
}
