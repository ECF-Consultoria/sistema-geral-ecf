import { comQuemEsta } from '@/lib/trilha';
import { cn } from '@/lib/utils';

// ─── Trilha de produtos — "Com quem está agora" ─────────────────────────────
//
// Um atalho por dono, com o trabalho que está com ele; clicar abre a etapa dele.
// A conta e os textos vêm de `comQuemEsta()` (lib/trilha.js) — o Analista não
// entra porque a Jardinagem é de outro módulo.

// A cor do círculo de cada papel, como no protótipo (`.av.cli` e `.av.robo`).
const AVATAR = {
    cli: 'bg-trilha-warn-bg text-trilha-warn',
    robo: 'bg-trilha-roxo-bg text-trilha-roxo',
};

const CHIP = 'inline-flex items-center gap-2 rounded-full border border-trilha-line bg-trilha-surface px-2.5 py-1.5 text-[12.5px] text-trilha-fg hover:border-trilha-muted focus-visible:[outline-style:solid] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-trilha-run';

/**
 * @param {{
 *   dados: {produtos: Array, ofertas: Array},
 *   visao: 'equipe'|'cliente',
 *   etapa: ?string,
 *   onEscolher: (chave: ?string) => void,
 *   trabalho?: boolean,
 * }} props  Com a trilha de trabalho (`trabalho`), a Lista é da equipe ("Equipe ECF · cadastrar a lista").
 */
export default function ComQuemEsta({ dados, visao, etapa, onEscolher, trabalho = false }) {
    const donos = comQuemEsta(dados, visao, trabalho);

    return (
        <div className="mb-[18px] mt-[14px] flex flex-wrap items-center gap-2" data-com-quem-esta="">
            <span className="mr-1 text-[12px] font-semibold text-trilha-muted">Com quem está agora</span>
            {donos.map((d) => (
                <button
                    key={d.dono}
                    type="button"
                    aria-pressed={etapa === d.etapa}
                    data-dono={d.dono}
                    onClick={() => onEscolher(d.etapa)}
                    className={CHIP}
                >
                    <span
                        aria-hidden="true"
                        className={cn(
                            'inline-flex h-5 w-5 items-center justify-center rounded-full bg-trilha-line2 text-[11px] font-bold text-trilha-fg',
                            AVATAR[d.papel],
                        )}
                    >
                        {d.sigla}
                    </span>
                    <span>
                        {d.dono}
                        {d.descricao && <span className="text-trilha-muted"> · {d.descricao}</span>}
                    </span>
                    <b className="tabular-nums">{d.quantidade}</b>
                </button>
            ))}
            <button type="button" aria-pressed={etapa === null} onClick={() => onEscolher(null)} className={CHIP}>
                <b>Ver tudo</b>
            </button>
        </div>
    );
}
