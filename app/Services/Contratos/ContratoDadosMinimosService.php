<?php

namespace App\Services\Contratos;

use App\Models\Company;
use App\Support\Cnpj;
use App\Support\NomeCompleto;

/**
 * ContratoDadosMinimosService — a recusa que acontece ANTES de qualquer
 * chamada HTTP à Clicksign (Success Criteria 1 do ROADMAP, REDE-05).
 *
 * Service puro, sem I/O, sem cache, sem construtor com dependência externa —
 * chamável de qualquer contexto: o orquestrador desta Fase 127 e, a partir
 * da Fase 131, a tela do Administrativo (que exibe `faltantes()` para o
 * usuário preencher os campos que faltam).
 *
 * ⚠️ NÃO reusa `App\Services\Comercial\PendenciasComerciaisService::calcular()`
 * (Q5 da pesquisa da Fase 127, confirmado lendo o código): aquele service é
 * gated por `is_origem_hubspot` — retorna array vazio para qualquer empresa
 * cadastrada à mão — e das 7 pendências que calcula, nenhuma olha
 * `email_cliente` nem `cnpj`. Reusá-lo abriria uma brecha onde metade das
 * empresas passaria pela checagem sem nenhum bloqueio. Aqui as regras
 * abaixo (7 desde o Quick 260819-guy) rodam SEMPRE, independente da origem
 * da empresa.
 *
 * ⚠️ SUPERADO em 2026-08-19 (Quick 260819-guy, decisão explícita do usuário
 * via AskUserQuestion — ver `.planning/quick/260819-guy-ajustes-fluxo-
 * contrato-administrativo/260819-guy-PLAN.md`): o parágrafo abaixo descrevia
 * o comportamento de 2026-07/08 e **não vale mais**. `razao_social` e
 * `endereco` (em `companies`, migration 2026_08_19_100000) e
 * `data_primeira_parcela`/`dia_vencimento` (em `contratos_servico`, migration
 * 2026_08_19_100001) agora EXISTEM no banco e são **obrigatórios já** — o
 * usuário decidiu isso depois do teste ponta-a-ponta de 2026-08-19 concluir
 * que um contrato saindo com "A DEFINIR" nesses 4 campos é pior do que
 * segurar a geração até alguém preencher. Consequência aceita e conhecida:
 * enquanto os quatro não forem preenchidos, nenhuma empresa gera contrato —
 * é exatamente o que foi pedido, não um efeito colateral.
 *
 * Texto original, preservado por histórico (não apagar): "Os 3 campos que
 * saem como 'A DEFINIR' no documento (`endereco`, `dia_vencimento`,
 * `data_primeira_parcela`) NÃO existem no banco e são placeholder por
 * decisão do usuário (checkpoint do plano 126-06, documentado na
 * <tensao_de_dados> do 127-CONTEXT.md). NÃO adicionar checagem para eles
 * aqui — isso travaria toda geração de contrato."
 *
 * ⚠️ Contrato de retorno é PÚBLICO: a Fase 131 consome `faltantes()` para
 * montar a tela de pendências. Mudar as chaves (`campo`, `rotulo`, `motivo`,
 * `servico_id`) ou os valores possíveis de `motivo` (`ausente`|`formato`)
 * quebra aquela tela.
 */
