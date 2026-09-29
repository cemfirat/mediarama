-- Synthetic Coppermine 1.6 migration interoperability fixture.
-- Source model reference: coppermine-gallery/cpg1.6.x sql/schema.sql and sql/basic.sql.
-- Hand-assembled minimal schema/data for Mediarama tests; not an upstream database dump.
-- Upstream reference license: GPL-3.0; Mediarama repository: GPL-3.0-or-later.

CREATE TABLE cpg_usergroups (
  group_id INT NOT NULL PRIMARY KEY,
  group_name VARCHAR(255) NOT NULL,
  group_quota INT NOT NULL DEFAULT 0,
  has_admin_access TINYINT NOT NULL DEFAULT 0,
  can_rate_pictures TINYINT NOT NULL DEFAULT 0,
  can_send_ecards TINYINT NOT NULL DEFAULT 0,
  can_post_comments TINYINT NOT NULL DEFAULT 0,
  can_upload_pictures TINYINT NOT NULL DEFAULT 0,
  can_create_albums TINYINT NOT NULL DEFAULT 0,
  pub_upl_need_approval TINYINT NOT NULL DEFAULT 1,
  priv_upl_need_approval TINYINT NOT NULL DEFAULT 1,
  access_level TINYINT NOT NULL DEFAULT 3
);

CREATE TABLE cpg_banned (
  ban_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT DEFAULT NULL,
  user_name VARCHAR(255) NOT NULL DEFAULT '',
  email VARCHAR(255) NOT NULL DEFAULT '',
  ip_addr TINYTEXT,
  expiry DATETIME DEFAULT NULL,
  brute_force TINYINT NOT NULL DEFAULT 0
);

CREATE TABLE cpg_plugins (
  plugin_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(64) NOT NULL DEFAULT '',
  enabled TINYINT NOT NULL DEFAULT 1,
  path VARCHAR(128) NOT NULL DEFAULT '',
  priority INT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uniq_plugin_name (name),
  UNIQUE KEY uniq_plugin_path (path)
);

CREATE TABLE cpg_filetypes (
  extension CHAR(7) NOT NULL DEFAULT '',
  mime CHAR(254) DEFAULT NULL,
  content CHAR(15) DEFAULT NULL,
  player VARCHAR(5) DEFAULT NULL,
  PRIMARY KEY (extension)
);

CREATE TABLE cpg_users (
  user_id INT NOT NULL PRIMARY KEY,
  user_group INT NOT NULL DEFAULT 2,
  user_active ENUM('YES','NO') NOT NULL DEFAULT 'NO',
  user_name VARCHAR(25) NOT NULL,
  user_password VARCHAR(255) NOT NULL DEFAULT '',
  user_password_salt VARCHAR(255) NOT NULL DEFAULT '',
  user_password_hash_algorithm VARCHAR(25) NOT NULL DEFAULT '',
  user_password_iterations VARCHAR(25) NOT NULL DEFAULT '',
  user_lastvisit DATETIME NOT NULL,
  user_regdate DATETIME NOT NULL,
  user_group_list VARCHAR(255) NOT NULL DEFAULT '',
  user_email VARCHAR(255) NOT NULL DEFAULT '',
  user_email_valid ENUM('YES','') NOT NULL DEFAULT '',
  user_profile1 VARCHAR(255) NOT NULL DEFAULT '',
  user_profile2 VARCHAR(255) NOT NULL DEFAULT '',
  user_profile3 VARCHAR(255) NOT NULL DEFAULT '',
  user_profile4 VARCHAR(255) NOT NULL DEFAULT '',
  user_profile5 VARCHAR(255) NOT NULL DEFAULT '',
  user_profile6 TEXT NOT NULL,
  user_actkey VARCHAR(32) NOT NULL DEFAULT '',
  user_language VARCHAR(40) NOT NULL DEFAULT ''
);

