# Fase 1B — transporte HTTP

Base limpa: `06300fb9d1647dfdf879e8f9ef83024950adfa7d` (correções da suíte),
com logging da Fase 1A em `b197589`. Sem acesso a documentação/API externa.

## Registro prévio das mudanças de expectativa

Este registro foi escrito antes de alterar as expectativas. Os 47 nomes/cenários
anteriores e suas entradas serão mantidos, com sentinelas intactas.

| Cenário anterior (debug off/on) | Expectativa anterior | Risco | Nova expectativa e motivo |
|---|---|---|---|
| nested request and JSON response | POST /logins sem id devolvia o objeto como sucesso | Criação não confirmada; consumidor poderia compensar ou buscar outro login | JSON reconhecido, mas exceção invalid_schema sem payload; body original continua enviado e lista adicional de substituição preservada |
| HTTP 200 raw | Texto arbitrário retornava como _raw | HTML/texto poderia virar sucesso em consumidores que só verificam code | non_json; nenhuma resposta bruta na exceção/diagnóstico |
| HTTP 200 JSON | GET de organização sem id/name aceitava qualquer objeto | Objeto incompleto poderia provocar alteração de organização | invalid_schema com formato json, sem payload nos diagnósticos |
| HTTP 400/401/403/429/500 raw e JSON | Erros retornavam corpo/mensagem/segredos junto de code | Consumidores confundiam erro com ausência; mensagens poderiam ser persistidas | Exceção classificada com HTTP e mensagem fixa, interrompendo antes da próxima mutação; nunca retorno bruto de erro |
| cURL error | Mensagem original do cURL era devolvida | Host/URL/segredos em mensagens e erro confundido com ausência | transport_error, errno 28 e mensagem genérica; sem resposta HTTP confirmada |

Os demais cenários mantêm expectativas funcionais. A categoria registrada no
Module Log passa a refletir também validação de formato/esquema, sem novos payloads.
As assertivas antigas que exigiam payload de erro passam a exigir explicitamente
sua ausência, classificação correta e preservação da requisição original.

## Inventário anterior e estratégia

Todas as falhas saem de `send()` como exceção sanitizada antes de retornar aos
consumidores. Não se altera sua descoberta, associação ou política comercial.

| Consumidores/operações | Interpretação anterior | Interrupção necessária |
|---|---|---|
| getLicense / enhance_TestConnection | Só unauthorized reprovava explicitamente | Falha HTTP/formato lança exceção capturada pelo catch existente; contrato de sucesso da licença permanece indeterminado |
| getOrg / syncCustomerFromWhmcs | Qualquer code descartava org e iniciava descoberta | Exceção antes de invalidar o ID ou listar/criar organizações |
| getSubscription / _enhance_resolve_subscription | Qualquer code apagava vínculo | Exceção antes de clearServiceSubscriptionId e descoberta |
| getCustomers, getMembers, getLogins / find* | Erro ou items ausente virava coleção vazia | Exceção antes de devolver ausência, criar login ou vincular Owner |
| createCustomerOrg / createLogin | Sem id disparava erro/DELETE compensatório | Exceção antes de exclusão compensatória, vínculo, e-mail ou nova busca |
| createSubscription / enhance_CreateAccount | Sem id falhava; resposta de website sem code era sucesso | Exceção antes de persistência seguinte ou rollback destrutivo |
| createWebsite / enhance_CreateAccount | code disparava DELETE da assinatura | Exceção antes de DELETE e remoção de vínculo |
| updateOrgName, setOrgSuspended, updateSubscriptionPlan, setSubscriptionSuspended, linkMemberAsOwner | Ausência de code bastava | Exigir resposta básica compatível; falha interrompe sem nova ação |
| deleteOrg, deleteSubscription, deleteWebsite | Ausência de code bastava; alguns resultados ignorados | Exceção interrompe laço/limpeza de vínculo após falha |
| getPlans, getCustomerSubscriptions, listWebsites*, listWebsiteDomains | items ausente virava lista vazia | Validar envelope e itens antes de qualquer fallback |
| getWebsite / aba administrativa | Sem id ocultava dados | Exceção capturada pelo catch existente |
| getEmails / widget | total ausente virava zero | Exceção em formato incompatível |
| triggerPasswordRecovery | Ausência de code bastava; fixture 204 existente | Preservar 204 somente nesta operação comprovada pela suíte |
| getOwnerSsoUrl | _raw, url ou loginUrl; podia devolver texto arbitrário | Exceção para formato inválido; texto URL somente nesta operação comprovada pelo código/fixtures; sem mudar autorização |
| importador/cron | code pulava registro ou arrays vazios ativavam fallback; estado ausente podia virar Active | Exceção antes da decisão insegura; catch externo existente pode interromper o lote |

