# WBoost

## AI clients (MCP)

WBoost exposes an MCP server at `https://wboost.cz/_mcp`. Connect Claude Code
with one command:

```bash
claude plugin marketplace add TheDevs-cz/wboost && claude plugin install wboost@wboost
```

See [`docs/mcp/connect.md`](docs/mcp/connect.md) for tokens, scopes and
troubleshooting, [`docs/mcp/prompts.md`](docs/mcp/prompts.md) for prompts that
work, and [`plugin/wboost/`](plugin/wboost/) for the plugin itself.

## Development
Simply run `docker compose up`

Application runs at `http://localhost:8080`

## Quick start
To create your user run (replace email+password placeholders):
`docker compose run --rm web bin/console app:user:register <email> <password>`

### Adminer (Database)

Runs at `http://localhost:8000`  
Driver: `postgres`  
User: `postgres`  
Password: `postgres`  
Database: `wboost`

### Mail catcher

Runs at `http://localhost:8025`

### Minio

Runs at `http://localhost:19001`  
Password: `wboost`  
Database: `wboostminio`