CREATE TABLE cpg_categories (
  cid INT NOT NULL PRIMARY KEY,
  owner_id INT NOT NULL DEFAULT 0,
  name VARCHAR(255) NOT NULL,
  description TEXT NOT NULL,
  pos INT NOT NULL DEFAULT 0,
  parent INT NOT NULL DEFAULT 0,
  thumb INT NOT NULL DEFAULT 0,
  lft INT UNSIGNED NOT NULL DEFAULT 0,
  rgt INT UNSIGNED NOT NULL DEFAULT 0,
  depth TINYINT UNSIGNED NOT NULL DEFAULT 0
);

CREATE TABLE cpg_albums (
  aid INT NOT NULL PRIMARY KEY,
  title VARCHAR(255) NOT NULL,
  description TEXT NOT NULL,
  visibility INT NOT NULL DEFAULT 0,
  uploads ENUM('YES','NO') NOT NULL DEFAULT 'NO',
  comments ENUM('YES','NO') NOT NULL DEFAULT 'YES',
  votes ENUM('YES','NO') NOT NULL DEFAULT 'YES',
  pos INT NOT NULL DEFAULT 0,
  category INT NOT NULL DEFAULT 0,
  owner INT NOT NULL DEFAULT 1,
  thumb INT NOT NULL DEFAULT 0,
  keyword VARCHAR(50) DEFAULT NULL,
  alb_password VARCHAR(32) DEFAULT NULL,
  alb_password_hint TEXT,
  moderator_group INT NOT NULL DEFAULT 0,
  alb_hits INT NOT NULL DEFAULT 0
);

CREATE TABLE cpg_pictures (
  pid INT NOT NULL PRIMARY KEY,
  aid INT NOT NULL DEFAULT 0,
  filepath VARCHAR(255) NOT NULL DEFAULT '',
  filename VARCHAR(255) NOT NULL DEFAULT '',
  filesize INT NOT NULL DEFAULT 0,
  total_filesize INT NOT NULL DEFAULT 0,
  pwidth SMALLINT NOT NULL DEFAULT 0,
  pheight SMALLINT NOT NULL DEFAULT 0,
  hits INT NOT NULL DEFAULT 0,
  mtime DATETIME NOT NULL,
  ctime INT NOT NULL DEFAULT 0,
  owner_id INT NOT NULL DEFAULT 0,
  pic_rating INT NOT NULL DEFAULT 0,
  votes INT NOT NULL DEFAULT 0,
  title VARCHAR(255) NOT NULL DEFAULT '',
  caption TEXT NOT NULL,
  keywords VARCHAR(255) NOT NULL DEFAULT '',
  approved ENUM('YES','NO') NOT NULL DEFAULT 'NO',
  galleryicon INT NOT NULL DEFAULT 0,
  user1 VARCHAR(255) NOT NULL DEFAULT '',
  user2 VARCHAR(255) NOT NULL DEFAULT '',
  user3 VARCHAR(255) NOT NULL DEFAULT '',
  user4 VARCHAR(255) NOT NULL DEFAULT '',
  url_prefix TINYINT NOT NULL DEFAULT 0,
  pic_raw_ip TEXT,
  pic_hdr_ip TEXT,
  lasthit_ip TEXT,
  position INT NOT NULL DEFAULT 0,
  guest_token VARCHAR(32) DEFAULT ''
);

CREATE TABLE cpg_categorymap (
  cid INT NOT NULL,
  group_id INT NOT NULL,
  PRIMARY KEY (cid, group_id)
);

CREATE TABLE cpg_comments (
  pid INT NOT NULL DEFAULT 0,
  msg_id INT NOT NULL PRIMARY KEY,
  msg_author VARCHAR(25) NOT NULL DEFAULT '',
  msg_body TEXT NOT NULL,
  msg_date DATETIME NOT NULL,
  msg_raw_ip TEXT,
  msg_hdr_ip TEXT,
  author_md5_id VARCHAR(32) NOT NULL DEFAULT '',
  author_id INT NOT NULL DEFAULT 0,
  approval ENUM('YES','NO') NOT NULL DEFAULT 'YES',
  spam ENUM('YES','NO') NOT NULL DEFAULT 'NO'
);

CREATE TABLE cpg_votes (
  pic_id INT NOT NULL,
  user_md5_id VARCHAR(32) NOT NULL,
  vote_time INT NOT NULL DEFAULT 0,
  PRIMARY KEY (pic_id, user_md5_id)
);

