# Testes isolados de logging

Requer PHP 8.1 ou posterior. Não requer Composer nem qualquer pacote novo.
Para validar a versão alvo, execute com o binário PHP 8.1.34 de homologação:

```sh
sh tests/php-isolated.sh tests/run.php
```

`php-isolated.sh` usa `-n` e desabilita todas as funções nativas das extensões
cURL e sockets via `disable_functions` antes de compilar/carregar os fakes.
Também bloqueia streams de URL, conexões por streams e subprocessos. PHP 8 permite
redefinir em userland as funções internas desabilitadas. `run.php` verifica o
isolamento antes de carregar os fakes; nunca há fallback para cURL real.

Para lint, use o mesmo executor (não execute `fakes.php` como programa):

```sh
for file in tests/*.php; do
    sh tests/php-isolated.sh -l "$file" || exit
done
sh -n tests/php-isolated.sh
```

As declarações falsas são condicionais a uma guarda explícita, evitando colisão
na compilação isolada. Sem a guarda, carregar os fakes falha. Nenhuma função nativa
é reutilizada. Constantes cURL já definidas são preservadas; isso não executa HTTP.
No Docker, use imagem local com `--pull=never --network none --read-only` e monte
este repositório em `/work` somente para leitura; execute os comandos em `/work`.

## Estrutura

- `run.php`: 47 cenários, com sentinelas fictícias, executando os métodos reais de
  transporte, login, recuperação e SSO, além das funções que persistem snapshots.
- `fakes.php`: funções cURL em memória, fila obrigatória de respostas, captura dos
  argumentos do logger e implementação mínima de banco para os snapshots.
- A instância da API é criada por reflexão, sem executar o construtor que altera
  campos e templates no WHMCS. Chamadas inesperadas a `localAPI()` falham.
- Os testes de Activity Log executam apenas as expressões de logging extraídas
  dos hooks, com mensagens de exceção/resultados contendo sentinelas. Não executam
  os hooks nem corrigem os defeitos de cron existentes.

## Cobertura

- Debug ligado/desligado, nenhuma saída de debug e nenhum escritor de arquivo
  no código de produção; comparação dos arquivos do repositório antes/depois.
- Senha, API key, Authorization, e-mail, URL/token de SSO, JSON aninhado/listas,
  variações de caixa nos nomes dos campos, strings serializadas e Base64.
- Preservação do body e cabeçalhos originais na requisição falsa e da resposta
  completa para os chamadores, incluindo quatro formatos de resposta SSO.
- HTTP 200, 204, 400, 401, 403, 429, 500, respostas JSON/não JSON e erro cURL.
- Rotas conhecidas, desconhecidas, IDs sensíveis no caminho e valores na query.
- Snapshots em inserções e atualizações, mensagens do Activity Log e lista
  permitida de campos do Module Log, inclusive dados processados vazios.

Os primeiros cinco argumentos de `logModuleCall` são verificados como candidatos
à persistência. O sexto é capturado separadamente: ele necessariamente contém a
API key/senha para a substituição adicional exigida pelo contrato do logger. Os
testes verificam sua presença ali, mas nunca imprimem essa lista. Isso não equivale
a persistir essas credenciais no log. A implementação real do WHMCS não está na suíte.

Nenhuma sentinela é uma credencial real. Falhas imprimem o nome do cenário, sem
valores nem mensagens potencialmente sensíveis. A fila cURL vazia causa falha, sem
fallback de rede. Esta suíte não valida as demais regras de negócio do módulo.

## Estado da execução nesta entrega

Executada na imagem local `php:8.1-cli`, PHP 8.1.34: lint dos dois arquivos PHP
aprovado; 47 cenários executados, 47 aprovados, 0 reprovados. Contêiner sem rede,
somente leitura, sem capabilities e com repositório montado somente para leitura.
Nenhuma dependência instalada, imagem baixada ou arquivo produtivo modificado.
A correção anterior de SSO com `JSON_UNESCAPED_SLASHES` foi preservada.
