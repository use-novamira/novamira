# Novamira REST Ability v1 server contract

Status: fixed contract for CLI implementers and the Phase 1 REST-only acceptance test.

## Compatibility floor

| Component | Supported value |
| --- | --- |
| WordPress | 6.9 or newer |
| Novamira plugin | 1.11.0 or newer within major 1 |
| REST contract | `rest_api_version: 1` |
| OAuth audience | normalized `rest_url('mcp/novamira-oauth')` identifier |

A client must fetch `<wordpress-home>/.well-known/oauth-protected-resource` before login and require all four `novamira.features` values to be `true`. The WordPress home may include a subdirectory. Public metadata and authenticated `novamira/agent-context` compatibility data must agree.

## OAuth grants

- `mcp`: full access to the MCP protected resource and otherwise-authorized REST-visible Abilities.
- Former `abilities` and `abilities:read` tokens are full-access upgrade aliases and are not advertised for new authorization.

Authorization Code with PKCE and refresh are the supported CLI grants. New and refreshed grants converge to `mcp`.

## HTTP surface

The standalone client uses only these REST/OAuth surfaces:

```text
GET  <home>/.well-known/oauth-protected-resource
GET  <home>/wp-admin/admin.php?page=novamira-oauth-authorize&...
POST <home>/wp-json/novamira/v1/oauth/token
GET  <home>/wp-json/wp-abilities/v1/abilities?page=<n>
GET  <home>/wp-json/wp-abilities/v1/abilities/<complete-name>
POST <home>/wp-json/novamira/v1/abilities/<complete-name>/run
```

The run body is `{ "input": <any JSON value> }`, plus two optional members for sites with AI approvals on: `review` (`attention`, `summary`, `details`) and `request_id`; `{}` means null. Successful values are not wrapped by the plugin. `novamira/agent-context` and `novamira/skill-get` are required components, not optional enhancements. Discovery is incomplete and must fail as `server_unsupported` if list pagination succeeds but context, a required item route, the shim, or required feature data is absent.

The main Bearer token is never sent to upload, temporary-administrator, chat, browser-runtime, canonical/legacy protocol, or unrelated REST routes. The CLI wire workflow sends no request to `/mcp/*`, initializes no protocol session, and sends no JSON-RPC body.

## Acceptance coverage

`tests/integration/RestOnlyContractTest.php` drives a standalone HTTP-style client through pre-login compatibility, authorization and token exchange, complete pagination, item lookup, context/read execution, mutation, and site-skill retrieval. It runs against root and subdirectory homes with direct and CGI-forwarded Authorization headers, scans every request for protocol/JSON-RPC use, and proves a missing context after successful pagination is observable as an atomic compatibility failure.

Lower-level PHPUnit coverage invokes the production metadata, OAuth, Bearer middleware, scope, shim, skill, and context implementations; the integration harness composes their fixed external contract without depending on the optional MCP adapter.
