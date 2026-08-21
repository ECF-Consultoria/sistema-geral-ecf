import { useState } from 'react';
import { cn } from '@/lib/utils';

// ─── A marca do CLIENTE no Portal ───────────────────────────────────────────
//
// Este é o único lugar do sistema em que a identidade visual do cliente ocupa
// o espaço que seria nosso. O objetivo é que ele sinta que entrou num ambiente
// da empresa dele — e é por isso que a logo não pode chegar deformada.
//
// ### Por que a logo vai sobre um "papel" claro
// Logo de cliente vem em qualquer coisa: PNG transparente com desenho preto,
// JPG com fundo branco, SVG colorido. O menu do portal é quase preto. Sem um
// fundo próprio, a metade das logos do mundo desapareceria no escuro — e o
// sintoma seria "a logo não está aparecendo", quando na verdade está lá,
// preta sobre preto. O papel claro dá o mesmo contraste para todas, e é o
// mesmo motivo pelo qual o fallback JPEG do reencode (que achata a
// transparência em branco) passa despercebido aqui.
//
// ### Por que a caixa tem altura fixa e a imagem é `object-contain`
// A caixa reserva SEMPRE o mesmo espaço no menu; a imagem se encaixa dentro
// dela respeitando a proporção original. Logo horizontal larga encosta nas
// laterais e sobra altura; logo vertical ou quadrada encosta em cima e embaixo
// e sobra largura. Nenhuma das duas estica. `object-cover` resolveria o
// preenchimento cortando a marca, o que é pior do que sobrar espaço: cortar
// logo de cliente é mexer na marca dele.

const TAMANHOS = {
    // No menu lateral: caixa larga e baixa, o formato em que a maioria das
    // logos empresariais é desenhada.
    menu: { caixa: 'h-12 w-full max-w-[168px] px-3 py-2', mono: 'h-12 w-12 text-[15px]', nome: 'text-[13px]' },
    // No hub, dentro do cartão de boas-vindas.
    hub:  { caixa: 'h-16 w-[184px] max-w-full px-3.5 py-2.5', mono: 'h-16 w-16 text-[19px]', nome: 'text-[15px]' },
};

/**
 * @param {{nome: string, logo_url: ?string, iniciais: string}} empresa
 * @param {'menu'|'hub'} tamanho
 * @param {boolean} comNome  desenha o nome da empresa abaixo/ao lado da marca
 */
export default function LogoEmpresa({ empresa, tamanho = 'menu', comNome = false, className }) {
    // Arquivo apagado do disco, URL externa fora do ar, storage:link não
    // rodado — em qualquer um desses casos a `img` quebra e mostraria o ícone
    // de imagem partida no topo do portal do cliente. Cair no monograma é
    // sempre melhor do que isso.
    const [falhou, setFalhou] = useState(false);

    const t = TAMANHOS[tamanho] ?? TAMANHOS.menu;
    const temLogo = Boolean(empresa?.logo_url) && !falhou;

    return (
        <div className={cn('flex items-center gap-3 min-w-0', className)}>
            {temLogo ? (
                <div className={cn(
                    'flex items-center justify-center rounded-xl bg-white/95 shadow-sm shrink-0',
                    t.caixa,
                )}>
                    <img
                        src={empresa.logo_url}
                        alt={empresa.nome}
                        onError={() => setFalhou(true)}
                        className="max-h-full max-w-full object-contain"
                    />
                </div>
            ) : (
                <div
                    aria-hidden="true"
                    className={cn(
                        'grid place-items-center rounded-xl shrink-0 font-display font-extrabold',
                        'bg-ecf-yellow/10 border border-ecf-yellow/25 text-ecf-yellow',
                        t.mono,
                    )}
                >
                    {empresa?.iniciais ?? '?'}
                </div>
            )}

            {comNome && (
                <div className="min-w-0">
                    <p className={cn('text-white font-semibold truncate', t.nome)}>{empresa?.nome}</p>
                    <p className="text-white/30 text-[11px] truncate">Portal do Cliente</p>
                </div>
            )}
        </div>
    );
}