class ContratoDadosMinimosService
{
    /**
     * Lista os campos que faltam para a empresa estar pronta para gerar
     * contrato. Lista vazia = pronta.
     *
     * As regras rodam SEMPRE, sem gate por `is_origem_hubspot` — o dado que
     * falta é o mesmo problema em qualquer origem de empresa.
     *
     * @return array<int, array{campo: string, rotulo: string, motivo: string, servico_id: ?int}>
     */
    public function faltantes(Company $company): array
    {
        $itens = [];

        // 1. E-mail do cliente — presença e formato.
        if (blank($company->email_cliente)) {
            $itens[] = $this->item('email_cliente', 'E-mail do cliente', 'ausente');
        } elseif (filter_var($company->email_cliente, FILTER_VALIDATE_EMAIL) === false) {
            $itens[] = $this->item('email_cliente', 'E-mail do cliente', 'formato');
        }

        // 2. CNPJ — presença, formato (14 dígitos após remover pontuação) e,
        // desde 2026-08-19 (Quick 260819-guy), dígito verificador.
        //
        // ⚠️ SUPERADO — texto original preservado por histórico: "Presença e
        // formato, NÃO dígito verificador — é literalmente o que o REDE-05
        // pede ('presença e formato'), e o projeto não tem helper de
        // validação de dígito verificador de CNPJ hoje. Não é esquecimento:
        // é escopo deliberado desta checagem." O usuário decidiu, em
        // 2026-08-19, fechar essa lacuna: `App\Support\Cnpj::valido()`
        // (helper novo deste quick) calcula o dígito verificador de verdade.
        // Dígito trocado vira `motivo: 'formato'` — reusa o valor JÁ
        // existente do contrato público de `faltantes()` (nunca inventar um
        // `motivo` novo, isso quebraria a tela do Administrativo).
        if (blank($company->cnpj)) {
            $itens[] = $this->item('cnpj', 'CNPJ', 'ausente');
        } elseif (strlen(preg_replace('/\D/', '', (string) $company->cnpj)) !== 14) {
            $itens[] = $this->item('cnpj', 'CNPJ', 'formato');
        } elseif (! Cnpj::valido((string) $company->cnpj)) {
            $itens[] = $this->item('cnpj', 'CNPJ', 'formato');
        }

        // 3. Nome de quem assina pela empresa — presença e, desde 2026-08-19
        // (Quick 260819-guy, Tarefa 7 item 4), nome COMPLETO (mínimo duas
        // palavras).
        //
        // ⚠️ SUPERADO — texto original preservado por histórico: esta regra
        // checava só presença. Palavra única (ex.: "teste") passava aqui e só
        // era recusada pela própria Clicksign, DEPOIS de já ter criado
        // envelope e documento (`400 "name não está em um formato válido"`,
        // caso real medido) — dois round-trips e ~6 minutos até o registro
        // terminar em `status = erro`. `App\Support\NomeCompleto::valido()`
        // (helper novo deste quick) recusa nome de uma palavra só ANTES de
        // qualquer chamada HTTP. Nome de uma palavra só vira
        // `motivo: 'formato'` — reusa o valor já existente do contrato
        // público (nunca inventar um `motivo` novo).
        if (blank($company->nome_contato)) {
            $itens[] = $this->item('nome_contato', 'Nome de quem assina pela empresa', 'ausente');
        } elseif (! NomeCompleto::valido((string) $company->nome_contato)) {
            $itens[] = $this->item('nome_contato', 'Nome de quem assina pela empresa', 'formato');
        }

        // 4. Razão social (Quick 260819-guy) — presença. `companies.razao_social`
        // é texto livre; não há formato a validar além de "preenchido".
        // Alimenta a variável `razao_social` do modelo `.docx` (hoje cai no
        // fallback `$company->name` em ContratoPdfService — ver Tarefa 5).
        if (blank($company->razao_social)) {
            $itens[] = $this->item('razao_social', 'Razão social', 'ausente');
        }

        // 5. Endereço (Quick 260819-guy) — presença. Alimenta a variável
        // `endereco` do modelo `.docx`.
        //
        // Quick 260821-cq0 — o endereço voltou a ser 5 campos separados. As
        // 4 partes novas (bairro/cidade/estado/cep) entram aqui como
        // OBRIGATÓRIAS, mesma disciplina de `endereco`: o contrato de Gestão
        // do jurídico usa as cinco como variáveis próprias, e campo em
        // branco num documento assinado é pior do que segurar a geração até
        // alguém preencher (mesma decisão da Quick 260819-guy, agora
        // estendida aos 4 campos novos).
        if (blank($company->endereco)) {
            $itens[] = $this->item('endereco', 'Rua e número', 'ausente');
        }
        if (blank($company->bairro)) {
            $itens[] = $this->item('bairro', 'Bairro', 'ausente');
        }
        if (blank($company->cidade)) {
            $itens[] = $this->item('cidade', 'Cidade', 'ausente');
        }
        if (blank($company->estado)) {
            $itens[] = $this->item('estado', 'Estado', 'ausente');
        }
        if (blank($company->cep)) {
            $itens[] = $this->item('cep', 'CEP', 'ausente');
        }

        // 6. Nenhum ContratoServico ativo — sem serviço não há o que
        // contratar (D-06 da Fase 127: um contrato é sempre de UM serviço).
        $contratosAtivos = $company->contratosServico->where('ativo', true);
        if ($contratosAtivos->isEmpty()) {
            $itens[] = $this->item('contratos_servico', 'Serviço contratado', 'ausente');

            return $itens;
        }

        // 7. Para cada ContratoServico ativo: data_contratacao, data da 1ª
        // parcela e dia do vencimento das demais, todos presentes e no
        // formato certo. REDE-05 pede literalmente "datas do contrato —
        // presença e formato"; data_primeira_parcela/dia_vencimento entraram
        // aqui por decisão do usuário em 2026-08-19 (Quick 260819-guy — ver
        // docblock da classe).
        //
        // ⚠️ data_vencimento (fim de vigência) vazia NÃO reprova — contrato
        // por prazo indeterminado é caso legítimo, não pendência. Isso
        // continua valendo; não confundir com data_primeira_parcela/
        // dia_vencimento (pagamento), que SÃO obrigatórios agora.
        foreach ($contratosAtivos as $contrato) {
            $nomeServico = optional($contrato->servico)->nome ?? "serviço #{$contrato->servico_id}";

            // 7a. data_contratacao — mesma checagem de sempre.
            //
            // ⚠️ Lê o atributo CRU (`getRawOriginal`), não o acessor com cast
            // `date:Y-m-d`. A coluna é NOT NULL no schema (migration
            // 2026_05_26_120002), então uma string vazia é o único jeito de
            // representar "sem data" em dado legado — e o cast `date` do
            // Eloquent interpretaria '' como "agora" via Carbon::parse(''),
            // mascarando exatamente o caso que esta regra existe para pegar.
            $rotuloInicio = "Data de início do contrato — {$nomeServico}";
            $rawInicio = $contrato->getRawOriginal('data_contratacao');

            if (blank($rawInicio)) {
                $itens[] = $this->item('data_contratacao', $rotuloInicio, 'ausente', $contrato->servico_id);
            } else {
                try {
                    \Illuminate\Support\Carbon::parse($rawInicio);
                } catch (\Throwable) {
                    $itens[] = $this->item('data_contratacao', $rotuloInicio, 'formato', $contrato->servico_id);
                }
            }

            // 7b. data_primeira_parcela (Quick 260819-guy) — presença e
            // formato, mesma disciplina de leitura CRUA de 7a (a coluna é
            // nullable, mas string vazia também não é uma data válida).
            $rotuloParcela = "Data da 1ª parcela — {$nomeServico}";
            $rawParcela = $contrato->getRawOriginal('data_primeira_parcela');

            if (blank($rawParcela)) {
                $itens[] = $this->item('data_primeira_parcela', $rotuloParcela, 'ausente', $contrato->servico_id);
            } else {
                try {
                    \Illuminate\Support\Carbon::parse($rawParcela);
                } catch (\Throwable) {
                    $itens[] = $this->item('data_primeira_parcela', $rotuloParcela, 'formato', $contrato->servico_id);
                }
            }

            // 7c. dia_vencimento (Quick 260819-guy) — presença e faixa válida
            // (1 a 31, é dia do MÊS — ver docblock da migration, não uma
            // data). A camada de validação (Tarefa 2) já trava isso no save;
            // esta checagem é defesa em profundidade contra dado gravado por
            // outro caminho (ex.: seed, import, edição direta no banco).
            $rotuloDia = "Dia do vencimento das demais parcelas — {$nomeServico}";
            $diaVencimento = $contrato->dia_vencimento;

            if (blank($diaVencimento)) {
                $itens[] = $this->item('dia_vencimento', $rotuloDia, 'ausente', $contrato->servico_id);
            } elseif (! is_numeric($diaVencimento) || (int) $diaVencimento < 1 || (int) $diaVencimento > 31) {
                $itens[] = $this->item('dia_vencimento', $rotuloDia, 'formato', $contrato->servico_id);
            }
        }

        return $itens;
    }