CREATE TABLE cpg_vote_stats (
  sid INT NOT NULL PRIMARY KEY,
  pid VARCHAR(100) NOT NULL DEFAULT '',
  rating SMALLINT NOT NULL DEFAULT 0,
  ip VARCHAR(40) NOT NULL DEFAULT '',
  sdate BIGINT NOT NULL DEFAULT 0,
  referer TEXT NOT NULL,
  browser VARCHAR(255) NOT NULL DEFAULT '',
  os VARCHAR(50) NOT NULL DEFAULT '',
  uid INT NOT NULL DEFAULT 0
);

CREATE TABLE cpg_favpics (
  user_id INT NOT NULL PRIMARY KEY,
  user_favpics TEXT NOT NULL
);

CREATE TABLE cpg_exif (
  pid INT NOT NULL PRIMARY KEY,
  exifData TEXT NOT NULL
);

CREATE TABLE cpg_config (
  name VARCHAR(40) NOT NULL PRIMARY KEY,
  value VARCHAR(255) NOT NULL DEFAULT ''
);

CREATE TABLE cpg_languages (
  lang_id VARCHAR(40) NOT NULL PRIMARY KEY,
  english_name VARCHAR(70) DEFAULT NULL,
  native_name VARCHAR(70) DEFAULT NULL,
  custom_name VARCHAR(70) DEFAULT NULL,
  flag VARCHAR(15) DEFAULT NULL,
  abbr VARCHAR(15) NOT NULL DEFAULT '',
  available ENUM('YES','NO') NOT NULL DEFAULT 'NO',
  enabled ENUM('YES','NO') NOT NULL DEFAULT 'NO',
  complete ENUM('YES','NO') NOT NULL DEFAULT 'NO'
);

INSERT INTO cpg_filetypes (extension, mime, content, player) VALUES
  ('jpg', 'image/jpg', 'image', ''),
  ('jpeg', 'image/jpeg', 'image', ''),
  ('mp3', 'audio/mpeg3', 'audio', 'WMP'),
  ('mp4', 'video/mp4', 'movie', 'HTMLV');

INSERT INTO cpg_usergroups (
  group_id, group_name, group_quota, has_admin_access, can_rate_pictures,
  can_post_comments, can_upload_pictures, can_create_albums,
  pub_upl_need_approval, priv_upl_need_approval, access_level
) VALUES (3, 'Registered', 2048, 0, 1, 1, 1, 1, 0, 0, 3);

INSERT INTO cpg_users (
  user_id, user_group, user_active, user_name, user_lastvisit, user_regdate,
  user_email, user_email_valid, user_profile6, user_language
) VALUES
(
  1, 3, 'YES', 'fixture-user', '2024-02-01 10:00:00', '2024-01-01 10:00:00',
  'fixture@example.test', 'YES', '', 'english'
),
(
  2, 3, 'YES', 'viewer-user', '2024-02-02 10:00:00', '2024-01-02 10:00:00',
  'viewer@example.test', 'YES', '', 'german'
);

INSERT INTO cpg_categories (
  cid, owner_id, name, description, pos, parent, thumb, lft, rgt, depth
) VALUES
  (2, 0, 'Fixture Category', 'Category imported by CI', 1, 0, 101, 1, 4, 0),
  (3, 0, 'Nested Category', 'Nested category imported by CI', 2, 2, 0, 2, 3, 1);

INSERT INTO cpg_albums (
  aid, title, description, visibility, uploads, comments, votes, pos, category,
  owner, thumb, alb_hits, alb_password, alb_password_hint
) VALUES (
  10, 'Fixture Album', 'Album imported by CI', 3, 'YES', 'YES', 'YES', 1, 2,
  1, 100, 17, '5ebe2294ecd0e0f08eab7690d2a6ee69', 'fixture hint'
);

INSERT INTO cpg_albums (
  aid, title, description, visibility, uploads, comments, votes, pos, category,
  owner, thumb, keyword
) VALUES (
  11, 'Linked Album', 'Membership comes from album keyword', 0, 'NO', 'NO', 'NO', 2, 2,
  1, -1, 'summer'
);

