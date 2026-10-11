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
        // Espera da IA depois do último save do produto; um save novo dentro dela adia (debounce). Decisão do
        // usuário (10/10/2026): 2 minutos — "se não mexer lá novamente, espera dois minutos e já pode ir gerando
        // tudo" (era 10; 10 perdia eficiência).
        'atraso_min' => (int) env('PUBLICADOR_PREPARO_IA_ATRASO_MIN', 2),
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
    // (id) com a chave `mlb.criativos_ia`. A aprovação das imagens continua sendo de gente.
    'criativos_auto' => [
        'ativo' => (bool) env('PUBLICADOR_CRIATIVOS_AUTO_ATIVO', false),
        // Kits automáticos por empresa por dia (cada kit = `slots` imagens).
        'limite_diario_por_empresa' => (int) env('PUBLICADOR_CRIATIVOS_AUTO_LIMITE_DIARIO', 10),
        // Os tipos de imagem do kit automático, na ordem (os do catálogo do Creative Engine).
        'slots' => ['lifestyle', 'hero'],
        // false = só a galeria geral ou a 1ª cor; true = cada grupo de fotos das cores (custo × cores).
        'todas_as_cores' => false,
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

];
