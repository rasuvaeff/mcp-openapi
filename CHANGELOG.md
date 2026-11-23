# Changelog

## Unreleased

- Initial package: the OpenAPI bridge extracted from `rasuvaeff/yii3-mcp`,
  under `Rasuvaeff\Mcp\OpenApi`.
- **Changed:** documents are compiled by `rasuvaeff/openapi-contract`. Differences
  from the 5.x bridge, for the selected operations only:
  - recursive schemas are bridged (5.x refused the whole document);
  - a keyword beside a `$ref` that the target also declares is combined through
    `allOf` instead of replacing the target's;
  - a broken operation that is not selected no longer refuses the document;
  - refused now: a document that is not OpenAPI 3.0/3.1 (including a missing
    `openapi`), an external `$ref`, a path parameter that is not `required: true` or
    not in the path template, a malformed parameter (5.x skipped it), an invalid
    schema in any request or response of the operation;
  - header parameters named `Accept`, `Authorization` and `Content-Type` are ignored,
    as OpenAPI prescribes (5.x refused the operation);
  - in OpenAPI 3.0 the request body of GET, HEAD and DELETE is ignored.
