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

CREATE TABLE cpg_config (
  name VARCHAR(40) NOT NULL PRIMARY KEY,
  value VARCHAR(255) NOT NULL DEFAULT ''
);

INSERT INTO cpg_usergroups (
  group_id, group_name, group_quota, has_admin_access, can_rate_pictures,
  can_post_comments, can_upload_pictures, can_create_albums
) VALUES (3, 'Registered', 2048, 0, 1, 1, 1, 1);

INSERT INTO cpg_users (
  user_id, user_group, user_active, user_name, user_lastvisit, user_regdate,
  user_email, user_email_valid, user_profile6, user_language
) VALUES (
  1, 3, 'YES', 'fixture-user', '2024-02-01 10:00:00', '2024-01-01 10:00:00',
  'fixture@example.test', 'YES', '', 'en'
);

INSERT INTO cpg_categories (
  cid, owner_id, name, description, pos, parent, lft, rgt, depth
) VALUES (2, 0, 'Fixture Category', 'Category imported by CI', 1, 0, 1, 2, 0);

INSERT INTO cpg_albums (
  aid, title, description, visibility, uploads, comments, votes, pos, category,
  owner, alb_password, alb_password_hint
) VALUES (
  10, 'Fixture Album', 'Album imported by CI', 3, 'YES', 'YES', 'YES', 1, 2,
  1, '5ebe2294ecd0e0f08eab7690d2a6ee69', 'fixture hint'
);

INSERT INTO cpg_pictures (
  pid, aid, filepath, filename, filesize, total_filesize, pwidth, pheight, hits,
  mtime, ctime, owner_id, pic_rating, votes, title, caption, keywords, approved,
  position
) VALUES (
  100, 10, 'userpics/', 'sample.jpg', 0, 0, 2, 2, 0,
  '2024-01-01 12:00:00', 1704110400, 1, 8000, 1,
  'Fixture Photo', 'Imported fixture caption', 'summer;vacation', 'YES', 1
);

INSERT INTO cpg_comments (
  pid, msg_id, msg_author, msg_body, msg_date, author_id, approval, spam
) VALUES (
  100, 200, 'fixture-user', 'Fixture comment', '2024-01-02 12:00:00', 1, 'YES', 'NO'
);

INSERT INTO cpg_votes (pic_id, user_md5_id, vote_time)
VALUES (100, 'c4ca4238a0b923820dcc509a6f75849b', 1704196800);

INSERT INTO cpg_vote_stats (
  sid, pid, rating, sdate, referer, browser, os, uid
) VALUES (
  300, '100', 4, 1704196800, '', 'fixture', 'fixture', 1
);

INSERT INTO cpg_config (name, value) VALUES
  ('keyword_separator', ';'),
  ('old_style_rating', '0'),
  ('rating_stars_amount', '5');
