MySQL setup for Poyoweb
=======================

1) Create a database and a user. Replace names and host as needed:

```sql
CREATE DATABASE poyoweb CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'poyoweb_user'@'127.0.0.1' IDENTIFIED BY 'choose_a_strong_password';
GRANT ALL PRIVILEGES ON poyoweb.* TO 'poyoweb_user'@'127.0.0.1';
FLUSH PRIVILEGES;
```

2) Apply the schema in `sql/schema.sql` while connected to the `poyoweb` database:

```bash
mysql -u root -p poyoweb < sql/schema.sql
```

3) Copy `config.sample.php` to `config.php` and edit the DSN, user and pass.

4) Visit `/admin.php` and create the admin user (setup). The site will then use MySQL.

Notes
- `admin.php` will automatically fall back to the existing JSON files if `config.php` is missing or the DB connection fails.
- Ensure your webserver user can read `config.php` but don't commit real credentials to Git.
