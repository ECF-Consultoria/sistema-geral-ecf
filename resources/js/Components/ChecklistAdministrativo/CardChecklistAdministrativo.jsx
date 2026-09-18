import { useState } from 'react';
import { Check, Copy, FileText } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import LinhaChecklistItem from '@/Components/ChecklistAdministrativo/LinhaChecklistItem';
import { cn } from '@/lib/utils';

/**
 * CardChecklistAdministrativo — os 9 itens do §5 na ficha da Entrada
 * (Fase 152, ADMIN-01/ADMIN-03/ADMIN-04; redesenho de 2026-09-18).
 *
 * Componente REAL, nunca re-export puro (ver `LinhaChecklistItem.jsx`).
 *
 * ### Duas colunas, cada uma com um trabalho
 * À esquerda a SEQUÊNCIA: uma espinha vertical numerada, sem cartão por item,
 * com as linhas em colunas de largura fixa para que tudo alinhe entre elas.
 * À direita, grudenta, a MENSAGEM de boas-vindas — o artefato que sai desta
 * tela para o grupo do cliente.
 *
 * O progresso e o FINALIZAR **não moram aqui** — subiram para o cabeçalho da
 * página (`EntradaFicha.jsx`), que é a faixa visível em qualquer largura.
 *
 * ### "Ver contrato" é do GRUPO, não da linha
 * Os três itens de contrato apontavam para o MESMO documento, então a tela
 * mostrava "Ver contrato assinado" três vezes empilhadas — a repetição era
 * metade do ruído visual do bloco. O botão subiu para o cabeçalho do grupo,
 * onde aparece uma vez só e continua a um clique de distância de quem está
 * marcando "Contrato revisado".
 */

/** Ordem de exibição dos grupos — Contrato antes de Entrada (D-03). */
const ORDEM_GRUPOS = ['contrato', 'entrada'];

/**
 * Escreve na área de transferência. Devolve `false` quando o navegador não
 * expõe a API (contexto não-seguro, ex.: HTTP em rede interna).
 */
const copiar = async (texto) => {
    if (!texto || !navigator.clipboard?.writeText) return false;

    try {
        await navigator.clipboard.writeText(texto);
        return true;
    } catch {
        return false;
    }
};

/**
 * A mensagem de boas-vindas, montada no SERVIDOR (Fase 153, D-B) — aqui só se
 * exibe e se copia, nada é remontado. Quando o servidor acusa pendência, o que
 * aparece é O QUE FALTA, nunca o texto pela metade.
 */
function PainelBoasVindas({ mensagem, bloqueio }) {
    const [copiado, setCopiado] = useState(false);

    if (!mensagem?.texto) return null;

    const copiarMensagem = async () => {
        if (await copiar(mensagem.texto)) {
            setCopiado(true);
            setTimeout(() => setCopiado(false), 2500);
        }
    };

    return (
        <div className="rounded-2xl border border-white/[0.06] bg-white/[0.015] p-5">
            <div className="flex items-center justify-between gap-3">
                <h3 className="text-[13px] font-semibold text-white/75">Mensagem de boas-vindas</h3>
                <Button size="sm" variant="outline" onClick={copiarMensagem} disabled={!mensagem.pronta}>
                    {copiado ? (
                        <>
                            <Check size={13} className="mr-1.5 text-emerald-300" /> Copiado
                        </>
                    ) : (
                        <>
                            <Copy size={13} className="mr-1.5" /> Copiar
                        </>
                    )}
                </Button>
            </div>

            {!mensagem.pronta && (
                <ul className="mt-3 space-y-1 border-l-2 border-amber-400/40 pl-3">
                    {mensagem.pendencias.map((p) => (
                        <li key={p} className="text-[12px] leading-snug text-amber-300/80">
                            {p}
                        </li>
                    ))}
                </ul>
            )}

            {/* A trava de ordem do item 9 é informação diferente da prontidão do
                texto: a mensagem pode estar completa e o item ainda assim não
                poder ser marcado. Dizer as duas coisas separadas evita a
                pergunta "está pronta, por que não deixa marcar?". */}
            {mensagem.pronta && bloqueio && (
                <p className="mt-3 text-[12px] leading-relaxed text-white/45">{bloqueio}</p>
            )}

            <textarea
                readOnly
                value={mensagem.texto}
                rows={20}
                onFocus={(e) => e.target.select()}
                className={cn(
                    'mt-3 max-h-[52vh] w-full resize-y rounded-none border-0 border-t border-white/[0.06] bg-transparent px-0 pb-0 pt-3',
                    'text-[12.5px] leading-relaxed text-white/55',
                    'focus:outline-none focus:ring-0'
                )}
            />

            {mensagem.template_servico_nome && (
                <p className="mt-1 text-[11.5px] text-white/25">
                    Texto do serviço {mensagem.template_servico_nome}
                </p>
            )}
        </div>
    );
}

