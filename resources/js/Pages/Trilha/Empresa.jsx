import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import TrilhaLayout from '@/Layouts/TrilhaLayout';
import MapaDaLoja from '@/Components/Trilha/MapaDaLoja';
import CabecalhoDaTrilha, { AbasDaVista } from '@/Components/Trilha/CabecalhoDaTrilha';
import BarraDeEtapas from '@/Components/Trilha/BarraDeEtapas';
import ComQuemEsta from '@/Components/Trilha/ComQuemEsta';
import PainelDaLista from '@/Components/Trilha/PainelDaLista';
import { PainelDaEtapa, PainelGeral } from '@/Components/Trilha/PainelDaEtapa';
import PainelLateral from '@/Components/Trilha/PainelLateral';
import { acharItem, contagemPorEtapa } from '@/lib/trilha';
import { buscaComEstado, estadoComUrl, etapaDoItem, lerEstadoDaUrl, linksParaVisaoCliente, paraVisaoCliente } from '@/Components/Trilha/estadoDaTela';
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

const ID_DA_VISTA = 'trilha-vista';

/** A busca da URL de agora ("" fora do navegador). */
const buscaDaUrl = () => (typeof window === 'undefined' ? '' : window.location?.search ?? '');

/**
 * @param {{
 *   visao: 'equipe'|'cliente',
 *   sessao_equipe: boolean,
 *   empresa: {id: number, nome: string, logo_url: ?string},
 *   voltar: {url: string, rotulo: string},
 *   links: {portal_abrir: ?string, planejamento: ?string, produtos: ?string, publicador: ?string},
 *   produtos: Array<object>,
 *   ofertas: Array<object>,
 *   ondas: Array<object>,
 *   gerado_em: string,
 * }} props
 */
