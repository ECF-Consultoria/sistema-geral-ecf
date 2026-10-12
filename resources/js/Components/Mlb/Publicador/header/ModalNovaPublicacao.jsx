import { useEffect, useRef, useState } from 'react';
import * as Dialog from '@radix-ui/react-dialog';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { textoUtil } from '../BarraDaConta';
import PainelNovaPublicacao from './PainelNovaPublicacao';
import { filtrarGrupos, normalizarPayload } from './novaPublicacao';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 178, plano 03 — a CASCA do modal "Nova publicação" (NOVA-01/07/09).
//
// Protótipo: `new_layout_publicador/PublicadorHeader.dc.html`
//   · fundo (backdrop)      → :133
//   · caixa de 620px        → :134
//   · abrir/zerar/focar     → :204   (o `open()`)
//   · fechar / stopPropag.  → :229   (`closeModal`, `stop`)
//
// O corpo (cabeçalho de 60px, lista de 420px, item de 50px, vazio e rodapé) é
// `PainelNovaPublicacao` do plano 178-02 — IMPORTADO, nunca reimplementado aqui.
//
// ⚠️⚠️ POR QUE O PORTAL É OBRIGATÓRIO AQUI (restrição concreta, não preferência)
//
// O `<header>` tem `backdrop-blur-[14px] backdrop-saturate-[1.4]`, e
// `backdrop-filter` CRIA CONTAINING BLOCK para descendentes `position: fixed`.
// Um overlay `fixed inset-0` escrito dentro do header seria posicionado em
// relação AO HEADER e nasceria recortado dentro da faixa de 109px do topo —
// não cobriria a página. Somando a isso, o header vive dentro do `<main>`, que
// tem `overflow-y-auto`, e D2 proíbe tocar no `AppLayout`. Por isso:
// `@radix-ui/react-dialog` com `Dialog.Portal`, exatamente como os dois
// `Popover.Content` do plano 177-06 (`SeletorDeEmpresaDoHeader` e
// `ConexaoDoHeader`). O pacote JÁ é dependência (`package.json:36`) — nenhum
// pacote foi instalado nesta fase.
//
// ⚠️ E É POR ISSO QUE AS CLASSES DA CASCA SÃO CONSTANTES EXPORTADAS: o
// renderizador de servidor do React NÃO suporta portal ("Portals are not
// currently supported by the server renderer"), então nada de dentro de
// `Dialog.Portal` aparece no HTML de `react-dom/server`. Sem as constantes, a
// geometria da casca só poderia ser conferida por LEITURA DE FONTE, que é o
// tipo de gate fraco que deixou 4 mutações passarem na Fase 177. Com elas, o
// gate compara a string INTEIRA por igualdade e confere que o JSX as usa
// (`className={CLASSES_CAIXA}`), para a constante não poder divergir do render.
//
// ─── Divergências DECLARADAS (FID-02 — cada uma com restrição concreta) ───
//
// 1. MECANISMO DE CENTRALIZAÇÃO. O protótipo centraliza com um contêiner flex
//    no próprio backdrop (`display:flex; justify-content:center;
//    align-items:flex-start; padding-top:110px`, :133). No Radix, `Overlay` e
//    `Content` são IRMÃOS dentro do portal — o Content não é filho do Overlay —,
//    então a caixa se posiciona sozinha com `fixed left-1/2 top-[110px]
//    -translate-x-1/2`. Para uma caixa de LARGURA FIXA (620px) o resultado
//    computado é idêntico: 110px do topo da janela, centrada na horizontal.
// 2. `bg-black/[0.65]` é exatamente o `rgba(0,0,0,0.65)` de :133. Não existe
//    token `pub-*` para preto puro com alfa e NÃO se inventa um aqui: `black` é
//    cor padrão do Tailwind, não hex literal (TOK-15 ok).
// 3. FOCO NO INPUT. O protótipo usa `setTimeout(…, 30)` (:204) porque lá não há
//    gerenciador de foco nenhum. Aqui o Radix já move o foco para dentro da
//    `Dialog.Content` na abertura; `onOpenAutoFocus` + `preventDefault()` +
//    `focus()` torna o ALVO determinístico (o input de busca) em vez de
//    depender da ordem do DOM — e sem timer, que é o que poderia correr contra
//    a montagem.
// 4. TÍTULO ACESSÍVEL INVISÍVEL. O Radix 1.1.15 escreve no console quando a
//    `Dialog.Content` não tem `Dialog.Title` (`index.mjs:288`), e o protótipo
//    NÃO tem título visível. A saída é `sr-only` (classe padrão do Tailwind):
//    o leitor de tela anuncia e NENHUM pixel é acrescentado à tela. Mesmo
//    motivo para `aria-describedby={undefined}`, que é o escape documentado do
//    próprio Radix para "este diálogo não tem descrição" (`index.mjs:304`).
// 5. `consulta`/`carregando`/`erro` são estado desta casca — o protótipo guarda
//    `mq` no componente e a lista dele é mock constante, sem carga nem falha.
//    Nada disso muda o desenho: as três frases usam a geometria do vazio
//    (:156), decidida na wave 2.
//
// ⚠️ O QUE NÃO PODE SER DESLIGADO: `onEscapeKeyDown` e `onPointerDownOutside`.
// Esc e clique fora vêm do `DismissableLayer` do Radix e são exatamente o que
// NOVA-09 exige. O `stop` do protótipo (:229) existe só porque lá o backdrop é
// o PAI da caixa e o clique subiria por propagação; aqui não há esse pai, então
// não há o que parar. Há asserção NEGATIVA no gate provando que os dois
// handlers não aparecem neste arquivo.
// ═══════════════════════════════════════════════════════════════════════════

