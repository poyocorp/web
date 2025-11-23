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

3) (Optional) If you later add a server backend, create a server-side `config.php` with your DSN, user and password.

4) This distribution is static — use `/admin.html` to edit `data/stuff.json` in your browser. To persist changes, download the JSON from the editor and upload it to the server path `data/stuff.json` using your host's file manager or FTP.

Notes
- This repository does not include a running PHP backend. If you add one, follow the SQL steps above and create `config.php` on the server.
- Make sure any server-side config containing credentials is not committed to source control.