INSERT INTO cpg_albums (
  aid, title, description, visibility, uploads, comments, votes, pos, category,
  owner, keyword
) VALUES
(
  12, 'Nested Album', 'Album inside a nested category', 0, 'NO', 'YES', 'YES', 3, 3,
  1, NULL
),
(
  13, 'User Gallery Album', 'Album in the virtual user gallery namespace', 0, 'NO', 'YES', 'YES', 4, 10001,
  1, NULL
),
(
  14, 'User Restricted Album', 'Album restricted to a specific user', 10002, 'NO', 'YES', 'YES', 5, 0,
  1, NULL
);

INSERT INTO cpg_pictures (
  pid, aid, filepath, filename, filesize, total_filesize, pwidth, pheight, hits,
  mtime, ctime, owner_id, pic_rating, votes, title, caption, keywords, approved,
  position
) VALUES
(
  100, 10, 'userpics/', 'sample.jpg', 0, 0, 2, 2, 42,
  '2024-01-01 12:00:00', 1704110400, 1, 8000, 1,
  'Fixture Photo', 'Imported fixture caption', 'summer;vacation', 'YES', 1
),
(
  101, 11, 'userpics/', 'sample2.jpg', 0, 0, 2, 2, 9,
  '2024-01-03 12:00:00', 1704283200, 1, 6000, 2,
  'Aggregate Only Photo', 'Rating aggregate without detailed vote rows', '', 'YES', 2
),
(
  102, 11, 'userpics/', 'sample.mp3', 0, 0, 0, 0, 0,
  '2024-01-04 12:00:00', 1704369600, 1, 0, 0,
  'Fixture Audio', 'Non-image audio fixture', '', 'YES', 3
),
(
  103, 11, 'userpics/', 'sample.mp4', 0, 0, 16, 16, 0,
  '2024-01-05 12:00:00', 1704456000, 1, 0, 0,
  'Fixture Video', 'Non-image video fixture', '', 'YES', 4
);

INSERT INTO cpg_categorymap (cid, group_id)
VALUES (2, 3);

INSERT INTO cpg_comments (
  pid, msg_id, msg_author, msg_body, msg_date, author_id, approval, spam
) VALUES
(
  100, 200, 'fixture-user', 'Fixture comment', '2024-01-02 12:00:00', 1, 'YES', 'NO'
),
(
  100, 201, 'Guest Alice', 'Guest fixture comment', '2024-01-03 12:00:00', 0, 'NO', 'NO'
);

INSERT INTO cpg_votes (pic_id, user_md5_id, vote_time)
VALUES (100, 'c4ca4238a0b923820dcc509a6f75849b', 1704196800);

INSERT INTO cpg_vote_stats (
  sid, pid, rating, sdate, referer, browser, os, uid
) VALUES (
  300, '100', 4, 1704196800, '', 'fixture', 'fixture', 1
);

INSERT INTO cpg_favpics (user_id, user_favpics)
VALUES (1, 'YToxOntpOjA7aToxMDA7fQ==');

-- Deliberately stale derived Coppermine EXIF cache. The source JPEG written by
-- CI contains Make="Fixture Camera Co"; migration must re-extract the file
-- and must not trust this cached historical value.
INSERT INTO cpg_exif (pid, exifData)
VALUES (100, 'a:1:{s:4:"Make";s:16:"Stale Cache Make";}');

INSERT INTO cpg_languages (
  lang_id, english_name, native_name, flag, abbr, available, enabled, complete
) VALUES
  ('english', 'English (US)', 'English (US)', 'us', 'en', 'YES', 'YES', 'YES'),
  ('german', 'German (informal)', 'Deutsch (Du)', 'de', 'de', 'YES', 'YES', 'YES');

INSERT INTO cpg_config (name, value) VALUES
  ('bridge_enable', '0'),
  ('allow_private_albums', '1'),
  ('keyword_separator', ';'),
  ('old_style_rating', '0'),
  ('rating_stars_amount', '5'),
  ('lang', 'english');