/**
 * ⚠️ A geometria da casca vive AQUI, em constante exportada, e não solta no
 * JSX — o motivo (portal × renderizador de servidor) está escrito acima.
 *
 * O fundo: `position:fixed; inset:0; z-index:80; background:rgba(0,0,0,0.65)`
 * (protótipo :133). O `display:flex` e o `padding-top:110px` do protótipo NÃO
 * vêm para cá: no Radix a caixa não é filha do fundo (divergência 1).
 */
export const CLASSES_FUNDO = 'fixed inset-0 z-[80] bg-black/[0.65]';

/**
 * A caixa: `width:620px; background:#111111; border:1px solid #2E2E2E;
 * border-radius:16px; box-shadow:0 30px 80px rgba(0,0,0,0.8); overflow:hidden`
 * (protótipo :134), alinhada a 110px do topo e centrada (protótipo :133).
 *
 * ⚠️ `font-pub` É OBRIGATÓRIO E NÃO É DECORAÇÃO: o conteúdo portado sai da
 * subárvore do `<header>` e vai para o `document.body`, que é `font-sans`
 * (Inter). Sem `font-pub` declarado AQUI, o modal inteiro perde a Geist em
 * silêncio — a mesma armadilha que os dois `Popover.Content` do plano 177-06
 * tiveram de resolver exatamente do mesmo jeito.
 */
export const CLASSES_CAIXA = 'fixed left-1/2 top-[110px] z-[80] w-[620px] -translate-x-1/2 overflow-hidden rounded-pub-card border border-pub-line-strong bg-pub-surface font-pub text-pub-txt shadow-pub-modal';

/** O título acessível que não ocupa pixel nenhum (divergência 4). */
const TITULO_ACESSIVEL = 'Nova publicação';

/**
 * A casca do modal "Nova publicação".
 *
 * @param {object} props
 * @param {boolean} [props.aberto] quem manda abrir é o header — os dois botões
 *   (a busca de 250px e o CTA amarelo) e os atalhos chamam a MESMA função lá
 * @param {Function} [props.aoFechar] chamado no Esc, no clique fora e depois de
 *   escolher um item
 * @param {string|null} [props.conta] a chave da conta (`empresa-N`/`company-N`)
 */
