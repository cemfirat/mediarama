#!/usr/bin/env bash
set -euo pipefail

SOURCE_DB="coppermine_batch"
TARGET_DB="mediarama_batch"
BATCH_ROOT="/tmp/coppermine-batch-albums"
TARGET_ROOT="/tmp/mediarama-batch-media"

rm -rf "$BATCH_ROOT" "$TARGET_ROOT"
mkdir -p "$BATCH_ROOT/userpics"

cp /tmp/coppermine-albums/userpics/sample.jpg "$BATCH_ROOT/userpics/sample.jpg"
cp /tmp/coppermine-albums/userpics/sample2.jpg "$BATCH_ROOT/userpics/sample2.jpg"
cp /tmp/coppermine-albums/userpics/sample.mp3 "$BATCH_ROOT/userpics/sample.mp3"
cp /tmp/coppermine-albums/userpics/sample.mp4 "$BATCH_ROOT/userpics/sample.mp4"

php <<'PHP'
<?php
$root = '/tmp/coppermine-batch-albums/userpics';
for ($i = 1000; $i <= 5999; ++$i) {
    $target = sprintf('%s/bulk-%d.mp3', $root, $i);
    if (!link($root.'/sample.mp3', $target)) {
        fwrite(STDERR, 'Unable to create bulk source link: '.$target.PHP_EOL);
        exit(1);
    }
}
PHP

mariadb --host=127.0.0.1 --port=3306 --user=root --password=root \
  -e "DROP DATABASE IF EXISTS $SOURCE_DB; CREATE DATABASE $SOURCE_DB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mariadb --host=127.0.0.1 --port=3306 --user=root --password=root "$SOURCE_DB" \
  < tests/Fixtures/Coppermine/1.6-minimal.sql

php <<'PHP' > /tmp/coppermine-batch-pictures.sql
<?php
echo <<<'SQL'
INSERT INTO cpg_pictures (
  pid, aid, filepath, filename, filesize, total_filesize, pwidth, pheight, hits,
  mtime, ctime, owner_id, pic_rating, votes, title, caption, keywords, approved,
  position
) VALUES
SQL;

for ($i = 1000; $i <= 5999; ++$i) {
    if ($i > 1000) {
        echo ",\n";
    }

    printf(
        "(%d, 11, 'userpics/', 'bulk-%d.mp3', 0, 0, 0, 0, 0, '2024-02-01 12:00:00', 1706788800, 1, 0, 0, 'Bulk media %d', '', '', 'YES', %d)",
        $i,
        $i,
        $i,
        $i,
    );
}

echo ";\n";
PHP

mariadb --host=127.0.0.1 --port=3306 --user=root --password=root "$SOURCE_DB" \
  < /tmp/coppermine-batch-pictures.sql
mariadb --host=127.0.0.1 --port=3306 --user=root --password=root \
  -e "CREATE USER IF NOT EXISTS 'readonly'@'%' IDENTIFIED BY 'readonly'; GRANT SELECT ON $SOURCE_DB.* TO 'readonly'@'%'; FLUSH PRIVILEGES;"

php <<'PHP'
<?php
$pdo = new PDO(
    'pgsql:host=127.0.0.1;port=5432;dbname=postgres',
    'mediarama',
    'mediarama',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$pdo->exec('DROP DATABASE IF EXISTS mediarama_batch');
$pdo->exec('CREATE DATABASE mediarama_batch');
PHP

export DATABASE_URL="postgresql://mediarama:mediarama@127.0.0.1:5432/$TARGET_DB?serverVersion=18&charset=utf8"
export MEDIA_STORAGE_PATH="$TARGET_ROOT"
export COPPERMINE_DATABASE_URL="mysql://readonly:readonly@127.0.0.1:3306/$SOURCE_DB"
export COPPERMINE_SOURCE_ID="fixture-batch"
export COPPERMINE_TABLE_PREFIX="cpg_"
export COPPERMINE_ALBUMS_ROOT="$BATCH_ROOT"

php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

php <<'PHP'
<?php
$pdo = new PDO(
    'pgsql:host=127.0.0.1;port=5432;dbname=mediarama_batch',
    'mediarama',
    'mediarama',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$pdo->exec(<<<'SQL'
CREATE OR REPLACE FUNCTION mediarama_ci_fail_bulk_media()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF NEW.original_filename = 'bulk-3500.mp3' THEN
        RAISE EXCEPTION 'synthetic checkpoint failure';
    END IF;
    RETURN NEW;
END;
$$;
SQL);
$pdo->exec(<<<'SQL'
CREATE TRIGGER mediarama_ci_fail_bulk_media
BEFORE INSERT ON media_assets
FOR EACH ROW
EXECUTE FUNCTION mediarama_ci_fail_bulk_media()
SQL);
PHP

set +e
php bin/console mediarama:import:coppermine > /tmp/coppermine-batch-first-run.txt 2>&1
status=$?
set -e

cat /tmp/coppermine-batch-first-run.txt

if [ "$status" -eq 0 ]; then
  echo "Expected the first large-library import to stop at the synthetic target failure."
  exit 1
fi

grep -F "synthetic checkpoint failure" /tmp/coppermine-batch-first-run.txt

php <<'PHP'
<?php
require 'vendor/autoload.php';

$dsn = new Doctrine\DBAL\Tools\DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));

