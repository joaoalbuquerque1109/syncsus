# Integração SYNC SUS e Synclab

## Estado da implementação

A base da integração é multitenant e permanece segura por padrão. Selecionar um exame do catálogo cria uma caixa de saída com estado `awaiting_contract`; nenhum pedido é transmitido enquanto as decisões externas abaixo não forem confirmadas e implementadas em um commit próprio.

## Decisões confirmadas

| Tema | Contrato adotado |
|---|---|
| Confirmação do envio | Somente HTTP 200 confirma o aceite |
| Identificação do paciente | Nome e pelo menos um entre CPF ou CNS |
| Amostra e código de barras | A requisição é enviada sem amostra; a identificação acontece posteriormente no Synclab |

## Decisões externas pendentes

1. Significado do código de tenant presente nas URLs e valor por unidade.
2. Comportamento ao reenviar a mesma `ordem_servico`.
3. Formato, tamanho e escopo de unicidade da `ordem_servico`.
4. Campo que diferencia resultado parcial de resultado final.
5. Identificadores estáveis de ordem, exame, componente e amostra nos resultados.
6. Origem oficial do catálogo: endpoint, arquivo versionado ou carga manual.
7. Estrutura do corpo HTTP 200 e identificadores devolvidos pelo Synclab.

## Regra para liberação

As respostas devem ser incorporadas ao arquivo `config/synclab_contract.php`. No mesmo commit serão adicionados ou ajustados os testes de contrato, o gerador do número externo, o tratamento de duplicidade e a interpretação dos resultados. Somente depois disso uma transmissão poderá sair de `awaiting_contract` para `pending`.

Credenciais, dados de pacientes e exemplos reais de resultados não devem ser registrados neste documento nem versionados no repositório.
