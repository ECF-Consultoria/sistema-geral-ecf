<?php

/*
|--------------------------------------------------------------------------
| Publicador do Anunciar (portal)
|--------------------------------------------------------------------------
|
| Especificação: `.planning/publicador-ml-spec/`. Toda hipótese [HIP] da spec
| vira opção aqui em vez de regra fixa no código (`12`): quando a API real
| contradisser, muda-se o valor, não o código.
|
*/

return [

    // RN-22: o schema da categoria vale 24h; antes de publicar, vencido é rebuscado.
    'schema_ttl_horas' => (int) env('PUBLICADOR_SCHEMA_TTL_HORAS', 24),

    // `09` §6: renovar o token quando faltar menos que isto (o refresh do ML é de uso único).
    'renovar_token_minutos' => 10,

    // `09` §2: novas tentativas em 429 (espera 1, 2, 4, 8, 16 s ou o Retry-After) e em 5xx de leitura.
    'tentativas_429' => 5,
    'tentativas_5xx' => 3,
    'espera_maxima_segundos' => 16,
    'timeout_segundos' => 30,

    // H-17: o ML não documenta limite de eixos.
    'max_eixos' => 3,

    // N-14: o validate limitou o family_name ao max_title_length da categoria; o teto de 120
    // do [ML·S8] não apareceu. Nulo = só o da categoria.
    'limite_family_name' => null,

    // `08` V-TIT-02 (aviso). Telefone, e-mail e link são detectados à parte.
    'termos_proibidos_titulo' => ['frete gratis', 'parcelado', 'sem juros', 'novo', 'usado', 'promocao', 'oferta'],

    // `08` V-ATT-11 (aviso), por domínio: medida "maior" não pode ficar abaixo da "menor".
    'plausibilidade' => [
        'MLB-OFFICE_CHAIRS' => [['maior' => 'MAX_CHAIR_HEIGHT', 'menor' => 'BACKREST_HEIGHT']],
    ],

    // `08` §1: no UP cada variante é um validate; avisar a demora acima disto.
    'avisar_acima_de_itens' => 20,
    'concorrencia_ml' => 2,

    // D9: a publicação roda em fatias que se redespacham (o Job não pode passar do
    // retry_after de 90 s da fila, senão é reentregue). A trava cobre a fatia inteira
    // mais um POST lento; UNKNOWN só é reenviado depois de reconciliar E de passado
    // este tempo desde o envio (a busca por SKU do ML demora a enxergar o item novo).
    'fatia_segundos' => 45,
    'trava_segundos' => 600,
    'reconciliar_apos_segundos' => 180,

    // D21: contas liberadas para publicar, uma a uma pelo usuário depois do teste real;
    // vazio = ninguém. Listas separadas por âncora (Company 5 não libera MlbEmpresa 5).
    'contas_liberadas' => [
        'companies' => array_values(array_filter(array_map('intval', explode(',', (string) env('PUBLICADOR_CONTAS_LIBERADAS_COMPANIES', env('PUBLICADOR_EMPRESAS_PILOTO', '459')))))),
        'mlb_empresas' => array_values(array_filter(array_map('intval', explode(',', (string) env('PUBLICADOR_CONTAS_LIBERADAS_MLB_EMPRESAS', ''))))),
    ],

    // D11 [HIP]: conta multidepósito cria pelo caminho próprio, com o estoque de cada
    // depósito. Formato lido na documentação por busca (acesso direto dá 403) e nunca
    // testado de verdade (a conta de teste não tem depósitos). Se o ML recusar com 4xx,
    // o Publicador cria pelo /items comum e avisa (plano B).
    'multideposito' => [
        'caminho' => '/items/multiwarehouse',
        'campo' => 'stock_locations',
    ],

    // ═══ Alavancas (Fase 166) ═══
    'alavancas' => [

        // D-03 (166): separada da trava de publicação de propósito — sem fallback para
        // as variáveis daquela trava (nem a antiga de piloto); vazio = ninguém;
        // Company 5 ≠ MlbEmpresa 5. Liberar uma não libera a outra.
        'contas_liberadas' => [
            'companies' => array_values(array_filter(array_map('intval', explode(',', (string) env('PUBLICADOR_ALAVANCAS_LIBERADAS_COMPANIES', '459'))))),
            'mlb_empresas' => array_values(array_filter(array_map('intval', explode(',', (string) env('PUBLICADOR_ALAVANCAS_LIBERADAS_MLB_EMPRESAS', ''))))),
        ],

        // D-12 (166): alertas simples; null/false desliga o alerta; nenhum alerta bloqueia ação.
        // `4_light_green` é suposição A4 do RESEARCH (reputação que ainda permite desconto/cupom).
        'alertas' => [
            'convite_vence_em_dias' => 3,
            'recebido_queda_percentual' => 10,
            'estoque_minimo' => true,
            'reputacao_ok' => ['5_green', '4_light_green'],
        ],

        // Segundos de cache das leituras; escrita bem-sucedida invalida a conta.
        'cache' => [
            'conta' => 300,
            'panorama' => 120,
            'itens_promocao' => 60,
            'produtos' => 300,
            'tarifa' => 3600,
            'frete' => 3600,
            'anunciante' => 86400,
        ],

        // itens_por_lote = Questão 4 do RESEARCH, decidido pelo orquestrador: 50 por confirmação.
        // paginas_preload: páginas de itens de uma promoção lidas de uma vez na prévia/confirmação
        // de vários produtos. itens_analise_previa: a prévia calcula "quanto recebe" só dos 20 primeiros.
        'limites' => [
            'itens_por_lote' => 50,
            'itens_por_analise' => 10,
            'chamadas_analise_por_minuto' => 120,
            'offset_maximo_produtos' => 1000,
            'paginas_convites' => 4,
            'cupons_detalhados' => 20,
            'tentativas_423' => 3,
            'espera_423_segundos' => 2,
            'janela_publicidade_dias' => 90,
            'paginas_preload' => 20,
            'itens_analise_previa' => 20,
        ],

        // D-04 (166): a assinatura da prévia de uma escrita vale este tempo.
        'previa_validade_minutos' => 10,
    ],

    // ═══ IA prepara o rascunho ao salvar no Portal (09/10/2026) ═══
    // Cada save do produto no Portal leva SÓ aquele produto ao Publicador em segundos (10/10/2026) e
    // agenda, com espera, a geração de título, Modelo e descrição pela IA — gravados no rascunho sem
    // tela aberta, nunca por cima do que a equipe editou (learnings publicador-ml §16).
    'preparo_ia' => [
        // Chave de segurança: false desliga TUDO (nem sincroniza nem gera).
        'ativo' => (bool) env('PUBLICADOR_PREPARO_IA_ATIVO', true),
        // Fila do preparo pela IA (10/10/2026, learnings publicador-ml §22): a cadeia título → Modelo → descrição
        // leva 5–7 min por produto e, na `high`, segurava a publicação, o Sincronizar ao salvar e o código de
        // acesso do Portal por até ~30 min numa importação grande. Fila própria, atendida pelo programa
        // `ecf-worker-ia` do supervisor (3 processos). Válvula de emergência: `high` (e `config:cache`) devolve a
        // IA ao lugar antigo sem deploy.
        'fila' => (string) env('PUBLICADOR_PREPARO_IA_FILA', 'publicador-ia'),
        // O produto chega ao Publicador logo (decisão do usuário, 10/10/2026: "ou vai instantâneo ou na hora
        // de sincronizar"): estes segundos depois do save, um Sincronizar SÓ dele, sem IA. Saves seguidos
        // dentro da espera viram uma sincronização só.
        'sincronizar_atraso_s' => (int) env('PUBLICADOR_SINCRONIZAR_ATRASO_S', 15),
        // Espera da IA (título, Modelo e, no fim, as imagens) depois do último save; um save novo dentro dela adia
        // (debounce). Decisão do usuário (20/10/2026): 20 SEGUNDOS, "que salvar o produto com imagem, já gerar" (era
        // 2 min; antes, 10). Foto subida mais de 20 s depois da 1ª não entra no kit já gerado (um kit por grupo).
        'atraso_s' => (int) env('PUBLICADOR_PREPARO_IA_ATRASO_S', 20),
        // Preparações com IA por empresa por dia (cada uma = título + Modelo + descrição de UM produto).
        // Passou disso, o produto só é sincronizado e o log diz por quê.
        'limite_diario_por_empresa' => (int) env('PUBLICADOR_PREPARO_IA_LIMITE_DIARIO', 60),
        // O editor do produto conta como "em uso" por este tempo depois do último sinal da tela.
        'editor_em_uso_min' => 3,
        // Editor em uso (ou "Anunciar por IA" rodando): a escrita espera este tempo e tenta de novo,
        // no máximo `max_adiamentos` vezes; depois desiste e o próximo save no Portal recomeça.
        'adiar_min' => 5,
        'max_adiamentos' => 24,
    ],

    // ═══ Tarefas pós-publicação (09/10/2026) ═══
    // Publicou pelo Publicador → nasce a tarefa das alavancas para outro colaborador (learnings
    // publicador-ml §17). O responsável padrão NÃO mora aqui: é `configuracoes.publicador_alavancas_responsavel`
    // (o admin escolhe na própria fila, sem deploy).
    'tarefas' => [
        // "O ideal é D+0, no máximo D+1" (reunião de 09/10): o prazo é D+1 útil.
        'prazo_dias_uteis' => 1,
    ],

    // ═══ Promoção automática pós-publicação (10/10/2026) ═══
    // Cada anúncio criado ganha o desconto individual de 14 dias com o preço de promoção do Portal, e ele
    // se renova sozinho (`publicador:promocoes-renovar`, 00:05). Só nas contas das Alavancas
    // (`alavancas.contas_liberadas`); nas outras, a tarefa orienta a fazer à mão (learnings publicador-ml §19).
    // Quem assina a escrita quando quem publicou não está ativo: `configuracoes.publicador_usuario_sistema`.
    'promocao_automatica' => [
        // Espera depois de publicar até a 1ª tentativa (o anúncio costuma nascer em revisão).
        'atraso_min' => 3,
        // Anúncio ainda não ativo: espera entre as tentativas, crescente; esgotadas, a tarefa orienta.
        'esperas_min' => [5, 10, 20, 40, 60, 120, 240],
        'tentativas_max' => 8,
    ],

    // ═══ Termos que o Mercado Livre VETA em título e descrição (10/10/2026) ═══
    // O ML pausou um anúncio de teste da #459 por "criado-mudo" no título (infração LENGUAJE, política 1020).
    // A IA troca pelo termo aceito e a conferência trava se alguém digitar (V-TIT-05 / V-DES-05; TermosVetados).
    // Chave = o termo vetado, escrito sem acento (caixa, acento e hífen × espaço não importam); valor = a troca.
    // Os conhecidos moram em `TermosVetados::PADRAO`; termo novo entra aqui, depois deles.
    'termos_vetados' => [
        ...App\Support\Publicador\TermosVetados::PADRAO,
    ],

    // ═══ Publicação em lote — a fila em rodadas (10/10/2026) ═══
    // "Conferir selecionados" + "Agendar publicação" da conta; quem anda a fila é o `publicador:fila-publicacao`
    // (todo minuto, routes/console.php). A fila anda em RODADAS: alguns produtos (Clássico + Premium, todas as
    // cores) começam juntos, e a rodada seguinte só vem depois do intervalo E depois de a anterior terminar —
    // para não subir anúncio "na porrada" e arriscar restrição do Mercado Livre (learnings publicador-ml §20).
    'fila_publicacao' => [
        // Produtos que começam juntos numa rodada (decisão do usuário, 10/10: "cinco de uma vez", ajustável na tela).
        'produtos_por_rodada' => (int) env('PUBLICADOR_FILA_POR_RODADA', 5),
        // O máximo que a tela aceita por rodada (cada produto são 2 anúncios: Clássico e Premium).
        'produtos_por_rodada_max' => 10,
        // Minutos entre o INÍCIO de uma rodada e o da próxima (decisão do usuário, 10/10: "uns 20 minutos", ajustável).
        'intervalo_minutos' => (int) env('PUBLICADOR_FILA_INTERVALO_MIN', 20),
        // O menor intervalo que a tela aceita.
        'intervalo_minimo' => 2,
        // Inícios por minuto, somando TODAS as filas (contas diferentes também contam).
        'teto_inicios_por_minuto' => (int) env('PUBLICADOR_FILA_TETO_POR_MINUTO', 2),
        // Publicação ainda rodando depois disto: a fila pausa com aviso (o item espera a publicação terminar).
        'publicando_max_min' => 40,
        // Segundos entre uma conferência e a próxima no "Conferir selecionados" (fila `high`).
        'conferir_espaco_s' => 10,
        // A fila que terminou continua no painel por estes dias.
        'mostrar_concluida_dias' => 3,
    ],

    // ═══ Imagens por IA automáticas (gatilho PRONTO e DESLIGADO, 10/10/2026) ═══
    // Quando o produto chega do Portal com a ficha completa e as fotos do cliente, o Creative Engine pode gerar
    // sozinho as imagens (2 por kit ≈ US$ 0,20). Fica DESLIGADO até o dono do Creative Engine ajustar o lado
    // dele (`.planning/coordenacao/261010-criativos-automaticos.md`). Ligar exige TUDO: esta chave, a do Creative
    // Engine (`configuracoes.creative_engine_ativo`), a empresa na lista `configuracoes.publicador_criativos_auto_companies`
    // (ids separados por vírgula) e o usuário de sistema `configuracoes.publicador_criativos_auto_usuario`
    // (id) com a chave `mlb.criativos_ia`. Desde 20/10/2026 as imagens do kit automático entram SOZINHAS no anúncio e
    // passam a ser AS fotos do grupo — a do cliente é só referência para a IA recriar o produto —, e quem confere é a
    // Prévia (etapa 5); a que o juiz reprovou fica para alguém decidir.
    'criativos_auto' => [
        'ativo' => (bool) env('PUBLICADOR_CRIATIVOS_AUTO_ATIVO', false),
        // Kits automáticos por empresa por dia (cada kit = `slots` imagens).
        'limite_diario_por_empresa' => (int) env('PUBLICADOR_CRIATIVOS_AUTO_LIMITE_DIARIO', 10),
        // Tipos FIXOS do kit automático, na ordem da galeria — o 1º é a capa do Clássico e o 2º a do Premium (a rotação
        // D6/CAPA-01 manda a 1ª para o fim). Decisão do usuário de 20/10/2026, "3 imagens, 2 capas": Imagem principal +
        // Ambientação + Textura, e a textura nunca vira capa. Em MÓVEL a Ambientação vai para a frente (Quick
        // 261007-amb, `CriativosAutomaticosService::tiposDoKit`). Vazio (`PUBLICADOR_CRIATIVOS_AUTO_SLOTS=`) = o kit do
        // botão: o planejador escolhe e a quantidade é `services.creative.kit.slots`.
        'slots' => array_values(array_filter(array_map('trim', explode(',', (string) env('PUBLICADOR_CRIATIVOS_AUTO_SLOTS', 'hero,lifestyle,detail_textura'))))),
        // A 4ª imagem do kit automático (22/10/2026): uma 2ª ambientada, a capa do Premium. Vai no FIM da galeria, e o
        // Premium só troca a 1ª foto com ela — a sequência é a mesma do Clássico, e a capa do Premium nunca é fundo
        // branco (`OrdemCapaPorAlvo`). Vazio = sem 4ª imagem (o Premium fica igual ao Clássico em móvel).
        'capa_premium' => (string) env('PUBLICADOR_CRIATIVOS_AUTO_CAPA_PREMIUM', 'composicao'),
        // true (o padrão desde 20/10/2026) = cada grupo de fotos — a galeria geral e cada cor — ganha o seu kit (custo
        // × grupos): com a foto do cliente só como referência, a cor sem kit ficaria com a foto do cliente no anúncio.
        // false = só a galeria geral, ou a 1ª cor.
        'todas_as_cores' => (bool) env('PUBLICADOR_CRIATIVOS_AUTO_TODAS_AS_CORES', true),
    ],

    // Feriados que NÃO são de data fixa (os fixos nacionais estão em `DiasUteis::FIXOS`), em `Y-m-d`.
    // Carnaval é ponto facultativo nacional: entra porque a ECF não trabalha (tirar daqui se mudar).
    // Datas de outros anos: acrescentar aqui ou em PUBLICADOR_FERIADOS (separadas por vírgula).
    'feriados' => array_values(array_unique(array_filter(array_map('trim', [
        '2026-02-16', '2026-02-17', '2026-04-03', '2026-06-04', // Carnaval, Sexta-feira Santa, Corpus Christi
        '2027-02-08', '2027-02-09', '2027-03-26', '2027-05-27',
        '2028-02-28', '2028-02-29', '2028-04-14', '2028-06-15',
        ...explode(',', (string) env('PUBLICADOR_FERIADOS', '')),
    ])))),

    // Só a conferência visual local (plano 166-16) aponta para um servidor de mentira;
    // em produção o cliente IGNORA este valor e usa o host oficial (plano 166-02).
    'ml_api_base' => env('PUBLICADOR_ML_API_BASE', 'https://api.mercadolibre.com'),

    /*
    |--------------------------------------------------------------------------
    | O portão de aprovação (Fase 186) — A ÚNICA configuração nova da milestone
    |--------------------------------------------------------------------------
    |
    | `margem_minima_percentual` é o **FALLBACK** da margem mínima exigida de
    | toda oferta, em ponto percentual. O valor que MANDA é o
    | `margem_contribuicao` dos parâmetros de preço da empresa
    | (`EstruturaPrecificacaoParametros::daEmpresa()`) **quando ele é > 0**;
    | sendo 0, negativo, não numérico, ou não havendo linha da empresa, vale
    | este padrão. O resolvedor mora num lugar só:
    | `ComposicaoFinanceiraService::margemMinimaComOrigem()`.
    |
    | ⚠️ O FALLBACK É DELIBERADO — decisão do usuário de 14/10/2026. O
    | `PADROES` daquele modelo tem `margem_contribuicao => 0.0`, então usar só
    | o parâmetro DESLIGARIA o bloqueio de margem em toda empresa que nunca
    | salvou a Calculadora de Custo; e usar só este padrão IGNORARIA quem
    | configurou a própria exigência. As duas simplificações falham em
    | silêncio, e por isso existem os dois lados. ⚠️ **O zero do parâmetro NÃO
    | desliga o bloqueio** — ele cai aqui.
    |
    | ⚠️ O 12 vem do protótipo do desenho (`Aprovacao.dc.html:914`, `MIN = 12`)
    | e é o DEFAULT da tela, não uma taxa do Mercado Livre: comissão, tarifa
    | fixa e imposto NUNCA moram em config (FIN-02) — eles vêm da API do ML e
    | dos parâmetros da empresa. A frase da tela é montada com o valor
    | resolvido: *"Margem mínima: 12% · Toda oferta precisa de aprovação humana
    | antes de publicar"* (FIN-09).
    |
    */
    'aprovacao' => [
        'margem_minima_percentual' => (float) env('PUBLICADOR_APROVACAO_MARGEM_MINIMA', 12.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Conferência de frete (11/10/2026)
    |--------------------------------------------------------------------------
    |
    | O frete que a Precificação do Portal usou × o que o Mercado Livre cota ao
    | conferir × o que ele cobra do anúncio publicado (`ConferenciaDeFrete`).
    | Faixas aprovadas pelo usuário: até `tolerancia` não avisa; acima, avisa;
    | acima de `reprecificar_valor` OU de `reprecificar_percentual` do frete do
    | Mercado Livre, o aviso pede para refazer o preço. Sempre aviso, nunca trava.
    |
    | `max_cotacoes`: teto de cotações por conferência (uma por tipo de anúncio e
    | preço distinto; variações com o mesmo preço dividem a resposta).
    |
    */
    'frete_conferencia' => [
        'tolerancia' => (float) env('PUBLICADOR_FRETE_TOLERANCIA', 1.00),
        'reprecificar_valor' => (float) env('PUBLICADOR_FRETE_REPRECIFICAR_VALOR', 10.00),
        'reprecificar_percentual' => (float) env('PUBLICADOR_FRETE_REPRECIFICAR_PERCENTUAL', 10.0),
        'max_cotacoes' => (int) env('PUBLICADOR_FRETE_MAX_COTACOES', 6),
    ],

    /*
    |--------------------------------------------------------------------------
    | Acervo de imagens (v28.0)
    |--------------------------------------------------------------------------
    |
    | `retencao_geracoes_dias`: geração de IA que ninguém aprovou fica no disco da VPS
    | (`creative-geradas/`) por este prazo e então é apagada por `acervo:limpar-geracoes`
    | (decisão do usuário, 15/10/2026: 30 dias). O comando só apaga com `--aplicar`.
    |
    */
    'acervo' => [
        'retencao_geracoes_dias' => (int) env('ACERVO_RETENCAO_GERACOES_DIAS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Abas ocultas da conta (20/10/2026)
    |--------------------------------------------------------------------------
    |
    | Pedido do usuário depois da reunião de 19/10 ("menos é mais"): na conta do
    | Publicador fica só Produtos. As páginas continuam existindo e abrem pela
    | URL; a aba some do header (`CabecalhoPublicadorService` manda a lista e o
    | `AbasDoHeader` filtra). Para devolver uma aba, tire a chave daqui.
    | Produtos nunca some: é a página de entrada da conta.
    |
    */
    'abas_ocultas' => ['visao-geral', 'publicacoes', 'alavancas'],

    // 20/10/2026 — a conta abre na VITRINE de cartões (`ProdutosVitrine`) em vez da lista. `vitrine` liga para
    // TODAS as contas; `vitrine_contas` liga só para as listadas, pela chave da URL da conta, separadas por vírgula
    // (ex.: `company-459,empresa-12`) — "só na #459 primeiro", até o usuário aprovar para o time.
    'vitrine' => (bool) env('PUBLICADOR_VITRINE', false),
    'vitrine_contas' => array_values(array_filter(array_map('trim', explode(',', (string) env('PUBLICADOR_VITRINE_CONTAS', ''))))),

    /*
    |--------------------------------------------------------------------------
    | Prévia do anúncio (etapa 5 do editor, 20/10/2026)
    |--------------------------------------------------------------------------
    |
    | `parcelas_premium`: quantas parcelas SEM juros o Premium mostra, pela faixa
    | de preço (o primeiro `ate` que cobre o preço; `null` = sem teto). É a regra
    | que o Mercado Livre divulgou em 2022 (até R$ 299,99 → 9x; até R$ 1.499,99 →
    | 10x; acima → 12x) e que não deu para conferir na API: a tela a chama de
    | estimativa. Se o ML mudou, ajuste aqui. O Clássico não tem parcela sem juros.
    |
    */
    'previa' => [
        'parcelas_premium' => [
            ['ate' => 299.99, 'vezes' => 9],
            ['ate' => 1499.99, 'vezes' => 10],
            ['ate' => null, 'vezes' => 12],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Releitura do estado dos anúncios publicados (R17, 22/10/2026)
    |--------------------------------------------------------------------------
    |
    | O ML pausa ou põe em revisão DEPOIS de criar, e a lista final do editor
    | seguia dizendo "Publicado". Logo depois de publicar, o
    | `RelerEstadoDoPublicadoJob` relê duas vezes; o que foi publicado antes dele
    | (ou mudou depois) é relido pela rodada diária `publicador:reler-publicados
    | --aplicar` (`routes/console.php`). Só `GET /items/{id}` — um por anúncio —,
    | só nas contas liberadas e com o mesmo vendedor que publicou.
    |
    | `dias`: publicados nos últimos N dias. `horas`: não relê o que foi lido há
    | menos disso. `teto`: no máximo N anúncios (= N GETs) por rodada. `lote` e
    | `intervalo_s`: Jobs de até `lote` anúncios, um a cada `intervalo_s`
    | segundos, para não fazer rajada no limite do ML. `horario`: São Paulo,
    | depois da renovação de tokens das 08:00 (§32/§41 — fora da janela as
    | contas de Polos estão com o token vencido e cada leitura renovaria um).
    |
    */
    'releitura_publicados' => [
        'ativo' => (bool) env('PUBLICADOR_RELEITURA_ATIVO', true),
        'horario' => (string) env('PUBLICADOR_RELEITURA_HORARIO', '09:20'),
        'dias' => (int) env('PUBLICADOR_RELEITURA_DIAS', 30),
        // 20, não 24 (23/10/2026, P-22): a rodada de ontem leu às 09:2x, e às 09:20 de hoje isso ainda contava como
        // "recente" — cada anúncio era relido só a cada 2 dias.
        'horas' => (int) env('PUBLICADOR_RELEITURA_HORAS', 20),
        'teto' => (int) env('PUBLICADOR_RELEITURA_TETO', 300),
        'lote' => (int) env('PUBLICADOR_RELEITURA_LOTE', 20),
        'intervalo_s' => (int) env('PUBLICADOR_RELEITURA_INTERVALO_S', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Jardinagem dos publicados — a árvore do MAG (23/10/2026)
    |--------------------------------------------------------------------------
    |
    | Análise MENSAL de cada anúncio criado pelo Publicador ("fazer que nem a
    | MAG", Turma 8, aula de 27/11/2025): visitas e vendas dos últimos
    | `dias_do_ciclo` dias → um dos quatro ramos (`ReguaDaJardinagem`). A
    | rodada diária `publicador:jardinagem --aplicar` (`routes/console.php`)
    | analisa só o anúncio cujo ciclo fechou hoje — cada um, uma vez por mês.
    | Só GET no ML, só conta liberada do mesmo vendedor que publicou.
    |
    | `visitas_corte`: abaixo disto, sem venda = sem tração (nova oferta).
    | `conversao_corte`: em %, o MAG dá 2,00% como EXEMPLO. `teto`: anúncios
    | por rodada (cada um = 2 GETs). `termos_priorizados`: quantos termos novos
    | da semana a IA recebe como prioridade no título da nova oferta.
    | `horario`: São Paulo, depois da releitura das 09:20 e longe da cascata de
    | ML das 11h.
    |
    | `ativo` nasce DESLIGADO (pedido do usuário em 23/10/2026: "não quero ele
    | funcionando por enquanto, quero ver como ficou antes"). Desligado, a
    | rodada diária não roda e o `--aplicar` à mão se recusa a gravar; a tela
    | abre (só pela URL, sem item no menu) e o `--ler` continua só lendo.
    | Ligar = `PUBLICADOR_JARDINAGEM_ATIVO=true` no `.env` + `config:cache`.
    |
    */
    'jardinagem' => [
        'ativo' => (bool) env('PUBLICADOR_JARDINAGEM_ATIVO', false),
        'horario' => (string) env('PUBLICADOR_JARDINAGEM_HORARIO', '09:40'),
        'dias_do_ciclo' => (int) env('PUBLICADOR_JARDINAGEM_DIAS', 30),
        'visitas_corte' => (int) env('PUBLICADOR_JARDINAGEM_VISITAS_CORTE', 100),
        'conversao_corte' => (float) env('PUBLICADOR_JARDINAGEM_CONVERSAO_CORTE', 2.0),
        'teto' => (int) env('PUBLICADOR_JARDINAGEM_TETO', 300),
        'termos_priorizados' => (int) env('PUBLICADOR_JARDINAGEM_TERMOS', 8),
    ],

];
