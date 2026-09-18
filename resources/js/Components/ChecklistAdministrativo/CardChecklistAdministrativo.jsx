import { useState } from 'react';
import { Check, Copy } from 'lucide-react';
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
 * À esquerda a SEQUÊNCIA: uma espinha vertical numerada, sem cartão por item.
 * À direita, grudenta, a MENSAGEM de boas-vindas — o artefato que sai desta
 * tela para o grupo do cliente. Ela ficava lá embaixo, dentro do item 9, num
 * bloco alto que empurrava tudo; agora acompanha a rolagem e pode ser copiada
 * a qualquer momento, e a coluna da direita deixou de ser espaço morto abaixo
 * de um cartão curto.
 *
 * O progresso e o FINALIZAR **não moram aqui** — subiram para o cabeçalho da
 * página (`EntradaFicha.jsx`), que é a faixa visível em qualquer largura.
 *
 * ### Cada item cabe numa linha
 * Título e estado dividem a mesma linha, separados por um ponto médio, com as
 * ações à direita. Antes eram duas linhas por item — com 9 itens, a lista não
 * cabia na tela e o olho perdia a sequência.
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
        <div className="rounded-2xl border border-white/[0.07] bg-white/[0.02] p-5">
            <div className="flex items-start justify-between gap-3">
                <h3 className="text-[13px] font-semibold text-white/80">Mensagem de boas-vindas</h3>
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
                <div className="mt-3 rounded-lg border border-amber-500/20 bg-amber-500/[0.06] px-3.5 py-2.5">
                    <p className="text-[12px] font-semibold text-amber-300">Falta para a mensagem ficar pronta</p>
                    <ul className="mt-1 space-y-0.5">
                        {mensagem.pendencias.map((p) => (
                            <li key={p} className="text-[12px] text-amber-300/75">
                                {p}
                            </li>
                        ))}
                    </ul>
                </div>
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
                rows={16}
                onFocus={(e) => e.target.select()}
                className={cn(
                    'mt-3 w-full resize-y rounded-xl border border-white/[0.07] bg-black/30',
                    'px-3.5 py-3 text-[12.5px] leading-relaxed text-white/65'
                )}
            />

            {mensagem.template_servico_nome && (
                <p className="mt-2 text-[11.5px] text-white/25">
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

    return (
        <div className="grid gap-8 lg:grid-cols-[minmax(0,1fr)_23rem] lg:gap-10 xl:grid-cols-[minmax(0,1fr)_26rem]">
            {/* ─── A sequência ──────────────────────────────────────────── */}
            <div className="space-y-8">
                {grupos.map((grupo) => (
                    <section key={grupo.chave}>
                        <div className="mb-4 flex items-center gap-4">
                            <h3 className="shrink-0 text-[13px] font-semibold text-white/45">{grupo.titulo}</h3>
                            <span aria-hidden className="h-px flex-1 bg-white/[0.06]" />
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
                                    contratoAcesso={contratoAcesso}
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
