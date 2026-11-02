import { CHAVE_NOVA_ONDA, chaveDaOnda } from '@/lib/trilha';
import { andamentoDaOnda, comMaiuscula, ondasEmOrdem, quandoDaOndaPorExtenso } from '@/lib/trilhaOndas';
import { cn } from '@/lib/utils';
import { Pilula } from '@/Components/Trilha/Pecas';
import { plural } from '@/Components/Trilha/formatos';

// ─── Trilha de produtos — os cartões das ondas (etapas 3 e 4) ───────────────
//
// O `.ondas` do protótipo, com a régua da 2ª entrega: a data da onda é o dia em
// que o robô COMEÇA ("Começa em 05/11", "Começou em 03/11"), nunca prazo. A
// barra conta as ofertas da onda que já estão na revisão ou no ar. Clicar abre
// a onda (`?abrir=w:{id}`): nome, data e as ofertas dela.
//
// O cartão "+ Nova onda" só aparece no Planejamento e só para quem escreve
// (`podeCriar` — nunca o cliente, nem o "Ver como: Cliente"). Na etapa 4 os
// cartões são os mesmos, sem ele. Ordem: pela data e, no empate, pelo id.

const CARTAO = cn(
    'flex h-full w-full flex-col gap-1.5 rounded-xl border px-[14px] py-3 text-left',
    'focus-visible:[outline-style:solid] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-trilha-run',
);

/**
 * @param {{
 *   dados: {ofertas: Array, ondas?: Array},
 *   podeCriar?: boolean,
 *   onAbrir: (chave: string, origem: ?HTMLElement) => void,
 * }} props
 */
export default function CartoesDasOndas({ dados, podeCriar = false, onAbrir }) {
    const ondas = ondasEmOrdem(dados.ondas);
    if (!ondas.length && !podeCriar) return null;

    return (
        <div className="border-b border-trilha-line px-[18px] py-4" data-ondas="">
            <ul aria-label="Ondas" className="m-0 grid list-none grid-cols-[repeat(auto-fill,minmax(220px,1fr))] gap-3 p-0">
                {ondas.map((onda) => (
                    <li key={onda.id} className="min-w-0">
                        <CartaoDaOnda onda={onda} dados={dados} onAbrir={onAbrir} />
                    </li>
                ))}
                {podeCriar && (
                    <li className="min-w-0">
                        <button
                            type="button"
                            data-chave={CHAVE_NOVA_ONDA}
                            data-acao="nova-onda"
                            onClick={(evento) => onAbrir(CHAVE_NOVA_ONDA, evento.currentTarget)}
                            className={cn(CARTAO, 'min-h-[104px] justify-center border-dashed border-trilha-line bg-trilha-surface hover:border-trilha-muted')}
                        >
                            <b className="text-[14px] text-trilha-fg">+ Nova onda</b>
                            <span className="text-[12.5px] text-trilha-muted">Agrupe as ofertas que começam no mesmo dia.</span>
                        </button>
                    </li>
                )}
            </ul>
        </div>
    );
}

function CartaoDaOnda({ onda, dados, onAbrir }) {
    const chave = chaveDaOnda(onda);
    const { total, prontas, porcento, concluida } = andamentoDaOnda(dados, onda.id);
    let fase = { tom: 'neutro', rotulo: 'Agendada' };
    if (concluida) fase = { tom: 'ok', rotulo: 'Concluída' };
    else if (onda.comecou) fase = { tom: 'run', rotulo: 'Começou' };

    return (
        <button
            type="button"
            data-chave={chave}
            data-onda={onda.id}
            onClick={(evento) => onAbrir(chave, evento.currentTarget)}
            className={cn(CARTAO, 'border-trilha-line bg-trilha-surface hover:border-trilha-muted')}
        >
            <span className="flex items-start justify-between gap-2">
                <b className="min-w-0 text-[14px] text-trilha-fg [overflow-wrap:anywhere]">{onda.nome}</b>
                <Pilula tom={fase.tom} className="shrink-0">
                    {fase.rotulo}
                </Pilula>
            </span>
            <span className="text-[12.5px] text-trilha-muted">
                {comMaiuscula(quandoDaOndaPorExtenso(onda))} · {plural(total, 'oferta', 'ofertas')}
            </span>
            <span className="block h-1.5 overflow-hidden rounded-full bg-trilha-line2" aria-hidden="true">
                <i className="block h-full bg-trilha-ok" style={{ width: `${porcento}%` }} />
            </span>
            <span className="text-[12.5px] text-trilha-fg2">
                {total ? `${prontas} de ${total} já na revisão ou no ar` : 'Nenhuma oferta nesta onda ainda.'}
            </span>
        </button>
    );
}