export default function CardChecklistAdministrativo({
    checklist,
    companyId,
    podeVerContrato = false,
    admanRegisterUrl = null,
    portalClienteUrl = null,
    mensagemBoasVindas = null,
    contratoAcesso = null,
    emailColaborador = null,
}) {
    if (!checklist) return null;

    const grupos = ORDEM_GRUPOS
        .map((chave) => checklist.grupos?.[chave])
        .filter(Boolean)
        // O grupo Contrato depende de DUAS condições, por razões diferentes:
        // ele não existe para empresa isenta (D-07) e não é enviado para quem
        // não tem `admin.contratos` (D-17). A segunda é defesa em
        // profundidade — o servidor já não manda os itens; isto evita bloco
        // vazio confuso caso um dia mande.
        .filter((grupo) => grupo.chave !== 'contrato' || podeVerContrato);

    // A sequência achatada, para numerar os degraus de forma contínua entre os
    // grupos e descobrir qual é o da vez. Nada aqui decide regra de negócio: a
    // trava vem pronta do servidor em `item.bloqueio`, e o "da vez" é só o
    // primeiro item aberto que não está travado.
    const sequencia = grupos.flatMap((grupo) => grupo.itens);
    const chaveAtual = sequencia.find((item) => item.status !== 'concluido' && !item.bloqueio)?.chave ?? null;
    const numeroPorChave = new Map(sequencia.map((item, indice) => [item.chave, indice + 1]));

    // A espinha termina no último item DE CADA GRUPO, não só no último de
    // todos: senão ela descia do "Contrato assinado" por um vão vazio até o
    // título do grupo seguinte, ligando duas coisas que não se ligam.
    const ultimaDoGrupo = new Set(
        grupos.map((grupo) => grupo.itens[grupo.itens.length - 1]?.chave).filter(Boolean)
    );

    const itemBoasVindas = sequencia.find((item) => item.chave === 'boas_vindas_enviada');

    /**
     * Abre o PDF assinado quando existe; senão o painel da Clicksign, onde se
     * acompanha o envelope. Sem nenhum dos dois não há documento para mostrar.
     *
     * ⚠️ O checklist **não gera** contrato. A geração fica no bloco próprio, na
     * ficha de Contrato — decisão do usuário de manter o fluxo de contrato como
     * o outro dev construiu e encaixar o fluxo de entrada em volta.
     */
    const verContrato = () => {
        if (contratoAcesso?.url) {
            window.open(contratoAcesso.url, '_blank', 'noopener');
        }
    };

    return (
        <div className="grid gap-8 lg:grid-cols-[minmax(0,1fr)_21rem] lg:gap-9 xl:grid-cols-[minmax(0,1fr)_26rem]">
            {/* ─── A sequência ──────────────────────────────────────────── */}
            <div className="space-y-7">
                {grupos.map((grupo) => (
                    <section key={grupo.chave}>
                        <div className="mb-4 flex items-center gap-4">
                            <h3 className="shrink-0 text-[13px] font-semibold text-white/45">{grupo.titulo}</h3>
                            <span aria-hidden className="h-px flex-1 bg-white/[0.06]" />

                            {grupo.chave === 'contrato' && contratoAcesso?.url && (
                                <button
                                    onClick={verContrato}
                                    className="inline-flex shrink-0 items-center gap-1.5 text-[12px] text-white/45 transition-colors hover:text-ecf-yellow"
                                >
                                    <FileText size={13} />
                                    {contratoAcesso.rotulo}
                                </button>
                            )}
                        </div>

                        <ol>
                            {grupo.itens.map((item) => (
                                <LinhaChecklistItem
                                    key={item.chave}
                                    item={item}
                                    numero={numeroPorChave.get(item.chave)}
                                    atual={item.chave === chaveAtual}
                                    ultimo={ultimaDoGrupo.has(item.chave)}
                                    companyId={companyId}
                                    admanRegisterUrl={admanRegisterUrl}
                                    portalClienteUrl={portalClienteUrl}
                                    emailColaborador={emailColaborador}
                                />
                            ))}
                        </ol>
                    </section>
                ))}
            </div>

            {/* ─── A mensagem, sempre à vista ───────────────────────────── */}
            <aside className="lg:sticky lg:top-6 lg:self-start">
                <PainelBoasVindas mensagem={mensagemBoasVindas} bloqueio={itemBoasVindas?.bloqueio ?? null} />
            </aside>
        </div>
    );
}
