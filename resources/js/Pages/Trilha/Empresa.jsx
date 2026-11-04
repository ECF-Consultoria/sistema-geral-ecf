import { Suspense, lazy, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import TrilhaLayout from '@/Layouts/TrilhaLayout';
import MapaDaLoja from '@/Components/Trilha/MapaDaLoja';
import CabecalhoDaTrilha, { AbasDaVista } from '@/Components/Trilha/CabecalhoDaTrilha';
import BarraDeEtapas from '@/Components/Trilha/BarraDeEtapas';
import ComQuemEsta from '@/Components/Trilha/ComQuemEsta';
import PainelDaLista from '@/Components/Trilha/PainelDaLista';
import { PainelDaEtapa, PainelGeral } from '@/Components/Trilha/PainelDaEtapa';
import { Gaveta, MioloDaGaveta, PainelDoItem } from '@/Components/Trilha/PainelLateral';
import PainelDaOnda from '@/Components/Trilha/PainelDaOnda';
import PainelDeMapear from '@/Components/Trilha/PainelDeMapear';
import Aviso from '@/Components/Trilha/Aviso';
import { CHAVE_NOVA_ONDA, chaveDeCadastro, contagemPorEtapa } from '@/lib/trilha';
import { recarregar } from '@/lib/trilhaEscrita';
import {
    buscaComEstado,
    enderecoDasFotosDoCliente,
    enderecosDaAprovacaoDoCliente,
    estadoComUrl,
    etapaDoItem,
    lerEstadoDaUrl,
    linksDaTrilha,
    linksParaVisaoCliente,
    painelDaChave,
    paraVisaoCliente,
    podeEscrever,
    podeVerPrevia,
    produtosDaTrilha,
} from '@/Components/Trilha/estadoDaTela';
import { fmtGeradoEm } from '@/Components/Trilha/formatos';

// ─── Trilha de produtos — a trilha de UMA empresa ───────────────────────────
//
// Uma página, duas portas: a equipe em `admin…/trilha/{empresa}` (visao
// 'equipe') e o cliente em `cliente…/portal/{empresa}/trilha` (visao 'cliente').
// O servidor já manda cada porta com o que ela pode ver; a página só agrupa e
// desenha — nenhuma regra de etapa mora aqui.
//
// O estado da tela (etapa escolhida, aba e item aberto) vive na URL com
// `history.replaceState`, sem ir ao servidor: um F5 ou um link colado reabrem no
// mesmo lugar. O "Ver como: Cliente" da equipe é só do navegador e não vai
// para a URL — é uma conferência, não um lugar.
//
// ### 2ª entrega (02/11/2026): a trilha de trabalho
// Com `trabalho` (a empresa na chave `TRILHA_TRABALHO_EMPRESAS`), a equipe mapeia
// (etapa 2) e planeja em ondas com data (etapa 3) aqui dentro. Escreve só quem
// recebeu `pode_editar` e os endereços em `escrita` — nunca o cliente, e nunca no
// "Ver como: Cliente". Os painéis de escrita (a onda, `w:`; o mapear, `m:`) usam
// a MESMA gaveta dos itens; depois de cada escrita a página recarrega só os
// dados da trilha (`recarregar`) e mostra o resultado no `Aviso`.
//
// ### 3ª entrega (02/11/2026): o cadastro do produto na trilha
// A equipe com escrita cria ("+ Produto na lista", `e:novo`) e completa o produto
// (`e:{id}`) na mesma gaveta. A pasta `Components/Trilha/Cadastro/` chega SÓ por
// `lazy` e só com `podeEditar`: o cliente nunca baixa aquele JS (os textos são da
// equipe). A gaveta de cadastro registra uma GUARDA: com algo não salvo, fechar,
// abrir outro item por dentro e trocar o "Ver como" pedem confirmação no rodapé
// dela antes de seguir.
//
// F7 (03/11/2026): a planilha por categoria (`e:planilha`), a 3ª gaveta da pasta
// `Cadastro/` — "Usar planilha" no quadro da Lista. Também só para quem escreve,
// e também por `lazy` com o 2º caminho em `CadastroSobDemanda.jsx` (§30 dos
// learnings: sem ele esta página some do manifest do Vite).
//
// F8 (03/11/2026): a importação dos anúncios da loja (`e:ml`), a 4ª gaveta da
// pasta — "Escolher anúncios" no quadro da Lista. Só para quem escreve, por
// `lazy`, com o 2º caminho em `CadastroSobDemanda.jsx`. O nome do marketplace só
// aparece dentro da gaveta (o cliente baixa o JS desta página).
//
// ### 4ª entrega, fatia 4a (03/11/2026): a prévia do anúncio
// A equipe com `escrita.revisao` confere o anúncio pré-pronto na gaveta `v:{item}`
// (`Components/Trilha/Revisao/`, só leitura): o "Revisar" da linha em revisão abre
// ela, e o painel da oferta ganha [Ver prévia]. Também por `lazy`, com o 2º caminho
// em `CadastroSobDemanda.jsx` — o cartão do anúncio não vai no pacote do cliente.
//
// ### 4ª entrega, fatia 4f (04/11/2026): as fotos que o cliente envia
// A 1ª escrita da porta do CLIENTE: com `cliente_escrita.fotos` (só o cliente de
// verdade recebe; nunca a equipe, nem no "Ver como: Cliente"), o painel do produto
// ganha a caixa "Fotos" (`Components/Trilha/Cliente/`, por `lazy` dentro do
// `PainelLateral`, com o 2º caminho em `CadastroSobDemanda.jsx`). Depois de enviar
// ou excluir, a trilha recarrega (as faltas do produto mudam).
//
// ### 4ª entrega, fatia 4e (04/11/2026): a aprovação do cliente por loja
// Com `cliente_escrita.aprovacao` (de novo só o cliente de verdade), o anúncio que a
// equipe mandou para ele abre na gaveta dele (`v:{item}`, a linha diz "Aprovar"):
// `Components/Trilha/Cliente/AprovarAnuncio.jsx`, por `lazy` com o 2º caminho em
// `CadastroSobDemanda.jsx`. Na porta da equipe o `v:` segue sendo a prévia dela (com o
// [Mandar ao cliente] quando a loja aprova com o cliente).

const ID_DA_VISTA = 'trilha-vista';

// Só quem escreve chega a desenhar estes (a página não abre o painel `cadastro` para mais ninguém).
const PainelDoCadastro = lazy(() => import('@/Components/Trilha/Cadastro/PainelDoCadastro'));
const NovoProduto = lazy(() => import('@/Components/Trilha/Cadastro/NovoProduto'));
const PlanilhaPorCategoria = lazy(() => import('@/Components/Trilha/Cadastro/PlanilhaPorCategoria'));
const ImportarDoMercadoLivre = lazy(() => import('@/Components/Trilha/Cadastro/ImportarDoMercadoLivre'));
// Só a equipe com `escrita.revisao` abre o painel `previa` (`painelDaChave`).
const PreviaDoAnuncio = lazy(() => import('@/Components/Trilha/Revisao/PreviaDoAnuncio'));
// Só o cliente de verdade com `cliente_escrita.aprovacao` abre o painel `aprovacao` (`painelDaChave`).
const AprovarAnuncio = lazy(() => import('@/Components/Trilha/Cliente/AprovarAnuncio'));

/** O que a gaveta mostra enquanto o JS do cadastro chega (a 1ª vez que alguém abre um). */
function AbrindoOCadastro({ produto }) {
    return (
        <MioloDaGaveta sobrelinha={produto ? 'Produto da lista' : 'Produto novo'} titulo={produto ? produto.nome || 'Produto sem nome' : 'Produto novo'} ocupado>
            <p className="m-0 text-[13px] text-trilha-muted" data-carregando-cadastro="">
                Abrindo o cadastro…
            </p>
        </MioloDaGaveta>
    );
}

/** O que a gaveta da planilha mostra enquanto o JS dela chega. */
function AbrindoAPlanilha() {
    return (
        <MioloDaGaveta sobrelinha="Planilha por categoria" titulo="Cadastrar pela planilha" ocupado>
            <p className="m-0 text-[13px] text-trilha-muted" data-carregando-planilha="">
                Abrindo a planilha…
            </p>
        </MioloDaGaveta>
    );
}

/** O que a gaveta da importação mostra enquanto o JS dela chega. */
function AbrindoAImportacao() {
    return (
        <MioloDaGaveta sobrelinha="Anúncios da loja" titulo="Importar anúncios" ocupado>
            <p className="m-0 text-[13px] text-trilha-muted" data-carregando-importacao="">
                Abrindo os anúncios…
            </p>
        </MioloDaGaveta>
    );
}

/** O que a gaveta da prévia mostra enquanto o JS dela chega. */
function AbrindoAPrevia({ oferta }) {
    return (
        <MioloDaGaveta sobrelinha={`${oferta?.tipo ?? 'Oferta'} · Prévia do anúncio`} titulo={oferta?.nome || 'Oferta sem nome'} ocupado>
            <p className="m-0 text-[13px] text-trilha-muted" data-abrindo-previa="">
                Abrindo a prévia…
            </p>
        </MioloDaGaveta>
    );
}

/** A busca da URL de agora ("" fora do navegador). */
const buscaDaUrl = () => (typeof window === 'undefined' ? '' : window.location?.search ?? '');

/**
 * Grava o estado da tela na URL (a forma do Laravel, ver `buscaComEstado`). O
 * `state` continua sendo o do Inertia (ele guarda a página ali), só com a URL nova.
 */
function gravarNaUrl(estado) {
    try {
        const busca = buscaComEstado(window.location.search, estado);
        if (busca === window.location.search) return;
        const url = `${window.location.pathname}${busca}${window.location.hash}`;
        window.history.replaceState(estadoComUrl(window.history.state, url), '', url);
    } catch {
        // Sem history (modo restrito do navegador): a tela só não sobrevive ao F5.
    }
}

/**
 * A página: guarda só o "Ver como" (é do navegador, não vai para a URL) e desenha a tela.
 *
 * @param {{
 *   visao: 'equipe'|'cliente',
 *   sessao_equipe: boolean,
 *   empresa: {id: number, nome: string, logo_url: ?string},
 *   voltar: {url: string, rotulo: string},
 *   links: {portal_abrir: ?string, planejamento: ?string, produtos: ?string, publicador: ?string},
 *   trabalho: boolean,
 *   pode_editar: boolean,
 *   escrita: ?{ondas: string, planejamento: string, produtos: string, categorias?: string,
 *     planilha?: {modelo: string, previa: string, gravar: string},
 *     mercado_livre?: {anuncios: string, importar: string, estado: string, limpar: string},
 *     revisao?: {previa: string}},
 *   cliente_escrita?: ?{fotos?: string, aprovacao?: {previa: string, aprovar: string, ajuste: string}},
 *   produtos: Array<object>,
 *   ofertas: Array<object>,
 *   ondas: Array<object>,
 *   gerado_em: string,
 * }} props
 */
export default function Empresa(props) {
    const [verComo, setVerComo] = useState('equipe');

    return <TelaDaTrilha {...props} verComo={verComo} onVerComo={setVerComo} />;
}

/**
 * A tela da trilha com o "Ver como" vindo de fora — exportada para o teste desenhar o "Ver como: Cliente"
 * (com a `escrita` de verdade nas props) sem precisar clicar.
 */
export function TelaDaTrilha({
    verComo = 'equipe',
    onVerComo = () => {},
    visao = 'equipe',
    sessao_equipe = false,
    empresa = null,
    voltar = null,
    links = null,
    trabalho = false,
    pode_editar = false,
    escrita = null,
    cliente_escrita = null,
    produtos = [],
    ofertas = [],
    ondas = [],
    gerado_em = null,
}) {
    const portaDaEquipe = visao !== 'cliente';
    const simulandoCliente = portaDaEquipe && verComo === 'cliente';
    const visaoEfetiva = portaDaEquipe ? verComo : 'cliente';
    const comTrabalho = !!trabalho;
    // Escreve só a equipe com escrita, e fora do "Ver como: Cliente" (lá a tela é a do cliente, que só acompanha).
    const podeEditar = podeEscrever({ visao, trabalho, pode_editar, escrita }, verComo);
    // A prévia do anúncio (4a): quem escreve E recebeu `escrita.revisao.previa`.
    const comPrevia = podeVerPrevia({ visao, trabalho, pode_editar, escrita }, verComo);
    // As fotos que o cliente envia (4f): só a porta do cliente de verdade, com o endereço do servidor.
    const baseDasFotos = enderecoDasFotosDoCliente({ visao, trabalho, cliente_escrita });
    // A aprovação do anúncio pelo cliente (4e): a mesma regra — só a porta do cliente de verdade, com os três endereços.
    const enderecosDaAprovacao = useMemo(() => enderecosDaAprovacaoDoCliente({ visao, trabalho, cliente_escrita }), [visao, trabalho, cliente_escrita]);
    const comAprovacao = !!enderecosDaAprovacao;

    // Com a trilha de trabalho a trilha substitui o Portal (F5): nenhum atalho para a ficha de lá.
    const dadosDaPorta = useMemo(
        () => ({ produtos: produtosDaTrilha(produtos, comTrabalho), ofertas: ofertas ?? [], ondas: ondas ?? [] }),
        [produtos, ofertas, ondas, comTrabalho],
    );
    // Na simulação, o mesmo corte que o servidor faz na porta do cliente.
    const dados = useMemo(() => (simulandoCliente ? paraVisaoCliente(dadosDaPorta) : dadosDaPorta), [simulandoCliente, dadosDaPorta]);
    // Com a trilha de trabalho, nenhum link para o Portal (F5); no "Ver como: Cliente", também sem os da equipe.
    const linksDaVisao = useMemo(
        () => linksDaTrilha(simulandoCliente ? linksParaVisaoCliente(links) : links, comTrabalho),
        [simulandoCliente, links, comTrabalho],
    );
    const contagem = useMemo(() => contagemPorEtapa(dados), [dados]);

    // O começo vem da URL (lido uma vez, na montagem). Valor estranho, item que não existe mais
    // ou painel de escrita para quem não escreve é ignorado em `lerEstadoDaUrl`.
    const [inicial] = useState(() => lerEstadoDaUrl(buscaDaUrl(), dadosDaPorta, { podeEditar, comPrevia, comAprovacao }));
    const [etapa, setEtapa] = useState(inicial.etapa);
    const [vista, setVista] = useState(inicial.vista);
    const [aberto, setAberto] = useState(inicial.abrir);

    // O aviso depois de uma escrita; a oferta que pediu a "+ Nova onda"; a onda nova já escolhida ao voltar.
    const [aviso, setAviso] = useState(null);
    const [voltaDaOnda, setVoltaDaOnda] = useState(null);
    const [preEscolha, setPreEscolha] = useState(null);

    // Quem abriu o painel lateral, para o foco voltar para ele ao fechar.
    const origem = useRef(null);
    const abertoAgora = useRef(aberto);
    abertoAgora.current = aberto;

    // O número da abertura do painel: sobe a cada abrir, fechar ou trocar por dentro. Uma escrita guarda o
    // número de quando saiu e, na resposta (que pode chegar depois de Esc, "Fechar" ou de outro painel), só
    // fecha ou volta para a oferta se ele ainda for o de agora.
    const abertura = useRef(0);
    const marcaDaAbertura = useCallback(() => abertura.current, []);
    const trocarAberto = useCallback((chave) => {
        abertura.current += 1;
        setAberto(chave);
    }, []);

    // O estado que a URL já mostra. Na montagem é o lido dela: nada a gravar — e nem
    // convém, porque logo ao montar o Inertia registra a página no histórico com a URL
    // pedida, e uma gravação nossa ali (ex.: tirando um `?abrir=` de item apagado)
    // disputaria com a dele. O parâmetro velho sai na primeira mudança de estado.
    const estadoNaUrl = useRef(inicial);
    const estadoAgora = useRef(inicial);
    estadoAgora.current = { etapa, vista, abrir: aberto };

    useEffect(() => {
        const naUrl = estadoNaUrl.current;
        if (naUrl.etapa === etapa && naUrl.vista === vista && naUrl.abrir === aberto) return;
        estadoNaUrl.current = { etapa, vista, abrir: aberto };
        gravarNaUrl({ etapa, vista, abrir: aberto });
    }, [etapa, vista, aberto]);

    // Recarrega os dados da trilha depois de uma escrita e, no fim, regrava a URL de agora: a resposta da
    // recarga traz a URL de quando ela foi pedida, e a pessoa pode ter trocado de painel no meio. Se ela saiu
    // da trilha enquanto isso (o "Empresas"), a URL já é de outra página: não mexe.
    const montada = useRef(true);
    useEffect(() => {
        montada.current = true;

        return () => {
            montada.current = false;
        };
    }, []);
    const recarregarTrilha = useCallback(async () => {
        await recarregar();
        if (montada.current) gravarNaUrl(estadoAgora.current);
    }, []);

    const avisar = useCallback((texto, tipo = 'sucesso', extras = []) => {
        setAviso(texto ? { id: `${Date.now()}-${Math.random()}`, texto, tipo, extras } : null);
    }, []);
    const fecharAviso = useCallback(() => setAviso(null), []);

    // Clicar de novo na etapa escolhida volta para "Ver tudo" (null).
    const escolherEtapa = useCallback((chave) => setEtapa((atual) => (chave && atual === chave ? null : chave)), []);

    const abrirItem = useCallback((chave, elemento = null) => {
        // Trocar de item com o painel aberto (da oferta para o produto, p. ex.) mantém a
        // origem do primeiro clique: é para lá que o foco volta quando o painel fechar.
        if (!abertoAgora.current) origem.current = elemento ?? document.activeElement ?? null;
        setVoltaDaOnda(null);
        setPreEscolha(null);
        trocarAberto(chave);
    }, [trocarAberto]);

    const fechar = useCallback(() => {
        setVoltaDaOnda(null);
        setPreEscolha(null);
        trocarAberto(null);
    }, [trocarAberto]);

    // A guarda de saída da gaveta (3ª entrega): o painel de cadastro se registra aqui (`useGuardaDaGaveta`). Com algo
    // mexido, ele mostra a confirmação e só chama `seguir` se a pessoa descartar ou salvar.
    const guarda = useRef(null);
    const passarPelaGuarda = useCallback((seguir) => {
        const g = guarda.current;
        if (g?.mexido?.()) {
            g.confirmar(seguir);

            return;
        }
        seguir();
    }, []);
    const fecharComGuarda = useCallback(() => passarPelaGuarda(fechar), [passarPelaGuarda, fechar]);
    const abrirComGuarda = useCallback((chave, elemento = null) => passarPelaGuarda(() => abrirItem(chave, elemento)), [passarPelaGuarda, abrirItem]);
    const verComoComGuarda = useCallback((valor) => passarPelaGuarda(() => onVerComo(valor)), [passarPelaGuarda, onVerComo]);

    // "Abrir na trilha" (Mapa da loja): volta para a aba Trilha, na etapa em que o item
    // tem linha (a onda, no Planejamento), com o painel dele aberto.
    const abrirNaTrilha = useCallback(
        (chave) => {
            const destino = etapaDoItem(dados, chave);
            if (!destino) return;
            setVista('trilha');
            setEtapa(destino);
            abrirComGuarda(chave);
        },
        [dados, abrirComGuarda],
    );

    // "+ Nova onda" na caixa da oferta: abre a onda nova lembrando de onde veio.
    const novaOndaDaOferta = useCallback(() => {
        const daOferta = abertoAgora.current;
        setPreEscolha(null);
        setVoltaDaOnda(daOferta);
        trocarAberto(CHAVE_NOVA_ONDA);
    }, [trocarAberto]);

    // A onda foi criada. Espera a recarga nos dois casos (o painel fica em "Criando…"): pelo cartão, quem clica
    // de novo em "+ Nova onda" já recebe a sugestão seguinte ("Onda N+1"); pela oferta, a onda nova precisa
    // estar no seletor. Depois, só se o painel ainda é o mesmo (`marca`): fecha, ou volta para a oferta com a
    // onda escolhida — a mensagem da criação já foi no aviso.
    const ondaCriada = useCallback(
        async (onda, marca) => {
            const daOferta = voltaDaOnda;
            await recarregarTrilha();
            if (marca !== abertura.current) return;
            if (!daOferta) {
                fechar();

                return;
            }
            setVoltaDaOnda(null);
            setPreEscolha(onda?.id ? { chave: daOferta, onda } : null);
            trocarAberto(daOferta);
        },
        [voltaDaOnda, recarregarTrilha, fechar, trocarAberto],
    );

    // A onda foi apagada: fecha só depois da recarga (o cartão dela some e o foco vai para o "+ Nova onda", não
    // para o <body>), e só se o painel ainda é o mesmo.
    const ondaApagada = useCallback(
        async (marca) => {
            await recarregarTrilha();
            if (marca === abertura.current) fechar();
        },
        [recarregarTrilha, fechar],
    );

    const voltarParaAOferta = useCallback(() => {
        const daOferta = voltaDaOnda;
        setVoltaDaOnda(null);
        if (daOferta) trocarAberto(daOferta);
    }, [voltaDaOnda, trocarAberto]);

    // O produto nasceu ("Criar produto"): espera a recarga (ele precisa estar nos dados para a gaveta dele abrir)
    // e, se a gaveta ainda é a mesma, troca para o cadastro dele. O foco, ao fechar, vai para a linha do produto.
    // `recemCriado`: a gaveta dele leva o foco ao "Usar esta" da categoria sugerida quando ela chegar (§5.2) —
    // uma vez só (a gaveta avisa em `onFocoUsado`; reabrir o produto depois começa pelo nome, como sempre).
    const recemCriado = useRef(null);
    const produtoCriado = useCallback(
        async (resposta, marca) => {
            await recarregarTrilha();
            if (marca !== abertura.current) return;
            const id = resposta?.produto?.id;
            if (!id) {
                fechar();

                return;
            }
            origem.current = null;
            recemCriado.current = id;
            trocarAberto(chaveDeCadastro({ id }));
        },
        [recarregarTrilha, fechar, trocarAberto],
    );
    const focoDoRecemCriadoUsado = useCallback(() => {
        recemCriado.current = null;
    }, []);

    // "Ver no Planejamento" do mapeamento concluído.
    const verNoPlanejamento = useCallback(() => {
        setVista('trilha');
        setEtapa('plano');
        fechar();
    }, [fechar]);

    const painel = painelDaChave(dados, aberto, { podeEditar, comPrevia, comAprovacao });
    const temPainel = !!painel;

    // O que estava aberto sumiu (apagado numa recarga — por outra pessoa, inclusive —, ou o painel de escrita
    // depois do "Ver como: Cliente"): fecha, e a URL perde o `?abrir=`.
    useEffect(() => {
        if (aberto && !temPainel) fechar();
    }, [aberto, temPainel, fechar]);

    const geradoEm = fmtGeradoEm(gerado_em);
    const propsDoPainel = { dados, visao: visaoEfetiva, links: linksDaVisao, onAbrir: abrirComGuarda, trabalho: comTrabalho, podeEditar, comPrevia, comAprovacao };
    // Os endereços de escrita só vão aos painéis de quem escreve: no "Ver como: Cliente" eles nem chegam lá.
    const escritaComum = { escrita: podeEditar ? escrita : null, onAvisar: avisar, onRecarregar: recarregarTrilha };
    // A chave troca o conteúdo inteiro a cada painel (de uma oferta para outra, também): o formulário, o
    // resultado da caixa da onda e a rolagem começam do zero.
    const chaveDoConteudo = `${painel?.painel}:${aberto}`;

    let conteudoDaGaveta = null;
    if (painel?.painel === 'item') {
        conteudoDaGaveta = (
            <PainelDoItem
                key={chaveDoConteudo}
                entrada={painel}
                dados={dados}
                visao={visaoEfetiva}
                links={linksDaVisao}
                onAbrir={abrirComGuarda}
                trabalho={comTrabalho}
                podeEditar={podeEditar}
                comPrevia={comPrevia}
                comAprovacao={comAprovacao}
                planejamento={{ ...escritaComum, preEscolha, onNovaOnda: podeEditar ? novaOndaDaOferta : null }}
                fotosDoCliente={baseDasFotos ? { base: baseDasFotos, onRecarregar: recarregarTrilha, onAvisar: avisar } : null}
            />
        );
    } else if (painel?.painel === 'previa') {
        // Só chega aqui com `comPrevia` (`painelDaChave`): para os outros, o `v:` não abre nada.
        conteudoDaGaveta = (
            <Suspense key={chaveDoConteudo} fallback={<AbrindoAPrevia oferta={painel.oferta} />}>
                <PreviaDoAnuncio oferta={painel.oferta} trabalho={comTrabalho} onAbrir={abrirComGuarda} {...escritaComum} />
            </Suspense>
        );
    } else if (painel?.painel === 'aprovacao') {
        // Só chega aqui o cliente de verdade com `cliente_escrita.aprovacao` (`painelDaChave`): a gaveta dele (4e).
        conteudoDaGaveta = (
            <Suspense key={chaveDoConteudo} fallback={<AbrindoAPrevia oferta={painel.oferta} />}>
                <AprovarAnuncio oferta={painel.oferta} enderecos={enderecosDaAprovacao} onAvisar={avisar} onRecarregar={recarregarTrilha} />
            </Suspense>
        );
    } else if (painel?.painel === 'onda') {
        conteudoDaGaveta = (
            <PainelDaOnda
                key={chaveDoConteudo}
                onda={painel.onda}
                dados={dados}
                visao={visaoEfetiva}
                podeEditar={podeEditar}
                retorno={painel.onda ? null : voltaDaOnda}
                onAbrir={abrirComGuarda}
                onFechar={fechar}
                onCriada={ondaCriada}
                onApagada={ondaApagada}
                onVoltar={voltarParaAOferta}
                marcaDaAbertura={marcaDaAbertura}
                {...escritaComum}
            />
        );
    } else if (painel?.painel === 'mapear') {
        conteudoDaGaveta = (
            <PainelDeMapear
                key={chaveDoConteudo}
                produto={painel.produto}
                dados={dados}
                onAbrir={abrirComGuarda}
                onVerPlanejamento={verNoPlanejamento}
                {...escritaComum}
            />
        );
    } else if (painel?.painel === 'planilha') {
        // Só chega aqui com `podeEditar` (`painelDaChave`): para os outros, o `e:planilha` não abre nada.
        conteudoDaGaveta = (
            <Suspense key={chaveDoConteudo} fallback={<AbrindoAPlanilha />}>
                <PlanilhaPorCategoria escrita={escritaComum.escrita} onAbrir={abrirComGuarda} onAvisar={avisar} onRecarregar={recarregarTrilha} />
            </Suspense>
        );
    } else if (painel?.painel === 'ml') {
        // Só chega aqui com `podeEditar` (`painelDaChave`): para os outros, o `e:ml` não abre nada. `dados`: o [Abrir]
        // de cada produto importado só aparece quando ele já está na Lista (senão o `e:{id}` não acharia a gaveta).
        conteudoDaGaveta = (
            <Suspense key={chaveDoConteudo} fallback={<AbrindoAImportacao />}>
                <ImportarDoMercadoLivre escrita={escritaComum.escrita} dados={dados} onAbrir={abrirComGuarda} onAvisar={avisar} onRecarregar={recarregarTrilha} />
            </Suspense>
        );
    } else if (painel?.painel === 'cadastro') {
        // Só chega aqui com `podeEditar` (`painelDaChave`): o `e:` de quem não escreve já virou o painel `p:`.
        conteudoDaGaveta = (
            <Suspense key={chaveDoConteudo} fallback={<AbrindoOCadastro produto={painel.produto} />}>
                {painel.produto ? (
                    <PainelDoCadastro
                        produto={painel.produto}
                        dados={dados}
                        links={linksDaVisao}
                        trabalho={comTrabalho}
                        onAbrir={abrirComGuarda}
                        recemCriado={recemCriado.current === painel.produto.id}
                        onFocoUsado={focoDoRecemCriadoUsado}
                        {...escritaComum}
                    />
                ) : (
                    <NovoProduto escrita={escritaComum.escrita} onCriado={produtoCriado} marcaDaAbertura={marcaDaAbertura} onAvisar={avisar} />
                )}
            </Suspense>
        );
    }

    return (
        <TrilhaLayout titulo={empresa?.nome ? `Trilha de produtos · ${empresa.nome}` : 'Trilha de produtos'} empresa={empresa} voltar={voltar}>
            <CabecalhoDaTrilha
                empresa={empresa}
                portaDaEquipe={portaDaEquipe}
                verComo={verComo}
                onVerComo={verComoComGuarda}
                sessaoEquipe={!!sessao_equipe}
                trabalho={comTrabalho}
            />

            <AbasDaVista vista={vista} onVista={setVista} idDoPainel={ID_DA_VISTA} />

            {/* `tabIndex={-1}`: sem linha do item na vista, o foco volta para cá ao fechar o painel (T3). */}
            <div role="tabpanel" id={ID_DA_VISTA} aria-labelledby={`trilha-aba-${vista}`} tabIndex={-1} className="outline-none">
                {vista === 'mapa' ? (
                    <MapaDaLoja dados={dados} visao={visaoEfetiva} onAbrir={abrirNaTrilha} trabalho={comTrabalho} />
                ) : (
                    <>
                        <BarraDeEtapas contagem={contagem} etapa={etapa} onEscolher={escolherEtapa} trabalho={comTrabalho} />
                        <ComQuemEsta dados={dados} visao={visaoEfetiva} etapa={etapa} onEscolher={escolherEtapa} trabalho={comTrabalho} />
                        <div className="grid grid-cols-[minmax(0,1fr)] gap-4">
                            {etapa === null && <PainelGeral {...propsDoPainel} />}
                            {etapa === 'lista' && (
                                <PainelDaLista {...propsDoPainel} comPlanilha={podeEditar && !!escrita?.planilha} comImportacao={podeEditar && !!escrita?.mercado_livre} />
                            )}
                            {etapa !== null && etapa !== 'lista' && <PainelDaEtapa chave={etapa} {...propsDoPainel} />}
                        </div>
                    </>
                )}
            </div>

            <footer className="mt-[18px] flex flex-wrap justify-between gap-2.5 text-[12.5px] text-trilha-muted">
                {/* Para quem escreve, a trilha deixou de ser só leitura; no "Ver como: Cliente", o rodapé é o do cliente. */}
                <span>{podeEditar ? 'Os números são os do sistema agora.' : 'Primeira versão · só leitura · os números são os do sistema agora.'}</span>
                {geradoEm && <span data-gerado-em={gerado_em}>Atualizado em {geradoEm}</span>}
            </footer>

            {conteudoDaGaveta && (
                <Gaveta chave={aberto} origem={origem} onFechar={fecharComGuarda} guarda={guarda}>
                    {conteudoDaGaveta}
                </Gaveta>
            )}

            <Aviso aviso={aviso} onFechar={fecharAviso} comPainel={!!conteudoDaGaveta} />
        </TrilhaLayout>
    );
}
