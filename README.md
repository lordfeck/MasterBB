MasterBB 1.5
============
This is a fork of the phpBB 1.4.4 code, tidied up and ported to run on modern PHP 8 and MariaDB environments.

# Current environment
This setup deliberately uses legacy software to approximate phpBB 1.4.4's original environment:

- Apache + PHP 4.4.9 (`remiq/apache-php4:4.4.9`)
- MySQL 4.1.11 (`oblakstudio/mysql41:4.1.11`)

## Start

```sh
docker compose up --build
```

Then open:

    http://localhost:8080/phpBB/install.php

## Installer database settings

Use:

- Database server: `db`
- Database name: `phpbb`
- Database username: `phpbb`
- Database password: `phpbb`

The hostname is `db`, not `localhost`, because MySQL is running in the other Compose service.

## After installation

phpBB 1.4.4 requires `config.php` to be writable during installation. The Dockerfile sets it to mode 666 for this reason.

After the installer has completed, lock it back down:

```sh
docker compose exec phpbb chmod 444 /www/phpBB/config.php
```

The original phpBB documentation also recommends removing or renaming the installer and upgrade scripts. For this local retro setup you can do:

```sh
docker compose exec phpbb rm -f \
  /www/phpBB/install.php \
  /www/phpBB/upgrade_12.php \
  /www/phpBB/upgrade_14.php
```

## Reset everything

To throw away the database and start again:

```sh
docker compose down -v
docker compose up --build
```

## Important

This is obsolete, vulnerable software running obsolete PHP and MySQL. The Compose file intentionally publishes Apache only on `127.0.0.1`. Do not expose this stack directly to the public Internet.