$checks = [
    'one failed import run' => (int) $db->fetchOne("SELECT COUNT(*) FROM import_runs WHERE source_type = 'coppermine' AND source_key = 'coppermine:fixture-batch' AND status = 'failed'") === 1,
    'failure recorded in progress' => (int) $db->fetchOne("SELECT COUNT(*) FROM import_runs WHERE source_type = 'coppermine' AND status = 'failed' AND progress->>'error' LIKE '%synthetic checkpoint failure%'") === 1,
    'checkpoint stops before failed source row' => (string) $db->fetchOne("SELECT cursor FROM import_checkpoints WHERE source_key = 'coppermine:fixture-batch' AND stage = 'pictures'") === '3499',
    '2504 picture mappings before failure' => (int) $db->fetchOne("SELECT COUNT(*) FROM import_mappings WHERE source_key = 'coppermine:fixture-batch' AND entity_type = 'picture'") === 2504,
    '2504 media rows before failure' => (int) $db->fetchOne('SELECT COUNT(*) FROM media_assets') === 2504,
    'failed source row is not mapped' => (int) $db->fetchOne("SELECT COUNT(*) FROM import_mappings WHERE source_key = 'coppermine:fixture-batch' AND entity_type = 'picture' AND source_id = '3500'") === 0,
    '2504 queued processing jobs before resume' => (int) $db->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'async'") === 2504,
];

foreach ($checks as $name => $ok) {
    echo ($ok ? 'OK ' : 'FAIL ').$name.PHP_EOL;
    if (!$ok) {
        exit(1);
    }
}
PHP

first_files="$(find "$MEDIA_STORAGE_PATH/originals" -type f | wc -l)"
if [ "$first_files" -ne 2504 ]; then
  echo "Expected 2504 stored originals after the interrupted run, got $first_files"
  exit 1
fi

php <<'PHP'
<?php
$pdo = new PDO(
    'pgsql:host=127.0.0.1;port=5432;dbname=mediarama_batch',
    'mediarama',
    'mediarama',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$pdo->exec('DROP TRIGGER IF EXISTS mediarama_ci_fail_bulk_media ON media_assets');
$pdo->exec('DROP FUNCTION IF EXISTS mediarama_ci_fail_bulk_media()');
PHP

php bin/console mediarama:import:coppermine | tee /tmp/coppermine-batch-resumed-run.txt

grep -F "Source pictures: 5004" /tmp/coppermine-batch-resumed-run.txt
grep -F "Mapped pictures: 5004" /tmp/coppermine-batch-resumed-run.txt
grep -F "Target media: 5004" /tmp/coppermine-batch-resumed-run.txt
grep -F "Migration completed and reconciled." /tmp/coppermine-batch-resumed-run.txt

php <<'PHP'
<?php
require 'vendor/autoload.php';

$dsn = new Doctrine\DBAL\Tools\DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = Doctrine\DBAL\DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));

$checks = [
    'one failed and one completed import run' =>
        (int) $db->fetchOne("SELECT COUNT(*) FROM import_runs WHERE source_type = 'coppermine' AND source_key = 'coppermine:fixture-batch'") === 2
        && (int) $db->fetchOne("SELECT COUNT(*) FROM import_runs WHERE source_type = 'coppermine' AND source_key = 'coppermine:fixture-batch' AND status = 'failed'") === 1
        && (int) $db->fetchOne("SELECT COUNT(*) FROM import_runs WHERE source_type = 'coppermine' AND source_key = 'coppermine:fixture-batch' AND status = 'completed'") === 1,
    'final picture checkpoint' => (string) $db->fetchOne("SELECT cursor FROM import_checkpoints WHERE source_key = 'coppermine:fixture-batch' AND stage = 'pictures'") === '5999',
    '5004 picture mappings' => (int) $db->fetchOne("SELECT COUNT(*) FROM import_mappings WHERE source_key = 'coppermine:fixture-batch' AND entity_type = 'picture'") === 5004,
    '5004 distinct source mappings' => (int) $db->fetchOne("SELECT COUNT(DISTINCT source_id) FROM import_mappings WHERE source_key = 'coppermine:fixture-batch' AND entity_type = 'picture'") === 5004,
    '5004 distinct target mappings' => (int) $db->fetchOne("SELECT COUNT(DISTINCT target_id) FROM import_mappings WHERE source_key = 'coppermine:fixture-batch' AND entity_type = 'picture'") === 5004,
    '5004 target media rows' => (int) $db->fetchOne('SELECT COUNT(*) FROM media_assets') === 5004,
    'previously failed row imported exactly once' => (int) $db->fetchOne("SELECT COUNT(*) FROM import_mappings i JOIN media_assets m ON m.id = i.target_id WHERE i.source_key = 'coppermine:fixture-batch' AND i.entity_type = 'picture' AND i.source_id = '3500' AND m.original_filename = 'bulk-3500.mp3'") === 1,
    '5004 queued processing jobs without duplicate dispatch' => (int) $db->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'async'") === 5004,
];

foreach ($checks as $name => $ok) {
    echo ($ok ? 'OK ' : 'FAIL ').$name.PHP_EOL;
    if (!$ok) {
        exit(1);
    }
}
PHP

final_files="$(find "$MEDIA_STORAGE_PATH/originals" -type f | wc -l)"
if [ "$final_files" -ne 5004 ]; then
  echo "Expected 5004 stored originals after resume, got $final_files"
  exit 1
fi

echo "Large-library batching/resume fixture completed successfully."