export default function Empresa({
    visao = 'equipe',
    sessao_equipe = false,
    empresa = null,
    voltar = null,
    links = null,
    produtos = [],
    ofertas = [],
    ondas = [],
    gerado_em = null,
}) {
    const portaDaEquipe = visao !== 'cliente';
    const [verComo, setVerComo] = useState('equipe');
    const simulandoCliente = portaDaEquipe && verComo === 'cliente';
    const visaoEfetiva = portaDaEquipe ? verComo : 'cliente';

    const dadosDaPorta = useMemo(
        () => ({ produtos: produtos ?? [], ofertas: ofertas ?? [], ondas: ondas ?? [] }),
        [produtos, ofertas, ondas],
    );
    // Na simulação, o mesmo corte que o servidor faz na porta do cliente.
    const dados = useMemo(() => (simulandoCliente ? paraVisaoCliente(dadosDaPorta) : dadosDaPorta), [simulandoCliente, dadosDaPorta]);
    const linksDaVisao = useMemo(() => (simulandoCliente ? linksParaVisaoCliente(links) : links ?? {}), [simulandoCliente, links]);
    const contagem = useMemo(() => contagemPorEtapa(dados), [dados]);

    // O começo vem da URL (lido uma vez, na montagem). Valor estranho ou item que não
    // existe mais é ignorado em `lerEstadoDaUrl`.
    const [inicial] = useState(() => lerEstadoDaUrl(buscaDaUrl(), dadosDaPorta));
    const [etapa, setEtapa] = useState(inicial.etapa);
    const [vista, setVista] = useState(inicial.vista);
    const [aberto, setAberto] = useState(inicial.abrir);

    // Quem abriu o painel lateral, para o foco voltar para ele ao fechar.
    const origem = useRef(null);
    const abertoAgora = useRef(aberto);
    abertoAgora.current = aberto;

    // O estado que a URL já mostra. Na montagem é o lido dela: nada a gravar — e nem
    // convém, porque logo ao montar o Inertia registra a página no histórico com a URL
    // pedida, e uma gravação nossa ali (ex.: tirando um `?abrir=` de item apagado)
    // disputaria com a dele. O parâmetro velho sai na primeira mudança de estado.
    const estadoNaUrl = useRef(inicial);

    useEffect(() => {
        const naUrl = estadoNaUrl.current;
        if (naUrl.etapa === etapa && naUrl.vista === vista && naUrl.abrir === aberto) return;
        estadoNaUrl.current = { etapa, vista, abrir: aberto };
        try {
            const url = `${window.location.pathname}${buscaComEstado(window.location.search, { etapa, vista, abrir: aberto })}${window.location.hash}`;
            // O `state` continua sendo o do Inertia (ele guarda a página ali), só com a URL nova.
            window.history.replaceState(estadoComUrl(window.history.state, url), '', url);
        } catch {
            // Sem history (modo restrito do navegador): a tela só não sobrevive ao F5.
        }
    }, [etapa, vista, aberto]);

    // Clicar de novo na etapa escolhida volta para "Ver tudo" (null).
    const escolherEtapa = useCallback((chave) => setEtapa((atual) => (chave && atual === chave ? null : chave)), []);

    const abrirItem = useCallback((chave, elemento = null) => {
        // Trocar de item com o painel aberto (da oferta para o produto, p. ex.) mantém a
        // origem do primeiro clique: é para lá que o foco volta quando o painel fechar.
        if (!abertoAgora.current) origem.current = elemento ?? document.activeElement ?? null;
        setAberto(chave);
    }, []);

    const fechar = useCallback(() => setAberto(null), []);

    // "Abrir na trilha" (Mapa da loja): volta para a aba Trilha, na etapa em que o item
    // tem linha, com o painel dele aberto.
    const abrirNaTrilha = useCallback(
        (chave) => {
            const destino = etapaDoItem(dados, chave);
            if (!destino) return;
            setVista('trilha');
            setEtapa(destino);
            abrirItem(chave);
        },
        [dados, abrirItem],
    );

    const entradaAberta = aberto ? acharItem(dados, aberto) : null;
    const geradoEm = fmtGeradoEm(gerado_em);
    const propsDoPainel = { dados, visao: visaoEfetiva, links: linksDaVisao, onAbrir: abrirItem };

    return (
        <TrilhaLayout titulo={empresa?.nome ? `Trilha de produtos · ${empresa.nome}` : 'Trilha de produtos'} empresa={empresa} voltar={voltar}>
            <CabecalhoDaTrilha
                empresa={empresa}
                portaDaEquipe={portaDaEquipe}
                verComo={verComo}
                onVerComo={setVerComo}
                sessaoEquipe={!!sessao_equipe}
            />

            <AbasDaVista vista={vista} onVista={setVista} idDoPainel={ID_DA_VISTA} />

            <div role="tabpanel" id={ID_DA_VISTA} aria-labelledby={`trilha-aba-${vista}`}>
                {vista === 'mapa' ? (
                    <MapaDaLoja dados={dados} visao={visaoEfetiva} onAbrir={abrirNaTrilha} />
                ) : (
                    <>
                        <BarraDeEtapas contagem={contagem} etapa={etapa} onEscolher={escolherEtapa} />
                        <ComQuemEsta dados={dados} visao={visaoEfetiva} etapa={etapa} onEscolher={escolherEtapa} />
                        <div className="grid grid-cols-[minmax(0,1fr)] gap-4">
                            {etapa === null && <PainelGeral {...propsDoPainel} />}
                            {etapa === 'lista' && <PainelDaLista {...propsDoPainel} />}
                            {etapa !== null && etapa !== 'lista' && <PainelDaEtapa chave={etapa} {...propsDoPainel} />}
                        </div>
                    </>
                )}
            </div>

            <footer className="mt-[18px] flex flex-wrap justify-between gap-2.5 text-[12.5px] text-trilha-muted">
                <span>Primeira versão · só leitura · os números são os do sistema agora.</span>
                {geradoEm && <span data-gerado-em={gerado_em}>Atualizado em {geradoEm}</span>}
            </footer>

            {entradaAberta && (
                <PainelLateral
                    entrada={entradaAberta}
                    dados={dados}
                    visao={visaoEfetiva}
                    links={linksDaVisao}
                    origem={origem}
                    onAbrir={abrirItem}
                    onFechar={fechar}
                />
            )}
        </TrilhaLayout>
    );
}
