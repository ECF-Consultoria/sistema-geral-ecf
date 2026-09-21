import { useState } from 'react';
import { Check, Copy, FileText, Info, MessageSquare, Users } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import LinhaChecklistItem from '@/Components/ChecklistAdministrativo/LinhaChecklistItem';
import { cn } from '@/lib/utils';

/**
 * CardChecklistAdministrativo — os 9 itens do §5 na ficha da Entrada
 * (Fase 152, ADMIN-01/ADMIN-03/ADMIN-04; redesenho de 2026-09-21).
 *
 * Componente REAL, nunca re-export puro (ver `LinhaChecklistItem.jsx`).
 *
 * ### Duas colunas, 65/35
 * À esquerda, dois cartões de seção — Contrato e Estrutura e Comunicação —,
 * cada um com ícone, título e, quando faz sentido, uma ação do GRUPO no canto
 * oposto. À direita, grudenta, a mensagem de boas-vindas: área contextual
 * secundária, que acompanha a rolagem sem competir com o checklist.
 *
 * Os cartões são leves de propósito — contorno fino e um fundo pouco acima do
 * fundo da página. Sem sombra, sem gradiente: a separação vem da borda.
 *
 * O progresso e o FINALIZAR **não moram aqui** — ficam no cartão de resumo do
 * cabeçalho da página (`EntradaFicha.jsx`).
 *
 * ### "Ver contrato" é do GRUPO, não da linha
 * Os três itens de contrato apontam para o MESMO documento, então a tela
 * mostrava "Ver contrato assinado" três vezes empilhadas — a repetição era
 * metade do ruído visual do bloco. O botão vive no cabeçalho da seção, onde
 * aparece uma vez só e continua a um clique de quem marca "Contrato revisado".
 */

/** Ordem de exibição dos grupos — Contrato antes de Entrada (D-03). */
const ORDEM_GRUPOS = ['contrato', 'entrada'];

/** Ícone de cada seção — mapa fechado, como o catálogo que ele acompanha. */
const ICONE_DO_GRUPO = {
    contrato: FileText,
    entrada: Users,
};

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
 * O cartão de uma seção: contorno fino, cabeçalho com ícone + título e um
 * canto livre para a ação do grupo.
 */
function CartaoSecao({ icone: Icone, titulo, acao = null, children }) {
    return (
        <section className="rounded-xl border border-white/[0.07] bg-white/[0.02]">
            <header className="flex items-center justify-between gap-4 border-b border-white/[0.06] px-6 py-4">
                <h2 className="flex items-center gap-2.5 text-[14px] font-semibold text-white/85">
                    <Icone size={16} className="text-white/40" />
                    {titulo}
                </h2>
                {acao}
            </header>

            <div className="px-6 py-5">{children}</div>
        </section>
    );
}

/**
 * O texto da mensagem, quebrado nos blocos que as linhas em branco do próprio
 * template já marcam.
 *
 * ⚠️ NÃO interpreta nem reescreve o conteúdo: o texto vem montado do servidor
 * (Fase 153, D-B) e é editável por serviço em Boas-vindas. O que muda aqui é
 * só o respiro entre blocos e o recuo pendente — quando o bloco começa com um
 * emoji (é assim que o template marca cada acesso), as linhas seguintes
 * alinham com o TEXTO em vez de voltarem para debaixo do emoji. O que se
 * copia continua sendo a string original, intacta.
 */
