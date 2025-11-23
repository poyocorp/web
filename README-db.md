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

5) (GitHub Pages) To update `data/stuff.json` directly from the repo using GitHub Actions, this project includes a manual workflow `.github/workflows/update-stuff.yml`.

	Usage (UI):
	- Go to the repository Actions tab → "Update Stuff JSON" workflow → Run workflow.
	- In the input `items_b64`, paste the base64-encoded JSON array (single-line).

	Usage (CLI):
	- Base64-encode your JSON and dispatch the workflow with the `gh` CLI:

```bash
# Create a base64 single-line value (Linux)
base64 -w0 data/stuff.json > /tmp/stuff.b64

# Run the workflow
gh workflow run update-stuff.yml -f items_b64="$(cat /tmp/stuff.b64)"
```

	Note: On macOS use `base64` without `-w0` and then strip newlines, or use `python -c 'import base64,sys;print(base64.b64encode(sys.stdin.read().encode()).decode())' < data/stuff.json` to get a single-line base64 string.

Notes
- This repository does not include a running PHP backend. If you add one, follow the SQL steps above and create `config.php` on the server.
- Make sure any server-side config containing credentials is not committed to source control.
