import { useState } from 'react';
import { ImageOff } from 'lucide-react';
import { cn } from '@/lib/utils';
import { fotoDoMl } from './formato';

/**
 * Foto de capa do anúncio (o `thumbnail` que o servidor já manda, sempre por https).
 * Sem foto, ou se ela não carregar, um quadro neutro no mesmo tamanho — a linha não pula.
 */
export default function FotoProduto({ url, className }) {
    const [falhou, setFalhou] = useState(false);
    const src = fotoDoMl(url);
    const base = cn('h-14 w-14 shrink-0 rounded-lg border border-white/[0.08]', className);

    if (! src || falhou) {
        return (
            <div className={cn(base, 'flex items-center justify-center bg-white/[0.03]')} aria-hidden="true">
                <ImageOff className="h-5 w-5 text-white/30" />
            </div>
        );
    }

    return <img src={src} alt="" loading="lazy" onError={() => setFalhou(true)} className={cn(base, 'bg-white object-contain')} />;
}