export default function ModalNovaPublicacao({ aberto = false, aoFechar = () => {}, conta = null }) {
    const [consulta, setConsulta] = useState('');
    const [dados, setDados] = useState(null);
    const [carregando, setCarregando] = useState(false);
    const [erro, setErro] = useState(false);

    const inputRef = useRef(null);

    // ⚠️ Guarda a CONTA já pedida, e não um booleano: o header vive nas 9
    // páginas e uma navegação do Inertia para outra empresa reconcilia este
    // componente em vez de remontá-lo. Com um `jaPediu` booleano, o modal
    // mostraria a lista de produtos da empresa ANTERIOR — dado errado é pior
    // que dado lento. `null` também significa "a última tentativa falhou, pode
    // tentar de novo".
    const contaJaPedida = useRef(null);

    // ⚠️ `textoUtil` e não `String(conta)`: a chave vai para `route()` como
    // parâmetro de URL, e um objeto (ou `''`) montaria um destino inventado
    // (T-178-05). O header já faz isso, e a guarda fica aqui TAMBÉM porque este
    // componente é exportado e pode ganhar outro chamador amanhã.
    const contaUsavel = textoUtil(conta);

    // Sem conta usável não existe destino: o modal não abre e a rede não é
    // tocada. Quem já barra é o header (os dois botões ficam `disabled` com
    // `title` explicando, EXMP-03) — isto é a segunda tranca da mesma porta.
    const abertoDeVerdade = aberto && contaUsavel !== null;

    useEffect(() => {
        if (!abertoDeVerdade) {
            // É o `mq: ''` do `open()` do protótipo (:204), só do lado do
            // fechamento: reabrir mostra a lista inteira, não a busca de ontem.
            setConsulta('');
            return;
        }

        // UMA chamada por montagem e por conta (T-178-03): `produtosParaTela()`
        // é a consulta mais pesada do módulo. O dado volta fresco por
        // construção, porque escolher um item NAVEGA e remonta a página.
        if (contaJaPedida.current === contaUsavel) return;
        contaJaPedida.current = contaUsavel;

        setCarregando(true);
        setErro(false);
        axios
            .get(route('mlb.anuncios.publicador.nova-publicacao', { conta: contaUsavel }))
            // ⚠️ `normalizarPayload` é a FRONTEIRA: nada do servidor entra em
            // estado (nem no JSX) sem passar por ela. É a lição de 07/10 —
            // campo não-escalar renderizado como texto derrubou a página.
            .then((resposta) => setDados(normalizarPayload(resposta.data)))
            .catch(() => {
                // `null` libera nova tentativa na próxima abertura. Lista vazia
                // em silêncio é proibida: o painel mostra a frase de erro.
                contaJaPedida.current = null;
                setErro(true);
            })
            .finally(() => setCarregando(false));
    }, [abertoDeVerdade, contaUsavel]);

    /**
     * NOVA-07 — a `url` vem PRONTA do servidor (wave 1, montada lá por
     * `route()`). ⚠️ O cliente NÃO monta rota de destino: um `route()` aqui com
     * campo do item seria exatamente o destino inventado de T-178-05. Item sem
     * `url` usável nem chega (a wave 2 o desenha desabilitado), e a guarda fica
     * aqui também porque `aoEscolher` é prop pública do painel.
     */
    function escolher(item) {
        const destino = textoUtil(item?.url);
        if (destino === null) return;
        aoFechar();
        router.get(destino);
    }

    return (
        <Dialog.Root
            open={abertoDeVerdade}
            onOpenChange={(valor) => {
                if (!valor) aoFechar();
            }}
        >
            {/* Sem `Dialog.Trigger` DE PROPÓSITO: quem abre são os dois botões
                do header e os atalhos ⌘K/N — uma função, três chamadores,
                nenhum deles aqui dentro. */}
            <Dialog.Portal>
                <Dialog.Overlay className={CLASSES_FUNDO} />
                <Dialog.Content
                    className={CLASSES_CAIXA}
                    aria-describedby={undefined}
                    onOpenAutoFocus={(evento) => {
                        evento.preventDefault();
                        inputRef.current?.focus();
                    }}
                >
                    <Dialog.Title className="sr-only">{TITULO_ACESSIVEL}</Dialog.Title>
                    <PainelNovaPublicacao
                        consulta={consulta}
                        aoConsultar={(evento) => setConsulta(evento.target.value)}
                        grupos={filtrarGrupos({ grupos: dados?.grupos ?? [], consulta })}
                        carregando={carregando}
                        erro={erro}
                        aoEscolher={escolher}
                        inputRef={inputRef}
                    />
                </Dialog.Content>
            </Dialog.Portal>
        </Dialog.Root>
    );
}
