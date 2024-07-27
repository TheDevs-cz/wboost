# WBoost

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
