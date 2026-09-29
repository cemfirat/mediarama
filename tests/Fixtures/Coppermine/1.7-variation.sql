-- Synthetic Coppermine 1.7 capability variation layered onto the 1.6 test fixture.
-- Source model reference: coppermine-gallery/cpg1.7.x schema/update sources.
-- Hand-assembled minimal variation for Mediarama tests; not an upstream database dump.
-- Upstream reference license: GPL-3.0; Mediarama repository: GPL-3.0-or-later.

ALTER TABLE cpg_pictures
    ADD COLUMN mime VARCHAR(255) NOT NULL DEFAULT 'image/*' AFTER total_filesize,
    ADD COLUMN ftype VARCHAR(32) NOT NULL DEFAULT 'image' AFTER mime;

ALTER TABLE cpg_vote_stats
    MODIFY COLUMN ip VARCHAR(20) NOT NULL DEFAULT '';

INSERT INTO cpg_config (name, value)
VALUES ('thumbs_per', '20')
ON DUPLICATE KEY UPDATE value = VALUES(value);

UPDATE cpg_pictures
SET mime = CASE
        WHEN filename IN ('sample.jpg', 'sample2.jpg') THEN 'image/jpeg'
        WHEN filename = 'sample.mp3' THEN 'audio/mpeg'
        WHEN filename = 'sample.mp4' THEN 'video/mp4'
        ELSE mime
    END,
    ftype = CASE
        WHEN filename IN ('sample.jpg', 'sample2.jpg') THEN 'image'
        WHEN filename = 'sample.mp3' THEN 'audio'
        WHEN filename = 'sample.mp4' THEN 'movie'
        ELSE ftype
    END
WHERE pid IN (100, 101, 102, 103);

INSERT INTO cpg_pictures (
  pid, aid, filepath, filename, filesize, total_filesize, mime, ftype,
  pwidth, pheight, hits, mtime, ctime, owner_id, pic_rating, votes,
  title, caption, keywords, approved, position
) VALUES (
  104, 11, 'userpics/', 'sample.webp', 0, 0, 'image/webp', 'image',
  2, 2, 0, '2024-01-06 12:00:00', 1704542400, 1, 0, 0,
  '1.7 WebP Fixture', 'WebP added to the default 1.7 file type set', '', 'YES', 5
);