    /**
     * Atalho para a tela/orquestrador: true quando não há nada faltando.
     */
    public function estaPronta(Company $company): bool
    {
        return $this->faltantes($company) === [];
    }

    /**
     * Os signatários da ECF (D-08) estão configurados?
     *
     * ⚠️ **Eram três, obrigatórios, até 2026-08-20.** Por decisão da diretoria
     * passou a assinar só o Thiago Messina pela ECF — o sócio e a testemunha
     * saíram, e o rodapé do `.docx` na Clicksign foi editado junto. A lista em
     * `config/services.php` virou VARIÁVEL: descarta o slot com nome e e-mail
     * ambos vazios. Esta checagem acompanhou — passou a exigir **pelo menos
     * um**, em vez dos três.
     *
     * ⚠️ O que NÃO afrouxou: entrada preenchida **pela metade** (nome sem
     * e-mail, ou o contrário) continua reprovando. Meio preenchido é erro de
     * digitação, não remoção intencional — e é justamente o caso que o gate do
     * plano 127-07 pegou contra o sandbox real. Só o slot com os DOIS vazios
     * significa "não usado", e esse o `config` já descartou antes de chegar aqui.
     *
     * ⚠️ **Achado no gate do plano 127-07, contra o sandbox real.** Sem isto, a
     * checagem validava só os dados da EMPRESA e deixava passar um contrato que
     * quebra no meio da montagem: o fluxo criava o envelope, criava o documento,
     * e **só então** a API recusava o primeiro signatário com
     * `email - não pode ficar em branco`. Três chamadas queimadas da janela
     * medida de 20/min, mais o rollback — por um dado que já se sabia estar
     * incompleto ANTES de qualquer requisição.
     *
     * É exatamente o que o Goal da fase proíbe. `config('services.clicksign.
     * signatarios_ecf')` vem do `.env` e nasce com as 3 entradas presentes mas
     * **vazias** (`.env.example` traz as chaves sem valor), então o caso não é
     * hipotético: é o estado padrão de qualquer ambiente recém-configurado.
     *
     * Separado de `faltantes()` de propósito: aquilo é pendência **da empresa**,
     * que o Comercial resolve na tela da Fase 131; isto é configuração **da
     * ECF**, que só um admin resolve no `.env`. Misturar os dois mandaria o
     * Comercial caçar um campo que não é dele.
     *
     * @return array<int, string>  rótulos do que falta; vazio = configuração ok
     */
    public function faltantesDaConfiguracaoEcf(): array
    {
        $signatarios = config('services.clicksign.signatarios_ecf', []);

        // Pelo menos UM. Zero significa que nenhum slot do `.env` tem nome ou
        // e-mail — nesse estado o envelope nasceria só com o cliente, sem
        // ninguém assinando pela ECF.
        if (! is_array($signatarios) || $signatarios === []) {
            return ['Nenhum signatário da ECF (CLICKSIGN_SIG*_NOME / _EMAIL) está configurado'];
        }

        $problemas = [];

        foreach ($signatarios as $i => $s) {
            $papel = $s['papel'] ?? "#{$i}";

            if (blank($s['nome'] ?? null)) {
                $problemas[] = "Nome do signatário da ECF ({$papel}) não configurado";
            }

            if (blank($s['email'] ?? null)) {
                $problemas[] = "E-mail do signatário da ECF ({$papel}) não configurado";
            } elseif (filter_var($s['email'], FILTER_VALIDATE_EMAIL) === false) {
                $problemas[] = "E-mail do signatário da ECF ({$papel}) tem formato inválido";
            }
        }

        return $problemas;
    }

    /**
     * @return array{campo: string, rotulo: string, motivo: string, servico_id: ?int}
     */
    private function item(string $campo, string $rotulo, string $motivo, ?int $servicoId = null): array
    {
        return [
            'campo'      => $campo,
            'rotulo'     => $rotulo,
            'motivo'     => $motivo,
            'servico_id' => $servicoId,
        ];
    }
}
