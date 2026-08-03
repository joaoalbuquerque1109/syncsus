# Integração SYNC HOSP e Synclab

## Escopo ativo

O SYNC HOSP envia requisições de exames criadas na recepção ou no consultório médico para o Synclab. O envio é assíncrono, isolado por unidade e direcionado ao endpoint:

```text
POST https://synclabweb.unisync.com.br/app/addrequisicao/{cnes}
```

Para São Caetano/PE, o CNES de teste é `6612547`. O campo `ordem_servico` enviado ao Synclab é o `id` numérico de `exam_orders`, que também aparece como ID no grid do SYNC HOSP.

Somente uma resposta HTTP 200 confirma a transmissão. Respostas 429 e 5xx entram em nova tentativa; outros códigos HTTP ficam rejeitados para revisão operacional.

## Catálogo

O arquivo versionado `database/data/synclab_exams.csv` é importado para `laboratory_exams`. O seeder aceita exclusivamente linhas com `itemexame = 0`; itens/componentes não podem ser selecionados diretamente. O catálogo atual possui 123 exames-pai.

Cada exame é transmitido com as coleções obrigatórias `amostras` e `itens` vazias. Isso atende ao parser do Synclab sem antecipar coleta, código de barras, componentes ou resultados; essas informações continuam fora do escopo e serão identificadas posteriormente no laboratório.

O campo `sus_procedure_code` possui até 10 caracteres. Códigos SIGTAP só são preenchidos quando o pareamento com `DadosPadraoTabelaProcedimentoSUS.php` é inequívoco. Exames sem pareamento continuam utilizáveis porque o contrato de envio usa `external_code`, o código próprio do Synclab.

## Dados enviados

- Identificador da requisição e ordem de serviço.
- Unidade e CNES.
- Solicitante e registro profissional, quando cadastrados.
- ID numérico do paciente no campo `paciente.codigo`, nome e pelo menos CPF ou CNS.
- Exames selecionados, usando o código externo do Synclab.
- Prioridade, data, origem e observações da requisição.

## Fora do escopo atual

Amostras, códigos de barras, componentes analíticos e recebimento de resultados permanecem desabilitados. Esses campos não são enviados no JSON. A estrutura futura foi preservada no projeto e sinalizada como `standby` em `config/synclab_contract.php`.

## Configuração de produção

```dotenv
SYNC_SUS_SYNCLAB_ENABLED=true
SYNC_SUS_SYNCLAB_BASE_URL=https://synclabweb.unisync.com.br
SYNC_SUS_SYNCLAB_UNIT_CODE=URGENCIA-CENTRAL
SYNC_SUS_SYNCLAB_CNES=6612547
SYNC_SUS_SYNCLAB_USERNAME=<usuario-fornecido-pelo-synclab>
SYNC_SUS_SYNCLAB_PASSWORD=<senha-fornecida-pelo-synclab>
SYNC_SUS_SYNCLAB_QUEUE=integrations
SYNC_SUS_SYNCLAB_CONNECT_TIMEOUT=5
SYNC_SUS_SYNCLAB_TIMEOUT=30
```

Depois de configurar as variáveis, execute o deploy normalmente. A inicialização aplica migrations e seeders; o mesmo container do Railway executa a aplicação web, o worker da fila e o agendador. Não é necessário criar outro serviço neste momento.

### Ambiente local no Windows

O PHP precisa confiar na cadeia HTTPS. O bundle pode ser obtido em `https://curl.se/ca/cacert.pem`. Configure no `C:\php\php.ini`:

```ini
curl.cainfo="C:\caminho\seguro\cacert.pem"
openssl.cafile="C:\caminho\seguro\cacert.pem"
```

Use uma fila assíncrona (`QUEUE_CONNECTION=database` ou `redis`) e inicie todo o ambiente com `composer run dev`. Esse comando sobe servidor, worker das filas `integrations,default`, agendador e Vite. Assim uma indisponibilidade do Synclab não bloqueia a tela clínica. O Laravel Pail não faz parte desse comando porque depende de `pcntl`, extensão indisponível no PHP para Windows; quando necessário, acompanhe `storage/logs/laravel.log` em outro terminal.

Mantenha apenas uma execução de `composer run dev`. O Vite usa obrigatoriamente a porta `5173`, que é a origem local autorizada pela política de segurança do sistema. O comando remove automaticamente referências `public/hot` obsoletas antes de iniciar.

Pedidos criados enquanto a integração estava desativada permanecem em `awaiting_configuration`. Eles não são enviados em lote ao habilitar a unidade; um gestor deve revisar e usar **Tentar novamente** em cada pedido.

Credenciais e dados reais de pacientes nunca devem ser versionados. Antes do primeiro envio em produção, use uma requisição de paciente fictício autorizada pelo Synclab e confira o registro correspondente no grid do sistema de destino.
