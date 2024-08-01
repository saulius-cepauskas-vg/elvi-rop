# Reorder point calculation

Modeling of reorder point implementation in a supply chain. See Cli directory for output files generation.

## Installation

Composer install

## Config

1. Set user / password for database connection
2. Open SSH tunnels for remote connections to the database.

```bash
# DWH
ssh -L 3326:46.101.192.100:3306 deploy@elviapp -N

# Percona
ssh -L 3316:64.226.123.58:3306 deploy@elviapp -N
```
