import { cn } from '@/lib/utils';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 177, plano 02 — mapa de cobertura F1/F2 × Clássico/Premium (IDENT-05).
//
// README §Componentes de identidade: "células 18–20px, raio 4–5px, letra C/P.
// Existe F1 → `#E4E4E7` com texto escuro; existe F2 → `#FACC15`; não existe →
// transparente, borda `1px dashed #3F3F46`, letra `#52525B`. Tooltip:
// 'Fase 1 · Clássico — publicada/não criada'."
//
// Medidas do protótipo ("Publicacoes v2.dc.html":185 e a função `cell()` da
// linha 569): célula 20×20px, raio 5px, letra 10.5px/600, 3px entre as células,
// borda de 1px sempre presente (sólida quando existe, tracejada quando não) —
// com a borda nos dois estados a célula ocupa o mesmo espaço nas duas situações.
//
// ⚠️ Em quatro células a forma não basta para distinguir (todas são quadrados),
// então a informação vai na LETRA (C/P), na POSIÇÃO (F1 à esquerda, F2 à
// direita), na borda (sólida × tracejada) e no nome acessível de cada célula.
// Cor é a quarta camada, nunca a única.
// ═══════════════════════════════════════════════════════════════════════════

/** A ordem é a do README: Fase 1 primeiro, Clássico antes de Premium. */
const CELULAS = [
    { chave: 'f1_classico', letra: 'C', fase: 1, tipo: 'Clássico' },
    { chave: 'f1_premium', letra: 'P', fase: 1, tipo: 'Premium' },
    { chave: 'f2_classico', letra: 'C', fase: 2, tipo: 'Clássico' },
    { chave: 'f2_premium', letra: 'P', fase: 2, tipo: 'Premium' },
];

/**
 * @param {object} props
 * @param {{f1_classico?: boolean, f1_premium?: boolean, f2_classico?: boolean, f2_premium?: boolean}|null} props.cobertura
 * @param {number} [props.tamanho]  lado da célula em px (protótipo: 20; README: 18–20)
 * @param {string} [props.className]
 */
export default function MapaDeCobertura({ cobertura, tamanho = 20, className }) {
    return (
        <div
            role="group"
            aria-label="Cobertura de publicações por metodologia e tipo"
            className={cn('flex flex-none gap-[3px]', className)}
        >
            {CELULAS.map((celula) => {
                // ⚠️ REND-04: TODA flag é calculada AQUI DENTRO, no callback do
                // map. Variável de escopo do componente usada dentro de um map
                // já virou ReferenceError no bundle de produção deste projeto
                // (o Rollup elimina a declaração e mantém o uso).
                const mapaValido = cobertura !== null && typeof cobertura === 'object' && !Array.isArray(cobertura);
                const bruto = mapaValido ? cobertura[celula.chave] : false;
                const existe = bruto === true || bruto === 1;
                const lado = typeof tamanho === 'number' && Number.isFinite(tamanho) && tamanho > 0 ? tamanho : 20;

                // Nome acessível e `title` com O MESMO texto (IDENT-07): não
                // existe componente de tooltip no codebase, e informação nenhuma
                // pode morar só no `title` (quem usa teclado ou leitor de tela
                // nunca passa o mouse).
                const descricao = `Fase ${celula.fase} · ${celula.tipo} — ${existe ? 'publicada' : 'não criada'}`;

                return (
                    <span
                        key={celula.chave}
                        role="img"
                        title={descricao}
                        aria-label={descricao}
                        style={{ width: lado, height: lado }}
                        className={cn(
                            'grid place-items-center rounded-[5px] border text-[10.5px] font-semibold',
                            existe && celula.fase === 2 && 'border-pub-accent bg-pub-accent text-pub-page',
                            existe && celula.fase === 1 && 'border-pub-fase1-2 bg-pub-fase1-2 text-pub-page',
                            !existe && 'border-dashed border-pub-line-off text-pub-txt-off',
                        )}
                    >
                        {celula.letra}
                    </span>
                );
            })}
        </div>
    );
}
