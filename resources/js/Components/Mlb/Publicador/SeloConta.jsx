import { cn } from '@/lib/utils';

// Estado do token ML da conta. 'sem_token' = autorizou antes de 21/09/2026,
// quando o callback descartava a credencial: autorizou de verdade, falta reconectar.
const ESTILO = {
    ativo:     { rotulo: 'Conectada',        titulo: 'Conta ML ativa',                classe: 'border-emerald-500/30 bg-emerald-500/10 text-emerald-400', ponto: 'bg-emerald-400' },
    expirado:  { rotulo: 'Reconectar',       titulo: 'Reconectar conta',              classe: 'border-amber-300/25 bg-amber-300/10 text-amber-300',       ponto: 'bg-amber-300' },
    sem_token: { rotulo: 'Falta reconectar', titulo: 'Autorizada — falta reconectar', classe: 'border-amber-300/25 bg-amber-300/10 text-amber-300',       ponto: 'bg-amber-300' },
};

export default function SeloConta({ token = 'ativo', compacto = false }) {
    const e = ESTILO[token] ?? ESTILO.sem_token;

    return (
        <span
            title={e.titulo}
            className={cn(
                'inline-flex items-center gap-1 rounded-full border px-2 py-1 text-[11px] font-bold',
                e.classe,
            )}
        >
            <span aria-hidden="true" className={cn('h-[6px] w-[6px] rounded-full', e.ponto)} />
            {compacto ? <span className="sr-only">{e.rotulo}</span> : e.rotulo}
        </span>
    );
}