Uma mutação já enviada pode ter sido aplicada remotamente antes de timeout/resposta
inválida. Não haverá retry nem compensação automática após essa falha. Reconciliação,
retomada e isolamento por item pertencem às fases posteriores.

## Implementação e categorias

`EnhanceHttpResult` separa categoria, formato, HTTP, errno e payload privado. Sua
serialização JSON/PHP e visualização de debug expõem somente classificação/códigos.
O payload de sucesso é acessível apenas pelo adaptador `data()`. Não há retenção
adicional de corpo bruto; `_raw` só é usado para URL SSO e para a resposta vazia de
recuperação já coberta pelo fixture anterior.

`send()` preserva método, URL, headers, autenticação, corpo JSON, timeout de 45s e
verificação TLS. Trata falha de encoding antes da requisição, consulta errno (sem
ler mensagem bruta de cURL), classifica a resposta, registra só metadados e chama
`data()`. Qualquer categoria diferente de success lança `EnhanceTransportException`
com mensagem fixa e códigos locais. Não há retry, descoberta ou compensação aqui.

| Categoria | Significado |
|---|---|
| success | HTTP 200/201 e formato mínimo aceito; 204 apenas recuperação de senha |
| not_found | Somente contrato explicitamente declarado; teste sintético, nenhum endpoint produtivo habilitado |
| auth_error | HTTP 401/403 |
| rate_limited | HTTP 429 |
| validation_error | HTTP 400/409/422 |
| remote_error | HTTP 5xx |
| http_error | Outros HTTP não 2xx, inclusive 404 sem declaração e redirecionamentos |
| invalid_http_status | Status ausente/fora de 100–599 sem errno; não confirma resposta HTTP válida |
| transport_error | errno diferente de zero, curl_exec false ou falha local na execução |
| request_encoding_error | Corpo não codificável; nenhuma requisição enviada |
| empty_response | Corpo inesperadamente vazio em 200/201 |
| unexpected_empty_response | 204 sem contrato explícito ou corpo incompatível com 204 |
| non_json / invalid_json | Texto/HTML não JSON ou conteúdo com aparência de JSON malformado |
| invalid_schema | JSON válido, mas tipo/campos mínimos incompatíveis |
| api_error | Objeto com code não vazio, inclusive em HTTP 200 |
| indeterminate | Contrato não demonstrado, HTTP 202/206 ou outro 2xx não contemplado |

O campo `format` diferencia json, text_url, empty, non_json, invalid_json e
unavailable. Erros HTTP são classificados antes de interpretar seu corpo.
Método/rota sanitizados, HTTP, errno e duração continuam sendo os metadados locais
do transporte; duração só vai ao logger com debug ligado, conforme Fase 1A.

## Contratos provisórios e limites conhecidos

- Organizações: id e name; criação de organização/login/assinatura/website e
  consulta de website: id. O uso de id para confirmar website criado é inferido
  da entidade consumida por getWebsite, não de documentação externa.
- Listagens: items deve ser lista; IDs dos registros usados nas chamadas seguintes
  devem existir. Clientes/planos exigem name; logins, email; membros, roles como
  lista de strings e isActive booleano quando presente.
- Assinatura consultada: id e estado utilizável em comum por provisionamento/cron.
  Aceita isSuspended booleano coerente, ou suspendedBy não vazio sem campo suspended.
  Formatos apenas status/suspended e estados contraditórios são rejeitados, pois
  os consumidores atuais os interpretam de maneiras diferentes. Não há conversão
  nem nova política de estados; ampliar esses contratos exige confirmação.
- Domínios preservam os dois formatos já consumidos: strings e estruturas; emails
  exige total inteiro não negativo. Tipos alternativos ainda precisam de confirmação.
- SSO é a única exceção de texto comprovada pelo código e fixtures: URL http/https
  sintaticamente válida, texto ou string JSON, ou campo url/loginUrl. Não há mudança
  de proprietário/autorização. String JSON com barras escapadas agora é decodificada
  como URL; isto é correção de formato no transporte, não redesign de SSO.
- Não há documentação suficiente para declarar 404 como ausência em produção.
  Todos continuam erros HTTP; um teste de contrato sintético comprova que a
  declaração explícita distingue ausência de autenticação/infraestrutura.
