import * as React from 'react';
import { IMaskInput } from 'react-imask';
import { cn } from '@/lib/utils';
import { MASCARA_DINHEIRO, formatarDinheiro, paraTextoDeCampo } from '@/lib/dinheiro';

/**
 * `CampoDinheiro` — input mascarado de dinheiro que devolve NÚMERO, nunca
 * string (Fase 142 Plano 03, D-02).
 *
 * ⚠️ Quem desmascara é a própria máscara. Nunca fazer `parseFloat` de uma
 * string mascarada no componente pai — é exatamente o caminho onde um
 * separador de milhar vira parte do número ("1.500.000,00" → `parseFloat`
 * ingênuo lê `1`) e o zero a mais volta a ficar invisível, que é o problema
 * que esta fase existe para fechar. `onAccept` lê `mask.typedValue`, o valor
 * já tipado como número pelo próprio imask.
 *
 * Classes replicam `Components/ui/input.jsx` porque `IMaskInput` renderiza
 * um `<input>` cru, sem o wrapper do componente (mesmo padrão já usado no
 * campo de CNPJ de `Admin/ContratoDetalhe.jsx`).
 */
export const CampoDinheiro = React.forwardRef(function CampoDinheiro(
    { valor, onChange, placeholder, disabled, avisoAcimaDe, className, 'aria-label': ariaLabel, ...props },
    ref
) {
    const acimaDoTeto = avisoAcimaDe != null && typeof valor === 'number' && valor > avisoAcimaDe;

    return (
        <div className="space-y-1">
            <IMaskInput
                {...MASCARA_DINHEIRO}
                inputRef={ref}
                value={paraTextoDeCampo(valor)}
                onAccept={(_, mask) => onChange(mask.value === '' ? null : mask.typedValue)}
                placeholder={placeholder}
                disabled={disabled}
                aria-label={ariaLabel}
                className={cn(
                    'flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50',
                    'focus:border-ecf-yellow/40',
                    className
                )}
                {...props}
            />
            {/* Defesa barata contra o zero a mais (142-CONTEXT.md) — não bloqueia
                nada, só chama atenção antes de salvar. */}
            {acimaDoTeto && (
                <p className="text-amber-400 text-[12px]">
                    Confira: esse valor é maior que {formatarDinheiro(avisoAcimaDe)}.
                </p>
            )}
        </div>
    );
});
