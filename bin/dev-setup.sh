#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
export DOCKER_CONFIG="${DOCKER_CONFIG:-/tmp/prompt-hub-docker}"
docker build -t prompt-hub-php .
docker network inspect prompt-hub-net >/dev/null 2>&1 || docker network create prompt-hub-net >/dev/null
if ! docker container inspect prompt-hub-db >/dev/null 2>&1; then
  if [ -f config/local.php ]; then
    echo 'Existing config/local.php found without the development DB. Preserve it and configure the DB explicitly; refusing to overwrite.' >&2
    exit 1
  fi
  python3 - <<'PY'
from pathlib import Path
import secrets,os
password=secrets.token_hex(24)
path=Path('config/local.php')
path.write_text("<?php\nreturn ['host'=>'prompt-hub-db','port'=>3306,'database'=>'prompt_hub','username'=>'prompt_hub','password'=>'"+password+"','base_path'=>'','secure_cookie'=>false];\n")
os.chmod(path,0o600)
path=Path('storage/docker.env')
path.write_text('MARIADB_RANDOM_ROOT_PASSWORD=1\nMARIADB_DATABASE=prompt_hub\nMARIADB_USER=prompt_hub\nMARIADB_PASSWORD='+password+'\n')
os.chmod(path,0o600)
PY
  docker run -d --name prompt-hub-db --network prompt-hub-net --env-file storage/docker.env -v prompt-hub-data:/var/lib/mysql mariadb:11.4 >/dev/null
  rm storage/docker.env
else
  docker start prompt-hub-db >/dev/null
fi
for attempt in {1..30}; do
 if docker exec prompt-hub-db healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1; then break; fi
 if [ "$attempt" = 30 ]; then echo 'Database readiness failed' >&2; exit 1; fi
 sleep 1
done
docker run --rm --network prompt-hub-net -v "$PWD:/app" prompt-hub-php php bin/dev-init.php
if docker container inspect prompt-hub-web >/dev/null 2>&1; then
 docker start prompt-hub-web >/dev/null
else
 docker run -d --name prompt-hub-web --network prompt-hub-net -p 127.0.0.1:8080:8080 -v "$PWD:/app" prompt-hub-php >/dev/null
fi
curl --fail --silent --show-error 'http://127.0.0.1:8080/index.php?r=browse' -o /tmp/prompt-hub-readiness.html
python3 - <<'PY'
from pathlib import Path
html=Path('/tmp/prompt-hub-readiness.html').read_text()
assert 'สำรวจ Prompt' in html and 'ข้อมูลสาธิต' in html, 'Expected demo listing missing'
print('Prompt Hub: development services and Thai demo listing verified.')
PY