- **Licença, PATCH, DELETE e criação de vínculo Owner não têm contrato positivo
  demonstrado. Mesmo um objeto JSON nessas operações retorna indeterminate.**
  Portanto, Test Connection não confirma sucesso da licença nesta entrega; mutações
  de confirmação desconhecida podem ser aplicadas no servidor e continuar sem
  confirmação local. Não usar esta implementação em produção antes da homologação
  desses contratos. Recuperação só confirma o 204 já demonstrado; JSON nessa operação
  permanece indeterminado.

## Mudanças funcionais inevitáveis

| Antes | Agora | Motivo | Consumidores afetados |
|---|---|---|---|
| Erro retornava array com code e dados remotos | Exceção sanitizada antes do retorno | Evitar tratar falha como ausência e evitar payload em erro | Todos os consumidores de send |
| Erro de GET podia apagar vínculo/descobrir outro alvo | Fluxo encerra antes desse ramo | Falha não comprova inexistência | syncCustomerFromWhmcs, _enhance_resolve_subscription |
| Erro/missing id podia iniciar DELETE compensatório | Exceção sem compensação adicional | Estado remoto pode ser incerto | createCustomerOrg, enhance_CreateAccount |
| TestConnection quase sempre retornava success | Catch existente retorna failure seguro; contrato positivo desconhecido | Impedir falso positivo | enhance_TestConnection |
| HTML/texto/vazio/objeto incompleto podia significar sucesso | Falha classificada | Formato mínimo obrigatório | Todos |
| Qualquer 204 era aceito | Só recuperação com corpo vazio | Não inventar semântica de endpoints | Alterações/exclusões/link Owner |
| Mutações sem contrato de confirmação eram consideradas concluídas | indeterminate, sem avançar o fluxo | Confirmação insuficiente | PATCH/DELETE/link Owner/licença |
| Cron/importador ignorava falha de item ou tentava fallback | Exceção chega ao catch externo; lote pode parar | Evitar decisões sobre dados inválidos | Importador, cron, interfaces |
| Mensagem original de cURL/HTTP retornava ao chamador | Mensagem genérica, HTTP/errno/categoria disponíveis | Prevenir exposição em mensagens/erros | Todos |

Nenhum corpo de consumidor, hook, importador, template ou campo foi alterado.
Os cinco consumidores autorizados foram exercitados em integração falsa: timeout,
401, 403, 404, 429, 500, HTML e JSON incompleto. Asserções conferem contagem exata de
HTTP e escritas locais antes da falha, impedindo mutação seguinte silenciosa.

Pendências: granularidade de tratamento no widget administrativo (sem catch local),
continuidade de cron/importador por item, reconciliação de operação já enviada,
contratos reais dos ACKs, licença e ausência 404. Não foi redesenhado nenhum fluxo.
A falha interrompe esses consumidores, mas sua experiência de erro/retomada requer
as fases apropriadas. A exceção tem mensagem, string, debug e serialização seguros;
nenhum destino do módulo recebe trace. Um integrador externo que invoque getTrace()
com argumentos habilitados ainda deve evitar persistir esses argumentos.

## Testes e evidências

47 cenários originais preservados + 109 novos = **156 testes**. Foram atualizadas
expectativas de 28 cenários antigos (14 por modo de debug), conforme registro acima.
Nenhuma sentinela de entrada, nome de cenário ou verificação contra vazamento foi
removida. Acrescentadas sentinelas de corpo/mensagem, proteção de serialização e
verificação de ausência de requisição/mutação após falha.

Executados em imagem local php:8.1-cli, PHP **8.1.34**, com --pull=never, --network none,
--read-only, --cap-drop ALL, --security-opt no-new-privileges e bind do repositório
somente leitura. Executor tests/php-isolated.sh inalterado. Lint dos cinco PHP
alterados/criados aprovado; sh -n aprovado; **156 aprovados, 0 reprovados**, inclusive
com zend.exception_ignore_args=0 para testar proteção de serialização de exceções.

Comandos internos do contêiner:

```sh
for file in modules/servers/enhance/EnhanceApi.php modules/servers/enhance/EnhanceHttpResult.php tests/*.php; do
    sh tests/php-isolated.sh -l "$file" || exit
done
sh -n tests/php-isolated.sh
sh tests/php-isolated.sh -d zend.exception_ignore_args=0 tests/run.php
```

A documentação e testes não equivalem à homologação do Enhance/WHMCS. Não houve
acesso externo, uso de credencial real, instalação de dependência ou commit.
