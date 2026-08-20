import { useState } from 'react';
import { cn } from '@/lib/utils';

/**
 * AvatarUsuario — foto de `users.avatar_url` quando existe, iniciais quando
 * não.
 *
 * ### Por que este componente existe
 * O mesmo avatar já estava escrito DUAS vezes no projeto — em
 * `Pages/Nps/Respond.jsx` e em `Pages/Performance/Index.jsx` — e o segundo
 * anota no próprio docblock que é "mesmo contrato" do primeiro. As telas de
 * onboarding seriam a terceira cópia. Aqui há uma só; as duas antigas seguem
 * onde estão (mexer nelas é outro assunto, com outro risco).
 *
 * ### O `onError` é o motivo de existir, não um detalhe
 * `avatar_url` pode apontar para arquivo já apagado do disco ou para foto
 * externa (Google) que deixou de responder. Sem o fallback, a tela mostra o
 * ícone de imagem quebrada no lugar do rosto de quem atende o cliente — e no
 * portal público isso é visto por gente de fora. O estado de erro é POR
 * avatar: uma foto quebrada não derruba as outras.
 *
 * ### `tema`
 * O onboarding interno é escuro; o portal do cliente é claro. As iniciais
 * precisam de contraste nos dois, e herdar a cor do texto do pai não resolve
 * (o círculo tem fundo próprio). Duas variantes explícitas, nenhuma mágica.
 */
const TEMAS = {
    escuro: 'border border-white/10 bg-white/[0.07] text-white/80',
    claro:  'border border-slate-200 bg-slate-100 text-slate-600',
};

function iniciais(nome) {
    return (nome || '?')
        .trim()
        .split(/\s+/)
        .map((p) => p[0])
        .slice(0, 2)
        .join('')
        .toUpperCase();
}

export default function AvatarUsuario({
    nome,
    foto = null,
    size = 32,
    tema = 'escuro',
    className,
    anel = false,
}) {
    const [erro, setErro] = useState(false);
    const usaFoto = Boolean(foto) && !erro;

    return (
        <span
            // O nome já aparece ao lado em todos os usos — repeti-lo aqui faria
            // o leitor de tela dizer duas vezes. `title` continua servindo ao
            // mouse quando o nome está truncado.
            aria-hidden="true"
            title={nome || undefined}
            className={cn(
                'inline-grid shrink-0 select-none place-items-center overflow-hidden rounded-full font-display font-bold',
                usaFoto ? '' : TEMAS[tema] ?? TEMAS.escuro,
                anel && 'ring-2 ring-ecf-yellow/50',
                className
            )}
            style={{ width: size, height: size, fontSize: Math.round(size * 0.36) }}
        >
            {usaFoto ? (
                <img
                    src={foto}
                    alt=""
                    loading="lazy"
                    className="h-full w-full object-cover"
                    onError={() => setErro(true)}
                />
            ) : (
                iniciais(nome)
            )}
        </span>
    );
}
