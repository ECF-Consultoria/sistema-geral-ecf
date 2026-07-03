import * as React from 'react';
import * as CheckboxPrimitive from '@radix-ui/react-checkbox';
import { Check } from 'lucide-react';
import { cn } from '@/lib/utils';

// Wrapper shadcn oficial do Radix Checkbox — quadrado, dark-theme-friendly.
// Estado default: fundo bg-white/[0.03], border white/20.
// Estado checked: bg-ecf-yellow + texto preto + border ecf-yellow (ícone Check lucide).
// Uso: <Checkbox checked={bool} onCheckedChange={fn} disabled={bool} />
// OBS: Radix dispara `onCheckedChange` (nao `onChange`).
const Checkbox = React.forwardRef(({ className, ...props }, ref) => (
    <CheckboxPrimitive.Root
        ref={ref}
        className={cn(
            'peer h-4 w-4 shrink-0 rounded-sm border border-white/20 bg-white/[0.03]',
            'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow/40',
            'disabled:cursor-not-allowed disabled:opacity-50',
            'data-[state=checked]:bg-ecf-yellow data-[state=checked]:text-black data-[state=checked]:border-ecf-yellow',
            className,
        )}
        {...props}
    >
        <CheckboxPrimitive.Indicator className="flex items-center justify-center">
            <Check className="h-3 w-3" strokeWidth={3} />
        </CheckboxPrimitive.Indicator>
    </CheckboxPrimitive.Root>
));
Checkbox.displayName = 'Checkbox';

export { Checkbox };
