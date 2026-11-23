# rasuvaeff/mcp-openapi

[![Stable Version](https://img.shields.io/packagist/v/rasuvaeff/mcp-openapi?label=stable&sort_semver=1)](https://packagist.org/packages/rasuvaeff/mcp-openapi)
[![Total Downloads](https://img.shields.io/packagist/dt/rasuvaeff/mcp-openapi)](https://packagist.org/packages/rasuvaeff/mcp-openapi)
[![Build](https://img.shields.io/github/actions/workflow/status/rasuvaeff/mcp-openapi/build.yml?branch=master)](https://github.com/rasuvaeff/mcp-openapi/actions)
[![Static analysis](https://img.shields.io/github/actions/workflow/status/rasuvaeff/mcp-openapi/static-analysis.yml?branch=master&label=static%20analysis)](https://github.com/rasuvaeff/mcp-openapi/actions)
[![Psalm level](https://img.shields.io/badge/psalm-level%201-141F48?logo=psalm&logoColor=white)](https://github.com/rasuvaeff/mcp-openapi/blob/master/psalm.xml)
[![License](https://img.shields.io/packagist/l/rasuvaeff/mcp-openapi)](LICENSE.md)

[English version](README.md)

Публикует разрешённые операции документа OpenAPI 3.x как MCP tools. Работает
поверх [`rasuvaeff/mcp`](https://github.com/rasuvaeff/mcp) и не требует фреймворка:
нужны спецификация, PSR-18 клиент (или PSR-15 handler) и PSR-17 фабрики. Для Yii3
его подключает из params [`rasuvaeff/yii3-mcp`](https://github.com/rasuvaeff/yii3-mcp).

> **Пользуетесь AI coding assistant?** [llms.txt](llms.txt) содержит компактную
> API-справку для модели. Для контрибьюторов: [AGENTS.md](AGENTS.md).

## Требования

| Требование | Версия |
|---|---|
| PHP | 8.3 – 8.6 |
| `rasuvaeff/mcp` | `^0.1` |
| `rasuvaeff/openapi-contract` | `^0.19` |
| Реализация PSR-17 | любая |
| PSR-18 клиент | для HTTP-исполнения или спецификации по URL |

## Установка

```bash
composer require rasuvaeff/mcp-openapi
```

## Использование

```php
use Rasuvaeff\Mcp\McpServerFactory;
use Rasuvaeff\Mcp\OpenApi\OpenApiBridgeFactory;

$bridge = OpenApiBridgeFactory::create(
    spec: __DIR__ . '/openapi.json', // JSON file, http(s) URL or decoded array
    baseUrl: 'https://api.example.com',
    httpClient: $httpClient,
    requestFactory: $psr17,
    streamFactory: $psr17,
    operations: ['getBlogTags'],
    headers: ['Authorization' => 'Bearer ' . getenv('API_TOKEN')],
    safeMethodsOnly: true,
    toolNames: ['getBlogTags' => 'blog_tags_list'],
);

$server = (new McpServerFactory(
    container: $container,
    sessionStore: $sessionStore,
    name: 'my-api',
    version: '1.0.0',
))->create([], [$bridge]);
```

Имя tool — `operationId` (или `toolNames`), описание — `summary`/`description`;
параметры и JSON-тело запроса становятся аргументами, тело — одним аргументом `body`. Общие
схемы раскрываются на месте; рекурсивная остаётся ссылкой, её определение лежит в
`$defs` схемы tool.

### Исполнение внутри процесса

Передайте `inProcessHandler` (PSR-15) и `serverRequestFactory`, чтобы вызывать
handler самого приложения без сети; `httpClient` и `requestFactory` тогда могут
быть `null`, если спецификация не по URL.

### Ограничения

Проверяются при сборке сервера для разрешённых операций:

- параметры пути — скаляры, параметры query — скаляры или массивы скаляров;
- параметры header и cookie, нестандартные `style`/`explode` (кроме массивов в
  query) и `allowReserved` отклоняются;
- документ — OpenAPI 3.0 или 3.1, его компилирует
  [`rasuvaeff/openapi-contract`](https://github.com/rasuvaeff/openapi-contract);
  ссылки только локальные; `servers`, `security` и `webhooks` документа не читаются;
- тело запроса только `application/json`; документ — JSON до 10 MiB.

### Компоненты

| Класс | Роль |
|---|---|
| `OpenApiBridgeFactory` | собирает мост из спецификации и PSR-сервисов |
| `OpenApiServerConfigurator` | регистрирует разрешённые операции в SDK builder |
| `SpecLoader` | загружает спецификацию по URL, с опциональным PSR-16 кэшем |
| `Operation`, `OperationModifierInterface` | настройка tool для отдельной операции |
| `DelegatedHeaderProviderInterface` | заголовки upstream для текущей идентичности |
| `ExecutionRequestAttributesInterface` | идентичность в атрибутах in-process запроса |
| `InProcessScopeInterface` | сброс состояния запроса вокруг in-process вызова |
| `Exception\InvalidSpecException` | документ или операцию нельзя подключить |
| `Exception\UnknownOperationException` | операции из списка нет в документе |
| `Exception\UnsafeOperationException` | не-GET операция при `safeMethodsOnly` |
| `Exception\InvalidToolArgumentException` | аргумент нельзя поместить в запрос |
| `Exception\OperationFailedException` | вызов upstream завершился ошибкой |

## Безопасность

- Публикуются только операции из `operations`; пустой список не публикует ничего.
- `headers` уходят только на `baseUrl`, `specHeaders` — только на URL спецификации;
  URL спецификации с учётными данными отклоняется.
- Аргумент пути пустой, `.` или содержащий `..`, `/` или `\` отклоняется;
  `multiSegmentPathParams` разрешает только `/` и только для указанного параметра.
- Тела ответов читаются по частям до `maxResponseBytes`.
- `opaqueErrors: true` скрывает тела ошибок upstream.
- Спецификация и `baseUrl` — доверенная конфигурация: защиты от SSRF нет.

## Примеры

См. [examples/](examples/): скрипт работает offline.

## Разработка

PHP и Composer на хосте не нужны, запускайте в Docker через образ `composer:2`:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
```

Или через Make: `make build`, `make cs-fix`, `make psalm`, `make test`.

## Лицензия

BSD-3-Clause. См. [LICENSE.md](LICENSE.md).
