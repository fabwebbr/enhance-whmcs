# Fase 1C-D-A — representação conservadora, sem integração

Base: 7f733ed85fe4594015b69fd49823d0e38e56a5d2. Fonte local: OpenAPI orchd
12.25.12, SHA-256 e70647d68d5c5bbaf781cd6bd1c3072dde94701895c7965e1e6174eb9e0100f0.

EnhanceSubscriptionState::fromDecoded recebe arrays associativos já decodificados.
Não faz coerção, HTTP, banco, logs ou ações. Não retém o payload nem identificadores.
O objeto final contém somente propriedades privadas readonly de classificação.

Ciclo de vida (active/deleted) e evidência de suspensão (present/absent) são eixos
separados. Ausência de suspendedBy não prova ausência de suspensão. UUID presente
não identifica, para este modelo, quem suspendeu ou qual entidade está referenciada.

Validação: todos os campos obrigatórios de Subscription, tipos e enums, elementos
das listas obrigatórias (UsedResource, Allowance, Selection, PhpVersion), total
opcional de UsedResource e suspendedBy quando presente. Campos adicionais são
ignorados, inclusive os demais campos opcionais de Subscription. Portanto a
validade representa essa projeção estrutural, não validação exaustiva do OpenAPI.
Nenhum mínimo de ID/quota ou minItems não documentado é inventado. Arrays
associativos são a convenção de entrada; objetos stdClass não são aceitos.

| Estrutura | Ciclo | Evidência | Leitura estrutural | Mutação |
|---|---|---|---|---|
| Válida | active | absent | sim | não |
| Válida | active | present | sim | não |
| Válida | deleted | absent | sim | não |
| Válida | deleted | present | sim | não |
| Inválida | indeterminate | indeterminate | não | não |

allowsRead não comprova titularidade nem autoriza acesso a um serviço: apenas
indica aptidão estrutural para leitura. Não decide status comercial WHMCS.
Nenhuma combinação libera mutação. deleted permite inspeção, nunca limpeza
automática: preservar o vínculo é necessário para reconciliação futura.

Diagnósticos JSON, debug e serialização contêm somente classificações fixas.
Desserializar um diagnóstico produz estado indeterminado, nunca autorização.
O modelo não oferece mensagens remotas, UUIDs ou payload em sua API.

Não está integrado: requireSubscriptionState e o resolvedor permanecem intactos.
Importador, cron, associação, autoria/herança de suspensão e políticas de ciclo
continuam pendentes. Testes sintéticos não representam homologação. Produção e
homologação não autorizadas. Nenhum contrato HTTP é ampliado nesta fase.

## Correção de retenção nos métodos mágicos

Atribuições externas e unset são ignorados silenciosamente. Lançar exceções nesses
métodos poderia conservar nomes/valores sensíveis nos argumentos do trace com
zend.exception_ignore_args=0. Não há propriedade dinâmica, conversão ou retenção.

__unserialize ignora integralmente a entrada: instâncias inicializadas permanecem
inalteradas; instâncias sem construtor recebem apenas o estado canônico inválido,
indeterminado e sem autorização. A serialização é diagnóstica, não persistência
confiável. Os testes verificam reflexão, WeakReference, JSON, serialize,
var_export, debug, clonagem e cópias de arrays, com diagnósticos convertidos em falha.
Erros externos de reflexão e chamadas incompatíveis com as assinaturas tipadas
não fazem parte dessa garantia; não se trata de uma barreira contra código com
acesso privilegiado ao runtime.