function CorpoDaMensagem({ texto }) {
    const blocos = texto.split(/\n{2,}/).filter((bloco) => bloco.trim() !== '');

    // `\p{Extended_Pictographic}` é a propriedade Unicode que cobre emoji.
    // Motor sem suporte a property escapes cai no `catch` e ninguém ganha
    // recuo — degradação silenciosa é o certo para um detalhe de apresentação.
    let comecaComEmoji = () => false;
    try {
        const emoji = new RegExp('^\\p{Extended_Pictographic}', 'u');
        comecaComEmoji = (bloco) => emoji.test(bloco);
    } catch {
        /* sem suporte a property escapes */
    }

    return (
        <div className="space-y-3.5">
            {blocos.map((bloco, indice) => (
                <p
                    key={indice}
                    className={cn(
                        'whitespace-pre-line break-words text-[12.5px] leading-relaxed text-white/65',
                        comecaComEmoji(bloco) && '-indent-6 pl-6'
                    )}
                >
                    {bloco}
                </p>
            ))}
        </div>
    );
}

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
        <div className="rounded-xl border border-white/[0.07] bg-white/[0.02]">
            <header className="flex items-center justify-between gap-4 border-b border-white/[0.06] px-6 py-4">
                <h2 className="flex items-center gap-2.5 text-[14px] font-semibold text-white/85">
                    <MessageSquare size={16} className="text-white/40" />
                    Mensagem de boas-vindas
                </h2>

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
            </header>

            <div className="space-y-4 px-6 py-5">
                {!mensagem.pronta && (
                    <div className="flex gap-2.5 rounded-lg border border-amber-400/25 bg-amber-400/[0.07] px-3.5 py-3">
                        <Info size={15} className="mt-px shrink-0 text-amber-300/90" />
                        <div className="space-y-1">
                            {mensagem.pendencias.map((p) => (
                                <p key={p} className="text-[12px] leading-snug text-amber-200/90">
                                    {p}
                                </p>
                            ))}
                        </div>
                    </div>
                )}

                {/* A trava de ordem do item 9 é informação diferente da prontidão
                    do texto: a mensagem pode estar completa e o item ainda assim
                    não poder ser marcado. Dizer as duas coisas separadas evita a
                    pergunta "está pronta, por que não deixa marcar?". */}
                {mensagem.pronta && bloqueio && (
                    <p className="text-[12px] leading-relaxed text-white/50">{bloqueio}</p>
                )}

                <CorpoDaMensagem texto={mensagem.texto} />

                {mensagem.template_servico_nome && (
                    <p className="border-t border-white/[0.06] pt-3 text-[11.5px] text-white/35">
                        Texto do serviço {mensagem.template_servico_nome}
                    </p>
                )}
            </div>
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
        <div className="grid gap-6 lg:grid-cols-[minmax(0,65fr)_minmax(0,35fr)]">
            <div className="space-y-6">
                {grupos.map((grupo) => {
                    // Contrato é uma lista de três estados que não se
                    // encadeiam; Entrada é uma sequência de verdade. A espinha
                    // só diz a verdade na segunda — na primeira vale o filete
                    // entre as linhas.
                    const ehSequencia = grupo.chave === 'entrada';

                    return (
                        <CartaoSecao
                            key={grupo.chave}
                            icone={ICONE_DO_GRUPO[grupo.chave] ?? FileText}
                            titulo={grupo.titulo}
                            acao={
                                grupo.chave === 'contrato' && contratoAcesso?.url ? (
                                    <button
                                        onClick={verContrato}
                                        className="inline-flex shrink-0 items-center gap-1.5 text-[12.5px] text-white/50 transition-colors hover:text-ecf-yellow"
                                    >
                                        <FileText size={13} />
                                        {contratoAcesso.rotulo}
                                    </button>
                                ) : null
                            }
                        >
                            <ol>
                                {grupo.itens.map((item) => (
                                    <LinhaChecklistItem
                                        key={item.chave}
                                        item={item}
                                        numero={numeroPorChave.get(item.chave)}
                                        atual={item.chave === chaveAtual}
                                        ultimo={ultimaDoGrupo.has(item.chave)}
                                        espinha={ehSequencia}
                                        separador={!ehSequencia}
                                        companyId={companyId}
                                        admanRegisterUrl={admanRegisterUrl}
                                        portalClienteUrl={portalClienteUrl}
                                        emailColaborador={emailColaborador}
                                    />
                                ))}
                            </ol>
                        </CartaoSecao>
                    );
                })}
            </div>

            <aside className="lg:sticky lg:top-6 lg:self-start">
                <PainelBoasVindas mensagem={mensagemBoasVindas} bloqueio={itemBoasVindas?.bloqueio ?? null} />
            </aside>
        </div>
    );
}
